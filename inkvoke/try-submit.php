<?php
/**
 * Live try for inkvoke: Turnstile → newsletter subscribe → OpenAI generate → metrics log.
 *
 * Deep public module: one POST interface. Secrets from the site environment
 * file /etc/stephens.page/site.env, outside the document root.
 * Trial log: /var/lib/gpt-image/trials.jsonl
 *
 * Invite bypass: POST invite= matching GPT_IMAGE_INVITE_TOKEN makes email/newsletter optional
 * (Turnstile + IP rate limits still apply). Shareable URL: /inkvoke/?invite=<token>
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
// Image generation often exceeds PHP's default 30s max_execution_time.
@set_time_limit(200);
@ini_set('max_execution_time', '200');

const TRIALS_PATH = '/var/lib/gpt-image/trials.jsonl';
const GALLERY_DIR = __DIR__ . '/gallery';
const GALLERY_MANIFEST = GALLERY_DIR . '/manifest.json';
/** Public running tally of live-try OpenAI spend (survives gallery pruning). */
const SPEND_PATH = GALLERY_DIR . '/spend.json';
/** Durable copy under /var/lib (same totals; public file is for the page). */
const SPEND_PATH_LIB = '/var/lib/gpt-image/spend.json';
const GALLERY_MAX_ITEMS = 48;
const MAX_PROMPT = 2000;
const MIN_PROMPT = 3;
const MAX_EMAIL = 254;
const MAX_NAME = 40;
const MAX_TURNSTILE = 2048;
const MAX_POST_FIELDS = 20;
const IP_LIMIT = 5;
const IP_WINDOW = 3600;
const EMAIL_LIMIT = 3;
const EMAIL_WINDOW = 86400;

function respond(bool $ok, string $message, array $extra = [], int $code = 200): void {
    http_response_code($code);
    // Never include raw user input in message; only fixed strings + server-built extras.
    echo json_encode(
        array_merge(['ok' => $ok, 'message' => $message], $extra),
        JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

/**
 * Coerce a POST field to a clean string: scalar only, no null bytes, trim.
 */
function post_string(string $key, int $maxLen = 0): string {
    if (!array_key_exists($key, $_POST)) {
        return '';
    }
    $raw = $_POST[$key];
    if (is_array($raw) || is_object($raw)) {
        return '';
    }
    $s = (string) $raw;
    // Strip NULs and other C0 controls except tab/newline/CR (handled later per field).
    $s = str_replace("\0", '', $s);
    if (!mb_check_encoding($s, 'UTF-8')) {
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }
    $s = trim($s);
    if ($maxLen > 0 && mb_strlen($s) > $maxLen) {
        $s = mb_substr($s, 0, $maxLen);
    }
    return $s;
}

/**
 * Email: lowercase, length-capped, no CR/LF (header injection), FILTER_VALIDATE_EMAIL.
 * Returns '' if invalid.
 */
function sanitize_email(string $email): string {
    $email = strtolower(trim($email));
    $email = str_replace(["\0", "\r", "\n", "\t", ' '], '', $email);
    if ($email === '' || mb_strlen($email) > MAX_EMAIL) {
        return '';
    }
    // Only allow a conservative character set (rejects quotes, angle brackets, etc.).
    if (!preg_match('/^[a-z0-9.!#$%&\'*+\/=?^_`{|}~-]+@[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/i', $email)) {
        return '';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return '';
    }
    return $email;
}

/**
 * Optional public display name for gallery attribution.
 * Empty input becomes "anonymous". Never stores email.
 */
function sanitize_display_name(string $name): string {
    $name = str_replace("\0", '', $name);
    $name = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $name) ?? '';
    $name = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]/u', '', $name) ?? '';
    // Never allow markup / attribute breakers in a public attribution field.
    $name = str_replace(['<', '>', '&', '"', "'", '`'], '', $name);
    $name = str_replace(["\r", "\n", "\t"], ' ', $name);
    $name = preg_replace('/\s+/u', ' ', $name) ?? '';
    $name = trim($name);
    if ($name === '') {
        return 'anonymous';
    }
    if (mb_strlen($name) > MAX_NAME) {
        $name = mb_substr($name, 0, MAX_NAME);
    }
    // Require at least one letter or number (blocks emoji-only / punctuation spam).
    $alnum = preg_match_all('/[\p{L}\p{N}]/u', $name);
    if ($alnum === false || $alnum < 1) {
        return 'anonymous';
    }
    return $name;
}

/**
 * Image prompt: printable text only, normalized whitespace, length bounds.
 * Returns [prompt, error_code|null].
 */
function sanitize_prompt(string $prompt): array {
    $prompt = str_replace("\0", '', $prompt);
    // Normalize newlines; keep paragraph breaks for multi-line pastes.
    $prompt = str_replace(["\r\n", "\r"], "\n", $prompt);
    // Unicode line/paragraph separators → newline (avoids log / structure injection).
    $prompt = str_replace(["\u{2028}", "\u{2029}"], "\n", $prompt);
    // Drop C0 controls except newline and tab.
    $prompt = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $prompt) ?? '';
    // Bidi / zero-width / format controls (homoglyph smuggling, spoofed UI text).
    $prompt = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}]/u', '', $prompt) ?? '';
    $prompt = str_replace("\t", ' ', $prompt);
    // Collapse horizontal whitespace only; preserve newlines.
    $prompt = preg_replace('/[^\S\n]+/u', ' ', $prompt) ?? '';
    $prompt = preg_replace('/ *\n */u', "\n", $prompt) ?? '';
    // Cap runs of blank lines at a single paragraph break.
    $prompt = preg_replace('/\n{3,}/u', "\n\n", $prompt) ?? '';
    $prompt = trim($prompt);
    if ($prompt === '') {
        return ['', 'empty'];
    }
    $len = mb_strlen($prompt);
    if ($len < MIN_PROMPT) {
        return ['', 'too_short'];
    }
    if ($len > MAX_PROMPT) {
        return ['', 'too_long'];
    }
    // Reject prompts that are almost entirely non-letter/number (noise/binary paste).
    $alnum = preg_match_all('/[\p{L}\p{N}]/u', $prompt);
    if ($alnum !== false && $alnum < 2) {
        return ['', 'invalid'];
    }
    return [$prompt, null];
}

