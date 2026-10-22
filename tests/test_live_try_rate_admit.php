<?php
/**
 * Live-try hit admission. Five tries an hour per connection, three a day
 * per email, recorded only by rate_admit().
 *
 * CLI only. The deployed tree is the site document root, and this file
 * must not include try-submit.php or record hits for a web request.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit;
}

if (($argv[1] ?? '') === 'admit') {
    require dirname(__DIR__) . '/inkvoke/try-submit.php';
    $emailKey = $argv[3] === '-' ? '' : $argv[3];
    fwrite(STDOUT, rate_admit($argv[2], $emailKey, (int) $argv[4]));
    exit;
}

require dirname(__DIR__) . '/inkvoke/try-submit.php';

$failures = [];
$tracked = [];

function key_for(string $prefix): string {
    global $tracked;
    $key = $prefix . bin2hex(random_bytes(8));
    [$file] = rate_hits($key, 3600);
    $tracked[] = $file;
    return $key;
}

register_shutdown_function(static function (): void {
    global $tracked;
    foreach ($tracked as $file) {
        if (is_string($file) && is_file($file)) {
            @unlink($file);
        }
    }
});

function expect(bool $ok, string $message): void {
    global $failures;
    if (!$ok) {
        $failures[] = $message;
        fwrite(STDERR, "FAIL {$message}\n");
    }
}

/**
 * Hold $file's exclusive lock, run $whileLocked while a second process is
 * blocked inside rate_admit, then release. Returns that process's decision.
 *
 * @param callable(resource): void $whileLocked
 */
