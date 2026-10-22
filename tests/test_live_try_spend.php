<?php
/**
 * Live-try spend. One public tally and one durable copy, updated when a
 * live try's image is accepted.
 *
 * CLI only. The deployed tree is the site document root, and this file
 * must not load the spend module or record a tally for a web request.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit;
}

if (($argv[1] ?? '') === 'record') {
    require dirname(__DIR__) . '/inkvoke/live_try_spend.php';
    $cost = $argv[2] === 'null' ? null : (float) $argv[2];
    $result = LiveTrySpend::record($cost, (int) $argv[3], $argv[4], $argv[5], $argv[6]);
    fwrite(STDOUT, json_encode($result));
    exit;
}

require dirname(__DIR__) . '/inkvoke/live_try_spend.php';

$failures = [];
$tracked = [];
$gallerySpend = dirname(__DIR__) . '/inkvoke/gallery/spend.json';
$galleryBefore = is_file($gallerySpend) ? (string) file_get_contents($gallerySpend) : null;

function expect(bool $ok, string $message): void
{
    global $failures;
    if (!$ok) {
        $failures[] = $message;
        fwrite(STDERR, "FAIL {$message}\n");
    }
}

/** @return array{public: string, durable: string, trials: string} */
function spend_paths(): array
{
    global $tracked;
    $root = sys_get_temp_dir() . '/sp_live_try_spend_' . bin2hex(random_bytes(8));
    $tracked[] = $root;
    return [
        'public' => $root . '/gallery/spend.json',
        'durable' => $root . '/lib/spend.json',
        'trials' => $root . '/lib/trials.jsonl',
    ];
}

function write_file(string $path, string $bytes): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    file_put_contents($path, $bytes);
}

function file_bytes(string $path): string
{
    if (!is_file($path)) {
        return '';
    }
    return (string) file_get_contents($path);
}

function mode_of(string $path): int
{
    clearstatcache(true, $path);
    return fileperms($path) & 0777;
}

function tally_bytes(float $total, int $count, int $updated): string
{
    $json = json_encode([
        'total_usd' => $total,
        'image_count' => $count,
        'updated_at' => $updated,
        'currency' => 'USD',
        'note' => 'Estimated live-try OpenAI image spend (gpt-image-2 rates). Running tally for public gallery items; hidden images are excluded (dashboard hide subtracts their cost).',
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    return ($json === false ? '' : $json) . "\n";
}

function expect_tally(array $got, float $total, int $count, int $updated, string $label): void
{
    expect(
        $got === ['total_usd' => $total, 'image_count' => $count, 'updated_at' => $updated],
        "{$label} should return {$count} images and {$total} at {$updated}, got " . json_encode($got)
    );
}

function expect_copies(string $public, string $durable, float $total, int $count, int $updated, string $label): void
{
    $bytes = tally_bytes($total, $count, $updated);
    expect(file_bytes($public) === $bytes, "{$label} public tally bytes mismatch: " . file_bytes($public));
    expect(file_bytes($durable) === $bytes, "{$label} durable tally bytes mismatch: " . file_bytes($durable));
    expect(file_bytes($public) === file_bytes($durable), "{$label} copies should match");
    expect(mode_of($public) === 0664, "{$label} public mode should be 0664, got " . decoct(mode_of($public)));
    expect(mode_of($durable) === 0664, "{$label} durable mode should be 0664, got " . decoct(mode_of($durable)));
}

/**
 * @param list<string> $lines
 */
function write_trials(string $path, array $lines): void
{
    write_file($path, implode("\n", $lines) . "\n");
}

function remove_tree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @chmod($path, 0664);
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    $items = scandir($path);
    if ($items === false) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        remove_tree($path . '/' . $item);
    }
    @rmdir($path);
}

register_shutdown_function(static function (): void {
    global $tracked;
    foreach ($tracked as $path) {
        if (is_string($path)) {
            remove_tree($path);
        }
    }
});

/**
 * Hold the public tally's exclusive lock, run $whileLocked while a second
 * process is blocked inside record, then release. Returns that process's stdout.
 *
 * @param callable(resource): void $whileLocked
 */