/**
 * Turnstile tokens are opaque; allow a safe base64url-ish charset and length.
 */
function sanitize_turnstile(string $token): string {
    $token = trim(str_replace("\0", '', $token));
    if ($token === '' || strlen($token) > MAX_TURNSTILE) {
        return '';
    }
    // Cloudflare tokens are opaque; allow base64 / base64url / dotted segments.
    if (!preg_match('/^[A-Za-z0-9._\-+\/]+=*$/', $token)) {
        return '';
    }
    return $token;
}

/**
 * Invite link token from POST (hex-ish secrets only). Empty if missing/invalid shape.
 */
function sanitize_invite(string $token): string {
    $token = trim(str_replace(["\0", "\r", "\n", "\t", ' '], '', $token));
    if ($token === '' || strlen($token) > 128) {
        return '';
    }
    if (!preg_match('/^[A-Za-z0-9._\-+]+$/', $token)) {
        return '';
    }
    return $token;
}

/**
 * Constant-time compare of invite token against env secret.
 */
function invite_ok(string $posted, string $expected): bool {
    if ($posted === '' || $expected === '') {
        return false;
    }
    if (strlen($posted) !== strlen($expected)) {
        // hash_equals requires equal length; length mismatch is not a valid invite.
        return false;
    }
    return hash_equals($expected, $posted);
}

function sanitize_ip(string $ip): string {
    $ip = trim($ip);
    if ($ip === '' || $ip === 'unknown') {
        return 'unknown';
    }
    // XFF may be IPv4/IPv6; only trust if it validates.
    if (filter_var($ip, FILTER_VALIDATE_IP)) {
        return $ip;
    }
    return 'unknown';
}

function load_env(string $path): array {
    $env = [];
    if (!is_readable($path)) {
        return $env;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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
    return $env;
}

function client_ip(): string {
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if (is_string($xff) && $xff !== '') {
        $first = trim(explode(',', $xff)[0]);
        $first = sanitize_ip($first);
        if ($first !== 'unknown') {
            return $first;
        }
    }
    return sanitize_ip((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}

function log_trial(array $event): void {
    $event['ts'] = $event['ts'] ?? time();
    $dir = dirname(TRIALS_PATH);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $line = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) {
        return;
    }
    @file_put_contents(TRIALS_PATH, $line . "\n", FILE_APPEND | LOCK_EX);
}

function rate_hits(string $key, int $window): array {
    $file = sys_get_temp_dir() . '/sp_gptimage_rl_' . md5($key) . '.json';
    $now = time();
    $hits = [];
    if (is_readable($file)) {
        $hits = json_decode((string) file_get_contents($file), true) ?: [];
    }
    $hits = array_values(array_filter($hits, static fn($t) => is_int($t) && $t > $now - $window));
    return [$file, $hits, $now];
}

function rate_record(string $file, array $hits, int $now): void {
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
}

function turnstile_ok(string $secret, string $token, string $ip): bool {
    $params = [
        'secret'   => $secret,
        'response' => $token,
    ];
    if ($ip !== '') {
        $params['remoteip'] = $ip;
    }
    $raw = @file_get_contents(
        'https://challenges.cloudflare.com/turnstile/v0/siteverify',
        false,
        stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($params),
                'timeout' => 10,
            ],
        ])
    );
    $j = json_decode((string) $raw, true);
    return is_array($j) && ($j['success'] ?? false) === true;
}

function newsletter_add(string $url, string $token, string $email): array {
    $url = rtrim($url, '/') . '/admin/add';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode(['email' => $email, 'list' => 'stephens']),
        CURLOPT_TIMEOUT        => 10,
    ]);
    $resp = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $body = json_decode((string) $resp, true);
    if (!is_array($body)) {
        $body = [];
    }
    return [$status, $body, $err];
}

function openai_generate(string $apiKey, string $prompt): array {
    $payload = json_encode([
        'model'          => 'gpt-image-2',
        'prompt'         => $prompt,
        'quality'        => 'medium',
        'size'           => '1024x1024',
        'output_format'  => 'png',
    ]);
    $ch = curl_init('https://api.openai.com/v1/images/generations');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 180,
    ]);
    $resp = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $body = json_decode((string) $resp, true);
    if (!is_array($body)) {
        $body = [];
    }
    return [$status, $body, $err];
}

/**
 * Read spend tally from public file (and optionally durable lib copy).
 *
 * @return array{total_usd: float, image_count: int, updated_at: int}
 */
function read_spend(): array {
    $empty = ['total_usd' => 0.0, 'image_count' => 0, 'updated_at' => 0];
    foreach ([SPEND_PATH, SPEND_PATH_LIB] as $path) {
        if (!is_readable($path)) {
            continue;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            continue;
        }
        return [
            'total_usd' => (float) ($data['total_usd'] ?? 0),
            'image_count' => (int) ($data['image_count'] ?? 0),
            'updated_at' => (int) ($data['updated_at'] ?? 0),
        ];
    }
    return $empty;
}

