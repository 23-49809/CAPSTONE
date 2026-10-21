<?php

/**
 * Sends the "request received" confirmation email through the Gmail API.
 * Throws on any failure (the caller records it as failed); error messages
 * starting with "[mailer]" are fixed text and safe to log.
 *
 * Credentials: SMART_ASSESS_GMAIL_DIR (folder with google-oauth-client.json
 * and gmail-token.json), else $HOME/.config/smart-assess. Keep it outside the
 * web root and readable/writable only by the web server user.
 * $transport is for tests only: it receives the encoded message instead of
 * Gmail.
 */
return static function (array $details, ?callable $transport = null): void {
    // Validate and build the message before touching any credentials.
    $email = (string) ($details['email'] ?? '');
    $reference = (string) ($details['reference_no'] ?? '');
    $flow = (string) ($details['flow'] ?? '');
    $clean = static fn (mixed $value): string => trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value));
    $name = trim($clean($details['first_name'] ?? '') . ' ' . $clean($details['last_name'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) ||
        !preg_match('/^[A-Z0-9-]{1,30}$/i', $reference) ||
        !in_array($flow, ['docreq', 'landtransfer'], true)) {
        throw new RuntimeException('[mailer] Invalid confirmation email details.');
    }

    $requestType = $flow === 'docreq' ? 'Document Request' : 'Land Transfer request';
    $subject = 'SMART ASSESS: We received your ' . $requestType . ' (' . $reference . ')';

    // Plain text with short paragraphs: readable in any mail app and on phones.
    $body = 'Dear ' . ($name !== '' ? $name : 'Client') . ",\r\n\r\n"
        . 'We received your ' . $requestType . " at the Municipal Assessor's Office of Mabini, Batangas.\r\n\r\n"
        . "Reference Number: {$reference}\r\n\r\n"
        . "Please keep this number. You can use it to check the status of your request on the SMART ASSESS website:\r\n"
        . 'https://smartassessmabini.com/?track=' . rawurlencode($reference) . "\r\n\r\n"
        . "When your request is ready, please claim it in person at the Municipal Assessor's Office during office hours.\r\n\r\n"
        . "Thank you,\r\n"
        . "SMART ASSESS\r\n"
        . "Municipal Assessor's Office, Mabini, Batangas";

    $mime = "To: {$email}\r\n"
        . "Subject: {$subject}\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($body), 76, "\r\n");

    $raw = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');

    if ($transport !== null) {
        $transport($raw);
        return;
    }

    $configDir = getenv('SMART_ASSESS_GMAIL_DIR');
    if ($configDir === false || trim($configDir) === '') {
        $home = getenv('HOME');
        if (!$home) {
            throw new RuntimeException('[mailer] Gmail settings folder is not configured (set SMART_ASSESS_GMAIL_DIR).');
        }
        $configDir = $home . '/.config/smart-assess';
    }
    $configDir = rtrim($configDir, '/');
    $autoload = __DIR__ . '/vendor/autoload.php';
    $tokenPath = $configDir . '/gmail-token.json';
    $credentialsPath = $configDir . '/google-oauth-client.json';

    if (!is_file($autoload) || !is_readable($tokenPath) || !is_readable($credentialsPath)) {
        throw new RuntimeException('[mailer] Gmail dependencies, OAuth client, or private token are unavailable.');
    }

    require_once $autoload;

    $token = json_decode((string) file_get_contents($tokenPath), true);
    if (!is_array($token) || empty($token['access_token'])) {
        throw new RuntimeException('[mailer] The Gmail token is invalid.');
    }

    $client = new Google\Client();
    // The client ID/secret are required to refresh an expired access token.
    $client->setAuthConfig($credentialsPath);
    $client->setAccessType('offline');
    $client->setScopes([Google\Service\Gmail::GMAIL_SEND]);
    $client->setAccessToken($token);

    if ($client->isAccessTokenExpired()) {
        $refreshToken = $token['refresh_token'] ?? $client->getRefreshToken();

        if (!$refreshToken) {
            throw new RuntimeException('[mailer] Gmail authorization must be renewed.');
        }

        $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);

        if (isset($newToken['error'])) {
            throw new RuntimeException('[mailer] Gmail access-token refresh failed.');
        }

        if (empty($newToken['refresh_token'])) {
            $newToken['refresh_token'] = $refreshToken;
        }

        $token = array_merge($token, $newToken);
        $tempPath = $tokenPath . '.tmp.' . bin2hex(random_bytes(4));

        if (file_put_contents(
            $tempPath,
            json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        ) === false) {
            throw new RuntimeException('[mailer] Could not save the refreshed Gmail token.');
        }

        chmod($tempPath, 0600);

        if (!rename($tempPath, $tokenPath)) {
            @unlink($tempPath);
            throw new RuntimeException('[mailer] Could not update the private Gmail token.');
        }

        chmod($tokenPath, 0600);
        $client->setAccessToken($token);
    }

    $gmail = new Google\Service\Gmail($client);
    $message = new Google\Service\Gmail\Message();
    $message->setRaw($raw);

    $gmail->users_messages->send('me', $message);
};
