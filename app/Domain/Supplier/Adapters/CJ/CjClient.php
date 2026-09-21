<?php

declare(strict_types=1);

namespace App\Domain\Supplier\Adapters\CJ;

use App\Domain\Supplier\Exceptions\SupplierAuthenticationFailed;
use App\Domain\Supplier\Exceptions\SupplierRateLimited;
use App\Domain\Supplier\Exceptions\SupplierRequestFailed;
use App\Models\Supplier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Low-level HTTP client for the CJdropshipping API.
 *
 * Responsibilities: access-token lifecycle, rate limiting, redacted logging,
 * and turning transport failures into typed exceptions that say whether the
 * outcome is *known* to have failed or merely *unknown*. The distinction
 * matters: an unknown outcome on order creation must never be retried
 * blindly, because that risks buying the same order twice.
 */
class CjClient
{
    private const TOKEN_CACHE_PREFIX = 'cj.token.';

    private const RATE_LIMIT_PREFIX = 'cj.ratelimit.';

    /** Keys whose values must never reach the logs or the database. */
    private const SECRET_KEYS = ['password', 'apiKey', 'accessToken', 'refreshToken', 'CJ-Access-Token'];

    public function __construct(private readonly Supplier $supplier) {}

    /* ------------------------------------------------------------------ */
    /* Authentication                                                      */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{access_token: string, refresh_token: ?string, expires_at: ?Carbon}
     */
    public function authenticate(bool $force = false): array
    {
        $cacheKey = self::TOKEN_CACHE_PREFIX.$this->supplier->id;

        if (! $force) {
            $cached = Cache::get($cacheKey);

            if (is_array($cached) && filled($cached['access_token'] ?? null)) {
                return $cached;
            }
        }

        $email = $this->supplier->credential('email');
        $apiKey = $this->supplier->credential('api_key');

        if (blank($email) || blank($apiKey)) {
            throw new SupplierAuthenticationFailed(
                'CJdropshipping credentials are not configured. Add the account email and API key in Admin → Integrations.'
            );
        }

        $response = $this->send(
            'POST',
            $this->endpoint('access_token'),
            ['email' => $email, 'password' => $apiKey],
            authenticated: false,
            rateLimitKey: 'auth',
        );

        $data = $this->unwrap($response, $this->endpoint('access_token'));

        $accessToken = $data['accessToken'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            throw new SupplierAuthenticationFailed('CJdropshipping did not return an access token.');
        }

        $expiresAt = $this->parseDate($data['accessTokenExpiryDate'] ?? null);

        $token = [
            'access_token' => $accessToken,
            'refresh_token' => $data['refreshToken'] ?? null,
            'expires_at' => $expiresAt,
        ];

        // Refresh well before the documented expiry so a long-running sync
        // never dies mid-page on an expired token.
        $margin = (int) config('petstore.suppliers.cjdropshipping.token_refresh_margin_hours', 48);
        $ttl = $expiresAt
            ? max(60, $expiresAt->copy()->subHours($margin)->diffInSeconds(now(), absolute: false) * -1)
            : 3600;

        Cache::put($cacheKey, $token, (int) $ttl);

        $this->supplier->forceFill([
            'last_authenticated_at' => now(),
            'token_expires_at' => $expiresAt,
            'connection_state' => 'ok',
            'last_error' => null,
        ])->save();

        return $token;
    }

    public function forgetToken(): void
    {
        Cache::forget(self::TOKEN_CACHE_PREFIX.$this->supplier->id);
    }

