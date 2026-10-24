<?php
/**
 * Live-try address. The connection peer is the address unless that peer is
 * named on the trusted-proxy allowlist in try-submit.php.
 *
 * CLI only. The deployed tree is the site document root, and this file
 * must not include try-submit.php for a web request.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit;
}

require dirname(__DIR__) . '/inkvoke/try-submit.php';

$failures = [];

function expect(bool $ok, string $message): void {
    global $failures;
    if (!$ok) {
        $failures[] = $message;
        fwrite(STDERR, "FAIL {$message}\n");
    }
}

function test_private_or_reserved_hop_is_not_used(): void {
    $peer = '10.0.0.2';
    $_SERVER['REMOTE_ADDR'] = $peer;
    $allow = [$peer];
    $rejected = ['10.1.2.3', '192.168.1.9', '172.16.5.5', 'fd00::1', '127.0.0.1', '169.254.1.1', '::1', '0.0.0.0'];
    foreach ($rejected as $hop) {
        $_SERVER['HTTP_X_FORWARDED_FOR'] = $hop;
        $got = client_ip($allow);
        expect($got === $peer, "private or reserved hop {$hop} should not be used, got {$got}");
    }
    $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip';
    $got = client_ip($allow);
    expect($got === $peer, "a hop that is not an IP should not be used, got {$got}");
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';
    $got = client_ip($allow);
    expect($got === '8.8.8.8', "a public hop from an allowlisted peer should be the live-try address, got {$got}");
}

function test_missing_forwarded_header_yields_the_peer(): void {
    $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
    $got = client_ip();
    expect($got === '10.0.0.2', "a missing forwarded header should yield the unlisted peer, got {$got}");
    $got = client_ip(['10.0.0.2']);
    expect($got === '10.0.0.2', "a missing forwarded header should yield the allowlisted peer, got {$got}");
}

function test_unlisted_peer_is_kept_when_a_forwarded_header_is_present(): void {
    expect(
        defined('LIVE_TRY_TRUSTED_PROXIES') && LIVE_TRY_TRUSTED_PROXIES === [],
        'the trusted-proxy allowlist in try-submit.php should be empty'
    );
    $_SERVER['REMOTE_ADDR'] = '10.0.0.2';
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';
    $got = client_ip();
    expect(
        $got === '10.0.0.2',
        "an unlisted peer should be kept when a forwarded header is present, got {$got}"
    );
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    $got = client_ip();
    expect(
        $got === '127.0.0.1',
        "a reserved unlisted peer should be kept, not replaced or dropped, got {$got}"
    );
}

$tests = [
    'test_unlisted_peer_is_kept_when_a_forwarded_header_is_present',
    'test_private_or_reserved_hop_is_not_used',
    'test_missing_forwarded_header_yields_the_peer',
];

foreach ($tests as $name) {
    $before = count($failures);
    $name();
    if (count($failures) === $before) {
        fwrite(STDOUT, "PASS {$name}\n");
    }
}

exit($failures === [] ? 0 : 1);