/**
 * One-time backfill of spend from trials.jsonl when the tally file is empty
 * but past successful generations exist.
 */
function maybe_backfill_spend_from_trials(array $spend): array {
    if (($spend['image_count'] ?? 0) > 0 || ($spend['total_usd'] ?? 0) > 0) {
        return $spend;
    }
    if (!is_readable(TRIALS_PATH)) {
        return $spend;
    }
    $total = 0.0;
    $count = 0;
    $fh = @fopen(TRIALS_PATH, 'r');
    if ($fh === false) {
        return $spend;
    }
    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $ev = json_decode($line, true);
        if (!is_array($ev)) {
            continue;
        }
        if (($ev['event'] ?? '') !== 'try' || empty($ev['ok'])) {
            continue;
        }
        if (!isset($ev['cost_usd']) || !is_numeric($ev['cost_usd'])) {
            continue;
        }
        $total += (float) $ev['cost_usd'];
        $count++;
    }
    fclose($fh);
    if ($count === 0) {
        return $spend;
    }
    return write_spend($total, $count);
}

/**
 * Write spend tally to public + durable paths.
 *
 * @return array{total_usd: float, image_count: int, updated_at: int}
 */
function write_spend(float $totalUsd, int $imageCount): array {
    $payload = [
        'total_usd' => round(max(0, $totalUsd), 6),
        'image_count' => max(0, $imageCount),
        'updated_at' => time(),
        'currency' => 'USD',
        'note' => 'Estimated live-try OpenAI image spend (gpt-image-2 rates). Running tally for public gallery items; hidden images are excluded (dashboard hide subtracts their cost).',
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) {
        return $payload;
    }
    foreach ([SPEND_PATH, SPEND_PATH_LIB] as $path) {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($path, $json . "\n", LOCK_EX);
        @chmod($path, 0664);
    }
    return $payload;
}

/**
 * Atomically add one successful generation's estimated cost to the running tally.
 *
 * @return array{total_usd: float, image_count: int, updated_at: int}
 */
function record_spend(?float $costUsd): array {
    $add = ($costUsd !== null && $costUsd > 0) ? $costUsd : 0.0;
    $path = SPEND_PATH;
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $fp = @fopen($path, 'c+');
    if ($fp === false) {
        // Fallback without lock.
        $cur = maybe_backfill_spend_from_trials(read_spend());
        return write_spend($cur['total_usd'] + $add, $cur['image_count'] + 1);
    }
    try {
        flock($fp, LOCK_EX);
        $raw = stream_get_contents($fp);
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            $data = maybe_backfill_spend_from_trials(read_spend());
        }
        $total = (float) ($data['total_usd'] ?? 0) + $add;
        $count = (int) ($data['image_count'] ?? 0) + 1;
        $payload = [
            'total_usd' => round(max(0, $total), 6),
            'image_count' => max(0, $count),
            'updated_at' => time(),
            'currency' => 'USD',
            'note' => 'Estimated live-try OpenAI image spend (gpt-image-2 rates). Running tally for public gallery items; hidden images are excluded (dashboard hide subtracts their cost).',
        ];
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json !== false) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $json . "\n");
            fflush($fp);
        }
        flock($fp, LOCK_UN);
        fclose($fp);
        // Mirror durable copy.
        $libDir = dirname(SPEND_PATH_LIB);
        if (!is_dir($libDir)) {
            @mkdir($libDir, 0775, true);
        }
        @file_put_contents(SPEND_PATH_LIB, $json . "\n", LOCK_EX);
        return $payload;
    } catch (Throwable $e) {
        error_log('inkvoke try: spend tally error: ' . $e->getMessage());
        if (is_resource($fp)) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
        return read_spend();
    }
}

/**
 * Seed entries always present in the public gallery (not under gallery/ disk pruning).
 *
 * @return list<array<string, mixed>>
 */
function gallery_seed_items(): array {
    return [
        [
            'id' => 'lighthouse',
            'file' => 'lighthouse.png',
            'url' => 'lighthouse.png',
            'prompt' => 'a lighthouse in a storm, gouache',
            'by' => 'Jacob Stephens',
            'ts' => 1753830660,
            'pinned' => true,
            'model' => 'gpt-image-2',
            'quality' => 'high',
            'size' => '1536x1024',
            'cost_usd' => null,
        ],
    ];
}

function gallery_item_is_pinned(array $item): bool {
    if (!empty($item['pinned'])) {
        return true;
    }
    $id = (string) ($item['id'] ?? '');
    return $id === 'lighthouse';
}

/**
 * Ensure seed items exist; de-dupe by id (first wins - keeps newer unshifted gens).
 *
 * @param list<array<string, mixed>> $items
 * @return list<array<string, mixed>>
 */
function ensure_gallery_seeds(array $items): array {
    $seen = [];
    $out = [];
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $id = (string) ($it['id'] ?? '');
        if ($id !== '' && isset($seen[$id])) {
            continue;
        }
        if ($id !== '') {
            $seen[$id] = true;
        }
        $out[] = $it;
    }
    foreach (gallery_seed_items() as $seed) {
        $id = (string) ($seed['id'] ?? '');
        if ($id !== '' && isset($seen[$id])) {
            continue;
        }
        if ($id !== '') {
            $seen[$id] = true;
        }
        $out[] = $seed;
    }
    return $out;
}

/**
 * Keep newest unpinned items within max, always retaining pinned seeds.
 * Returns [kept_items, items_to_remove_from_disk].
 *
 * @param list<array<string, mixed>> $items
 * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
 */
