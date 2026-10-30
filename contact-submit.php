<?php
/**
 * Contact form handler for stephens.page/contact.html
 * Verifies a Cloudflare Turnstile token, then sends the message via the Resend
 * HTTP API. Secrets are read from the site environment file
 * /etc/stephens.page/site.env, outside the document root. Returns JSON.
 */

header('Content-Type: application/json');

function respond(bool $ok, string $message, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['ok' => $ok, 'message' => $message]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(false, 'Method not allowed.', 405);
}

// --- Load secrets from the site environment file (outside the document root)
$env = [];
$envFile = '/etc/stephens.page/site.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (strlen($v) >= 2
            && (($v[0] === '"' && substr($v, -1) === '"') || ($v[0] === "'" && substr($v, -1) === "'"))) {
            $v = substr($v, 1, -1);
        }
        $env[$k] = $v;
    }
}

$resendKey = $env['RESEND_API_KEY'] ?? '';
$fromEmail = $env['CONTACT_FROM_EMAIL'] ?? 'jacob@stephens.page';
$fromName  = $env['CONTACT_FROM_NAME'] ?? 'Stephens Page';
$toEmail   = $env['CONTACT_TO_EMAIL'] ?? 'jacob@stephens.page';
$tsSecret  = $env['TURNSTILE_SECRET'] ?? '';

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

// --- Honeypot: silently accept and drop bot submissions ---------------------
if (trim($_POST['website_url'] ?? '') !== '') {
    respond(true, 'Thanks! Your message has been sent.');
}

// --- Rate limit: 5 messages per hour per IP (file-based, in temp) ------------
$rlFile = sys_get_temp_dir() . '/sp_contact_rl_' . md5($ip) . '.json';
$now = time();
$hits = [];
if (is_readable($rlFile)) {
    $hits = json_decode((string) file_get_contents($rlFile), true) ?: [];
}
$hits = array_values(array_filter($hits, static fn($t) => is_int($t) && $t > $now - 3600));
if (count($hits) >= 5) {
    respond(false, 'Too many messages from this connection. Please wait a bit and try again.', 429);
}

// --- Validate fields --------------------------------------------------------
$name    = trim($_POST['name'] ?? '');
$email   = trim($_POST['email'] ?? '');
$message = trim($_POST['message'] ?? '');

if ($name === '' || $email === '' || $message === '') {
    respond(false, 'Please fill in your name, email, and message.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Please enter a valid email address.');
}
if (mb_strlen($message) > 5000) {
    respond(false, 'That message is a bit long - please keep it under 5000 characters.');
}

// --- Verify Cloudflare Turnstile --------------------------------------------
if ($tsSecret === '') {
    respond(false, 'The form is not fully configured yet. Please email jacob@stephens.page directly.', 500);
}
$token = $_POST['cf-turnstile-response'] ?? '';
if ($token === '') {
    respond(false, 'Please complete the verification challenge and try again.');
}
$verifyRaw = @file_get_contents(
    'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    false,
    stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => http_build_query([
                'secret'   => $tsSecret,
                'response' => $token,
                'remoteip' => $ip,
            ]),
            'timeout' => 10,
        ],
    ])
);
$verify = json_decode((string) $verifyRaw, true);
if (!is_array($verify) || ($verify['success'] ?? false) !== true) {
    respond(false, 'Verification failed. Please try the challenge again.');
}

// --- Record this attempt against the rate limit -----------------------------
$hits[] = $now;
@file_put_contents($rlFile, json_encode($hits), LOCK_EX);

// --- Send via Resend HTTP API -----------------------------------------------
if ($resendKey === '') {
    respond(false, 'Email is not configured yet. Please email jacob@stephens.page directly.', 500);
}

$safeName  = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$safeMsg   = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

$html = '<div style="font-family:Arial,Helvetica,sans-serif;line-height:1.6;color:#111714">'
    . '<h2 style="color:#215239;margin:0 0 12px">New message from the stephens.page contact form</h2>'
    . '<p style="margin:0 0 8px"><strong>Name:</strong> ' . $safeName . '</p>'
    . '<p style="margin:0 0 8px"><strong>Email:</strong> <a href="mailto:' . $safeEmail . '">' . $safeEmail . '</a></p>'
    . '<p style="margin:16px 0 4px"><strong>Message:</strong></p>'
    . '<p style="margin:0;white-space:pre-wrap">' . $safeMsg . '</p>'
    . '</div>';

$payload = json_encode([
    'from'     => $fromName . ' <' . $fromEmail . '>',
    'to'       => [$toEmail],
    'reply_to' => $email,
    'subject'  => 'Contact form: ' . $name,
    'html'     => $html,
]);

$ch = curl_init('https://api.resend.com/emails');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . $resendKey,
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_TIMEOUT        => 15,
]);
$resp   = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err    = curl_error($ch);
curl_close($ch);

if ($resp === false || $status < 200 || $status >= 300) {
    error_log('stephens.page contact: Resend failure status=' . $status . ' err=' . $err . ' resp=' . substr((string) $resp, 0, 300));
    respond(false, 'Sorry, the message could not be sent right now. Please email jacob@stephens.page directly.', 502);
}

respond(true, "Thanks for reaching out - your message is on its way. I'll get back to you soon.");
