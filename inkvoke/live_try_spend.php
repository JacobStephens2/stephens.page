<?php

declare(strict_types=1);

/**
 * Live-try spend. The one public operation records the running estimated cost
 * of a successful live try to the public tally and the durable copy.
 * The contact form is not a caller.
 */
final class LiveTrySpend
{
    private const NOTE = 'Estimated live-try OpenAI image spend (gpt-image-2 rates). '
        . 'Running tally for public gallery items; hidden images are excluded '
        . '(dashboard hide subtracts their cost).';

    /**
     * @return array{total_usd: float, image_count: int, updated_at: int}
     */
    public static function record(?float $costUsd, int $now, string $publicPath, string $durablePath, string $trialsPath): array
    {
        $fp = self::lockPublic($publicPath);
        if (!is_resource($fp)) {
            return self::priorWhenPublicUnheld($publicPath, $durablePath);
        }

        $original = '';
        $base = self::zeros();
        $next = self::zeros();
        $bytes = '';
        $published = false;
        try {
            rewind($fp);
            $read = stream_get_contents($fp);
            if ($read === false) {
                return self::priorWhenPublicUnheld($publicPath, $durablePath);
            }
            $original = $read;
            $base = self::base($original, $durablePath, $trialsPath);
            $next = self::apply($base, $costUsd, $now);
            $encoded = self::encode($next);
            if ($encoded === null || !self::replace($fp, $encoded) || !@chmod($publicPath, 0664)) {
                self::replace($fp, $original);
                return self::fields($base);
            }
            $bytes = $encoded;
            $published = true;
        } catch (Throwable $e) {
            error_log('inkvoke try: spend tally error: ' . $e->getMessage());
            if (!$published) {
                self::replace($fp, $original);
            }
        } finally {
            self::unlock($fp);
        }

        if (!$published) {
            return self::fields($base);
        }
        self::mirror($durablePath, $bytes);
        return self::fields($next);
    }

    /** @return resource|null */
    private static function lockPublic(string $path)
    {
        if (!self::ensureDir($path)) {
            return null;
        }
        $existed = is_file($path);
        $fp = @fopen($path, 'c+');
        if ($fp === false) {
            return null;
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            if (!$existed && is_file($path) && @filesize($path) === 0) {
                @unlink($path);
            }
            return null;
        }
        return $fp;
    }

    /** @param resource|null $fp */
    private static function unlock($fp): void
    {
        if (!is_resource($fp)) {
            return;
        }
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }

    /**
     * @return array{total_usd: float, image_count: int, updated_at: int}
     */
    private static function base(string $publicRaw, string $durablePath, string $trialsPath): array
    {
        $public = self::tallyFromBytes($publicRaw);
        if ($public !== null) {
            return $public;
        }
        $durable = self::readTally($durablePath);
        if ($durable !== null && ($durable['image_count'] > 0 || $durable['total_usd'] > 0)) {
            return $durable;
        }
        return self::fromTrials($trialsPath);
    }

