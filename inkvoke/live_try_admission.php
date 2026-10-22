<?php

declare(strict_types=1);

/**
 * Live-try admission. The one public operation records a live try, five an hour
 * per connection and three a day per email, only after the caller proves the try.
 * The contact form is not a caller.
 */
final class LiveTryAdmission
{
    private const CONNECTION_LIMIT = 5;
    private const CONNECTION_WINDOW = 3600;
    private const EMAIL_LIMIT = 3;
    private const EMAIL_WINDOW = 86400;

    /**
     * @param callable(): string $prove
     */
    public static function admit(string $address, string $email, int $now, callable $prove): string
    {
        if (self::atCap(self::hitFile('ip:' . $address), $now, self::CONNECTION_WINDOW, self::CONNECTION_LIMIT)) {
            return 'rate_ip';
        }
        if ($email !== '' && self::atCap(self::hitFile('email:' . $email), $now, self::EMAIL_WINDOW, self::EMAIL_LIMIT)) {
            return 'rate_email';
        }

        $proof = $prove();
        if ($proof !== 'ok') {
            return $proof;
        }

        return self::record($address, $email, $now);
    }

    private static function record(string $address, string $email, int $now): string
    {
        $connection = self::lock(self::hitFile('ip:' . $address));
        if ($connection === null) {
            return 'rate_lock';
        }
        $emailHandle = null;
        try {
            $connectionHits = self::inWindow(self::readLocked($connection), $now, self::CONNECTION_WINDOW);
            if (count($connectionHits) >= self::CONNECTION_LIMIT) {
                return 'rate_ip';
            }
            $emailHits = [];
            if ($email !== '') {
                $emailHandle = self::lock(self::hitFile('email:' . $email));
                if ($emailHandle === null) {
                    return 'rate_lock';
                }
                $emailHits = self::inWindow(self::readLocked($emailHandle), $now, self::EMAIL_WINDOW);
                if (count($emailHits) >= self::EMAIL_LIMIT) {
                    return 'rate_email';
                }
            }
            // Email first. If the connection write then fails, the email hit stays.
            if ($emailHandle !== null) {
                $emailHits[] = $now;
                if (!self::writeLocked($emailHandle, $emailHits)) {
                    return 'rate_lock';
                }
            }
            $connectionHits[] = $now;
            if (!self::writeLocked($connection, $connectionHits)) {
                return 'rate_lock';
            }
            return 'ok';
        } catch (Throwable $e) {
            error_log('inkvoke try: rate admit error: ' . $e->getMessage());
            return 'rate_lock';
        } finally {
            self::unlock($emailHandle);
            self::unlock($connection);
        }
    }

    private static function atCap(string $file, int $now, int $window, int $limit): bool
    {
        return count(self::inWindow(self::readUnlocked($file), $now, $window)) >= $limit;
    }

    private static function hitFile(string $key): string
    {
        return sys_get_temp_dir() . '/sp_gptimage_rl_' . md5($key) . '.json';
    }

    /**
     * A hit at exactly $now - $window is outside.
     *
     * @param list<mixed> $hits
     * @return list<int>
     */
    private static function inWindow(array $hits, int $now, int $window): array
    {
        $kept = [];
        foreach ($hits as $hit) {
            if (is_int($hit) && $hit > $now - $window) {
                $kept[] = $hit;
            }
        }
        return $kept;
    }

    /** @return list<mixed> */
    private static function readUnlocked(string $file): array
    {
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /** @return resource|null */
    private static function lock(string $file)
    {
        $fp = @fopen($file, 'c+');
        if ($fp === false) {
            return null;
        }
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
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
     * @param resource $fp
     * @return list<mixed>
     */
    private static function readLocked($fp): array
    {
        rewind($fp);
        $raw = stream_get_contents($fp);
        if ($raw === false) {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param resource $fp
     * @param list<int> $hits
     */
    private static function writeLocked($fp, array $hits): bool
    {
        $json = json_encode(array_values($hits));
        if ($json === false) {
            return false;
        }
        $payload = $json . "\n";
        if (ftruncate($fp, 0) === false) {
            return false;
        }
        rewind($fp);
        $written = fwrite($fp, $payload);
        if ($written !== strlen($payload)) {
            return false;
        }
        return fflush($fp);
    }
}