function record_while_locked(string $public, string $durable, string $trials, ?float $cost, int $now, callable $whileLocked): string
{
    $fp = fopen($public, 'c+');
    if ($fp === false || !flock($fp, LOCK_EX)) {
        expect(false, 'test could not lock the public tally');
        if (is_resource($fp)) {
            fclose($fp);
        }
        return '';
    }
    $costArg = $cost === null ? 'null' : (string) $cost;
    $proc = proc_open(
        ['php', realpath(__FILE__), 'record', $costArg, (string) $now, $public, $durable, $trials],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        expect(false, 'could not start the blocked record');
        return '';
    }
    $status = proc_get_status($proc);
    $pid = (int) $status['pid'];
    $blocked = false;
    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $wchan = trim((string) @file_get_contents("/proc/{$pid}/wchan"));
        if (str_contains($wchan, 'locks_lock_inode_wait')) {
            $blocked = true;
            break;
        }
        $status = proc_get_status($proc);
        if (!$status['running']) {
            break;
        }
        usleep(20000);
    }
    expect($blocked, "record should wait on the public tally lock (pid {$pid})");
    $whileLocked($fp);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    proc_close($proc);
    if ($stderr !== '') {
        fwrite(STDERR, $stderr);
    }
    return $stdout;
}

function test_first_record_writes_one_image_to_both_copies(): void
{
    $paths = spend_paths();
    $now = 1700000000;
    $got = LiveTrySpend::record(0.25, $now, $paths['public'], $paths['durable'], $paths['trials']);
    expect_tally($got, 0.25, 1, $now, 'a first record');
    expect_copies($paths['public'], $paths['durable'], 0.25, 1, $now, 'a first record');
    expect(!is_file($paths['trials']), 'a first record with no trial log should not create one');
    expect(is_dir(dirname($paths['public'])), 'a first record should create a missing public directory');
    expect(is_dir(dirname($paths['durable'])), 'a first record should create a missing durable directory');
}

function test_second_record_adds_one_image_and_its_cost(): void
{
    $paths = spend_paths();
    $first = 1700000000;
    $second = 1700000005;
    LiveTrySpend::record(0.25, $first, $paths['public'], $paths['durable'], $paths['trials']);
    $got = LiveTrySpend::record(0.5, $second, $paths['public'], $paths['durable'], $paths['trials']);
    expect_tally($got, 0.75, 2, $second, 'a second record');
    expect_copies($paths['public'], $paths['durable'], 0.75, 2, $second, 'a second record');
}

function test_null_cost_counts_one_image_and_adds_no_dollars(): void
{
    $paths = spend_paths();
    $now = 1700000000;
    $got = LiveTrySpend::record(null, $now, $paths['public'], $paths['durable'], $paths['trials']);
    expect_tally($got, 0.0, 1, $now, 'a null cost');
    expect_copies($paths['public'], $paths['durable'], 0.0, 1, $now, 'a null cost');
    $again = LiveTrySpend::record(null, $now + 5, $paths['public'], $paths['durable'], $paths['trials']);
    expect_tally($again, 0.0, 2, $now + 5, 'a second null cost');
}

function test_zero_negative_and_non_finite_costs_add_no_dollars(): void
{
    $now = 1700000000;
    $cases = [
        'zero' => 0.0,
        'negative' => -3.5,
        'infinity' => INF,
        'not a number' => NAN,
    ];
    foreach ($cases as $label => $cost) {
        $paths = spend_paths();
        write_file($paths['public'], '{"total_usd":0.5,"image_count":2,"updated_at":4}');
        $got = LiveTrySpend::record($cost, $now, $paths['public'], $paths['durable'], $paths['trials']);
        expect_tally($got, 0.5, 3, $now, "a {$label} cost");
        expect_copies($paths['public'], $paths['durable'], 0.5, 3, $now, "a {$label} cost");
    }
}