function prune_gallery_items(array $items, int $max): array {
    $pinned = [];
    $rest = [];
    foreach ($items as $it) {
        if (gallery_item_is_pinned($it)) {
            $pinned[] = $it;
        } else {
            $rest[] = $it;
        }
    }
    $keepRest = max(0, $max - count($pinned));
    $toRemove = array_slice($rest, $keepRest);
    $keptRest = array_slice($rest, 0, $keepRest);
    // Newest first for live gens; pinned seeds after (typically older).
    return [array_merge($keptRest, $pinned), $toRemove];
}

/**
 * Only unlink files that live under gallery/ (never root seeds like lighthouse.png).
 */
function gallery_maybe_unlink_file(array $old): void {
    if (gallery_item_is_pinned($old)) {
        return;
    }
    $url = (string) ($old['url'] ?? '');
    $oldFile = (string) ($old['file'] ?? '');
    // Must be a gallery/ path with a safe filename.
    if ($url !== '' && !str_starts_with($url, 'gallery/')) {
        return;
    }
    if ($oldFile === '' || !preg_match('/^[a-zA-Z0-9._-]+\.(png|jpg|jpeg|webp)$/', $oldFile)) {
        return;
    }
    if (str_contains($oldFile, '..') || str_contains($oldFile, '/')) {
        return;
    }
    $oldPath = GALLERY_DIR . '/' . $oldFile;
    if (is_file($oldPath)) {
        @unlink($oldPath);
    }
}

/**
 * Persist a successful generation into the public community gallery.
 * No email is stored. Optional public display name ($by). Keeps newest + pinned seeds.
 * Returns public relative paths or null on failure.
 */
function save_to_gallery(string $b64, string $prompt, ?float $costUsd = null, string $by = 'anonymous'): ?array {
    if (!is_dir(GALLERY_DIR)) {
        if (!@mkdir(GALLERY_DIR, 0775, true) && !is_dir(GALLERY_DIR)) {
            error_log('inkvoke try: cannot create gallery dir');
            return null;
        }
    }
    if (!is_writable(GALLERY_DIR)) {
        error_log('inkvoke try: gallery dir not writable');
        return null;
    }

    $bytes = base64_decode($b64, true);
    if ($bytes === false || strlen($bytes) < 64) {
        return null;
    }
    // Basic PNG magic check (OpenAI returns png for this endpoint).
    if (substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") {
        // Still allow jpeg/webp if magic matches; otherwise reject.
        $isJpeg = str_starts_with($bytes, "\xff\xd8\xff");
        $isWebp = str_starts_with($bytes, 'RIFF') && str_contains(substr($bytes, 0, 16), 'WEBP');
        if (!$isJpeg && !$isWebp) {
            error_log('inkvoke try: gallery reject non-image payload');
            return null;
        }
        $ext = $isJpeg ? 'jpg' : 'webp';
    } else {
        $ext = 'png';
    }

    $id = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4));
    if (!preg_match('/^[a-zA-Z0-9-]+$/', $id)) {
        return null;
    }
    $filename = $id . '.' . $ext;
    $path = GALLERY_DIR . '/' . $filename;
    if (@file_put_contents($path, $bytes, LOCK_EX) === false) {
        error_log('inkvoke try: failed writing gallery image');
        return null;
    }
    @chmod($path, 0664);

    $by = sanitize_display_name($by);
    // Prompt was already sanitized at the gate; re-bound for public manifest (plain text only).
    $publicPrompt = mb_substr($prompt, 0, 160);
    $publicPrompt = str_replace(['<', '>', '`'], '', $publicPrompt);
    $publicPrompt = trim($publicPrompt);

    $entry = [
        'id' => $id,
        'file' => $filename,
        'url' => 'gallery/' . $filename,
        'prompt' => $publicPrompt,
        'by' => $by,
        'ts' => time(),
        'model' => 'gpt-image-2',
        'quality' => 'medium',
        'size' => '1024x1024',
        'cost_usd' => $costUsd,
    ];

    $fp = @fopen(GALLERY_MANIFEST, 'c+');
    if ($fp === false) {
        return $entry; // image saved; manifest update best-effort
    }
    try {
        if (!flock($fp, LOCK_EX)) {
            fclose($fp);
            return $entry;
        }
        $raw = stream_get_contents($fp);
        $data = json_decode((string) $raw, true);
        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
            $data = ['items' => []];
        }
        array_unshift($data['items'], $entry);
        $data['items'] = ensure_gallery_seeds($data['items']);
        [$kept, $toRemove] = prune_gallery_items($data['items'], GALLERY_MAX_ITEMS);
        $data['items'] = $kept;
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json !== false) {
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, $json . "\n");
            fflush($fp);
        }
        flock($fp, LOCK_UN);
        fclose($fp);

        foreach ($toRemove as $old) {
            if (is_array($old)) {
                gallery_maybe_unlink_file($old);
            }
        }
    } catch (Throwable $e) {
        error_log('inkvoke try: gallery manifest error: ' . $e->getMessage());
        if (is_resource($fp)) {
            @flock($fp, LOCK_UN);
            @fclose($fp);
        }
    }

    return $entry;
}

/**
 * Email Jacob when a live-try image succeeds (Resend). Best-effort: never fails the user request.
 *
 * @param array{email: string, prompt: string, cost_usd: ?float, cost_line: string, latency_ms: int, gallery: ?array, spend: ?array, b64: string} $info
 */
