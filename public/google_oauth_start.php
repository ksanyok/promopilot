<?php
require_once __DIR__ . '/../includes/init.php';

// Check if Google OAuth is enabled
$enabled = get_setting('google_oauth_enabled', '0') === '1';
$clientId = trim((string)get_setting('google_client_id', ''));
$redirectUri = pp_google_redirect_url();

if (!$enabled || $clientId === '') {
    http_response_code(400);
    echo 'Google OAuth is not enabled.';
    exit;
}

// Optional: keep where to redirect after login
$next = isset($_GET['next']) ? (string)$_GET['next'] : '';
if ($next !== '' && strpos($next, '://') !== false) { $next = ''; } // don't allow absolute URLs
$_SESSION['google_oauth_next'] = $next;

// CSRF protection via state
$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

$params = [
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => $state,
    'access_type' => 'online',
    'include_granted_scopes' => 'true',
    'prompt' => 'consent',
];

// If the current page was reached with a referral (?ref=...), try to carry it through OAuth
if (!empty($_GET['ref'])) {
    $ref = preg_replace('~[^a-zA-Z0-9_\-]~', '', (string)$_GET['ref']);
    if ($ref !== '') {
        $_SESSION['pp_ref_qs'] = 'ref=' . $ref;
    }
}

$authUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
redirect($authUrl);
