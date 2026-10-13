<?php

namespace AyeCode\FlarumRestrictions\Sso;

use AyeCode\FlarumRestrictions\Access\Entitlements;
use AyeCode\FlarumRestrictions\Settings;
use Flarum\Http\RememberAccessToken;
use Flarum\Http\Rememberer;
use Flarum\Http\SessionAuthenticator;
use Flarum\Http\UrlGenerator;
use Flarum\User\Event\LoggedIn;
use Flarum\User\LoginProvider;
use Flarum\User\User;
use GuzzleHttp\Client;
use Illuminate\Contracts\Events\Dispatcher;
use Laminas\Diactoros\Response\HtmlResponse;
use Laminas\Diactoros\Response\RedirectResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;

/**
 * "Log in with AyeCode": OAuth 2 authorization code flow with PKCE against the store (plugin ayecode-sso).
 *
 *   GET /auth/ayecode?return=<forum url>   start: state + PKCE verifier into the session, off to the store
 *   GET /auth/ayecode?code=..&state=..     back from the store: swap the code, read the user, log them in
 *
 * Who is who: the store user id is the WordPress user id the old forum login (flarum-wp-users) already recorded,
 * so an existing forum account is found by that id first, then by confirmed email; anyone else gets a new
 * account. Forum passwords stay empty, so the store is the only way in.
 */
class LoginController implements RequestHandlerInterface
{
    const PROVIDER = 'ayecode';
    const LEGACY_PROVIDER = 'alexanderomara-wp-users';
    const SESSION_KEY = 'ayecode_sso';
    const MAX_AGE = 900;

    protected $settings;
    protected $url;
    protected $authenticator;
    protected $rememberer;
    protected $events;
    protected $log;
    protected $http;

    public function __construct(Settings $settings, UrlGenerator $url, SessionAuthenticator $authenticator, Rememberer $rememberer, Dispatcher $events, LoggerInterface $log, Client $http = null)
    {
        $this->settings = $settings;
        $this->url = $url;
        $this->authenticator = $authenticator;
        $this->rememberer = $rememberer;
        $this->events = $events;
        $this->log = $log;
        $this->http = $http ?: new Client();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (! $this->settings->ssoReady()) {
            return $this->fail('Log in with AyeCode is not configured yet.', 503);
        }
        $session = $request->getAttribute('session');
        $q = $request->getQueryParams();

        if (! isset($q['code']) && ! isset($q['error'])) {
            return $this->start($session, (string) ($q['return'] ?? ''));
        }

        $saved = $session->get(self::SESSION_KEY);
        $session->remove(self::SESSION_KEY);
        if (! is_array($saved) || ! is_string($q['state'] ?? null) || ! hash_equals((string) $saved['state'], $q['state']) || time() - (int) $saved['at'] > self::MAX_AGE) {
            return $this->fail('That login link has expired or was not started here. Please try again.');
        }
        if (isset($q['error'])) {
            return $this->fail('The AyeCode account login did not complete. Please try again.');
        }

        $info = $this->fetchUser((string) $q['code'], (string) $saved['verifier']);
        if (! $info) {
            return $this->fail('Could not reach your AyeCode account. Please try again in a minute.');
        }

        $user = $this->findOrCreate($info);
        if (is_string($user)) {
            return $this->fail($user);
        }

        $token = RememberAccessToken::generate($user->id);
        $this->authenticator->logIn($session, $token);
        $this->events->dispatch(new LoggedIn($user, $token));
        resolve(Entitlements::class)->forget($user); // a fresh login sees a licence bought a minute ago

        return $this->rememberer->remember(new RedirectResponse($saved['return']), $token);
    }

    protected function base(): string
    {
        return rtrim($this->url->to('forum')->base(), '/');
    }

    protected function redirectUri(): string
    {
        return $this->url->to('forum')->route('ayecode.sso');
    }

    /** Only ever send people back into this forum. */
    protected function safeReturn(string $return): string
    {
        $base = $this->base();
        if ($return === $base || strpos($return, $base . '/') === 0 || strpos($return, $base . '?') === 0) {
            return $return;
        }
        return $base . '/';
    }

