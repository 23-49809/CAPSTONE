<?php
declare(strict_types=1);

require_once __DIR__ . '/RemoteWorkerSignature.php';

/**
 * Remote worker side: signed HTTPS calls to the website's web/ai-remote.php.
 *
 *  - HTTPS with certificate verification (an optional private CA file); plain HTTP
 *    only for a loopback address (local tests). No redirects, no credentials in the URL.
 *  - Every request is signed (RemoteWorkerSignature); every answer must carry a valid
 *    response signature for that request's nonce, or it is refused (a different server,
 *    a proxy page or an altered answer is never used).
 *  - Documents: size and SHA-256 must match what the claim announced.
 * Throws RuntimeException with a short code: remote_unreachable, remote_tls_failed,
 * remote_bad_response, remote_too_large, remote_download_failed.
 */
final class AiRemoteClient
{
    public const MAX_JSON_BYTES = 2_097_152;
    private readonly string $path;

    public function __construct(
        private readonly string $url,
        private readonly RemoteWorkerSignature $signer,
        private readonly ?string $caFile = null,
        private readonly int $timeoutSeconds = 60
    ) {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment']) || !str_ends_with((string) ($parts['path'] ?? ''), '/ai-remote.php')) {
            throw new InvalidArgumentException('The remote endpoint must be an https:// URL ending in /ai-remote.php.');
        }
        if ($scheme === 'http' && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new InvalidArgumentException('Documents contain personal data: only a loopback test endpoint may use plain HTTP.');
        }
        if ($caFile !== null && ($caFile === '' || $caFile[0] !== '/' || !is_file($caFile) || !is_readable($caFile))) {
            throw new InvalidArgumentException('The CA file must be an absolute path to a readable file.');
        }
        if ($timeoutSeconds < 5 || $timeoutSeconds > 600) {
            throw new InvalidArgumentException('The request timeout is out of range.');
        }
        $this->path = (string) $parts['path'];
    }

    /** @return array{0:int,1:array} HTTP status and the decoded, signature-verified JSON answer */
    public function call(array $request): array
    {
        [$status, $body, $headers, $nonce] = $this->send($request, self::MAX_JSON_BYTES);
        if (!$this->signer->verifyResponse($nonce, $status, $body, (string) ($headers[strtolower(RemoteWorkerSignature::HEADER_RESPONSE_SIGNATURE)] ?? ''))) {
            // Unsigned answers (404 when disabled, 401, a proxy's error page) carry nothing to trust.
            $plain = json_decode($body, true);
            $code = is_array($plain) && is_string($plain['error'] ?? null) && in_array($plain['error'], ['not_found', 'unauthorized'], true) ? $plain['error'] : null;
            throw new RuntimeException($code === 'unauthorized' ? 'remote_auth_failed' : ($code === 'not_found' ? 'remote_disabled' : 'remote_bad_response'));
        }
        $decoded = json_decode($body, true, 32);
        if (!is_array($decoded)) {
            throw new RuntimeException('remote_bad_response');
        }
        return [$status, $decoded];
    }

    /** One document's bytes, checked against the size and SHA-256 announced by the claim. */
    public function download(int $jobId, string $token, string $key, int $size, string $sha256): string
    {
        if ($size < 1 || $size >= 5 * 1024 * 1024 || !preg_match('/^[a-f0-9]{64}$/', $sha256)) {
            throw new RuntimeException('remote_download_failed');
        }
        try {
            [$status, $body, $headers, $nonce] = $this->send(['op' => 'document', 'job_id' => $jobId, 'claim_token' => $token, 'key' => $key], $size + 1);
        } catch (RuntimeException $exception) {
            // Larger than announced (the file changed after the claim) is a failed download, like any mismatch.
            throw new RuntimeException($exception->getMessage() === 'remote_too_large' ? 'remote_download_failed' : $exception->getMessage());
        }
        $announced = strtolower((string) ($headers[strtolower(RemoteWorkerSignature::HEADER_BODY_SHA256)] ?? ''));
        $signature = (string) ($headers[strtolower(RemoteWorkerSignature::HEADER_RESPONSE_SIGNATURE)] ?? '');
        if ($status !== 200 || !preg_match('/^[a-f0-9]{64}$/', $announced) || !hash_equals($this->signer->signResponseHash($nonce, 200, $announced), $signature)
            || strlen($body) !== $size || !hash_equals($sha256, hash('sha256', $body)) || !hash_equals($announced, $sha256)) {
            throw new RuntimeException('remote_download_failed');
        }
        return $body;
    }

    /** @return array{0:int,1:string,2:array<string,string>,3:string} */
    private function send(array $request, int $maxBytes): array
    {
        $body = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $signed = $this->signer->signRequest('POST', $this->path, $body);
        $nonce = $signed[RemoteWorkerSignature::HEADER_NONCE];
        $httpHeaders = ['Content-Type: application/json', 'Accept: application/json, application/octet-stream', 'Expect:'];
        foreach ($signed as $name => $value) {
            $httpHeaders[] = $name . ': ' . $value;
        }
        $response = '';
        $responseHeaders = [];
        $tooLarge = false;
        $curl = curl_init($this->url);
        if ($curl === false) {
            throw new RuntimeException('remote_unreachable');
        }
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $httpHeaders,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response, &$tooLarge, $maxBytes): int {
                if (strlen($response) + strlen($chunk) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($this->caFile !== null) {
            $options[CURLOPT_CAINFO] = $this->caFile;
        }
        curl_setopt_array($curl, $options);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($curl);
        if ($tooLarge) {
            throw new RuntimeException('remote_too_large');
        }
        if ($ok === false) {
            throw new RuntimeException(in_array($error, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91], true) ? 'remote_tls_failed' : 'remote_unreachable');
        }
        return [$status, $response, $responseHeaders, $nonce];
    }
}
