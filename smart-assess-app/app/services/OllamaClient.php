<?php
declare(strict_types=1);

/**
 * Minimal Ollama /api/chat client for structured (JSON schema) output.
 * Everything is configured by the server: endpoint, model, timeouts and size
 * limits. The endpoint must be loopback, or HTTPS, so document text never
 * crosses a network unencrypted. No tools, no streaming, no redirects.
 */
final class OllamaClient
{
    private readonly string $endpoint;

    public function __construct(
        string $endpoint,
        private readonly string $model,
        private readonly int $timeoutSeconds = 180,
        private readonly int $maxResponseBytes = 262_144,
        private readonly int $contextTokens = 8192,
        private readonly int $maxOutputTokens = 1500,
        private readonly ?string $unixSocket = null
    ) {
        $endpoint = rtrim(trim($endpoint), '/');
        $parts = parse_url($endpoint);
        $host = strtolower(trim((string) ($parts['host'] ?? ''), '[]'));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!is_array($parts) || !in_array($scheme, ['http', 'https'], true) || $host === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '')) {
            throw new InvalidArgumentException('The Ollama endpoint must be a plain http(s)://host[:port] address.');
        }
        if ($scheme === 'http' && !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
            throw new InvalidArgumentException('A non-local Ollama endpoint must use HTTPS.');
        }
        if (!preg_match('/^[A-Za-z0-9._:\/-]{1,100}$/', $model)) {
            throw new InvalidArgumentException('The Ollama model name is not valid.');
        }
        if ($timeoutSeconds < 5 || $timeoutSeconds > 900 || $maxResponseBytes < 1024 || $maxResponseBytes > 4_194_304
            || $contextTokens < 2048 || $contextTokens > 131_072 || $maxOutputTokens < 256 || $maxOutputTokens > 8192) {
            throw new InvalidArgumentException('An Ollama limit is out of range.');
        }
        // Optional Unix socket (Step 13): the HTTP request goes to this socket file instead of a TCP port.
        // Used when the caller runs without network access (systemd PrivateNetwork=yes), so it can reach
        // only this one service, whatever happens to the host firewall. Loopback http:// URLs only (the
        // URL then only names the Host header); an absolute path short enough for a socket address.
        if ($unixSocket !== null && ($unixSocket === '' || $unixSocket[0] !== '/' || strlen($unixSocket) > 100
            || str_contains($unixSocket, "\0") || !preg_match('#^/[A-Za-z0-9._/-]+$#', $unixSocket) || str_contains($unixSocket, '/../')
            || $scheme !== 'http' || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true))) {
            throw new InvalidArgumentException('The Ollama socket must be an absolute path, used with a loopback http:// endpoint.');
        }

        $this->endpoint = $endpoint;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * Asks the model for JSON matching $schema. Returns the decoded value; the
     * caller still validates it. Throws RuntimeException('ollama_unavailable'),
     * ('ollama_timeout'), ('ollama_model_missing') or ('ollama_invalid_json').
     * $timeoutSeconds can only shorten the configured timeout (used to stay
     * within a job's time budget).
     */
    public function chatJson(string $system, string $user, array $schema, float $temperature = 0.0, ?int $timeoutSeconds = null): mixed
    {
        $timeout = $timeoutSeconds === null ? $this->timeoutSeconds : max(1, min($this->timeoutSeconds, $timeoutSeconds));
        $payload = json_encode([
            'model' => $this->model,
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
            'format' => $schema,
            'stream' => false,
            'options' => ['temperature' => $temperature, 'num_ctx' => $this->contextTokens, 'num_predict' => $this->maxOutputTokens],
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        [$status, $body] = $this->request('POST', '/api/chat', $payload, $timeout);
        if ($status === 404) {
            throw new RuntimeException('ollama_model_missing');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('ollama_unavailable');
        }
        try {
            $envelope = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            $content = is_array($envelope) ? ($envelope['message']['content'] ?? null) : null;
            if (!is_string($content)) {
                throw new RuntimeException('ollama_invalid_json');
            }
            return json_decode($content, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('ollama_invalid_json');
        }
    }

    /**
     * 'ready' when Ollama answers and lists the configured model, 'missing' when
     * it answers but the model is not installed (a configuration problem), and
     * 'unreachable' when it does not answer (it may be starting or down).
     */
    public function modelStatus(): string
    {
        try {
            [$status, $body] = $this->request('GET', '/api/tags', null, 5);
        } catch (RuntimeException) {
            return 'unreachable';
        }
        $tags = $status === 200 ? json_decode($body, true, 16) : null;
        if (!is_array($tags) || !is_array($tags['models'] ?? null)) {
            return 'unreachable';
        }
        foreach ($tags['models'] as $model) {
            if (is_array($model) && ($model['name'] ?? null) === $this->model) {
                return 'ready';
            }
        }
        return 'missing';
    }

    /** The installed model's digest, for the record of what produced a result; null if unknown. */
    public function modelDigest(): ?string
    {
        try {
            [$status, $body] = $this->request('GET', '/api/tags', null, 5);
            $tags = $status === 200 ? json_decode($body, true, 16) : null;
            foreach (is_array($tags) ? ($tags['models'] ?? []) : [] as $model) {
                if (($model['name'] ?? null) === $this->model && is_string($model['digest'] ?? null)) {
                    return substr(preg_replace('/[^A-Za-z0-9:]/', '', $model['digest']) ?? '', 0, 80) ?: null;
                }
            }
        } catch (Throwable) {
            return null;
        }
        return null;
    }

    /** @return array{0:int,1:string} */
    private function request(string $method, string $path, ?string $body, int $timeout): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('ollama_unavailable');
        }
        $curl = curl_init($this->endpoint . $path);
        if ($curl === false) {
            throw new RuntimeException('ollama_unavailable');
        }
        $response = '';
        $tooLarge = false;
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$response, &$tooLarge): int {
                if (strlen($response) + strlen($chunk) > $this->maxResponseBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        if ($this->unixSocket !== null) {
            $options[CURLOPT_UNIX_SOCKET_PATH] = $this->unixSocket;
        }
        curl_setopt_array($curl, $options);
        $ok = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($curl);
        if ($tooLarge) {
            throw new RuntimeException('ollama_invalid_json');
        }
        if ($ok === false) {
            throw new RuntimeException($error === CURLE_OPERATION_TIMEDOUT ? 'ollama_timeout' : 'ollama_unavailable');
        }
        return [$status, $response];
    }
}