    protected static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    protected function start($session, string $return): ResponseInterface
    {
        $state = self::b64url(random_bytes(24));
        $verifier = self::b64url(random_bytes(48));
        $session->put(self::SESSION_KEY, [
            'state' => $state,
            'verifier' => $verifier,
            'return' => $this->safeReturn($return),
            'at' => time(),
        ]);

        return new RedirectResponse($this->settings->url('sso_url') . '/oauth/authorize?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->settings->get('sso_client_id'),
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
            'code_challenge' => self::b64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986));
    }

    /** Code -> access token -> userinfo. Null on any failure. */
    protected function fetchUser(string $code, string $verifier): ?array
    {
        $api = $this->settings->url('sso_url') . '/wp-json/ayecode-sso/v1';
        $opts = ['timeout' => 10, 'connect_timeout' => 5, 'http_errors' => false, 'allow_redirects' => false];
        try {
            $res = $this->http->post($api . '/token', $opts + [
                'form_params' => [
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->redirectUri(),
                    'code_verifier' => $verifier,
                    'client_id' => $this->settings->get('sso_client_id'),
                    'client_secret' => $this->settings->get('sso_client_secret'),
                ],
            ]);
            $tok = json_decode((string) $res->getBody(), true);
            if ($res->getStatusCode() !== 200 || empty($tok['access_token'])) {
                $this->log->warning('[ayecode/flarum-restrictions] SSO token exchange failed: ' . $res->getStatusCode() . ' ' . ($tok['error'] ?? ''));
                return null;
            }
            $res = $this->http->get($api . '/userinfo', $opts + [
                'headers' => ['Authorization' => 'Bearer ' . $tok['access_token'], 'Accept' => 'application/json'],
            ]);
            $info = json_decode((string) $res->getBody(), true);
        } catch (\Throwable $e) {
            $this->log->warning('[ayecode/flarum-restrictions] SSO request failed: ' . $e->getMessage());
            return null;
        }
        if ($res->getStatusCode() !== 200 || ! is_array($info)
            || ! isset($info['sub']) || ! ctype_digit((string) $info['sub'])
            || empty($info['email']) || ! filter_var($info['email'], FILTER_VALIDATE_EMAIL)
            || ($info['email_verified'] ?? false) !== true) {
            $this->log->warning('[ayecode/flarum-restrictions] SSO userinfo unusable: ' . $res->getStatusCode());
            return null;
        }
        return $info;
    }

    /** @return User|string the user, or a message for the person trying to log in */
    protected function findOrCreate(array $info)
    {
        $sub = (string) $info['sub'];
        $email = trim((string) $info['email']);

        // 1. logged in this way before
        $user = LoginProvider::logIn(self::PROVIDER, $sub);

        // 2. the old WordPress login knew this store user by the same id
        if (! $user && ($legacy = LoginProvider::where('provider', self::LEGACY_PROVIDER)->where('identifier', $sub)->first())) {
            $user = $legacy->user;
            if ($user) {
                $this->link($user, $sub);
            }
        }

        // 3. same email, only if that forum account confirmed it and is not tied to a different store user
        if (! $user && ($match = User::where('email', $email)->first())) {
            if (! $match->is_email_confirmed) {
                return 'A forum account with your email address exists but its email was never confirmed. Please contact support.';
            }
            $other = $match->loginProviders()->whereIn('provider', [self::PROVIDER, self::LEGACY_PROVIDER])->where('identifier', '!=', $sub)->exists();
            if ($other) {
                return 'Your email address belongs to a different forum account. Please contact support.';
            }
            $user = $match;
            $this->link($user, $sub);
        }

        // 4. new to the forum
        if (! $user) {
            $user = User::register($this->uniqueUsername((string) ($info['preferred_username'] ?? ''), $email), $email, '');
            $user->activate();
            $user->save();
            foreach ($user->releaseEvents() as $event) {
                $this->events->dispatch($event);
            }
            $this->link($user, $sub);
            return $user;
        }

        // The store is the source of truth for the email (the old login synced it the same way).
        if (strcasecmp($user->email, $email) !== 0 && ! User::where('email', $email)->where('id', '!=', $user->id)->exists()) {
            $user->email = $email;
            $user->is_email_confirmed = true;
            $user->save();
        }

        return $user;
    }

    protected function link(User $user, string $sub): void
    {
        if (! $user->loginProviders()->where('provider', self::PROVIDER)->exists()) {
            $user->loginProviders()->create(['provider' => self::PROVIDER, 'identifier' => $sub]);
        }
    }

    /** Flarum usernames: 3 to 30 of a-z 0-9 _ -, unique. */
    protected function uniqueUsername(string $wanted, string $email): string
    {
        $base = preg_replace('/[^a-z0-9_-]+/i', '-', $wanted);
        $base = trim((string) $base, '-');
        if (strlen($base) < 3) {
            $base = trim((string) preg_replace('/[^a-z0-9_-]+/i', '-', strstr($email, '@', true)), '-');
        }
        if (strlen($base) < 3) {
            $base = 'member';
        }
        $base = substr($base, 0, 24);
        $name = $base;
        for ($i = 2; User::where('username', $name)->exists(); $i++) {
            $name = $base . '-' . ($i < 100 ? $i : bin2hex(random_bytes(2)));
        }
        return $name;
    }

    protected function fail(string $message, int $status = 400): ResponseInterface
    {
        $home = htmlspecialchars($this->base() . '/', ENT_QUOTES);
        $login = htmlspecialchars($this->url->to('forum')->route('ayecode.sso'), ENT_QUOTES);
        $msg = htmlspecialchars($message, ENT_QUOTES);
        return new HtmlResponse(
            '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>Log in</title>'
            . '<style>:root{--bg:#f6f7f9;--text:#1d2327;--link:#2563eb}'
            . '@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--bg:#111418;--text:#e8eaed;--link:#60a5fa}}'
            . ':root[data-theme="dark"]{--bg:#111418;--text:#e8eaed;--link:#60a5fa}'
            . 'body{margin:0;background:var(--bg);color:var(--text);font:16px/1.5 system-ui,sans-serif}main{max-width:32rem;margin:15vh auto;padding:0 16px}a{color:var(--link)}</style></head>'
            . "<body><main><p>$msg</p><p><a href=\"$login\">Try again</a> &middot; <a href=\"$home\">Back to the forum</a></p></main></body></html>",
            $status
        );
    }
}
