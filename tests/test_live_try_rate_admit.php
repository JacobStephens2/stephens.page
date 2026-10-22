<?php
/**
 * Live-try admission. Five tries an hour per connection, three a day
 * per email, recorded only after the proof returns exactly ok.
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
    require dirname(__DIR__) . '/inkvoke/live_try_admission.php';
    $email = $argv[3] === '-' ? '' : $argv[3];
    fwrite(STDOUT, LiveTryAdmission::admit(
        $argv[2],
        $email,
        (int) $argv[4],
        static function (): string {
            return 'ok';
        }
    ));
    exit;
}

require dirname(__DIR__) . '/inkvoke/live_try_admission.php';

$failures = [];
$tracked = [];

function connection_file(string $address): string {
    return sys_get_temp_dir() . '/sp_gptimage_rl_' . md5('ip:' . $address) . '.json';
}

function email_file(string $email): string {
    return sys_get_temp_dir() . '/sp_gptimage_rl_' . md5('email:' . $email) . '.json';
}

function fresh_address(string $label): string {
    global $tracked;
    $address = $label . bin2hex(random_bytes(8));
    $tracked[] = connection_file($address);
    return $address;
}

function fresh_email(string $label): string {
    global $tracked;
    $email = $label . bin2hex(random_bytes(8));
    $tracked[] = email_file($email);
    return $email;
}

function read_hits(string $path): array {
    if (!is_file($path)) {
        return [];
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function file_bytes(string $path): string {
    if (!is_file($path)) {
        return '';
    }
    return (string) file_get_contents($path);
}

/**
 * @param callable(): string|null $prove
 */
function admit(string $address, string $email, int $now, ?callable $prove = null): string {
    return LiveTryAdmission::admit(
        $address,
        $email,
        $now,
        $prove ?? static function (): string {
            return 'ok';
        }
    );
}

