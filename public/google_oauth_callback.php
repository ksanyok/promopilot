<?php
require_once __DIR__ . '/../includes/init.php';

$enabled = get_setting('google_oauth_enabled', '0') === '1';
$clientId = trim((string)get_setting('google_client_id', ''));
$clientSecret = trim((string)get_setting('google_client_secret', ''));
$redirectUri = pp_google_redirect_url();

function pp_safe_error(string $msg) {
    http_response_code(400);
    echo htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    exit;
}

if (!$enabled || $clientId === '' || $clientSecret === '') {
    pp_safe_error('Google OAuth is not enabled.');
}

$state = (string)($_GET['state'] ?? '');
$code  = (string)($_GET['code'] ?? '');
$err   = (string)($_GET['error'] ?? '');

if ($err !== '') {
    pp_safe_error('OAuth error: ' . $err);
}

if ($state === '' || !isset($_SESSION['google_oauth_state']) || !hash_equals($_SESSION['google_oauth_state'], $state)) {
    pp_safe_error('Invalid OAuth state.');
}
// State one-time use
unset($_SESSION['google_oauth_state']);

if ($code === '') {
    pp_safe_error('Missing authorization code.');
}

// Exchange code for tokens
$post = http_build_query([
    'code' => $code,
    'client_id' => $clientId,
    'client_secret' => $clientSecret,
    'redirect_uri' => $redirectUri,
    'grant_type' => 'authorization_code',
]);
$ctx = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json",
        'content' => $post,
        'timeout' => 10,
        'ignore_errors' => true,
    ],
    'ssl' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
    ],
]);
$resp = @file_get_contents('https://oauth2.googleapis.com/token', false, $ctx);
if ($resp === false) {
    pp_safe_error('Failed to contact Google token endpoint.');
}
$data = json_decode($resp, true);
if (!$data || isset($data['error'])) {
    $desc = is_array($data) ? (string)($data['error_description'] ?? $data['error'] ?? 'Unknown error') : 'Invalid response';
    pp_safe_error('Token exchange failed: ' . $desc);
}
$idToken = (string)($data['id_token'] ?? '');
if ($idToken === '') {
    pp_safe_error('Missing id_token in response.');
}

// Decode JWT payload (base64url)
function b64url_decode(string $s): string { $s = strtr($s, '-_', '+/'); $pad = strlen($s) % 4; if ($pad) { $s .= str_repeat('=', 4 - $pad); } return (string)base64_decode($s); }
$parts = explode('.', $idToken);
if (count($parts) < 2) { pp_safe_error('Invalid id_token.'); }
$payloadJson = b64url_decode($parts[1]);
$claims = json_decode($payloadJson, true);
if (!is_array($claims)) { pp_safe_error('Invalid token payload.'); }

$iss = (string)($claims['iss'] ?? '');
$aud = (string)($claims['aud'] ?? '');
$sub = (string)($claims['sub'] ?? '');
$exp = (int)($claims['exp'] ?? 0);
$email = (string)($claims['email'] ?? '');
$emailVerified = (bool)($claims['email_verified'] ?? false);
$name = trim((string)($claims['name'] ?? ''));
$picture = (string)($claims['picture'] ?? '');

if (!in_array($iss, ['https://accounts.google.com', 'accounts.google.com'], true)) {
    pp_safe_error('Invalid token issuer.');
}
if ($aud !== $clientId) {
    pp_safe_error('Token audience mismatch.');
}
if ($exp && $exp < time() - 60) {
    pp_safe_error('Token expired.');
}
if ($email !== '' && !$emailVerified) {
    // not fatal, but warn
}
if ($sub === '') {
    pp_safe_error('Missing Google user id.');
}

// Find or create user
$conn = connect_db();
$uid = null; $role = 'client';

// 1) By google_id
$st = $conn->prepare("SELECT id, role, avatar FROM users WHERE google_id = ? LIMIT 1");
if ($st) {
    $st->bind_param('s', $sub);
    $st->execute();
    $st->bind_result($id, $r, $existingAvatar);
    if ($st->fetch()) { $uid = (int)$id; $role = (string)$r; }
    $st->close();
}

