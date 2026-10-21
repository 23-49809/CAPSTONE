<?php
// One-time Gmail authorization tool (redirects to the local dev server). It
// replaces the office's Gmail token, so it is disabled unless the server sets
// SMART_ASSESS_GMAIL_SETUP=1 for the setup session; otherwise it does not exist.
if (getenv('SMART_ASSESS_GMAIL_SETUP') !== '1') {
    http_response_code(404);
    exit;
}
session_start();

require_once dirname(__DIR__) . '/vendor/autoload.php';

header('Cache-Control: no-store');

$expectedState = $_SESSION['gmail_oauth_state'] ?? '';
unset($_SESSION['gmail_oauth_state']);

if (
    !is_string($expectedState) ||
    $expectedState === '' ||
    !isset($_GET['state']) ||
    !is_string($_GET['state']) ||
    !hash_equals($expectedState, $_GET['state'])
) {
    http_response_code(400);
    exit('Invalid OAuth state. Restart Gmail authorization.');
}

if (isset($_GET['error'])) {
    http_response_code(400);
    exit('Google authorization was cancelled or denied.');
}

if (!isset($_GET['code']) || !is_string($_GET['code'])) {
    http_response_code(400);
    exit('Missing authorization code.');
}

$credentials = (getenv('SMART_ASSESS_GMAIL_DIR') ?: getenv('HOME') . '/.config/smart-assess')
    . '/google-oauth-client.json';

$tokenPath = (getenv('SMART_ASSESS_GMAIL_DIR') ?: getenv('HOME') . '/.config/smart-assess')
    . '/gmail-token.json';

if (!is_file($credentials)) {
    http_response_code(500);
    exit('OAuth credentials file not found.');
}

$client = new Google\Client();
$client->setAuthConfig($credentials);
$client->setRedirectUri(
    'http://127.0.0.1:8923/oauth2callback.php'
);
$client->setScopes([
    Google\Service\Gmail::GMAIL_SEND,
]);
$client->setAccessType('offline');

$token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

if (isset($token['error'])) {
    http_response_code(500);
    exit('Google token exchange failed. Check server configuration.');
}

if (empty($token['refresh_token'])) {
    http_response_code(500);
    exit('No refresh token returned. Revoke app access and authorize again.');
}

$tokenJson = json_encode(
    $token,
    JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR
);

if (file_put_contents($tokenPath, $tokenJson, LOCK_EX) === false) {
    http_response_code(500);
    exit('Could not save Gmail token.');
}

chmod($tokenPath, 0600);

echo 'Gmail authorization completed. Token stored privately.';