function test_total_is_rounded_to_six_decimals_and_never_negative(): void
{
    $paths = spend_paths();
    $now = 1700000000;
    $rounded = LiveTrySpend::record(1.2345674, $now, $paths['public'], $paths['durable'], $paths['trials']);
    expect_tally($rounded, 1.234567, 1, $now, 'a cost past six decimals');
    expect_copies($paths['public'], $paths['durable'], 1.234567, 1, $now, 'a cost past six decimals');

    $negative = spend_paths();
    write_file($negative['public'], '{"total_usd":-1.5,"image_count":4,"updated_at":1}');
    $clamped = LiveTrySpend::record(null, $now, $negative['public'], $negative['durable'], $negative['trials']);
    expect_tally($clamped, 0.0, 5, $now, 'a negative tally');
    expect_copies($negative['public'], $negative['durable'], 0.0, 5, $now, 'a negative tally');

    $partial = spend_paths();
    write_file($partial['public'], '{"total_usd":-0.25,"image_count":1,"updated_at":1}');
    $added = LiveTrySpend::record(0.5, $now, $partial['public'], $partial['durable'], $partial['trials']);
    expect_tally($added, 0.25, 2, $now, 'a cost that only partly covers a negative tally');
    expect_copies($partial['public'], $partial['durable'], 0.25, 2, $now, 'a cost that only partly covers a negative tally');

    $below = spend_paths();
    write_file($below['public'], '{"total_usd":0.25,"image_count":-2,"updated_at":1}');
    $grown = LiveTrySpend::record(null, $now, $below['public'], $below['durable'], $below['trials']);
    expect_tally($grown, 0.25, -1, $now, 'a negative image count');
    expect_copies($below['public'], $below['durable'], 0.25, -1, $now, 'a negative image count');
}

function test_public_zero_object_is_not_refilled_from_trials_or_durable(): void
{
    $paths = spend_paths();
    $now = 1700000000;
    write_file($paths['public'], '{"total_usd":0,"image_count":0,"updated_at":10}');
    write_file($paths['durable'], '{"total_usd":8,"image_count":7,"updated_at":11}');
    write_trials($paths['trials'], ['{"event":"try","ok":true,"cost_usd":2.5}']);
    $trialsBefore = file_bytes($paths['trials']);
    $got = LiveTrySpend::record(0.5, $now, $paths['public'], $paths['durable'], $paths['trials']);
    expect_tally($got, 0.5, 1, $now, 'a public zero object');
    expect_copies($paths['public'], $paths['durable'], 0.5, 1, $now, 'a public zero object');
    expect(file_bytes($paths['trials']) === $trialsBefore, 'a public zero object should leave the trial log unread as bytes');
}

function test_json_list_is_a_tally_and_its_missing_keys_are_zero(): void
{
    $paths = spend_paths();
    $now = 1700000000;
    write_file($paths['public'], "[]\n");
    write_file($paths['durable'], '{"total_usd":8,"image_count":7,"updated_at":11}');
    write_trials($paths['trials'], ['{"event":"try","ok":true,"cost_usd":2.5}']);
    $got = LiveTrySpend::record(0.5, $now, $paths['public'], $paths['durable'], $paths['trials']);
    expect_tally($got, 0.5, 1, $now, 'a public JSON list');
    expect_copies($paths['public'], $paths['durable'], 0.5, 1, $now, 'a public JSON list');
}

function test_corrupt_public_file_uses_a_positive_durable_copy(): void
{
    $paths = spend_paths();
    $now = 1700000000;
    write_file($paths['public'], "not-json\n");
    write_file($paths['durable'], '{"total_usd":1.25,"image_count":3,"updated_at":99}');
    write_trials($paths['trials'], ['{"event":"try","ok":true,"cost_usd":9}']);
    $trialsBefore = file_bytes($paths['trials']);
    $got = LiveTrySpend::record(0.25, $now, $paths['public'], $paths['durable'], $paths['trials']);
    expect_tally($got, 1.5, 4, $now, 'a positive durable copy');
    expect_copies($paths['public'], $paths['durable'], 1.5, 4, $now, 'a positive durable copy');
    expect(file_bytes($paths['trials']) === $trialsBefore, 'a positive durable copy should leave the trial log unchanged');

    $countOnly = spend_paths();
    write_file($countOnly['public'], 'not-json');
    write_file($countOnly['durable'], '{"total_usd":0,"image_count":2,"updated_at":4}');
    write_trials($countOnly['trials'], ['{"event":"try","ok":true,"cost_usd":9}']);
    $countGot = LiveTrySpend::record(0.25, $now, $countOnly['public'], $countOnly['durable'], $countOnly['trials']);
    expect_tally($countGot, 0.25, 3, $now, 'a durable copy with only a positive image count');
    expect_copies($countOnly['public'], $countOnly['durable'], 0.25, 3, $now, 'a durable copy with only a positive image count');
}

