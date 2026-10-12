<?php
session_start();

require_once dirname(__DIR__) . '/vendor/autoload.php';

$credentials = getenv('HOME')
    . '/.config/smart-assess/google-oauth-client.json';

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
