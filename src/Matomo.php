<?php

declare(strict_types=1);

namespace Museum;

/**
 * Talks to the Matomo add-on through its two public HTTP APIs:
 *  - the Tracking API (matomo.php), fed by the visitors' browsers through this app;
 *  - the Reporting API (index.php?module=API), read server-side for the curator's office.
 * The token only lives here, in a POST body to Matomo. It is never sent to a browser.
 */
final class Matomo
{
    /** Parameters a browser may not set: they need a token, and the token belongs to the server. */
    private const PRIVILEGED = ['token_auth', 'cip', 'cdt', 'cid', 'country', 'region', 'city', 'lat', 'long', 'idsite', 'uid', 'bots'];

    /** @var list<array{method: string, params: array<string, mixed>}> Reporting API calls made for the current page. */
    public array $calls = [];
    public float $apiMs = 0.0;

    public function __construct(
        public readonly string $url,
        private readonly ?string $token,
    ) {
    }

    public static function fromEnv(): ?self
    {
        $url = rtrim((string) getenv('MATOMO_URL'), '/');
        if ($url === '') {
            return null;
        }
        $token = trim((string) getenv('MATOMO_TOKEN'));
        return new self($url, $token === '' ? null : $token);
    }

    public function hasToken(): bool
    {
        return $this->token !== null;
    }

    public function host(): string
    {
        return (string) parse_url($this->url, PHP_URL_HOST);
    }

    // ---------------------------------------------------------------- Reporting API

    /** One Reporting API method. Throws MatomoError on an HTTP or API error. */
    public function api(string $method, array $params = []): mixed
    {
        $result = $this->bulk([[$method, $params]])[0];
        if ($result instanceof MatomoError) {
            throw $result;
        }
        return $result;
    }

    /**
     * Several Reporting API methods in one HTTP request (API.getBulkRequest).
     * Returns one result per call, in order; a failed call comes back as a MatomoError instance.
     *
     * @param list<array{0: string, 1: array<string, mixed>}> $calls
     */
    public function bulk(array $calls): array
    {
        if ($this->token === null) {
            throw new MatomoError('MATOMO_TOKEN is not set');
        }
        $urls = [];
        foreach ($calls as [$method, $params]) {
            $this->calls[] = ['method' => $method, 'params' => $params];
            $urls[] = http_build_query(['method' => $method] + $params);
        }
        $start = microtime(true);
        $response = $this->http('POST', '/index.php?module=API&method=API.getBulkRequest&format=json', http_build_query([
            'urls' => $urls,
            'token_auth' => $this->token,
        ]), ['Content-Type: application/x-www-form-urlencoded'], 20);
        $this->apiMs += (microtime(true) - $start) * 1000;

        $data = json_decode($response['body'], true);
        if ($response['status'] !== 200 || !is_array($data)) {
            throw new MatomoError("Reporting API answered HTTP {$response['status']}");
        }
        if (($data['result'] ?? null) === 'error') {
            throw new MatomoError((string) $data['message']);
        }
        return array_map(fn ($item) => match (true) {
            is_array($item) && ($item['result'] ?? null) === 'error' => new MatomoError((string) $item['message']),
            is_array($item) && array_keys($item) === ['value'] => $item['value'], // scalars come wrapped as {"value": …}
            default => $item,
        }, array_values($data));
    }

    // ---------------------------------------------------------------- Tracking API

    /**
     * Forwards one tracking hit from a visitor's browser to matomo.php.
     * The browser's privileged parameters are dropped; the site id is forced; with a token,
     * the visitor's IP is passed as `cip` so Matomo anonymises the real address, not the app's.
     */
    public function forwardHit(array $params, int $siteId, string $clientIp, string $userAgent, string $language): array
    {
        $params = array_diff_key($params, array_flip(self::PRIVILEGED));
        $params['idsite'] = $siteId;
        $params['send_image'] = 0;
        if ($this->token !== null) {
            $params['cip'] = $clientIp;
            $params['token_auth'] = $this->token;
        }
        $start = microtime(true);
        $response = $this->http('POST', '/matomo.php', http_build_query($params), [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: ' . $userAgent,
            'Accept-Language: ' . $language,
        ], 5);
        return ['status' => $response['status'], 'ms' => round((microtime(true) - $start) * 1000)];
    }

    /** Server-side bulk tracking (used by the seed). Each request is a query string like "?idsite=1&rec=1&...". */
    public function trackBulk(array $requests): array
    {
        $body = json_encode(['requests' => $requests, 'token_auth' => $this->token], JSON_UNESCAPED_SLASHES);
        $response = $this->http('POST', '/matomo.php', (string) $body, ['Content-Type: application/json'], 20);
        return ['status' => $response['status'], 'body' => substr($response['body'], 0, 300)];
    }

    /** The official JavaScript tracker, served from this app's own domain (cached one hour). */
    public function trackerScript(): ?string
    {
        $cache = sys_get_temp_dir() . '/small-museum-matomo-' . md5($this->url) . '.js';
        if (is_file($cache) && filemtime($cache) > time() - 3600) {
            return (string) file_get_contents($cache);
        }
        $response = $this->http('GET', '/matomo.js', null, [], 5);
        if ($response['status'] === 200 && str_contains($response['body'], 'Matomo')) {
            file_put_contents($cache, $response['body'], LOCK_EX);
            return $response['body'];
        }
        return is_file($cache) ? (string) file_get_contents($cache) : null;
    }

    /** Is the tracking endpoint answering? Used by /health/deep, without writing any hit. */
    public function trackerAnswers(): int
    {
        return $this->http('GET', '/matomo.php', null, [], 5)['status'];
    }

    /** @return array{status: int, body: string} status 0 means Matomo could not be reached */
    private function http(string $method, string $path, ?string $body, array $headers, int $timeout): array
    {
        $curl = curl_init($this->url . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        return ['status' => $responseBody === false ? 0 : $status, 'body' => (string) $responseBody];
    }
}

final class MatomoError extends \RuntimeException
{
}