function notify_admin_new_image(array $env, array $info): void {
    $resendKey = (string) ($env['RESEND_API_KEY'] ?? '');
    if ($resendKey === '') {
        error_log('inkvoke try: notify skipped - no RESEND_API_KEY');
        return;
    }
    $fromEmail = (string) ($env['CONTACT_FROM_EMAIL'] ?? 'jacob@stephens.page');
    $fromName  = (string) ($env['CONTACT_FROM_NAME'] ?? 'inkvoke live try');
    $toEmail   = (string) ($env['GPT_IMAGE_NOTIFY_TO'] ?? $env['CONTACT_TO_EMAIL'] ?? 'jacob@stephens.page');
    if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $toEmail = 'jacob@stephens.page';
    }

    $rawEmail  = (string) ($info['email'] ?? '');
    $userEmail = htmlspecialchars($rawEmail !== '' ? $rawEmail : '(invite · no email)', ENT_QUOTES, 'UTF-8');
    $byName    = htmlspecialchars((string) ($info['by'] ?? 'anonymous'), ENT_QUOTES, 'UTF-8');
    $prompt    = htmlspecialchars((string) ($info['prompt'] ?? ''), ENT_QUOTES, 'UTF-8');
    $costLine  = htmlspecialchars((string) ($info['cost_line'] ?? ''), ENT_QUOTES, 'UTF-8');
    $latency   = (int) ($info['latency_ms'] ?? 0);
    $gallery   = is_array($info['gallery'] ?? null) ? $info['gallery'] : null;
    $spend     = is_array($info['spend'] ?? null) ? $info['spend'] : null;
    $b64       = (string) ($info['b64'] ?? '');
    $inviteTag = !empty($info['invite']) ? ' · invite' : '';
    $publicBase = rtrim((string) ($env['INKVOKE_PUBLIC_BASE'] ?? 'https://stephens.page/inkvoke'), '/');
    $dashboardUrl = 'https://dashboard.stephens.page/';

    $galleryUrl = '';
    $galleryId = '';
    if ($gallery && !empty($gallery['url']) && is_string($gallery['url'])) {
        $galleryUrl = $publicBase . '/' . ltrim($gallery['url'], '/');
    }
    if ($gallery && !empty($gallery['id']) && is_string($gallery['id'])) {
        $galleryId = $gallery['id'];
    }
    $galleryHtml = $galleryUrl !== ''
        ? '<p style="margin:0 0 8px"><strong>Gallery image:</strong> <a href="' . htmlspecialchars($galleryUrl, ENT_QUOTES, 'UTF-8') . '">'
            . htmlspecialchars($galleryUrl, ENT_QUOTES, 'UTF-8') . '</a></p>'
        : '';
    if ($galleryId !== '') {
        $galleryHtml .= '<p style="margin:0 0 8px"><strong>Gallery id:</strong> '
            . htmlspecialchars($galleryId, ENT_QUOTES, 'UTF-8')
            . ' (toggle visibility on the dashboard inkvoke tab)</p>';
    }

    $spendHtml = '';
    if ($spend && isset($spend['total_usd'])) {
        $spendHtml = '<p style="margin:0 0 8px"><strong>Running spend:</strong> ~$'
            . htmlspecialchars(number_format((float) $spend['total_usd'], 4), ENT_QUOTES, 'UTF-8')
            . ' est. (' . (int) ($spend['image_count'] ?? 0) . ' images)</p>';
    }

    $emailHtml = $rawEmail !== ''
        ? '<p style="margin:0 0 8px"><strong>User email:</strong> <a href="mailto:' . htmlspecialchars($rawEmail, ENT_QUOTES, 'UTF-8') . '">' . $userEmail . '</a></p>'
        : '<p style="margin:0 0 8px"><strong>User email:</strong> ' . $userEmail . '</p>';

    $html = '<div style="font-family:Arial,Helvetica,sans-serif;line-height:1.6;color:#181512">'
        . '<h2 style="color:#0e0f12;margin:0 0 12px">New inkvoke live try' . htmlspecialchars($inviteTag, ENT_QUOTES, 'UTF-8') . '</h2>'
        . '<p style="margin:0 0 8px"><strong>Display name:</strong> ' . $byName . '</p>'
        . $emailHtml
        . '<p style="margin:0 0 8px"><strong>Prompt:</strong></p>'
        . '<p style="margin:0 0 12px;white-space:pre-wrap;background:#f4f1ea;padding:10px 12px;border-radius:6px">' . $prompt . '</p>'
        . '<p style="margin:0 0 8px"><strong>Cost:</strong> ' . ($costLine !== '' ? $costLine : 'n/a') . '</p>'
        . '<p style="margin:0 0 8px"><strong>Latency:</strong> ' . $latency . ' ms</p>'
        . $spendHtml
        . $galleryHtml
        . '<p style="margin:12px 0 0"><a href="' . htmlspecialchars($dashboardUrl, ENT_QUOTES, 'UTF-8') . '">Manage on dashboard</a>'
        . ' · <a href="' . htmlspecialchars($publicBase, ENT_QUOTES, 'UTF-8') . '/#gallery">Community gallery</a>'
        . ' · <a href="' . htmlspecialchars($publicBase, ENT_QUOTES, 'UTF-8') . '/#try">Live try</a></p>'
        . '</div>';

    $text = "New inkvoke live try{$inviteTag}\n"
        . "By: " . ($info['by'] ?? 'anonymous') . "\n"
        . "User: " . ($rawEmail !== '' ? $rawEmail : '(invite · no email)') . "\n"
        . "Prompt: " . ($info['prompt'] ?? '') . "\n"
        . "Cost: " . ($info['cost_line'] ?? '') . "\n"
        . ($galleryUrl !== '' ? "Gallery: {$galleryUrl}\n" : '')
        . ($galleryId !== '' ? "Gallery id: {$galleryId}\n" : '')
        . "Dashboard: {$dashboardUrl}\n"
        . "{$publicBase}/#gallery\n";

    $payload = [
        'from'     => $fromName . ' <' . $fromEmail . '>',
        'to'       => [$toEmail],
        'subject'  => 'inkvoke try' . $inviteTag . ': ' . mb_substr((string) ($info['prompt'] ?? 'new image'), 0, 60),
        'html'     => $html,
        'text'     => $text,
    ];
    if ($rawEmail !== '' && filter_var($rawEmail, FILTER_VALIDATE_EMAIL)) {
        $payload['reply_to'] = $rawEmail;
    }

    // Attach the PNG when base64 looks valid (cap ~4MB attachment raw b64).
    if ($b64 !== '' && strlen($b64) < 6_000_000 && preg_match('/^[A-Za-z0-9+\/]+=*$/', $b64)) {
        $filename = 'trial.png';
        if ($gallery && !empty($gallery['file']) && is_string($gallery['file'])
            && preg_match('/^[a-zA-Z0-9._-]+\.(png|jpg|jpeg|webp)$/', $gallery['file'])) {
            $filename = $gallery['file'];
        }
        $payload['attachments'] = [[
            'filename' => $filename,
            'content'  => $b64,
        ]];
    }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $resendKey,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false || $status < 200 || $status >= 300) {
        error_log('inkvoke try: notify Resend fail status=' . $status . ' err=' . $err . ' resp=' . substr((string) $resp, 0, 300));
    }
}