function admit_while_locked(string $file, string $ipKey, string $emailKey, int $now, callable $whileLocked): string {
    $fp = fopen($file, 'c+');
    if ($fp === false || !flock($fp, LOCK_EX)) {
        expect(false, 'test could not lock the hit file');
        if (is_resource($fp)) {
            fclose($fp);
        }
        return 'lock-failed';
    }
    $emailArg = $emailKey === '' ? '-' : $emailKey;
    $proc = proc_open(
        ['php', realpath(__FILE__), 'admit', $ipKey, $emailArg, (string) $now],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($proc)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        expect(false, 'could not start the blocked admission');
        return 'spawn-failed';
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
    expect($blocked, "admission should wait on the hit-file lock (pid {$pid})");
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

function test_sixth_connection_try_within_the_hour_is_refused(): void {
    $ip = key_for('ip:hour-');
    $now = time();
    for ($n = 1; $n <= 5; $n++) {
        $decision = rate_admit($ip, '', $now);
        expect($decision === 'ok', "connection try {$n} should be admitted, got {$decision}");
    }
    $decision = rate_admit($ip, '', $now);
    expect($decision === 'rate_ip', "sixth connection try should be refused, got {$decision}");
    [, $hits] = rate_hits($ip, 3600);
    expect(
        $hits === array_fill(0, 5, $now),
        'the five admitted connection timestamps should remain, got ' . json_encode($hits)
    );
}

function test_fourth_email_try_within_the_day_is_refused(): void {
    $ip = key_for('ip:day-');
    $email = key_for('email:day-');
    $now = time();
    for ($n = 1; $n <= 3; $n++) {
        $decision = rate_admit($ip, $email, $now);
        expect($decision === 'ok', "email try {$n} should be admitted, got {$decision}");
    }
    $decision = rate_admit(key_for('ip:day-next-'), $email, $now);
    expect($decision === 'rate_email', "fourth email try should be refused, got {$decision}");
    [, $hits] = rate_hits($email, 86400);
    expect(
        $hits === array_fill(0, 3, $now),
        'the three admitted email timestamps should remain, got ' . json_encode($hits)
    );
}

function test_refused_email_does_not_consume_a_connection_try(): void {
    $email = key_for('email:noconnect-');
    $now = time();
    for ($n = 1; $n <= 3; $n++) {
        $decision = rate_admit(key_for('ip:noconnect-'), $email, $now);
        expect($decision === 'ok', "setup email try {$n} should be admitted, got {$decision}");
    }
    $ip = key_for('ip:noconnect-fresh-');
    $decision = rate_admit($ip, $email, $now);
    expect($decision === 'rate_email', "over-cap email should be refused, got {$decision}");
    [, $ipHits] = rate_hits($ip, 3600);
    expect($ipHits === [], 'a refused email try should not record a connection hit, got ' . json_encode($ipHits));
}

function test_connection_hits_older_than_an_hour_do_not_count(): void {
    $ip = key_for('ip:stale-hour-');
    $now = time();
    $then = $now - 3601;
    for ($n = 1; $n <= 5; $n++) {
        $decision = rate_admit($ip, '', $then);
        expect($decision === 'ok', "old connection try {$n} should be admitted, got {$decision}");
    }
    $decision = rate_admit($ip, '', $now);
    expect($decision === 'ok', "a hit from 3601 seconds ago should not count, got {$decision}");
    [, $hits] = rate_hits($ip, 3600);
    expect($hits === [$now], 'only the new connection hit should remain in the hour, got ' . json_encode($hits));
}

function test_connection_hits_inside_the_hour_count_toward_five(): void {
    $ip = key_for('ip:fresh-hour-');
    $now = time();
    $then = $now - 3599;
    for ($n = 1; $n <= 5; $n++) {
        $decision = rate_admit($ip, '', $then);
        expect($decision === 'ok', "in-hour connection try {$n} should be admitted, got {$decision}");
    }
    $decision = rate_admit($ip, '', $now);
    expect($decision === 'rate_ip', "five hits from 3599 seconds ago should fill the hour, got {$decision}");
    [, $hits] = rate_hits($ip, 3600);
    expect($hits === array_fill(0, 5, $then), 'the refused try should not be appended, got ' . json_encode($hits));
}

function test_email_hits_older_than_a_day_do_not_count(): void {
    $email = key_for('email:stale-day-');
    $now = time();
    $then = $now - 86401;
    for ($n = 1; $n <= 3; $n++) {
        $decision = rate_admit(key_for('ip:stale-day-'), $email, $then);
        expect($decision === 'ok', "old email try {$n} should be admitted, got {$decision}");
    }
    $decision = rate_admit(key_for('ip:stale-day-next-'), $email, $now);
    expect($decision === 'ok', "a hit from 86401 seconds ago should not count, got {$decision}");
    [, $hits] = rate_hits($email, 86400);
    expect($hits === [$now], 'only the new email hit should remain in the day, got ' . json_encode($hits));
}

function test_email_hits_inside_the_day_count_toward_three(): void {
    $email = key_for('email:fresh-day-');
    $now = time();
    $then = $now - 3601;
    for ($n = 1; $n <= 3; $n++) {
        $decision = rate_admit(key_for('ip:fresh-day-'), $email, $then);
        expect($decision === 'ok', "in-day email try {$n} should be admitted, got {$decision}");
    }
    $ip = key_for('ip:fresh-day-next-');
    $decision = rate_admit($ip, $email, $now);
    expect($decision === 'rate_email', "three hits from 3601 seconds ago should fill the day, got {$decision}");
    [, $ipHits] = rate_hits($ip, 3600);
    expect($ipHits === [], 'a day-capped email should not record the connection, got ' . json_encode($ipHits));
    [, $emailHits] = rate_hits($email, 86400);
    expect(
        $emailHits === array_fill(0, 3, $then),
        'the refused email try should not be appended, got ' . json_encode($emailHits)
    );
}

function test_one_connection_does_not_consume_another(): void {
    $now = time();
    $first = key_for('ip:separate-a-');
    $second = key_for('ip:separate-b-');
    for ($n = 1; $n <= 5; $n++) {
        expect(rate_admit($first, '', $now) === 'ok', "first connection try {$n} should be admitted");
    }
    expect(rate_admit($first, '', $now) === 'rate_ip', 'first connection should be full');
    expect(rate_admit($second, '', $now) === 'ok', 'a second connection should still have its own five');
}

function test_one_email_does_not_consume_another(): void {
    $now = time();
    $first = key_for('email:separate-a-');
    $second = key_for('email:separate-b-');
    for ($n = 1; $n <= 3; $n++) {
        expect(rate_admit(key_for('ip:separate-email-'), $first, $now) === 'ok', "first email try {$n} should be admitted");
    }
    expect(rate_admit(key_for('ip:separate-email-'), $first, $now) === 'rate_email', 'first email should be full');
    expect(rate_admit(key_for('ip:separate-email-'), $second, $now) === 'ok', 'a second email should still have its own three');
}

function test_missing_email_records_only_the_connection(): void {
    $ip = key_for('ip:noemail-');
    $email = key_for('email:noemail-');
    $now = time();
    expect(rate_admit($ip, '', $now) === 'ok', 'a try with no email should be admitted');
    [, $ipHits] = rate_hits($ip, 3600);
    [, $emailHits] = rate_hits($email, 86400);
    expect($ipHits === [$now], 'the connection hit should be the timestamp passed in, got ' . json_encode($ipHits));
    expect($emailHits === [], 'no email hit file should be written, got ' . json_encode($emailHits));
}

function test_try_is_counted_only_after_turnstile_and_before_generation(): void {
    $source = (string) file_get_contents(dirname(__DIR__) . '/inkvoke/try-submit.php');
    $entryAt = strpos($source, "// --- Entry");
    expect($entryAt !== false, 'try-submit.php should mark its request entry');
    $body = substr($source, (int) $entryAt);
    $turnstile = strpos($body, 'turnstile_ok(');
    $admit = strpos($body, 'rate_admit(');
    $newsletter = strpos($body, 'newsletter_add(');
    $generate = strpos($body, 'openai_generate(');
    expect(
        $turnstile !== false && $admit !== false && $newsletter !== false && $generate !== false,
        'the request entry should call turnstile, rate_admit, newsletter_add, and openai_generate'
    );
    expect($turnstile < $admit, 'a try should be counted only after Turnstile succeeds');
    expect($admit < $newsletter, 'the hit should be recorded before newsletter signup');
    expect($admit < $generate, 'the hit should be recorded before image generation');
    expect(strpos($body, 'rate_record(') === false, 'the request should not write a stale hit snapshot');
    $contact = (string) file_get_contents(dirname(__DIR__) . '/contact-submit.php');
    expect(strpos($contact, 'rate_admit(') === false, 'contact-submit.php should stay on its own rate limit');
    expect(strpos($contact, 'rate_lock(') === false, 'contact-submit.php should not take the live-try hit lock');
}

function test_admission_rereads_the_connection_file_under_the_lock(): void {
    $ip = key_for('ip:race-');
    $now = time();
    $marker = $now - 10;
    for ($n = 1; $n <= 4; $n++) {
        expect(rate_admit($ip, '', $now) === 'ok', "race setup try {$n} should be admitted");
    }
    [$file] = rate_hits($ip, 3600);
    $filled = [$now, $now, $now, $now, $marker];
    $decision = admit_while_locked($file, $ip, '', $now, static function ($fp) use ($filled): void {
        $payload = json_encode($filled);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $payload === false ? '[]' : $payload);
    });
    expect($decision === 'rate_ip', "a fifth hit written under the lock should refuse the waiter, got {$decision}");
    [, $hits] = rate_hits($ip, 3600);
    expect($hits === $filled, 'the waiter should keep the locked fifth hit, got ' . json_encode($hits));
}

function test_admission_rereads_the_email_file_under_the_lock(): void {
    $email = key_for('email:race-');
    $ip = key_for('ip:race-email-');
    $now = time();
    $marker = $now - 10;
    for ($n = 1; $n <= 2; $n++) {
        expect(rate_admit($ip, $email, $now) === 'ok', "email race setup try {$n} should be admitted");
    }
    [$emailFile] = rate_hits($email, 86400);
    $filled = [$now, $now, $marker];
    $decision = admit_while_locked($emailFile, $ip, $email, $now, static function ($fp) use ($ip, $now, $filled): void {
        [, $ipHits] = rate_hits($ip, 3600);
        expect($ipHits === [$now, $now], 'email lock wait should not have appended a connection hit, got ' . json_encode($ipHits));
        $payload = json_encode($filled);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $payload === false ? '[]' : $payload);
    });
    expect($decision === 'rate_email', "a third email hit written under the lock should refuse the waiter, got {$decision}");
    [, $ipHits] = rate_hits($ip, 3600);
    expect(count($ipHits) === 2, 'a refused email try should leave the connection at two, got ' . json_encode($ipHits));
    [, $emailHits] = rate_hits($email, 86400);
    expect($emailHits === $filled, 'the waiter should keep the locked third email hit, got ' . json_encode($emailHits));
}

$tests = [
    'test_sixth_connection_try_within_the_hour_is_refused',
    'test_fourth_email_try_within_the_day_is_refused',
    'test_refused_email_does_not_consume_a_connection_try',
    'test_connection_hits_older_than_an_hour_do_not_count',
    'test_connection_hits_inside_the_hour_count_toward_five',
    'test_email_hits_older_than_a_day_do_not_count',
    'test_email_hits_inside_the_day_count_toward_three',
    'test_one_connection_does_not_consume_another',
    'test_one_email_does_not_consume_another',
    'test_missing_email_records_only_the_connection',
    'test_try_is_counted_only_after_turnstile_and_before_generation',
    'test_admission_rereads_the_connection_file_under_the_lock',
    'test_admission_rereads_the_email_file_under_the_lock',
];

foreach ($tests as $name) {
    $before = count($failures);
    $name();
    if (count($failures) === $before) {
        fwrite(STDOUT, "PASS {$name}\n");
    }
}

exit($failures === [] ? 0 : 1);