// 2) Link by email if not found
if ($uid === null && $email !== '') {
    $st = $conn->prepare("SELECT id, role, avatar FROM users WHERE email = ? LIMIT 1");
    if ($st) {
        $st->bind_param('s', $email);
        $st->execute();
        $st->bind_result($id, $r, $existingAvatar);
        if ($st->fetch()) { $uid = (int)$id; $role = (string)$r; }
        $st->close();
        if ($uid !== null) {
            // Attach google_id and profile fields
            $st2 = $conn->prepare("UPDATE users SET google_id = ?, google_picture = ?, full_name = IFNULL(NULLIF(full_name,''), ?), avatar = IFNULL(NULLIF(avatar,''), ?) WHERE id = ?");
            if ($st2) {
                $fn = $name !== '' ? $name : null; $av = null; // keep avatar empty unless you copy picture
                $st2->bind_param('ssssi', $sub, $picture, $fn, $av, $uid);
                $st2->execute();
                $st2->close();
            }
        }
    }
}

// 3) Create new user if still not found
if ($uid === null) {
    // Generate unique username
    $baseUsername = '';
    if ($email !== '') {
        $local = strstr($email, '@', true);
        $baseUsername = preg_replace('~[^a-zA-Z0-9_.-]+~', '', strtolower($local ?: 'user'));
    }
    if ($baseUsername === '') { $baseUsername = 'google_' . substr($sub, 0, 12); }

    $username = $baseUsername;
    for ($i = 0; $i < 50; $i++) {
        $probe = $i === 0 ? $username : ($baseUsername . $i);
        $st = $conn->prepare("SELECT 1 FROM users WHERE username = ? LIMIT 1");
        if ($st) { $st->bind_param('s', $probe); $st->execute(); $exists = (bool)$st->get_result()->fetch_row(); $st->close(); }
        if (empty($exists)) { $username = $probe; break; }
    }
    $randPass = bin2hex(random_bytes(16));
    $hash = password_hash($randPass, PASSWORD_DEFAULT);
    $st = $conn->prepare("INSERT INTO users (username, password, role, full_name, email, google_id, google_picture, created_at) VALUES (?, ?, 'client', ?, ?, ?, ?, NOW())");
    if ($st) {
        $st->bind_param('ssssss', $username, $hash, $name, $email, $sub, $picture);
        if ($st->execute()) {
            $uid = $st->insert_id;
            $role = 'client';
        }
        $st->close();
    }
    if ($uid === null) {
        $conn->close();
        pp_safe_error('Failed to create user.');
    }
}

// Save avatar locally if missing and Google picture available
if ($picture !== '' && $uid !== null) {
    // Check if avatar already set
    $hasAvatar = false;
    $st = $conn->prepare("SELECT avatar FROM users WHERE id = ? LIMIT 1");
    if ($st) {
        $st->bind_param('i', $uid);
        $st->execute();
        $st->bind_result($av);
        if ($st->fetch()) { $hasAvatar = trim((string)$av) !== ''; }
        $st->close();
    }
    if (!$hasAvatar) {
        $saved = pp_save_remote_avatar($picture, (int)$uid);
        if ($saved) {
            $st = $conn->prepare("UPDATE users SET avatar = ?, google_picture = ? WHERE id = ?");
            if ($st) { $st->bind_param('ssi', $saved, $picture, $uid); $st->execute(); $st->close(); }
        } else {
            // Still update google_picture even if local save failed
            $st = $conn->prepare("UPDATE users SET google_picture = ? WHERE id = ?");
            if ($st) { $st->bind_param('si', $picture, $uid); $st->execute(); $st->close(); }
        }
    } else {
        // Ensure google_picture stored
        $st = $conn->prepare("UPDATE users SET google_picture = ? WHERE id = ?");
        if ($st) { $st->bind_param('si', $picture, $uid); $st->execute(); $st->close(); }
    }
}

$conn->close();

// Log user in
pp_session_regenerate();
$_SESSION['user_id'] = (int)$uid;
$_SESSION['role'] = $role;

$next = (string)($_SESSION['google_oauth_next'] ?? '');
unset($_SESSION['google_oauth_next']);
if ($next !== '') {
    // allow only internal relative paths
    if ($next[0] !== '/' || strpos($next, '://') !== false) { $next = ''; }
}
if ($next === '') {
    $next = $role === 'admin' ? 'admin/admin.php' : 'client/client.php';
}
redirect($next);