register_shutdown_function(static function (): void {
    global $tracked;
    foreach ($tracked as $file) {
        if (!is_string($file)) {
            continue;
        }
        if (is_file($file)) {
            @unlink($file);
        } elseif (is_dir($file)) {
            @rmdir($file);
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
 * blocked inside admission, then release. Returns that process's decision.
 *
 * @param callable(resource): void $whileLocked
 */
function admit_while_locked(string $file, string $address, string $email, int $now, callable $whileLocked): string {
    $fp = fopen($file, 'c+');
    if ($fp === false || !flock($fp, LOCK_EX)) {
        expect(false, 'test could not lock the hit file');
        if (is_resource($fp)) {
            fclose($fp);
        }
        return 'lock-failed';
    }
    $emailArg = $email === '' ? '-' : $email;
    $proc = proc_open(
        ['php', realpath(__FILE__), 'admit', $address, $emailArg, (string) $now],
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
    $address = fresh_address('hour-');
    $now = 1700000000;
    $calls = 0;
    for ($n = 1; $n <= 5; $n++) {
        $decision = admit($address, '', $now);
        expect($decision === 'ok', "connection try {$n} should be admitted, got {$decision}");
    }
    $decision = admit($address, '', $now, static function () use (&$calls): string {
        $calls++;
        return 'ok';
    });
    expect($decision === 'rate_ip', "sixth connection try should be refused, got {$decision}");
    expect($calls === 0, 'a full connection cap should not call the proof');
    expect(
        read_hits(connection_file($address)) === array_fill(0, 5, $now),
        'the five admitted connection timestamps should remain, got ' . json_encode(read_hits(connection_file($address)))
    );
}

function test_fourth_email_try_within_the_day_is_refused(): void {
    $email = fresh_email('day-');
    $now = 1700000000;
    $calls = 0;
    for ($n = 1; $n <= 3; $n++) {
        $decision = admit(fresh_address('day-'), $email, $now);
        expect($decision === 'ok', "email try {$n} should be admitted, got {$decision}");
    }
    $decision = admit(fresh_address('day-next-'), $email, $now, static function () use (&$calls): string {
        $calls++;
        return 'ok';
    });
    expect($decision === 'rate_email', "fourth email try should be refused, got {$decision}");
    expect($calls === 0, 'a full email cap should not call the proof');
    expect(
        read_hits(email_file($email)) === array_fill(0, 3, $now),
        'the three admitted email timestamps should remain, got ' . json_encode(read_hits(email_file($email)))
    );
}

function test_refused_email_does_not_write_either_file(): void {
    $email = fresh_email('noconnect-');
    $now = 1700000000;
    for ($n = 1; $n <= 3; $n++) {
        $decision = admit(fresh_address('noconnect-'), $email, $now);
        expect($decision === 'ok', "setup email try {$n} should be admitted, got {$decision}");
    }
    $before = file_bytes(email_file($email));
    $address = fresh_address('noconnect-fresh-');
    $decision = admit($address, $email, $now);
    expect($decision === 'rate_email', "over-cap email should be refused, got {$decision}");
    expect(!is_file(connection_file($address)), 'a refused email try should not create a connection file');
    expect(file_bytes(email_file($email)) === $before, 'a refused email try should not rewrite the email file');
}

function test_connection_hit_at_the_hour_edge_does_not_count(): void {
    $address = fresh_address('edge-hour-');
    $now = 1700000000;
    $edge = $now - 3600;
    $path = connection_file($address);
    file_put_contents($path, json_encode(array_fill(0, 5, $edge)) . "\n");
    $decision = admit($address, '', $now);
    expect($decision === 'ok', "a hit at exactly now - 3600 should not count, got {$decision}");
    expect(
        file_bytes($path) === json_encode([$now]) . "\n",
        'the hour-edge hits should be dropped on the recorded try, got ' . file_bytes($path)
    );
}

function test_connection_hits_inside_the_hour_count_toward_five(): void {
    $address = fresh_address('fresh-hour-');
    $now = 1700000000;
    $then = $now - 3599;
    for ($n = 1; $n <= 5; $n++) {
        $decision = admit($address, '', $then);
        expect($decision === 'ok', "in-hour connection try {$n} should be admitted, got {$decision}");
    }
    $before = file_bytes(connection_file($address));
    $decision = admit($address, '', $now);
    expect($decision === 'rate_ip', "five hits from 3599 seconds ago should fill the hour, got {$decision}");
    expect(file_bytes(connection_file($address)) === $before, 'the refused try should not rewrite the connection file');
}

function test_email_hit_at_the_day_edge_does_not_count(): void {
    $email = fresh_email('edge-day-');
    $address = fresh_address('edge-day-');
    $now = 1700000000;
    $edge = $now - 86400;
    $path = email_file($email);
    file_put_contents($path, json_encode(array_fill(0, 3, $edge)) . "\n");
    $decision = admit($address, $email, $now);
    expect($decision === 'ok', "a hit at exactly now - 86400 should not count, got {$decision}");
    expect(
        file_bytes($path) === json_encode([$now]) . "\n",
        'the day-edge hits should be dropped on the recorded try, got ' . file_bytes($path)
    );
    expect(
        file_bytes(connection_file($address)) === json_encode([$now]) . "\n",
        'the connection hit should be the same timestamp, got ' . file_bytes(connection_file($address))
    );
}

function test_email_hits_inside_the_day_count_toward_three(): void {
    $email = fresh_email('fresh-day-');
    $now = 1700000000;
    $then = $now - 86399;
    for ($n = 1; $n <= 3; $n++) {
        $decision = admit(fresh_address('fresh-day-'), $email, $then);
        expect($decision === 'ok', "in-day email try {$n} should be admitted, got {$decision}");
    }
    $before = file_bytes(email_file($email));
    $address = fresh_address('fresh-day-next-');
    $decision = admit($address, $email, $now);
    expect($decision === 'rate_email', "three hits from 86399 seconds ago should fill the day, got {$decision}");
    expect(!is_file(connection_file($address)), 'a day-capped email should not create the connection file');
    expect(file_bytes(email_file($email)) === $before, 'the refused email try should not rewrite the email file');
}

function test_one_connection_does_not_consume_another(): void {
    $now = 1700000000;
    $first = fresh_address('separate-a-');
    $second = fresh_address('separate-b-');
    for ($n = 1; $n <= 5; $n++) {
        expect(admit($first, '', $now) === 'ok', "first connection try {$n} should be admitted");
    }
    expect(admit($first, '', $now) === 'rate_ip', 'first connection should be full');
    expect(admit($second, '', $now) === 'ok', 'a second connection should still have its own five');
}

function test_one_email_does_not_consume_another(): void {
    $now = 1700000000;
    $first = fresh_email('separate-a-');
    $second = fresh_email('separate-b-');
    for ($n = 1; $n <= 3; $n++) {
        expect(admit(fresh_address('separate-email-'), $first, $now) === 'ok', "first email try {$n} should be admitted");
    }
    expect(admit(fresh_address('separate-email-'), $first, $now) === 'rate_email', 'first email should be full');
    expect(admit(fresh_address('separate-email-'), $second, $now) === 'ok', 'a second email should still have its own three');
}

function test_empty_email_writes_only_the_connection_file(): void {
    $address = fresh_address('noemail-');
    $now = 1700000000;
    $emptyEmail = email_file('');
    global $tracked;
    $tracked[] = $emptyEmail;
    expect(admit($address, '', $now) === 'ok', 'a try with no email should be admitted');
    expect(
        file_bytes(connection_file($address)) === json_encode([$now]) . "\n",
        'the connection hit should be the timestamp passed in, got ' . file_bytes(connection_file($address))
    );
    expect(!is_file($emptyEmail), 'an empty email should not create an email hit file');
}

function test_both_full_caps_refuse_the_connection_and_skip_the_proof(): void {
    $address = fresh_address('both-');
    $email = fresh_email('both-');
    $now = 1700000000;
    $connectionRaw = json_encode([$now, $now, $now, $now, $now, 'leave-connection']) . "\n";
    $emailRaw = json_encode([$now, $now, $now, 'leave-email']) . "\n";
    file_put_contents(connection_file($address), $connectionRaw);
    file_put_contents(email_file($email), $emailRaw);
    $calls = 0;
    $decision = admit($address, $email, $now, static function () use (&$calls): string {
        $calls++;
        return 'ok';
    });
    expect($decision === 'rate_ip', "both caps full should refuse the connection first, got {$decision}");
    expect($calls === 0, 'a full connection cap should skip the proof even when the email is also full');
    expect(file_bytes(connection_file($address)) === $connectionRaw, 'a connection refusal should not rewrite the connection file');
    expect(file_bytes(email_file($email)) === $emailRaw, 'a connection refusal should not rewrite the email file');
}

function test_proof_other_than_ok_writes_nothing(): void {
    $address = fresh_address('proof-fail-');
    $email = fresh_email('proof-fail-');
    $now = 1700000000;
    $calls = 0;
    $decision = admit($address, $email, $now, static function () use (&$calls): string {
        $calls++;
        return 'turnstile_fail';
    });
    expect($decision === 'turnstile_fail', "a non-ok proof should be returned unchanged, got {$decision}");
    expect($calls === 1, 'the proof should run once when both caps have room');
    expect(!is_file(connection_file($address)), 'a failed proof should not create a connection file');
    expect(!is_file(email_file($email)), 'a failed proof should not create an email file');
}

function test_thrown_proof_propagates_and_writes_nothing(): void {
    $address = fresh_address('proof-throw-');
    $email = fresh_email('proof-throw-');
    $now = 1700000000;
    $threw = false;
    try {
        admit($address, $email, $now, static function (): string {
            throw new RuntimeException('turnstile down');
        });
    } catch (RuntimeException $e) {
        $threw = $e->getMessage() === 'turnstile down';
    }
    expect($threw, 'a throw from the proof should propagate');
    expect(!is_file(connection_file($address)), 'a thrown proof should not create a connection file');
    expect(!is_file(email_file($email)), 'a thrown proof should not create an email file');
}

function test_success_rewrites_each_file_to_the_in_window_hits_plus_now(): void {
    $address = fresh_address('compact-');
    $email = fresh_email('compact-');
    $now = 1700000000;
    file_put_contents(
        connection_file($address),
        json_encode([$now - 3601, 'nope', 1.5, $now - 10]) . "\n"
    );
    file_put_contents(
        email_file($email),
        json_encode([$now - 86401, 'nope', $now - 20]) . "\n"
    );
    $calls = 0;
    $decision = admit($address, $email, $now, static function () use (&$calls): string {
        $calls++;
        return 'ok';
    });
    expect($decision === 'ok', "a try under both caps should be admitted, got {$decision}");
    expect($calls === 1, 'a successful try should call the proof once');
    expect(
        file_bytes(connection_file($address)) === json_encode([$now - 10, $now]) . "\n",
        'the connection file should keep in-window integers plus this timestamp, got ' . file_bytes(connection_file($address))
    );
    expect(
        file_bytes(email_file($email)) === json_encode([$now - 20, $now]) . "\n",
        'the email file should keep in-window integers plus this timestamp, got ' . file_bytes(email_file($email))
    );
}

function test_refusal_does_not_rewrite_the_file(): void {
    $address = fresh_address('refuse-bytes-');
    $now = 1700000000;
    $path = connection_file($address);
    $raw = "[$now,$now,$now,$now,$now,\"keep-me\",1]\n  ";
    file_put_contents($path, $raw);
    $decision = admit($address, '', $now);
    expect($decision === 'rate_ip', "five in-window hits should refuse, got {$decision}");
    expect(file_bytes($path) === $raw, 'a refusal should leave the hit file bytes alone');
}

function test_corrupt_or_missing_file_counts_as_no_hits(): void {
    $address = fresh_address('corrupt-');
    $now = 1700000000;
    $path = connection_file($address);
    file_put_contents($path, "not-json\n");
    $decision = admit($address, '', $now);
    expect($decision === 'ok', "a corrupt hit file should count as no hits, got {$decision}");
    expect(
        file_bytes($path) === json_encode([$now]) . "\n",
        'the recorded try should replace the corrupt file, got ' . file_bytes($path)
    );

    $missing = fresh_address('missing-');
    expect(admit($missing, '', $now) === 'ok', 'a missing hit file should count as no hits');
    expect(is_file(connection_file($missing)), 'a recorded try should create the connection file');
}

function test_existing_hit_file_still_counts(): void {
    $address = fresh_address('existing-');
    $now = 1700000000;
    $path = connection_file($address);
    file_put_contents($path, json_encode([$now, $now, $now, $now]) . "\n");
    expect(admit($address, '', $now) === 'ok', 'four existing hits should leave room for one more');
    expect(
        file_bytes($path) === json_encode([$now, $now, $now, $now, $now]) . "\n",
        'the existing hits should still count, got ' . file_bytes($path)
    );
    expect(admit($address, '', $now) === 'rate_ip', 'the fifth existing hit should fill the hour');
}

function test_lock_failure_returns_rate_lock(): void {
    $address = fresh_address('lock-dir-');
    $email = fresh_email('lock-dir-');
    $now = 1700000000;
    $path = connection_file($address);
    mkdir($path, 0700);
    $calls = 0;
    $decision = admit($address, $email, $now, static function () use (&$calls): string {
        $calls++;
        return 'ok';
    });
    expect($decision === 'rate_lock', "an unlockable connection file should return rate_lock, got {$decision}");
    expect($decision !== 'ok', 'rate_lock is not success');
    expect($calls === 1, 'the unlocked read of a missing hit list still has room, so the proof runs');
    expect(!is_file(email_file($email)), 'a connection lock failure should not create an email file');
}

function test_email_lock_failure_leaves_the_connection_hit_unwritten(): void {
    $address = fresh_address('email-lock-');
    $email = fresh_email('email-lock-');
    $now = 1700000000;
    expect(admit($address, '', $now) === 'ok', 'setup connection try should be admitted');
    $before = file_bytes(connection_file($address));
    $emailPath = email_file($email);
    mkdir($emailPath, 0700);
    $decision = admit($address, $email, $now);
    expect($decision === 'rate_lock', "an unlockable email file should return rate_lock, got {$decision}");
    expect(file_bytes(connection_file($address)) === $before, 'an email lock failure should not append the connection hit');
}

function test_request_admits_before_newsletter_and_generation(): void {
    $source = (string) file_get_contents(dirname(__DIR__) . '/inkvoke/try-submit.php');
    $entryAt = strpos($source, '// --- Entry');
    expect($entryAt !== false, 'try-submit.php should mark its request entry');
    $body = substr($source, (int) $entryAt);
    $admit = strpos($body, 'LiveTryAdmission::admit(');
    $newsletter = strpos($body, 'newsletter_add(');
    $generate = strpos($body, 'openai_generate(');
    expect(
        $admit !== false && $newsletter !== false && $generate !== false,
        'the request entry should call admission, newsletter_add, and openai_generate'
    );
    expect(substr_count($body, 'LiveTryAdmission::admit(') === 1, 'the request should call admission once');
    expect($admit < $newsletter, 'admission should run before newsletter signup');
    expect($admit < $generate, 'admission should run before image generation');
    $contact = (string) file_get_contents(dirname(__DIR__) . '/contact-submit.php');
    expect(strpos($contact, 'LiveTryAdmission') === false, 'contact-submit.php should not call live-try admission');
    expect(strpos($contact, 'live_try_admission') === false, 'contact-submit.php should not load the live-try admission module');
    expect(strpos($contact, 'rate_lock(') === false, 'contact-submit.php should not take the live-try hit lock');
}

function test_admission_rereads_the_connection_file_under_the_lock(): void {
    $address = fresh_address('race-');
    $now = 1700000000;
    $marker = $now - 10;
    for ($n = 1; $n <= 4; $n++) {
        expect(admit($address, '', $now) === 'ok', "race setup try {$n} should be admitted");
    }
    $file = connection_file($address);
    $filled = [$now, $now, $now, $now, $marker];
    $decision = admit_while_locked($file, $address, '', $now, static function ($fp) use ($filled): void {
        $payload = json_encode($filled);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $payload === false ? '[]' : $payload);
    });
    expect($decision === 'rate_ip', "a fifth hit written under the lock should refuse the waiter, got {$decision}");
    expect(read_hits($file) === $filled, 'the waiter should keep the locked fifth hit, got ' . json_encode(read_hits($file)));
}

function test_admission_rereads_the_email_file_under_the_lock(): void {
    $email = fresh_email('race-');
    $address = fresh_address('race-email-');
    $now = 1700000000;
    $marker = $now - 10;
    for ($n = 1; $n <= 2; $n++) {
        expect(admit($address, $email, $now) === 'ok', "email race setup try {$n} should be admitted");
    }
    $emailPath = email_file($email);
    $filled = [$now, $now, $marker];
    $decision = admit_while_locked($emailPath, $address, $email, $now, static function ($fp) use ($address, $now, $filled): void {
        $ipHits = read_hits(connection_file($address));
        expect($ipHits === [$now, $now], 'email lock wait should not have appended a connection hit, got ' . json_encode($ipHits));
        $payload = json_encode($filled);
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, $payload === false ? '[]' : $payload);
    });
    expect($decision === 'rate_email', "a third email hit written under the lock should refuse the waiter, got {$decision}");
    $ipHits = read_hits(connection_file($address));
    expect(count($ipHits) === 2, 'a refused email try should leave the connection at two, got ' . json_encode($ipHits));
    expect(read_hits($emailPath) === $filled, 'the waiter should keep the locked third email hit, got ' . json_encode(read_hits($emailPath)));
}

$tests = [
    'test_sixth_connection_try_within_the_hour_is_refused',
    'test_fourth_email_try_within_the_day_is_refused',
    'test_refused_email_does_not_write_either_file',
    'test_connection_hit_at_the_hour_edge_does_not_count',
    'test_connection_hits_inside_the_hour_count_toward_five',
    'test_email_hit_at_the_day_edge_does_not_count',
    'test_email_hits_inside_the_day_count_toward_three',
    'test_one_connection_does_not_consume_another',
    'test_one_email_does_not_consume_another',
    'test_empty_email_writes_only_the_connection_file',
    'test_both_full_caps_refuse_the_connection_and_skip_the_proof',
    'test_proof_other_than_ok_writes_nothing',
    'test_thrown_proof_propagates_and_writes_nothing',
    'test_success_rewrites_each_file_to_the_in_window_hits_plus_now',
    'test_refusal_does_not_rewrite_the_file',
    'test_corrupt_or_missing_file_counts_as_no_hits',
    'test_existing_hit_file_still_counts',
    'test_lock_failure_returns_rate_lock',
    'test_email_lock_failure_leaves_the_connection_hit_unwritten',
    'test_request_admits_before_newsletter_and_generation',
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
