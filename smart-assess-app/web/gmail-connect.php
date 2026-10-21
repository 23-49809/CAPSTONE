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

$credentials = (getenv('SMART_ASSESS_GMAIL_DIR') ?: getenv('HOME') . '/.config/smart-assess')
    . '/google-oauth-client.json';

if (!is_file($credentials)) {
    http_response_code(500);
    exit('OAuth credentials not found. Check the private file path.');
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
$client->setPrompt('consent');

$state = bin2hex(random_bytes(32));
$_SESSION['gmail_oauth_state'] = $state;
$client->setState($state);

header('Cache-Control: no-store');
header('Location: ' . $client->createAuthUrl());
exit;