function test_corrupt_public_file_plus_trials_records_the_trial_sum_plus_this_try(): void
{
    $paths = spend_paths();
    $now = 1700000000;
    write_file($paths['public'], "not-json\n");
    write_file($paths['durable'], '{"total_usd":0,"image_count":0,"updated_at":5}');
    // Counted: 0.25, 0.5, 0.25, and 0. The other lines are not a successful try with a finite numeric cost.
    $lines = [
        '{"event":"try","ok":true,"cost_usd":0.25}',
        '{"event":"try","ok":true,"cost_usd":0.5}',
        '{"event":"try","ok":1,"cost_usd":"0.25"}',
        '{"event":"try","ok":true,"cost_usd":0}',
        '{"event":"try","ok":true,"cost_usd":1e309}',
        '',
        '{"event":"try","ok":false,"cost_usd":9}',
        '{"event":"gallery","ok":true,"cost_usd":9}',
        '{"event":"try","ok":true,"cost_usd":"n/a"}',
        '{"event":"try","ok":true}',
        '{"event":"try","cost_usd":4}',
        'not json',
    ];
    write_trials($paths['trials'], $lines);
    $trialsBefore = file_bytes($paths['trials']);
    $got = LiveTrySpend::record(0.5, $now, $paths['public'], $paths['durable'], $paths['trials']);
    expect_tally($got, 1.5, 5, $now, 'a trial backfill');
    expect_copies($paths['public'], $paths['durable'], 1.5, 5, $now, 'a trial backfill');
    expect(file_bytes($paths['trials']) === $trialsBefore, 'a trial backfill should not append to the trial log');

    $again = LiveTrySpend::record(0.5, $now + 6, $paths['public'], $paths['durable'], $paths['trials']);
    expect_tally($again, 2.0, 6, $now + 6, 'the record after a backfill');
    expect_copies($paths['public'], $paths['durable'], 2.0, 6, $now + 6, 'the record after a backfill');
}

function test_stale_durable_copy_is_rewritten_from_the_public_file(): void
{
    $paths = spend_paths();
    $now = 1700000050;
    write_file($paths['public'], '{"total_usd":1,"image_count":2,"updated_at":10}');
    write_file($paths['durable'], '{"total_usd":0.25,"image_count":1,"updated_at":3}');
    write_trials($paths['trials'], ['{"event":"try","ok":true,"cost_usd":9}']);
    $got = LiveTrySpend::record(0.25, $now, $paths['public'], $paths['durable'], $paths['trials']);
    expect_tally($got, 1.25, 3, $now, 'a stale durable copy');
    expect_copies($paths['public'], $paths['durable'], 1.25, 3, $now, 'a stale durable copy');
}

function test_unlockable_public_tally_returns_zeros_and_writes_nothing(): void
{
    $paths = spend_paths();
    $publicBytes = "{\"total_usd\":1.25,\"image_count\":2,\"updated_at\":8}\n";
    $durableBytes = "{\"total_usd\":5,\"image_count\":5,\"updated_at\":9}\n";
    write_file($paths['public'], $publicBytes);
    write_file($paths['durable'], $durableBytes);
    chmod($paths['public'], 0444);
    $got = LiveTrySpend::record(0.25, 1700000000, $paths['public'], $paths['durable'], $paths['trials']);
    expect_tally($got, 0.0, 0, 0, 'an unlockable public tally');
    expect(file_bytes($paths['public']) === $publicBytes, 'an unlockable public tally should keep its bytes');
    expect(file_bytes($paths['durable']) === $durableBytes, 'an unlockable public tally should not rewrite the durable copy');
    chmod($paths['public'], 0664);
}