/**
 * Estimate USD cost from Images API usage tokens.
 * Rates: OpenAI standard-tier gpt-image-2 per 1M tokens (text in $5, image in $8, image out $30).
 * The API does not return a dollar amount; this matches the gpt-image CLI.
 */
function estimate_cost_from_usage(?array $usage): array {
    $empty = [
        'cost_usd' => null,
        'input_tokens' => 0,
        'output_tokens' => 0,
        'text_input' => 0,
        'image_input' => 0,
        'image_output' => 0,
        'text_output' => 0,
        'cost_line' => '',
    ];
    if (!is_array($usage)) {
        return $empty;
    }
    $input = (int) ($usage['input_tokens'] ?? 0);
    $output = (int) ($usage['output_tokens'] ?? 0);
    $inDet = $usage['input_tokens_details'] ?? [];
    $outDet = $usage['output_tokens_details'] ?? [];
    $textIn = (int) ($inDet['text_tokens'] ?? $input);
    $imgIn = (int) ($inDet['image_tokens'] ?? 0);
    $imgOut = (int) ($outDet['image_tokens'] ?? $output);
    $textOut = (int) ($outDet['text_tokens'] ?? 0);

    // gpt-image-2 standard rates per 1M tokens
    $cost = (
        $textIn * 5.0
        + $imgIn * 8.0
        + $imgOut * 30.0
        + $textOut * 0.0
    ) / 1_000_000.0;

    $parts = [sprintf('~$%.4f est.', $cost)];
    if ($input || $output) {
        $parts[] = "tokens in={$input} out={$output}";
        $detail = [];
        if ($textIn) {
            $detail[] = "text_in={$textIn}";
        }
        if ($imgIn) {
            $detail[] = "img_in={$imgIn}";
        }
        if ($imgOut) {
            $detail[] = "img_out={$imgOut}";
        }
        if ($textOut) {
            $detail[] = "text_out={$textOut}";
        }
        if ($detail) {
            $parts[] = '(' . implode(', ', $detail) . ')';
        }
    }

    return [
        'cost_usd' => $cost,
        'input_tokens' => $input,
        'output_tokens' => $output,
        'text_input' => $textIn,
        'image_input' => $imgIn,
        'image_output' => $imgOut,
        'text_output' => $textOut,
        'cost_line' => implode(' · ', $parts),
    ];
}

// --- Entry -------------------------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(false, 'Method not allowed.', [], 405);
}

// Reject oversized or non-form posts early (DoS / weird clients).
$contentLen = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLen > 64 * 1024) {
    respond(false, 'Request too large.', [], 413);
}
if (!is_array($_POST) || count($_POST) > MAX_POST_FIELDS) {
    respond(false, 'Invalid form submission.', [], 400);
}

$env = load_env('/etc/stephens.page/site.env');
$ip = client_ip();
$started = microtime(true);

// Honeypot: look successful, do nothing spendy. Do not log the honeypot value.
if (post_string('website_url', 200) !== '') {
    log_trial([
        'event' => 'honeypot',
        'ip' => $ip,
        'ok' => true,
    ]);
    respond(true, 'Almost there - check your email to confirm, then try again.');
}

$emailRaw = post_string('email', MAX_EMAIL + 32);
$promptRaw = post_string('prompt', MAX_PROMPT + 64);
$nameRaw = post_string('name', MAX_NAME + 32);
$tsToken = sanitize_turnstile(post_string('cf-turnstile-response', MAX_TURNSTILE + 32));
$invitePosted = sanitize_invite(post_string('invite', 128));
$inviteExpected = trim((string) ($env['GPT_IMAGE_INVITE_TOKEN'] ?? ''));
$isInvite = invite_ok($invitePosted, $inviteExpected);

$email = sanitize_email($emailRaw);
$displayName = sanitize_display_name($nameRaw);
[$prompt, $promptErr] = sanitize_prompt($promptRaw);

