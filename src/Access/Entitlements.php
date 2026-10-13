<?php

namespace AyeCode\FlarumRestrictions\Access;

use AyeCode\FlarumRestrictions\Settings;
use Flarum\User\User;
use GuzzleHttp\Client;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\Log\LoggerInterface;

/**
 * A user's paid status from the AyeCode API: GET {api_base}/v1/entitlements?email=... with the server token.
 *
 * Answer: {user_id, found, brands: {geodirectory: {active, membership, expires_at, products: [ids]}, ...}}.
 * Cached per email for 10 minutes (Flarum cache) and once per request in memory. If the API cannot be reached the
 * user gets no paid access (fail closed) but the page still loads: short timeouts, and after a failure every lookup
 * skips the API for a minute instead of waiting on it again.
 */
class Entitlements
{
    const TTL = 600;
    const DOWN_TTL = 60;
    const TIMEOUT = 3;
    const CONNECT_TIMEOUT = 2;
    const DOWN_KEY = 'ayecode_entitlements_down';

    /** @var array<string, array|null> */
    protected static $memo = [];

    protected $cache;
    protected $settings;
    protected $log;
    protected $http;

    public function __construct(Cache $cache, Settings $settings, LoggerInterface $log, Client $http = null)
    {
        $this->cache = $cache;
        $this->settings = $settings;
        $this->log = $log;
        $this->http = $http ?: new Client();
    }

    public static function cacheKey(string $email): string
    {
        return 'ayecode_entitlements_' . sha1(strtolower(trim($email)));
    }

    /** Forget what we know about this user, e.g. right after they log in. */
    public function forget(User $user): void
    {
        $email = strtolower(trim((string) $user->email));
        unset(self::$memo[$email]);
        $this->cache->forget(self::cacheKey($email));
    }

    /**
     * Brands map for the user, [] for no paid access, null when unknown (API down or not configured).
     * Only confirmed emails are looked up: the API matches by email, so an unconfirmed address proves nothing.
     */
    public function brands(?User $user): ?array
    {
        if (! $user || $user->isGuest() || ! $user->is_email_confirmed || ! $user->email) {
            return [];
        }
        $email = strtolower(trim((string) $user->email));
        if (array_key_exists($email, self::$memo)) {
            return self::$memo[$email];
        }
        $key = self::cacheKey($email);
        $cached = $this->cache->get($key);
        if (is_array($cached)) {
            return self::$memo[$email] = $cached;
        }
        if ($this->cache->get(self::DOWN_KEY)) {
            return self::$memo[$email] = null;
        }

        $brands = $this->fetch($email);
        if ($brands === null) {
            $this->cache->put(self::DOWN_KEY, 1, self::DOWN_TTL);
        } else {
            $this->cache->put($key, $brands, self::TTL);
        }
        return self::$memo[$email] = $brands;
    }

    protected function fetch(string $email): ?array
    {
        $token = $this->settings->get('entitlements_token');
        if ($token === '') {
            $this->log->warning('[ayecode/flarum-restrictions] entitlements_token is not set; paid areas are closed');
            return null;
        }
        try {
            $res = $this->http->get($this->settings->url('api_base') . '/v1/entitlements', [
                'query' => ['email' => $email],
                'headers' => ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json'],
                'timeout' => self::TIMEOUT,
                'connect_timeout' => self::CONNECT_TIMEOUT,
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        } catch (\Throwable $e) {
            $this->log->warning('[ayecode/flarum-restrictions] entitlements API unreachable: ' . $e->getMessage());
            return null;
        }
        $status = $res->getStatusCode();
        $body = json_decode((string) $res->getBody(), true);
        if ($status === 404 && is_array($body) && isset($body['found']) && $body['found'] === false) {
            return []; // no account on the store: no paid access, and that is a real answer worth caching
        }
        if ($status !== 200 || ! is_array($body) || ! isset($body['brands']) || ! is_array($body['brands'])) {
            $this->log->warning("[ayecode/flarum-restrictions] entitlements API answered $status");
            return null;
        }
        return $body['brands'];
    }

    /** Reset the per request memo (tests, queue workers). */
    public static function flushMemo(): void
    {
        self::$memo = [];
    }
}
