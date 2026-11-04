<?php
declare(strict_types=1);

namespace CanvasAutomations;

/**
 * A Canvas REST call failed. Messages never contain the API token.
 *
 * kind() is one of: http, config, token, transport, network, dns, connect, tls,
 * timeout, redirect, origin, rate_limited, response, deadline.
 */
class CanvasApiException extends \RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status = 0,
        private readonly string $kind = 'http',
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function kind(): string
    {
        return $this->kind;
    }
}

/** The batch deadline arrived before a Canvas call could finish. */
final class CanvasDeadlineExceeded extends CanvasApiException
{
    public function __construct(string $message = 'The batch deadline was reached before Canvas answered.')
    {
        parent::__construct($message, 0, 'deadline');
    }
}

interface CanvasTransport
{
    /**
     * @param list<string> $headers
     * @return array{status: int, headers: array<string, list<string>>, body: string}
     * @throws CanvasApiException on network, DNS, TLS, or timeout failures
     */
    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): array;
}

final class CanvasCurlTransport implements CanvasTransport
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): array
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new CanvasApiException('Could not start an HTTPS request.', 0, 'network');
        }
        $responseHeaders = [];
        $timeoutMs = max(1000, (int)round($timeoutSeconds * 1000));
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min($timeoutMs, 10000),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                if (preg_match('#^HTTP/\S+\s+\d{3}#', $line)) {
                    $responseHeaders = [];
                }
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))][] = trim($parts[1]);
                }
                return strlen($line);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'https,http';
        } elseif (defined('CURLOPT_PROTOCOLS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS | CURLPROTO_HTTP;
        }
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        // Windows PHP builds often ship without a CA bundle. When none is
        // configured, trust the operating system store instead of failing.
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')
            && trim((string)ini_get('curl.cainfo')) === '') {
            $options[CURLOPT_SSL_OPTIONS] = CURLSSLOPT_NATIVE_CA;
        }
        curl_setopt_array($handle, $options);
        $responseBody = curl_exec($handle);
        $errno = curl_errno($handle);
        $error = curl_error($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        unset($handle);

        if ($errno !== 0 || !is_string($responseBody)) {
            throw self::failure($errno, $error);
        }
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody];
    }

    private static function failure(int $errno, string $error): CanvasApiException
    {
        return match (true) {
            in_array($errno, [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91], true) => new CanvasApiException(
                'The TLS certificate check for Canvas failed (' . $error . '). Point curl.cainfo and openssl.cafile '
                . 'in php.ini at a current CA bundle, then restart the web server.',
                0,
                'tls'
            ),
            $errno === 6 => new CanvasApiException(
                'The Canvas host name could not be resolved. Check the Canvas URL and the server\'s DNS settings.',
                0,
                'dns'
            ),
            $errno === 7 => new CanvasApiException(
                'The server could not connect to Canvas. Allow outbound HTTPS from this server to the Canvas host '
                . '(firewall or proxy).',
                0,
                'connect'
            ),
            $errno === 28 => new CanvasApiException('Canvas did not answer in time.', 0, 'timeout'),
            default => new CanvasApiException('The request to Canvas failed (' . $error . ').', 0, 'network'),
        };
    }
}

final class CanvasStreamTransport implements CanvasTransport
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeoutSeconds): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", array_merge($headers, ['Connection: close'])),
                'content' => $body ?? '',
                'timeout' => max(1.0, $timeoutSeconds),
                'ignore_errors' => true,
                'follow_location' => 0,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        error_clear_last();
        $started = microtime(true);
        $responseBody = @file_get_contents($url, false, $context);
        $rawHeaders = function_exists('http_get_last_response_headers')
            ? (http_get_last_response_headers() ?? [])
            : ($http_response_header ?? []);

        if ($responseBody === false) {
            // PHP reports a read timeout only as "HTTP request failed", so use the clock.
            if (microtime(true) - $started >= max(1.0, $timeoutSeconds) - 0.25) {
                throw new CanvasApiException('Canvas did not answer in time.', 0, 'timeout');
            }
            throw self::failure((string)(error_get_last()['message'] ?? ''));
        }

        $status = 0;
        $responseHeaders = [];
        foreach ($rawHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match)) {
                $status = (int)$match[1];
                $responseHeaders = [];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $responseHeaders[strtolower(trim($parts[0]))][] = trim($parts[1]);
            }
        }
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody];
    }

    private static function failure(string $message): CanvasApiException
    {
        $lower = strtolower($message);
        return match (true) {
            str_contains($lower, 'certificate') || str_contains($lower, 'ssl') || str_contains($lower, 'crypto')
                => new CanvasApiException(
                    'The TLS certificate check for Canvas failed. Point openssl.cafile in php.ini at a current CA '
                    . 'bundle, then restart the web server.',
                    0,
                    'tls'
                ),
            str_contains($lower, 'getaddrinfo') || str_contains($lower, 'name or service') || str_contains($lower, 'no such host')
                => new CanvasApiException(
                    'The Canvas host name could not be resolved. Check the Canvas URL and the server\'s DNS settings.',
                    0,
                    'dns'
                ),
            str_contains($lower, 'timed out')
                => new CanvasApiException('Canvas did not answer in time.', 0, 'timeout'),
            str_contains($lower, 'refused') || str_contains($lower, 'unreachable')
                => new CanvasApiException(
                    'The server could not connect to Canvas. Allow outbound HTTPS from this server to the Canvas host '
                    . '(firewall or proxy).',
                    0,
                    'connect'
                ),
            default => new CanvasApiException('The request to Canvas failed.', 0, 'network'),
        };
    }
}