// Log only sanitized values (never raw POST).
$baseEvent = [
    'event' => 'try',
    'ip' => $ip,
    'invite' => $isInvite,
    'by' => $displayName,
    'email' => $email !== '' ? $email : mb_substr(preg_replace('/[^\x20-\x7E]/', '', $emailRaw) ?? '', 0, 64),
    'prompt' => $prompt !== '' ? $prompt : mb_substr(preg_replace('/[^\x20-\x7E]/', '', $promptRaw) ?? '', 0, 80),
    'prompt_len' => mb_strlen($prompt !== '' ? $prompt : $promptRaw),
];

// Email required unless a valid invite link is used.
if (!$isInvite) {
    if ($email === '' && ($prompt === '' && ($promptErr === null || $promptErr === 'empty'))) {
        log_trial($baseEvent + ['ok' => false, 'error' => 'missing_fields']);
        respond(false, 'Enter an image description and your email to continue.');
    }
    if ($email === '') {
        log_trial($baseEvent + ['ok' => false, 'error' => 'bad_email']);
        respond(false, 'Please enter a valid email address.');
    }
} elseif ($emailRaw !== '' && $email === '') {
    // Invite user typed something that is not a valid email.
    log_trial($baseEvent + ['ok' => false, 'error' => 'bad_email_optional']);
    respond(false, 'That email does not look valid. Leave it blank to skip the newsletter, or enter a real address.');
}
if ($promptErr === 'too_short') {
    log_trial($baseEvent + ['ok' => false, 'error' => 'prompt_too_short']);
    respond(false, 'Prompt is too short - use at least ' . MIN_PROMPT . ' characters.');
}
if ($promptErr === 'too_long') {
    log_trial($baseEvent + ['ok' => false, 'error' => 'prompt_too_long']);
    respond(false, 'Keep the prompt under ' . MAX_PROMPT . ' characters.');
}
if ($promptErr === 'invalid') {
    log_trial($baseEvent + ['ok' => false, 'error' => 'prompt_invalid']);
    respond(false, 'That prompt does not look like usable text. Try a short plain-language description.');
}
if ($promptErr === 'empty' || $prompt === '') {
    log_trial($baseEvent + ['ok' => false, 'error' => 'missing_prompt']);
    respond(false, 'Enter an image description to continue.');
}

[$ipFile, $ipHits, $now] = rate_hits('ip:' . $ip, IP_WINDOW);
if (count($ipHits) >= IP_LIMIT) {
    log_trial($baseEvent + ['ok' => false, 'error' => 'rate_ip']);
    respond(false, 'Too many tries from this connection. Please wait an hour and try again.', [], 429);
}
$emFile = '';
$emHits = [];
if ($email !== '') {
    [$emFile, $emHits] = rate_hits('email:' . $email, EMAIL_WINDOW);
    if (count($emHits) >= EMAIL_LIMIT) {
        log_trial($baseEvent + ['ok' => false, 'error' => 'rate_email']);
        respond(false, 'This email has reached the free trial limit for today (3 images). Try again tomorrow.', [], 429);
    }
}

$tsSecret = $env['TURNSTILE_SECRET'] ?? '';
if ($tsSecret === '') {
    log_trial($baseEvent + ['ok' => false, 'error' => 'config_turnstile']);
    respond(false, 'The form is not fully configured yet. Please email jacob@stephens.page.', [], 500);
}
if ($tsToken === '') {
    log_trial($baseEvent + ['ok' => false, 'error' => 'turnstile_missing']);
    respond(false, 'Please complete the verification challenge and try again.');
}
// Only pass a validated IP to Turnstile; unknown skips remoteip.
$tsIp = $ip !== 'unknown' ? $ip : '';
if (!turnstile_ok($tsSecret, $tsToken, $tsIp)) {
    log_trial($baseEvent + ['ok' => false, 'error' => 'turnstile_fail']);
    respond(false, 'Verification failed. Please try the challenge again.');
}

// Record attempt against rate limits only after Turnstile (blocks pure spam burn).
rate_record($ipFile, $ipHits, $now);
if ($email !== '' && $emFile !== '') {
    rate_record($emFile, $emHits, $now);
}

$already = false;
$subscribed = false;
// Newsletter: required for anonymous; optional when invite + email provided; skip if invite with no email.
if ($email !== '') {
    $nlUrl = $env['NEWSLETTER_ADMIN_URL'] ?? 'http://127.0.0.1:3462';
    $nlTok = $env['NEWSLETTER_ADMIN_TOKEN'] ?? '';
    if ($nlTok === '') {
        log_trial($baseEvent + ['ok' => false, 'error' => 'config_newsletter']);
        respond(false, 'Newsletter is not configured yet. Please email jacob@stephens.page.', [], 500);
    }
    [$nlStatus, $nlBody, $nlErr] = newsletter_add($nlUrl, $nlTok, $email);
    if ($nlStatus < 200 || $nlStatus >= 300 || !($nlBody['ok'] ?? false)) {
        error_log('inkvoke try: newsletter add failed status=' . $nlStatus . ' err=' . $nlErr . ' body=' . substr(json_encode($nlBody), 0, 200));
        log_trial($baseEvent + ['ok' => false, 'error' => 'newsletter_fail', 'http' => $nlStatus]);
        respond(false, 'Could not complete newsletter signup. Please try again in a moment.', [], 502);
    }
    $subscribed = true;
    $msg = (string) ($nlBody['message'] ?? '');
    if (stripos($msg, 'already') !== false) {
        $already = true;
    }
} elseif (!$isInvite) {
    // Should be unreachable (email required above); belt-and-suspenders.
    log_trial($baseEvent + ['ok' => false, 'error' => 'bad_email']);
    respond(false, 'Please enter a valid email address.');
}