    /**
     * @return array{total_usd: float, image_count: int, updated_at: int}
     */
    private static function fromTrials(string $path): array
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return self::zeros();
        }
        $total = 0.0;
        $count = 0;
        try {
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $event = json_decode($line, true);
                if (!is_array($event)) {
                    continue;
                }
                if (($event['event'] ?? '') !== 'try' || empty($event['ok'])) {
                    continue;
                }
                if (!isset($event['cost_usd']) || !is_numeric($event['cost_usd'])) {
                    continue;
                }
                $cost = (float) $event['cost_usd'];
                if (!is_finite($cost)) {
                    continue;
                }
                $total += $cost;
                $count++;
            }
        } finally {
            fclose($fh);
        }
        return [
            'total_usd' => $total,
            'image_count' => $count,
            'updated_at' => 0,
        ];
    }

    /**
     * @return array{total_usd: float, image_count: int, updated_at: int}|null
     */
    private static function tallyFromBytes(string $raw): ?array
    {
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }
        return [
            'total_usd' => self::dollarsOf($data['total_usd'] ?? 0),
            'image_count' => self::countOf($data['image_count'] ?? 0),
            'updated_at' => self::instantOf($data['updated_at'] ?? 0),
        ];
    }

    /** @return array{total_usd: float, image_count: int, updated_at: int}|null */
    private static function readTally(string $path): ?array
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        return self::tallyFromBytes($raw);
    }

    private static function dollarsOf(mixed $value): float
    {
        if (is_int($value)) {
            return (float) $value;
        }
        if (is_float($value)) {
            return is_finite($value) ? $value : 0.0;
        }
        if (is_string($value) && is_numeric($value)) {
            $number = (float) $value;
            return is_finite($number) ? $number : 0.0;
        }
        return 0.0;
    }

    private static function countOf(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if ((is_float($value) && is_finite($value)) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }
        return 0;
    }

    private static function instantOf(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if ((is_float($value) && is_finite($value)) || (is_string($value) && is_numeric($value))) {
            return (int) $value;
        }
        return 0;
    }

    private static function added(?float $cost): float
    {
        if ($cost === null || !is_finite($cost) || $cost <= 0) {
            return 0.0;
        }
        return $cost;
    }

    /**
     * @param array{total_usd: float, image_count: int, updated_at: int} $base
     * @return array{total_usd: float, image_count: int, updated_at: int}
     */
    private static function apply(array $base, ?float $cost, int $now): array
    {
        return [
            'total_usd' => round(max(0.0, $base['total_usd'] + self::added($cost)), 6),
            'image_count' => $base['image_count'] + 1,
            'updated_at' => $now,
        ];
    }

    /** @param array{total_usd: float, image_count: int, updated_at: int} $tally */
    private static function encode(array $tally): ?string
    {
        $json = json_encode([
            'total_usd' => $tally['total_usd'],
            'image_count' => $tally['image_count'],
            'updated_at' => $tally['updated_at'],
            'currency' => 'USD',
            'note' => self::NOTE,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            return null;
        }
        return $json . "\n";
    }

    /**
     * @param array{total_usd: float, image_count: int, updated_at: int} $tally
     * @return array{total_usd: float, image_count: int, updated_at: int}
     */
    private static function fields(array $tally): array
    {
        return [
            'total_usd' => $tally['total_usd'],
            'image_count' => $tally['image_count'],
            'updated_at' => $tally['updated_at'],
        ];
    }

    /** @return array{total_usd: float, image_count: int, updated_at: int} */
    private static function zeros(): array
    {
        return [
            'total_usd' => 0.0,
            'image_count' => 0,
            'updated_at' => 0,
        ];
    }

    /** @param resource $fp */
    private static function replace($fp, string $bytes): bool
    {
        if (ftruncate($fp, 0) === false || rewind($fp) === false) {
            return false;
        }
        $written = fwrite($fp, $bytes);
        if ($written !== strlen($bytes)) {
            return false;
        }
        return fflush($fp);
    }

    private static function mirror(string $path, string $bytes): void
    {
        try {
            if (!self::ensureDir($path)) {
                return;
            }
            $fp = @fopen($path, 'c+');
            if ($fp === false) {
                return;
            }
            try {
                if (!flock($fp, LOCK_EX)) {
                    return;
                }
                rewind($fp);
                $original = stream_get_contents($fp);
                if (!is_string($original)) {
                    $original = '';
                }
                if (!self::replace($fp, $bytes)) {
                    self::replace($fp, $original);
                    return;
                }
                @chmod($path, 0664);
            } finally {
                self::unlock($fp);
            }
        } catch (Throwable $e) {
            error_log('inkvoke try: spend tally error: ' . $e->getMessage());
        }
    }

    /**
     * The public file could not be opened or locked. Write nothing.
     *
     * @return array{total_usd: float, image_count: int, updated_at: int}
     */
    private static function priorWhenPublicUnheld(string $publicPath, string $durablePath): array
    {
        if (self::readTally($publicPath) !== null) {
            return self::zeros();
        }
        $durable = self::readTally($durablePath);
        if ($durable !== null) {
            return self::fields($durable);
        }
        return self::zeros();
    }

    private static function ensureDir(string $path): bool
    {
        $dir = dirname($path);
        if (is_dir($dir)) {
            return true;
        }
        return @mkdir($dir, 0775, true) || is_dir($dir);
    }
}