function test_missing_public_file_that_cannot_be_opened_returns_the_durable_copy(): void
{
    $paths = spend_paths();
    $root = dirname($paths['public'], 2);
    $parent = $root . '/blocked';
    write_file($parent, 'not-a-directory');
    $public = $parent . '/spend.json';
    $durableBytes = '{"total_usd":1.25,"image_count":3,"updated_at":42,"currency":"USD","note":"keep"}';
    write_file($paths['durable'], $durableBytes);
    write_trials($paths['trials'], ['{"event":"try","ok":true,"cost_usd":9}']);
    $got = LiveTrySpend::record(0.25, 1700000000, $public, $paths['durable'], $paths['trials']);
    expect_tally($got, 1.25, 3, 42, 'a public path that cannot be opened');
    expect(file_bytes($paths['durable']) === $durableBytes, 'a public path that cannot be opened should leave the durable copy unchanged');
    expect(file_bytes($parent) === 'not-a-directory', 'a blocked parent should stay a file');
    expect(!is_file($public), 'a blocked parent should not gain a public tally');

    $empty = spend_paths();
    $emptyRoot = dirname($empty['public'], 2);
    $emptyParent = $emptyRoot . '/blocked';
    write_file($emptyParent, 'not-a-directory');
    write_file($empty['durable'], 'not-json');
    $durableBefore = file_bytes($empty['durable']);
    $none = LiveTrySpend::record(0.25, 1700000000, $emptyParent . '/spend.json', $empty['durable'], $empty['trials']);
    expect_tally($none, 0.0, 0, 0, 'no public file and no durable array');
    expect(file_bytes($empty['durable']) === $durableBefore, 'no durable array should stay unwritten');
}

function test_durable_write_failure_returns_the_new_tally_and_leaves_the_public_file(): void
{
    $paths = spend_paths();
    $now = 1700000000;
    $root = dirname($paths['public'], 2);
    $durable = $root . '/durable-dir';
    mkdir($durable, 0775, true);
    $got = LiveTrySpend::record(0.25, $now, $paths['public'], $durable, $paths['trials']);
    expect_tally($got, 0.25, 1, $now, 'a durable path that is a directory');
    expect(file_bytes($paths['public']) === tally_bytes(0.25, 1, $now), 'the public tally should still be written');
    expect(is_dir($durable), 'a durable directory should not be replaced');
    expect(mode_of($paths['public']) === 0664, 'the public tally mode should be 0664');
}

function test_record_reads_the_public_file_under_the_lock(): void
{
    $paths = spend_paths();
    write_file($paths['public'], '{"total_usd":0.25,"image_count":1,"updated_at":1}');
    write_file($paths['durable'], '{"total_usd":9,"image_count":9,"updated_at":1}');
    write_trials($paths['trials'], ['{"event":"try","ok":true,"cost_usd":9}']);
    $trialsBefore = file_bytes($paths['trials']);
    $now = 1700000000;
    $stdout = record_while_locked(
        $paths['public'],
        $paths['durable'],
        $paths['trials'],
        0.25,
        $now,
        static function ($fp): void {
            $payload = '{"total_usd":1,"image_count":4,"updated_at":9}';
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $payload);
        }
    );
    $decoded = json_decode($stdout, true);
    expect(
        $decoded === ['total_usd' => 1.25, 'image_count' => 5, 'updated_at' => $now],
        'the waiter should add to the tally written under the lock, got ' . $stdout
    );
    expect_copies($paths['public'], $paths['durable'], 1.25, 5, $now, 'the locked record');
    expect(file_bytes($paths['trials']) === $trialsBefore, 'the locked record should not change the trial log');
}

function test_spend_module_does_not_call_time(): void
{
    $module = (string) file_get_contents(dirname(__DIR__) . '/inkvoke/live_try_spend.php');
    expect(preg_match('/\btime\s*\(/', $module) !== 1, 'live-try spend must not call time()');
}