$apiKey = $env['OPENAI_API_KEY'] ?? '';
if ($apiKey === '') {
    log_trial($baseEvent + ['ok' => false, 'error' => 'config_openai', 'subscribed' => $subscribed]);
    $cfgMsg = $subscribed
        ? 'Image generation is not configured yet. Your newsletter signup was recorded - thank you.'
        : 'Image generation is not configured yet. Please email jacob@stephens.page.';
    respond(false, $cfgMsg, [], 500);
}

[$oStatus, $oBody, $oErr] = openai_generate($apiKey, $prompt);
$latencyMs = (int) round((microtime(true) - $started) * 1000);
$latencySec = max(1, (int) round($latencyMs / 1000));
$b64 = $oBody['data'][0]['b64_json'] ?? null;
$cost = estimate_cost_from_usage(is_array($oBody['usage'] ?? null) ? $oBody['usage'] : null);

if ($oStatus < 200 || $oStatus >= 300 || !is_string($b64) || $b64 === '') {
    $apiMsg = $oBody['error']['message'] ?? $oErr ?: 'unknown';
    error_log('inkvoke try: openai fail status=' . $oStatus . ' msg=' . substr((string) $apiMsg, 0, 300));
    log_trial($baseEvent + [
        'ok' => false,
        'error' => 'openai_fail',
        'http' => $oStatus,
        'latency_ms' => $latencyMs,
        'subscribed' => $subscribed,
        'already_subscribed' => $already,
        'cost_usd' => $cost['cost_usd'],
        'input_tokens' => $cost['input_tokens'],
        'output_tokens' => $cost['output_tokens'],
    ]);
    $failMsg = $subscribed
        ? 'Image generation failed. Your newsletter signup was recorded - please try a different prompt later.'
        : 'Image generation failed. Please try a different prompt later.';
    respond(false, $failMsg, [], 502);
}

log_trial($baseEvent + [
    'ok' => true,
    'latency_ms' => $latencyMs,
    'subscribed' => $subscribed,
    'already_subscribed' => $already,
    'model' => 'gpt-image-2',
    'quality' => 'medium',
    'size' => '1024x1024',
    'cost_usd' => $cost['cost_usd'],
    'input_tokens' => $cost['input_tokens'],
    'output_tokens' => $cost['output_tokens'],
    'text_input' => $cost['text_input'],
    'image_output' => $cost['image_output'],
]);

if ($subscribed) {
    $subNote = $already
        ? "You're already on the list - thanks for reading."
        : "You're on Jacob Stephens' blog newsletter (unsubscribe anytime from any email).";
} elseif ($isInvite) {
    $subNote = 'Invite link trial - newsletter skipped.';
} else {
    $subNote = '';
}

$costLine = $cost['cost_line'] !== ''
    ? $cost['cost_line'] . " · {$latencySec}s"
    : "{$latencySec}s";

// Only return b64 if it is well-formed base64 (reject garbage / injection).
if (!preg_match('/^[A-Za-z0-9+\/]+=*$/', $b64) || strlen($b64) < 64) {
    log_trial($baseEvent + ['ok' => false, 'error' => 'bad_image_payload', 'latency_ms' => $latencyMs]);
    respond(false, 'Image generation returned an unreadable result. Please try again.', [], 502);
}

// Running public spend tally (all successful live-tries; not reduced when gallery prunes).
$spend = record_spend(isset($cost['cost_usd']) ? (float) $cost['cost_usd'] : null);

// Public community gallery (no email; optional display name). Best-effort.
$gallery = save_to_gallery(
    $b64,
    $prompt,
    isset($cost['cost_usd']) ? (float) $cost['cost_usd'] : null,
    $displayName
);
if ($gallery) {
    log_trial(array_merge($baseEvent, [
        'event' => 'gallery',
        'ok' => true,
        'gallery_id' => $gallery['id'],
        'gallery_file' => $gallery['file'],
        'by' => $gallery['by'] ?? $displayName,
        'cost_usd' => $cost['cost_usd'],
    ]));
}

// Notify jacob@stephens.page (best-effort; does not fail the try).
notify_admin_new_image($env, [
    'email' => $email,
    'invite' => $isInvite,
    'by' => $displayName,
    'prompt' => $prompt,
    'cost_usd' => $cost['cost_usd'] ?? null,
    'cost_line' => $costLine,
    'latency_ms' => $latencyMs,
    'gallery' => $gallery,
    'spend' => $spend,
    'b64' => $b64,
]);

respond(true, "Wrote trial.png · {$costLine} · {$subNote}", [
    'image_b64' => $b64,
    'already_subscribed' => $already,
    'latency_ms' => $latencyMs,
    'output' => 'trial.png',
    'by' => $displayName,
    'cost_usd' => $cost['cost_usd'],
    'cost_line' => $costLine,
    'input_tokens' => $cost['input_tokens'],
    'output_tokens' => $cost['output_tokens'],
    'usage' => [
        'text_input' => $cost['text_input'],
        'image_input' => $cost['image_input'],
        'image_output' => $cost['image_output'],
        'text_output' => $cost['text_output'],
    ],
    'gallery' => $gallery,
    'spend' => [
        'total_usd' => $spend['total_usd'],
        'image_count' => $spend['image_count'],
        'updated_at' => $spend['updated_at'] ?? time(),
    ],
]);