/**
 * Minimal Canvas REST client for administrator deployment tasks.
 *
 * Calls are strictly sequential. Every call honours an optional absolute
 * deadline so a web request can never outlive the server's FastCGI limits:
 * each HTTP timeout is capped by the time left, and rate-limit waits that would
 * pass the deadline end the request with CanvasDeadlineExceeded instead.
 */
final class CanvasClient
{
    public const PER_PAGE = 100;
    private const MAX_CALL_SECONDS = 15.0;
    private const MIN_CALL_SECONDS = 1.0;
    private const LOW_QUOTA = 100.0;
    private const LOW_QUOTA_PAUSE = 1.0;
    private const MAX_THROTTLE_RETRIES = 3;

    private string $origin;
    private CanvasTransport $transport;
    private \Closure $clock;
    private \Closure $sleeper;

    public function __construct(
        string $baseUrl,
        #[\SensitiveParameter] private string $token,
        ?CanvasTransport $transport = null,
        private ?float $deadline = null,
        ?callable $clock = null,
        ?callable $sleeper = null,
    ) {
        $this->origin = self::normalizeBaseUrl($baseUrl);
        $this->token = trim($this->token);
        if ($this->token === '' || preg_match('/[\r\n]/', $this->token)) {
            throw new CanvasApiException('Enter a Canvas API token.', 0, 'token');
        }
        $transport ??= self::defaultTransport();
        if ($transport === null) {
            throw new CanvasApiException(
                'This server cannot make outbound HTTPS requests: enable the PHP curl extension or allow_url_fopen.',
                0,
                'transport'
            );
        }
        $this->transport = $transport;
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn(): float => microtime(true);
        $this->sleeper = $sleeper !== null
            ? \Closure::fromCallable($sleeper)
            : static function (float $seconds): void {
                if ($seconds > 0) {
                    usleep((int)round($seconds * 1_000_000));
                }
            };
    }

    public function __debugInfo(): array
    {
        return ['origin' => $this->origin, 'deadline' => $this->deadline];
    }

