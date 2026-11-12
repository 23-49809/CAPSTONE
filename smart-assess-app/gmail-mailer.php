<?php

/**
 * Sends one email through the Gmail API: either a staff-written message to a
 * client ($details['message'] set; used by the Notifications page) or the
 * "request received" confirmation.
 * Throws on any failure (the caller records it as failed); error messages
 * starting with "[mailer]" are fixed text and safe to log.
 *
 * Credentials: SMART_ASSESS_GMAIL_DIR (folder with google-oauth-client.json
 * and gmail-token.json), else $HOME/.config/smart-assess. Keep it outside the
 * web root and readable/writable only by the web server user.
 * $details['check_only'] verifies the Gmail authorization without sending.
 * $transport and $refresher are for tests only: they replace Gmail and Google's
 * token refresh.
 */
return static function (array $details, ?callable $transport = null, ?callable $refresher = null): void {
    $checkOnly = !empty($details['check_only']);
    if (!$checkOnly) {
    // Validate and build the message before touching any credentials.
    $email = (string) ($details['email'] ?? '');
    $reference = (string) ($details['reference_no'] ?? '');
    $flow = (string) ($details['flow'] ?? '');
    $clean = static fn (mixed $value): string => trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $value));
    $name = trim($clean($details['first_name'] ?? '') . ' ' . $clean($details['last_name'] ?? ''));

    $isStaffMessage = array_key_exists('message', $details);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) ||
        !preg_match('/^[A-Z0-9-]{1,30}$/i', $reference) ||
        (!$isStaffMessage && !in_array($flow, ['docreq', 'landtransfer'], true))) {
        throw new RuntimeException('[mailer] Invalid ' . ($isStaffMessage ? 'notification' : 'confirmation') . ' email details.');
    }

    if ($isStaffMessage) {
        // Staff text kept as written; only control characters other than line
        // breaks are removed, and line breaks are normalised.
        $text = trim((string) preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]+/u', ' ', (string) $details['message']));
        $text = (string) preg_replace('/\r\n|\r|\n/', "\r\n", $text);
        if ($text === '' || strlen($text) > 4000) {
            throw new RuntimeException('[mailer] Invalid notification email details.');
        }
        $subject = 'SMART ASSESS: Update on your request (' . $reference . ')';
        $body = 'Dear ' . ($name !== '' ? $name : 'Client') . ",\r\n\r\n"
            . $text . "\r\n\r\n"
            . "Reference Number: {$reference}\r\n"
            . "Check the status of your request on the SMART ASSESS website:\r\n"
            . 'https://smartassess-mabini.com/?track=' . rawurlencode($reference) . "\r\n\r\n"
            . "Thank you,\r\n"
            . "SMART ASSESS\r\n"
            . "Municipal Assessor's Office, Mabini, Batangas";
    } else {
        $requestType = $flow === 'docreq' ? 'Document Request' : 'Land Transfer request';
        $subject = 'SMART ASSESS: We received your ' . $requestType . ' (' . $reference . ')';

        // Plain text with short paragraphs: readable in any mail app and on phones.
        $body = 'Dear ' . ($name !== '' ? $name : 'Client') . ",\r\n\r\n"
            . 'We received your ' . $requestType . " at the Municipal Assessor's Office of Mabini, Batangas.\r\n\r\n"
            . "Reference Number: {$reference}\r\n\r\n"
            . "Please keep this number. You can use it to check the status of your request on the SMART ASSESS website:\r\n"
            . 'https://smartassess-mabini.com/?track=' . rawurlencode($reference) . "\r\n\r\n"
            . "When your request is ready, please claim it in person at the Municipal Assessor's Office during office hours.\r\n\r\n"
            . "Thank you,\r\n"
            . "SMART ASSESS\r\n"
            . "Municipal Assessor's Office, Mabini, Batangas";
    }

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
    } elseif ($transport !== null) {
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

        try {
            $newToken = $refresher !== null
                ? $refresher($refreshToken)
                : $client->fetchAccessTokenWithRefreshToken($refreshToken);
        } catch (Throwable) {
            throw new RuntimeException('[mailer] Could not reach Google to refresh the Gmail authorization.');
        }

        if (isset($newToken['error'])) {
            // invalid_grant: Google will never accept this token again, so a retry cannot help.
            if ($newToken['error'] === 'invalid_grant') {
                throw new RuntimeException('[mailer] Gmail authorization has expired or been revoked (invalid_grant). An administrator must re-authorize Gmail.');
            }
            $code = preg_replace('/[^a-z_]/', '', strtolower((string) $newToken['error']));
            throw new RuntimeException('[mailer] Google refused the Gmail token refresh (' . $code . '). An administrator must check the Gmail OAuth client.');
        }

        if (empty($newToken['refresh_token'])) {
            $newToken['refresh_token'] = $refreshToken;
        }

        $token = array_merge($token, $newToken);
        $tempPath = $tokenPath . '.tmp.' . bin2hex(random_bytes(4));

        // The new access token is valid whether or not it can be saved: a folder
        // the web server cannot write to only means it is refreshed again next time.
        $saved = @file_put_contents(
            $tempPath,
            (string) json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        ) !== false;
        if ($saved) {
            @chmod($tempPath, 0600);
            $saved = @rename($tempPath, $tokenPath);
            if ($saved) {
                @chmod($tokenPath, 0600);
            } else {
                @unlink($tempPath);
            }
        }
        if (!$saved) {
            error_log('[SMART_ASSESS_EMAIL] The refreshed Gmail token could not be saved; check that the web server owns the Gmail settings folder.');
        }
        $client->setAccessToken($token);
    }

    if ($checkOnly) {
        return;
    }

    $gmail = new Google\Service\Gmail($client);
    $message = new Google\Service\Gmail\Message();
    $message->setRaw($raw);

    try {
        $response = $gmail->users_messages->send('me', $message);

$messageId = is_object($response) && method_exists($response, 'getId')
    ? trim((string) $response->getId())
    : '';

if ($messageId === '') {
    throw new RuntimeException('[mailer] Gmail did not confirm the message (no message ID returned).');
}

error_log('[SMART_ASSESS_EMAIL] Gmail accepted message ID ' . $messageId . '.');
    } catch (Google\Service\Exception $exception) {
        $status = (int) $exception->getCode();
        if ($status >= 400 && $status < 500) {
            throw new RuntimeException('[mailer] Gmail refused the message (HTTP ' . $status . ').');
        }
        throw new RuntimeException('[mailer] Gmail did not confirm the message (HTTP ' . $status . ').');
    } catch (Throwable) {
        throw new RuntimeException('[mailer] Gmail did not confirm the message (no reply from Google).');
    }
};
