<?php

return static function (array $details): void {
    $home = getenv('HOME');
    if (!$home) {
        throw new RuntimeException('HOME directory is unavailable.');
    }

    $autoload = __DIR__ . '/vendor/autoload.php';
    $tokenPath = $home . '/.config/smart-assess/gmail-token.json';
    $credentialsPath = $home . '/.config/smart-assess/google-oauth-client.json';

    if (!is_file($autoload) || !is_readable($tokenPath) || !is_readable($credentialsPath)) {
        throw new RuntimeException('Gmail dependencies, OAuth client, or private token are unavailable.');
    }

    require_once $autoload;

    $token = json_decode((string) file_get_contents($tokenPath), true);
    if (!is_array($token) || empty($token['access_token'])) {
        throw new RuntimeException('The Gmail token is invalid.');
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
            throw new RuntimeException('Gmail authorization must be renewed.');
        }

        $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);

        if (isset($newToken['error'])) {
            throw new RuntimeException('Gmail access-token refresh failed.');
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
            throw new RuntimeException('Could not save the refreshed Gmail token.');
        }

        chmod($tempPath, 0600);

        if (!rename($tempPath, $tokenPath)) {
            @unlink($tempPath);
            throw new RuntimeException('Could not update the private Gmail token.');
        }

        chmod($tokenPath, 0600);
        $client->setAccessToken($token);
    }

    $email = (string) ($details['email'] ?? '');
    $reference = (string) ($details['reference_no'] ?? '');
    $firstName = (string) ($details['first_name'] ?? '');
    $lastName = (string) ($details['last_name'] ?? '');
    $flow = (string) ($details['flow'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) ||
        !preg_match('/^[A-Z0-9-]{1,30}$/i', $reference) ||
        !in_array($flow, ['docreq', 'landtransfer'], true)) {
        throw new RuntimeException('Invalid confirmation email details.');
    }

    $requestType = $flow === 'docreq' ? 'Document Request' : 'Land Transfer';
    $subject = 'SMART ASSESS - Request received: ' . $reference;

    $body = "Dear {$firstName} {$lastName},\r\n\r\n"
        . "Your {$requestType} has been successfully submitted to "
        . "the Assessor Office of Mabini.\r\n\r\n"
        . "Reference Number: {$reference}\r\n"
        . "Current Status: Received\r\n\r\n"
        . "Please keep your reference number for tracking your request.\r\n\r\n"
        . "Thank you,\r\n"
        . "SMART ASSESS\r\n"
        . "Assessor Office of Mabini";

    $mime = "To: {$email}\r\n"
        . "Subject: {$subject}\r\n"
        . "MIME-Version: 1.0\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($body), 76, "\r\n");

    $raw = rtrim(strtr(base64_encode($mime), '+/', '-_'), '=');

    $gmail = new Google\Service\Gmail($client);
    $message = new Google\Service\Gmail\Message();
    $message->setRaw($raw);

    $gmail->users_messages->send('me', $message);
};