    /** Which outbound transport this PHP installation supports, if any. */
    public static function availableTransport(): ?string
    {
        if (extension_loaded('curl') && function_exists('curl_init')) {
            return 'curl';
        }
        if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
            return 'streams';
        }
        return null;
    }

    public static function defaultTransport(): ?CanvasTransport
    {
        return match (self::availableTransport()) {
            'curl' => new CanvasCurlTransport(),
            'streams' => new CanvasStreamTransport(),
            default => null,
        };
    }

    /**
     * Reduce a Canvas address to its origin. Only https is accepted, except
     * plain http to this machine (loopback) outside production, which lets a
     * local mock Canvas stand in during testing without the token leaving the host.
     */
    /** Adds https:// to an address typed without a scheme ("college.instructure.com"). */
    public static function withScheme(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            return $url;
        }
        return 'https://' . ltrim($url, '/');
    }

    public static function normalizeBaseUrl(string $url): string
    {
        $url = self::withScheme($url);
        $parts = $url === '' ? false : parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new CanvasApiException('Enter the Canvas URL, for example https://college.instructure.com.', 0, 'config');
        }
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new CanvasApiException('The Canvas URL must not include credentials, a query string, or a fragment.', 0, 'config');
        }
        if (trim((string)($parts['path'] ?? ''), '/') !== '') {
            throw new CanvasApiException('Enter only the Canvas address, without a path.', 0, 'config');
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $loopbackHttp = $scheme === 'http' && self::isLoopbackHost($host) && strtolower(trim((string)getenv('CANVAS_AUTOMATIONS_ENV'))) !== 'production';
        if ($scheme !== 'https' && !$loopbackHttp) {
            throw new CanvasApiException('The Canvas URL must start with https://.', 0, 'config');
        }
        $port = isset($parts['port']) ? (int)$parts['port'] : null;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        return $scheme . '://' . $host . ($port !== null && $port !== $defaultPort ? ':' . $port : '');
    }

    public static function isLoopbackHost(string $host): bool
    {
        return in_array(strtolower(trim($host, '[]')), ['localhost', '127.0.0.1', '::1'], true);
    }

    public function origin(): string
    {
        return $this->origin;
    }

    /** Build an absolute API URL on this Canvas origin. */
    public function url(string $path, array $query = []): string
    {
        $url = $this->origin . '/' . ltrim($path, '/');
        $queryString = self::buildQuery($query);
        return $queryString === '' ? $url : $url . '?' . $queryString;
    }

    /**
     * Encode parameters the way Canvas (Rails) expects: lists as key[]=a&key[]=b
     * and nested maps as key[sub]=value.
     */
    public static function buildQuery(array $params, string $prefix = ''): string
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            $name = $prefix === '' ? (string)$key : $prefix . '[' . $key . ']';
            if ($value === null) {
                continue;
            }
            if (is_array($value)) {
                if (array_is_list($value)) {
                    foreach ($value as $item) {
                        $pairs[] = rawurlencode($name . '[]') . '=' . rawurlencode(self::scalar($item));
                    }
                } else {
                    $nested = self::buildQuery($value, $name);
                    if ($nested !== '') {
                        $pairs[] = $nested;
                    }
                }
                continue;
            }
            $pairs[] = rawurlencode($name) . '=' . rawurlencode(self::scalar($value));
        }
        return implode('&', $pairs);
    }

    public function get(string $path, array $query = []): mixed
    {
        return $this->request('GET', $this->url($path, $query))['data'];
    }

    public function post(string $path, array $form): mixed
    {
        return $this->request('POST', $this->url($path), $form)['data'];
    }

    public function put(string $path, array $form): mixed
    {
        return $this->request('PUT', $this->url($path), $form)['data'];
    }

    public function delete(string $path): mixed
    {
        return $this->request('DELETE', $this->url($path))['data'];
    }

    /**
     * Fetch one page. $pathOrUrl is an API path, or a next-page URL returned
     * earlier (which must stay on this Canvas origin).
     *
     * @return array{items: list<array>, next: ?string}
     */
    public function getPage(string $pathOrUrl, array $query = [], ?string $wrapKey = null): array
    {
        $url = preg_match('#^https?://#i', $pathOrUrl)
            ? $pathOrUrl
            : $this->url($pathOrUrl, $query + ['per_page' => self::PER_PAGE]);
        $response = $this->request('GET', $url);
        $data = $response['data'];
        if ($wrapKey !== null && is_array($data)) {
            $data = $data[$wrapKey] ?? [];
        }
        $items = is_array($data) && array_is_list($data) ? array_values(array_filter($data, 'is_array')) : [];
        return ['items' => $items, 'next' => self::nextLink($response['headers']['link'] ?? [])];
    }

    /** @return list<array> every item across pages (bounded by $maxPages). */
    public function getAll(string $path, array $query = [], ?string $wrapKey = null, int $maxPages = 20): array
    {
        $items = [];
        $page = $this->getPage($path, $query, $wrapKey);
        for ($count = 1; ; $count++) {
            array_push($items, ...$page['items']);
            if ($page['next'] === null || $count >= $maxPages) {
                return $items;
            }
            $page = $this->getPage($page['next'], [], $wrapKey);
        }
    }

    /** @param list<string> $linkHeaders */
    public static function nextLink(array $linkHeaders): ?string
    {
        foreach ($linkHeaders as $header) {
            if (preg_match_all('/<([^>]+)>\s*;\s*rel="?([a-z]+)"?/i', $header, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    if (strtolower($match[2]) === 'next') {
                        return $match[1];
                    }
                }
            }
        }
        return null;
    }

    /** @return array{data: mixed, headers: array<string, list<string>>, status: int} */
    private function request(string $method, string $url, ?array $form = null): array
    {
        $this->assertSameOrigin($url);
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
            'User-Agent: CanvasAutomations-Deploy',
        ];
        $body = null;
        if ($form !== null) {
            $body = self::buildQuery($form);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        for ($attempt = 0; ; $attempt++) {
            $timeout = $this->callTimeout();
            try {
                $response = $this->transport->send($method, $url, $headers, $body, $timeout);
            } catch (CanvasDeadlineExceeded $exception) {
                throw $exception;
            } catch (CanvasApiException $exception) {
                if ($exception->kind() === 'timeout') {
                    // A slow call is retried by the next batch, not failed outright.
                    throw new CanvasDeadlineExceeded('Canvas did not answer before the batch deadline.');
                }
                throw new CanvasApiException($this->scrub($exception->getMessage()), $exception->status(), $exception->kind());
            }

            $status = $response['status'];
            if (self::isThrottled($status, $response['body'])) {
                if ($attempt >= self::MAX_THROTTLE_RETRIES) {
                    throw new CanvasApiException(
                        'Canvas is rate limiting this token. Wait a few minutes, raise the delay between courses, and run again.',
                        $status,
                        'rate_limited'
                    );
                }
                $this->pause(self::throttleWait($attempt, $response['headers']));
                continue;
            }
            $this->respectQuota($response['headers']);

            if ($status >= 300 && $status < 400) {
                throw new CanvasApiException(
                    'Canvas answered with a redirect. Use the institution\'s canonical https Canvas address.',
                    $status,
                    'redirect'
                );
            }
            if ($status < 200 || $status >= 300) {
                throw new CanvasApiException($this->scrub(self::errorMessage($status, $response['body'])), $status, 'http');
            }

            $raw = $response['body'];
            if (str_starts_with($raw, 'while(1);')) {
                $raw = substr($raw, 9);
            }
            if (trim($raw) === '') {
                return ['data' => null, 'headers' => $response['headers'], 'status' => $status];
            }
            $data = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new CanvasApiException('Canvas returned a response that is not JSON. Check the Canvas URL.', $status, 'response');
            }
            return ['data' => $data, 'headers' => $response['headers'], 'status' => $status];
        }
    }

    private function now(): float
    {
        return (float)($this->clock)();
    }

    private function remaining(): ?float
    {
        return $this->deadline === null ? null : $this->deadline - $this->now();
    }

    private function callTimeout(): float
    {
        $remaining = $this->remaining();
        if ($remaining === null) {
            return self::MAX_CALL_SECONDS;
        }
        if ($remaining < self::MIN_CALL_SECONDS) {
            throw new CanvasDeadlineExceeded();
        }
        return min(self::MAX_CALL_SECONDS, $remaining);
    }

    private function pause(float $seconds): void
    {
        $remaining = $this->remaining();
        if ($remaining !== null && $seconds >= $remaining - self::MIN_CALL_SECONDS) {
            throw new CanvasDeadlineExceeded('A Canvas rate-limit wait would pass the batch deadline.');
        }
        ($this->sleeper)($seconds);
    }

    /** @param array<string, list<string>> $headers */
    private function respectQuota(array $headers): void
    {
        $remaining = $headers['x-rate-limit-remaining'][0] ?? null;
        if ($remaining !== null && is_numeric($remaining) && (float)$remaining < self::LOW_QUOTA) {
            $this->pause(self::LOW_QUOTA_PAUSE);
        }
    }

    private static function isThrottled(int $status, string $body): bool
    {
        return $status === 429 || ($status === 403 && stripos($body, 'rate limit exceeded') !== false);
    }

    /** @param array<string, list<string>> $headers */
    private static function throttleWait(int $attempt, array $headers): float
    {
        $retryAfter = $headers['retry-after'][0] ?? null;
        if ($retryAfter !== null && is_numeric($retryAfter)) {
            return max(0.5, min(10.0, (float)$retryAfter));
        }
        return (float)(2 ** $attempt);
    }

    private function assertSameOrigin(string $url): void
    {
        $parts = parse_url($url);
        $candidate = is_array($parts) && isset($parts['scheme'], $parts['host'])
            ? strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
                . (isset($parts['port']) && (int)$parts['port'] !== (strtolower($parts['scheme']) === 'https' ? 443 : 80)
                    ? ':' . (int)$parts['port'] : '')
            : '';
        if ($candidate !== $this->origin) {
            throw new CanvasApiException('Canvas returned a link to a different host; stopping so the token is not sent there.', 0, 'origin');
        }
    }

    private static function errorMessage(int $status, string $body): string
    {
        $detail = '';
        $data = json_decode($body, true);
        if (is_array($data)) {
            $errors = $data['errors'] ?? null;
            if (is_array($errors)) {
                $messages = [];
                array_walk_recursive($errors, static function ($value, $key) use (&$messages): void {
                    if ($key === 'message' && is_string($value)) {
                        $messages[] = $value;
                    }
                });
                $detail = implode('; ', array_unique($messages));
            }
            if ($detail === '' && isset($data['message']) && is_string($data['message'])) {
                $detail = $data['message'];
            }
        }
        $summary = match ($status) {
            401 => 'Canvas rejected the token (401). Check that it is valid and not expired.',
            403 => 'The token does not have permission for this (403).',
            404 => 'Not found in Canvas (404).',
            default => 'Canvas returned HTTP ' . $status . '.',
        };
        $message = $detail !== '' ? $summary . ' ' . $detail : $summary;
        return mb_strimwidth(preg_replace('/\s+/', ' ', $message) ?? $message, 0, 300, '…');
    }

    private function scrub(string $message): string
    {
        return str_replace($this->token, '[token]', $message);
    }

    private static function scalar(mixed $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string)$value;
    }
}