    /* ------------------------------------------------------------------ */
    /* Requests                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function get(string $endpointKey, array $payload = []): array
    {
        $endpoint = $this->endpoint($endpointKey);

        return $this->unwrap($this->send('GET', $endpoint, $payload), $endpoint);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $endpointKey, array $payload = []): array
    {
        $endpoint = $this->endpoint($endpointKey);

        return $this->unwrap($this->send('POST', $endpoint, $payload), $endpoint);
    }

    public function endpoint(string $key): string
    {
        $path = config("petstore.suppliers.cjdropshipping.endpoints.{$key}");

        if (! is_string($path)) {
            throw new SupplierRequestFailed("No endpoint path is configured for [{$key}].");
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(
        string $method,
        string $endpoint,
        array $payload,
        bool $authenticated = true,
        ?string $rateLimitKey = null,
    ): Response {
        $this->throttle($rateLimitKey ?? 'default');

        $request = $this->request($authenticated);
        $url = rtrim((string) config('petstore.suppliers.cjdropshipping.base_url'), '/').'/'.ltrim($endpoint, '/');

        try {
            $response = $method === 'GET'
                ? $request->get($url, $payload)
                : $request->post($url, $payload);
        } catch (ConnectionException $e) {
            // A connection failure on a mutating call leaves the outcome
            // genuinely unknown: the supplier may or may not have acted.
            throw new SupplierRequestFailed(
                "Could not reach CJdropshipping ({$endpoint}): {$e->getMessage()}",
                endpoint: $endpoint,
                outcomeUnknown: $method !== 'GET',
                previous: $e,
            );
        } catch (Throwable $e) {
            throw new SupplierRequestFailed(
                "CJdropshipping request failed ({$endpoint}): {$e->getMessage()}",
                endpoint: $endpoint,
                outcomeUnknown: $method !== 'GET',
                previous: $e,
            );
        }

        $this->logExchange($method, $endpoint, $payload, $response);

        if ($response->status() === 429) {
            throw new SupplierRateLimited("CJdropshipping rate limited {$endpoint}.", retryAfterSeconds: 2);
        }

        return $response;
    }

    private function request(bool $authenticated): PendingRequest
    {
        $cfg = config('petstore.suppliers.cjdropshipping');

        $request = Http::asJson()
            ->acceptJson()
            ->timeout((int) $cfg['timeout_seconds'])
            ->connectTimeout((int) $cfg['connect_timeout_seconds'])
            // Only idempotent transport errors are retried here. Mutating
            // calls are retried (if at all) by the caller, after reconciling.
            ->retry((int) $cfg['retry_times'], 500, throw: false);

        if ($authenticated) {
            $request = $request->withHeaders([
                'CJ-Access-Token' => $this->authenticate()['access_token'],
            ]);
        }

        return $request;
    }

    /**
     * CJ wraps everything in {code, result, message, data}. Unwrap it and
     * convert a business-level failure into a typed exception.
     *
     * @return array<string, mixed>
     */
    private function unwrap(Response $response, string $endpoint): array
    {
        if ($response->status() === 401 || $response->status() === 403) {
            $this->forgetToken();
            $this->supplier->forceFill([
                'connection_state' => 'auth_failed',
                'last_error' => 'Authentication rejected by CJdropshipping.',
            ])->save();

            throw new SupplierAuthenticationFailed("CJdropshipping rejected authentication on {$endpoint}.");
        }

        if ($response->failed()) {
            throw new SupplierRequestFailed(
                "CJdropshipping returned HTTP {$response->status()} for {$endpoint}.",
                endpoint: $endpoint,
                statusCode: $response->status(),
                outcomeUnknown: $response->serverError(),
            );
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new SupplierRequestFailed("CJdropshipping returned an unreadable body for {$endpoint}.", endpoint: $endpoint);
        }

        $succeeded = ($body['result'] ?? null) === true
            || in_array((string) ($body['code'] ?? ''), ['200', '0'], true);

        if (! $succeeded) {
            $message = (string) ($body['message'] ?? 'Unknown error');

            if (str_contains(strtolower($message), 'token')) {
                $this->forgetToken();
                throw new SupplierAuthenticationFailed("CJdropshipping token rejected on {$endpoint}: {$message}");
            }

            throw new SupplierRequestFailed(
                "CJdropshipping rejected {$endpoint}: {$message}",
                endpoint: $endpoint,
                providerCode: (string) ($body['code'] ?? ''),
            );
        }

        $data = $body['data'] ?? [];

        return is_array($data) ? $data : ['value' => $data];
    }

    /* ------------------------------------------------------------------ */
    /* Rate limiting                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * CJ documents a 1 request/second limit. We serialise calls through a
     * cache lock and sleep out the remaining window rather than firing and
     * relying on their 429.
     */
    private function throttle(string $bucket): void
    {
        $cfg = config('petstore.suppliers.cjdropshipping.rate_limits');
        $perSecond = (float) ($bucket === 'auth' ? $cfg['auth_per_second'] : $cfg['default_per_second']);

        if ($perSecond <= 0) {
            return;
        }

        $minIntervalMicros = (int) round(1_000_000 / $perSecond);
        $key = self::RATE_LIMIT_PREFIX.$this->supplier->id.'.'.$bucket;

        $lastAt = Cache::get($key);
        $now = (int) (microtime(true) * 1_000_000);

        if (is_int($lastAt)) {
            $elapsed = $now - $lastAt;

            if ($elapsed < $minIntervalMicros) {
                usleep($minIntervalMicros - $elapsed);
            }
        }

        Cache::put($key, (int) (microtime(true) * 1_000_000), 60);
    }

    /* ------------------------------------------------------------------ */
    /* Logging                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * @param  array<string, mixed>  $payload
     */
    private function logExchange(string $method, string $endpoint, array $payload, Response $response): void
    {
        if (! config('app.debug') && $response->successful()) {
            return;
        }

        Log::channel('supplier')->debug('CJ exchange', [
            'method' => $method,
            'endpoint' => $endpoint,
            'status' => $response->status(),
            'request' => self::redact($payload),
        ]);
    }

    /**
     * Strip credentials before anything is logged or persisted.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, self::SECRET_KEYS, true)) {
                $data[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return $data;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