function test_request_records_spend_once_after_the_image_is_accepted(): void
{
    $source = (string) file_get_contents(dirname(__DIR__) . '/inkvoke/try-submit.php');
    expect(str_contains($source, "require_once __DIR__ . '/live_try_spend.php';"), 'try-submit.php should load live-try spend');
    expect(substr_count($source, 'LiveTrySpend::record(') === 1, 'the request should call live-try spend once');
    foreach ([
        'function read_spend',
        'function write_spend',
        'function record_spend',
        'function maybe_backfill_spend_from_trials',
        'read_spend(',
        'write_spend(',
        'record_spend(',
        'maybe_backfill_spend_from_trials(',
    ] as $needle) {
        expect(strpos($source, $needle) === false, "try-submit.php should not keep {$needle}");
    }

    $entryAt = strpos($source, '// --- Entry');
    expect($entryAt !== false, 'try-submit.php should mark its request entry');
    $body = substr($source, (int) $entryAt);
    $payload = strpos($body, 'bad_image_payload');
    $record = strpos($body, 'LiveTrySpend::record(');
    $success = strpos($body, "'model' => 'gpt-image-2'");
    $gallery = strpos($body, 'save_to_gallery(');
    $notify = strpos($body, 'notify_admin_new_image(');
    $openaiFail = strpos($body, 'openai_fail');
    $newsletter = strpos($body, 'newsletter_add(');
    expect(
        $payload !== false && $record !== false && $success !== false && $gallery !== false && $notify !== false,
        'the request entry should reject a bad image, record spend, log the try, save the gallery, and mail'
    );
    expect($openaiFail !== false && $openaiFail < $payload, 'a generation with no image should still be logged before the payload check');
    expect($newsletter !== false && $newsletter < $record, 'newsletter signup should stay before spend');
    expect($payload < $record, 'spend should be recorded only after the image bytes are accepted');
    expect($record < $success, 'the success line should be appended only after record returns');
    expect($success < $gallery && $gallery < $notify, 'gallery save and admin mail should stay after spend');
    expect(substr_count($body, 'LiveTrySpend::record(') === 1, 'the request entry should call live-try spend once');

    $recordAt = (int) $entryAt + (int) $record;
    $end = strpos($source, ');', $recordAt);
    $call = substr($source, $recordAt, (int) $end - $recordAt);
    expect(str_contains($call, 'time()'), 'the request should read the clock at the record call');
    expect(strpos($call, '$now') === false, 'the request should not reuse the admission timestamp');
    expect(preg_match('/\bSPEND_PATH\b/', $call) === 1, 'the call should pass the public tally path');
    expect(str_contains($call, 'SPEND_PATH_LIB'), 'the call should pass the durable tally path');
    expect(str_contains($call, 'TRIALS_PATH'), 'the call should pass the trials log path');

    $contact = (string) file_get_contents(dirname(__DIR__) . '/contact-submit.php');
    expect(strpos($contact, 'LiveTrySpend') === false, 'contact-submit.php should not call live-try spend');
    expect(strpos($contact, 'live_try_spend') === false, 'contact-submit.php should not load the live-try spend module');
    expect(strpos($contact, 'record_spend') === false, 'contact-submit.php should not call the old spend helper');
}

$tests = [
    'test_first_record_writes_one_image_to_both_copies',
    'test_second_record_adds_one_image_and_its_cost',
    'test_null_cost_counts_one_image_and_adds_no_dollars',
    'test_zero_negative_and_non_finite_costs_add_no_dollars',
    'test_total_is_rounded_to_six_decimals_and_never_negative',
    'test_public_zero_object_is_not_refilled_from_trials_or_durable',
    'test_json_list_is_a_tally_and_its_missing_keys_are_zero',
    'test_corrupt_public_file_uses_a_positive_durable_copy',
    'test_corrupt_public_file_plus_trials_records_the_trial_sum_plus_this_try',
    'test_stale_durable_copy_is_rewritten_from_the_public_file',
    'test_unlockable_public_tally_returns_zeros_and_writes_nothing',
    'test_missing_public_file_that_cannot_be_opened_returns_the_durable_copy',
    'test_durable_write_failure_returns_the_new_tally_and_leaves_the_public_file',
    'test_record_reads_the_public_file_under_the_lock',
    'test_spend_module_does_not_call_time',
    'test_request_records_spend_once_after_the_image_is_accepted',
];

foreach ($tests as $name) {
    $before = count($failures);
    $name();
    if (count($failures) === $before) {
        fwrite(STDOUT, "PASS {$name}\n");
    }
}

$galleryAfter = is_file($gallerySpend) ? (string) file_get_contents($gallerySpend) : null;
if ($galleryAfter !== $galleryBefore) {
    fwrite(STDERR, "FAIL the suite must not write the gallery tally\n");
    $failures[] = 'the suite must not write the gallery tally';
}

exit($failures === [] ? 0 : 1);
