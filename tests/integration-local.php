<?php
/*
 * Local end to end checks: paid access from the AyeCode API and Log in with AyeCode. Synthetic users only.
 *
 * Needs: a throwaway Flarum 1.8 with flarum-tags and this extension enabled, served at FLARUM_URL, with config.php
 *   'ayecode' => [api_base => <dev Worker>, entitlements_token => <its dev token>, sso_url => <WP_URL>,
 *                 sso_client_id => 'forum', sso_client_secret => <the client secret in AYECODE_SSO_CLIENTS>]
 * the dev Worker (ayecode-platform: bash api-worker/test/dev.sh restart), and a local WordPress at WP_PATH/WP_URL
 * with the ayecode-sso plugin and that client (redirect URI FLARUM_URL/auth/ayecode).
 *
 *   FLARUM_DIR=... FLARUM_URL=http://127.0.0.1:8890 WP_PATH=... WP_URL=http://localhost/v2 php tests/integration-local.php
 */

use AyeCode\FlarumRestrictions\Access\Entitlements;
use AyeCode\FlarumRestrictions\Access\LicenseChecker;
use AyeCode\FlarumRestrictions\Settings;
use Flarum\Foundation\Config;
use Flarum\Api\ApiKey;
use Flarum\Tags\Tag;
use Flarum\User\LoginProvider;
use Flarum\User\User;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

$FLARUM_DIR = getenv('FLARUM_DIR') ?: die("FLARUM_DIR?\n");
$FLARUM_URL = rtrim(getenv('FLARUM_URL') ?: 'http://127.0.0.1:8890', '/');
$WP_PATH = getenv('WP_PATH') ?: '';
$WP_URL = rtrim(getenv('WP_URL') ?: 'http://localhost/v2', '/');
$WORKER = getenv('WORKER') ?: 'http://127.0.0.1:8787';
$SYNC = getenv('STORE_SYNC_TOKEN') ?: 'dev-store-sync-token';

$site = require $FLARUM_DIR . '/site.php';
$app = $site->bootApp();
$container = $app->getContainer();
\Illuminate\Container\Container::setInstance($container);

$pass = 0; $fail = 0;
function chk($n, $ok, $info = '') { global $pass, $fail; echo ($ok ? 'ok   ' : 'FAIL ') . $n . ($info !== '' ? "  $info" : '') . "\n"; $ok ? $pass++ : $fail++; }
function http($method, $url, $opts = []) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30]);
    if (! empty($opts['headers'])) curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['headers']);
    if (isset($opts['body'])) curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
    if (! empty($opts['jar'])) { curl_setopt($ch, CURLOPT_COOKIEJAR, $opts['jar']); curl_setopt($ch, CURLOPT_COOKIEFILE, $opts['jar']); }
    if (! empty($opts['cookie'])) curl_setopt($ch, CURLOPT_COOKIE, $opts['cookie']);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr((string) $raw, 0, $hs);
    preg_match('/^location:\s*(\S+)/mi', $head, $m);
    return [$code, substr((string) $raw, $hs), $m[1] ?? '', $head];
}
function sync($ops) { global $WORKER, $SYNC; return http('POST', "$WORKER/v1/sync", ['headers' => ['content-type: application/json', "authorization: Bearer $SYNC"], 'body' => json_encode(['source' => 'getpaid', 'ops' => $ops])]); }

/* ------------------------------------------------------------------------------------------------------------ *
 * 1. Paid access from /v1/entitlements
 * ------------------------------------------------------------------------------------------------------------ */
$future = time() + 86400 * 365; $past = time() - 86400;
$U = [ // api user id => [email, product, expires]
    9900001 => ['ent-member@example.test', 66235, $future],   // GeoDirectory membership
    9900002 => ['ent-lm@example.test', 65853, $future],       // Location Manager only
    9900003 => ['ent-expired@example.test', 65853, $past],    // expired Location Manager
    9900004 => ['ent-none@example.test', null, null],         // store account, no licences
    9900005 => ['ent-unconfirmed@example.test', 66235, $future], // membership, but the forum email is unconfirmed
];
$ops = [];
foreach ($U as $id => [$email, $pid, $exp]) {
    $ops[] = ['entity' => 'user', 'op' => 'upsert', 'data' => ['id' => $id, 'login' => "ent$id", 'email' => $email, 'display_name' => "Ent $id"]];
    if ($pid) $ops[] = ['entity' => 'licence', 'op' => 'upsert', 'data' => ['key' => "FORUMTEST-$id", 'user_id' => $id, 'product_id' => $pid, 'status' => 'active', 'expires_at' => $exp, 'activation_limit' => 1, 'source' => 'getpaid']];
}
[$c, $b] = sync($ops);
chk('synthetic users and licences synced to the dev Worker', $c === 200 && (json_decode($b, true)['ok'] ?? false), "$c");

[$c, $b] = http('GET', "$WORKER/v1/entitlements?email=ent-member@example.test", ['headers' => ['authorization: Bearer nope']]);
chk('Worker refuses a wrong token', $c === 401);
$cfg = $container->make(Config::class);
$cfgArr = require $FLARUM_DIR . '/config.php';
[$c, $b] = http('GET', "$WORKER/v1/entitlements?email=ENT-MEMBER@example.test", ['headers' => ['authorization: Bearer ' . $cfg['ayecode.entitlements_token']]]);
$j = json_decode($b, true);
chk('Worker answers by email (case-insensitive) with brand, membership and products', $c === 200 && ($j['brands']['geodirectory']['membership'] ?? null) === true && in_array(66235, $j['brands']['geodirectory']['products'] ?? []), $b);
chk('Worker reports the expiry date of the active licence', ($j['brands']['geodirectory']['expires_at'] ?? null) === $future, 'expires_at=' . json_encode($j['brands']['geodirectory']['expires_at'] ?? 'missing'));

$tags = [];
foreach (['general', 'location-manager', 'pricing-manager'] as $i => $slug) {
    $tags[$slug] = Tag::where('slug', $slug)->first() ?: Tag::build(ucwords(str_replace('-', ' ', $slug)), $slug, '', '#888', null, false);
    $tags[$slug]->position = $i; $tags[$slug]->save();
}
$fu = [];
foreach ($U as $id => [$email]) {
    $fu[$id] = User::where('email', $email)->first() ?: User::register("ent$id", $email, Str::random(20));
    $fu[$id]->is_email_confirmed = $id !== 9900005;
    $fu[$id]->save();
}
$unknown = User::where('email', 'ent-unknown@example.test')->first() ?: User::register('entunknown', 'ent-unknown@example.test', Str::random(20));
$unknown->is_email_confirmed = true; $unknown->save();

$cache = $container->make(Cache::class);
foreach (array_merge($fu, [$unknown]) as $u) $cache->forget(Entitlements::cacheKey($u->email));
$cache->forget(Entitlements::DOWN_KEY);
Entitlements::flushMemo();
$chk = new LicenseChecker();
$can = function (User $u, $slug) use ($chk) { return $chk->can_access($u, $slug); };

chk('membership: every paid area', $can($fu[9900001], 'general') && $can($fu[9900001], 'location-manager') && $can($fu[9900001], 'pricing-manager'));
chk('Location Manager licence: general and its own area, not another add-on', $can($fu[9900002], 'general') && $can($fu[9900002], 'location-manager') && ! $can($fu[9900002], 'pricing-manager'));
chk('expired licence: nothing', ! $can($fu[9900003], 'general') && ! $can($fu[9900003], 'location-manager'));
chk('no licences: nothing', ! $can($fu[9900004], 'general'));
chk('unconfirmed forum email: nothing, and the API is not asked', ! $can($fu[9900005], 'general') && $cache->get(Entitlements::cacheKey('ent-unconfirmed@example.test')) === null);
chk('not on the store: nothing, answer cached as empty', ! $can($unknown, 'general') && $cache->get(Entitlements::cacheKey('ent-unknown@example.test')) === []);
chk('guest: nothing', ! $chk->can_access(new \Flarum\User\Guest(), 'general'));
chk('admin: everything', $chk->can_access(User::find(1), 'pricing-manager'));
chk('cookie is ignored (a forged one gives nothing)', (function () use ($can, $fu) { $_COOKIE['ayecode_licenses'] = json_encode(['products' => ['66235']]); $r = $can($fu[9900004], 'general'); unset($_COOKIE['ayecode_licenses']); return ! $r; })());
chk('answer cached for 10 minutes per email', is_array($cache->get(Entitlements::cacheKey('ent-lm@example.test'))));

// The cache holds until it expires or the user logs in again.
sync([['entity' => 'licence', 'op' => 'upsert', 'data' => ['key' => 'FORUMTEST-9900002', 'user_id' => 9900002, 'product_id' => 65853, 'status' => 'active', 'expires_at' => $past, 'activation_limit' => 1, 'source' => 'getpaid']]]);
Entitlements::flushMemo();
chk('cached answer used until it expires', $can($fu[9900002], 'location-manager'));
resolve(Entitlements::class)->forget($fu[9900002]);
chk('after forget (login), the new state is read', ! $can($fu[9900002], 'location-manager'));

// API down: fail closed, quickly, and stop asking for a minute.
$down = new Entitlements($cache, new Settings($container->make(\Flarum\Settings\SettingsRepositoryInterface::class), new Config(array_replace_recursive($cfgArr, ['ayecode' => ['api_base' => 'http://10.255.255.1:81']]))), $container->make(\Psr\Log\LoggerInterface::class));
$cache->forget(Entitlements::cacheKey('ent-member@example.test')); Entitlements::flushMemo();
$t = microtime(true); $r = $down->brands($fu[9900001]); $t1 = microtime(true) - $t;
chk('API unreachable: unknown (null), within the timeout', $r === null && $t1 < 4, sprintf('%.2fs', $t1));
$cache->forget(Entitlements::cacheKey('ent-lm@example.test')); Entitlements::flushMemo();
$t = microtime(true); $r = $down->brands($fu[9900002]); $t2 = microtime(true) - $t;
chk('after a failure the API is skipped for a minute (no second wait)', $r === null && $t2 < 0.2, sprintf('%.3fs', $t2));
Entitlements::flushMemo();
chk('while the API is down a paid member is shut out (fail closed)', ! $can($fu[9900001], 'general'));
$cache->forget(Entitlements::DOWN_KEY); Entitlements::flushMemo();

$bad = new Entitlements($cache, new Settings($container->make(\Flarum\Settings\SettingsRepositoryInterface::class), new Config(array_replace_recursive($cfgArr, ['ayecode' => ['entitlements_token' => 'wrong']]))), $container->make(\Psr\Log\LoggerInterface::class));
chk('wrong token: unknown (null), not cached as an answer', $bad->brands($fu[9900001]) === null && $cache->get(Entitlements::cacheKey('ent-member@example.test')) === null);
$cache->forget(Entitlements::DOWN_KEY); Entitlements::flushMemo();

// Through the forum's own API, as the browser sees it.
$key = new ApiKey(); $key->key = Str::random(40); $key->save();
$api = function ($path, $uid) use ($FLARUM_URL, $key) { [$c, $b] = http('GET', "$FLARUM_URL/api$path", ['headers' => ["Authorization: Token {$key->key}; userId=$uid"]]); return json_decode($b, true); };
$tagsAs = function ($uid) use ($api) { $o = []; foreach ($api('/tags', $uid)['data'] ?? [] as $t) $o[$t['attributes']['slug']] = $t['attributes']['canStartDiscussion']; return $o; };
$cache->forget(Entitlements::cacheKey('ent-member@example.test'));
$t = $tagsAs($fu[9900001]->id);
chk('API /tags as a member: can start discussions everywhere', ($t['general'] ?? null) === true && ($t['pricing-manager'] ?? null) === true, json_encode($t));
$t = $tagsAs($fu[9900004]->id);
chk('API /tags as a user without licences: cannot start discussions', ($t['general'] ?? null) === false && ($t['location-manager'] ?? null) === false, json_encode($t));
$d = \Flarum\Discussion\Discussion::where('title', 'Entitlements test')->first();
if (! $d) {
    $d = \Flarum\Discussion\Discussion::start('Entitlements test', User::find(1)); $d->save();
    $p = \Flarum\Post\CommentPost::reply($d->id, 'First post', 1, '127.0.0.1'); $p->save();
    $d->setFirstPost($p); $d->setLastPost($p); $d->save();
    $d->tags()->sync([$tags['location-manager']->id]);
}
$canReply = function ($uid) use ($api, $d) { return $api('/discussions/' . $d->id, $uid)['data']['attributes']['canReply'] ?? null; };
chk('API discussion in Location Manager: member can reply', $canReply($fu[9900001]->id) === true);
chk('API discussion in Location Manager: expired licence cannot', $canReply($fu[9900003]->id) === false);
$key->delete();

/* ------------------------------------------------------------------------------------------------------------ *
 * 2. Log in with AyeCode (needs the local WordPress)
 * ------------------------------------------------------------------------------------------------------------ */
if (! $WP_PATH) {
    echo "WP_PATH not set: SSO checks skipped\n";
} else {
    $wp = function ($cmd) use ($WP_PATH) { return trim((string) shell_exec('wp --path=' . escapeshellarg($WP_PATH) . ' ' . $cmd . ' 2>/dev/null')); };
    $wpUser = function ($login, $email) use ($wp) {
        $id = (int) $wp('user get ' . escapeshellarg($login) . ' --field=ID');
        if (! $id) $id = (int) $wp('user create ' . escapeshellarg($login) . ' ' . escapeshellarg($email) . ' --porcelain');
        else $wp("user update $id --user_email=" . escapeshellarg($email) . ' --skip-email');
        return $id;
    };
    $cookieFor = function ($id) use ($wp) { return $wp('eval ' . escapeshellarg("echo LOGGED_IN_COOKIE . '=' . rawurlencode( wp_generate_auth_cookie( $id, time() + 600, 'logged_in' ) );")); };
    $jar = sys_get_temp_dir() . '/ayecode-sso-test-' . getmypid() . '.jar';
    // One full browser round trip: forum -> store (logged in) -> forum. Returns [final code, final location, body, head].
    $login = function ($wpId, $return = null) use ($FLARUM_URL, $cookieFor, $jar) {
        @unlink($jar);
        [$c1, , $loc1] = http('GET', "$FLARUM_URL/auth/ayecode?return=" . rawurlencode($return ?? "$FLARUM_URL/t/general"), ['jar' => $jar]);
        [$c2, , $loc2] = http('GET', $loc1, ['cookie' => $cookieFor($wpId)]);
        $r = http('GET', $loc2, ['jar' => $jar]);
        return [$c1, $loc1, $c2, $loc2, $r];
    };
    $sessionUser = function () use ($FLARUM_URL, $jar) { [, $b] = http('GET', "$FLARUM_URL/", ['jar' => $jar]); return preg_match('/"session":\{"userId":(\d+)/', $b, $m) ? (int) $m[1] : 0; };

    // a. old forum account known to flarum-wp-users by WordPress id; the store email has changed since
    $aId = $wpUser('sso-legacy', 'sso-legacy-new@example.test');
    $a = User::where('username', 'ssolegacy')->first() ?: User::register('ssolegacy', 'sso-legacy-old@example.test', '');
    $a->is_email_confirmed = true; $a->save();
    LoginProvider::where('user_id', $a->id)->delete();
    $a->loginProviders()->create(['provider' => 'alexanderomara-wp-users', 'identifier' => (string) $aId]);
    [$c1, $loc1, $c2, $loc2, [$c3, , $loc3, $head3]] = $login($aId);
    chk('start: forum sends the browser to the store authorize URL with PKCE', $c1 === 302 && strpos($loc1, "$WP_URL/oauth/authorize?") === 0 && strpos($loc1, 'code_challenge_method=S256') !== false, $loc1);
    chk('store: logged-in customer comes straight back with a code', $c2 === 302 && strpos($loc2, "$FLARUM_URL/auth/ayecode?code=") === 0, $loc2);
    chk('callback: logged in, remember cookie set, sent to the return URL', $c3 === 302 && $loc3 === "$FLARUM_URL/t/general" && stripos($head3, 'flarum_remember=') !== false, "$c3 $loc3");
    $a->refresh();
    chk('a. matched the old account by WordPress user id', $sessionUser() === $a->id, 'session user ' . $sessionUser());
    chk('a. email updated from the store', $a->email === 'sso-legacy-new@example.test', $a->email);
    chk('a. ayecode login provider recorded', LoginProvider::where('provider', 'ayecode')->where('identifier', (string) $aId)->value('user_id') == $a->id);
    [, , , , [$c3b, , $loc3b]] = $login($aId);
    chk('a. second login uses the ayecode provider row', $c3b === 302 && $sessionUser() === $a->id);

    // b. forum account with the same confirmed email, no provider rows
    $bId = $wpUser('sso-email', 'sso-email@example.test');
    $b = User::where('email', 'sso-email@example.test')->first() ?: User::register('ssoemail', 'sso-email@example.test', '');
    $b->is_email_confirmed = true; $b->save(); LoginProvider::where('user_id', $b->id)->delete();
    $login($bId);
    chk('b. matched an existing account by confirmed email', $sessionUser() === $b->id);

    // c. new to the forum
    $cId = $wpUser('sso.new user', 'sso-new@example.test');
    User::where('email', 'sso-new@example.test')->delete();
    $login($cId);
    $nu = User::where('email', 'sso-new@example.test')->first();
    chk('c. new account created, email confirmed, no usable password', $nu && $sessionUser() === $nu->id && $nu->is_email_confirmed && $nu->password === '', $nu ? $nu->username : 'none');
    chk('c. username made Flarum-safe from the store login', $nu && preg_match('/^[a-z0-9_-]{3,30}$/i', $nu->username), $nu ? $nu->username : '');

    // d. a forum account holds the email but never confirmed it
    $dId = $wpUser('sso-unconf', 'sso-unconf@example.test');
    $d = User::where('email', 'sso-unconf@example.test')->first() ?: User::register('ssounconf', 'sso-unconf@example.test', Str::random(20));
    $d->is_email_confirmed = false; $d->save(); LoginProvider::where('user_id', $d->id)->delete();
    [, , , , [$c3, $body3]] = $login($dId);
    chk('d. unconfirmed email collision refused, nobody logged in', $c3 === 400 && strpos($body3, 'never confirmed') !== false && $sessionUser() === 0);

    // e. tampered state, replayed callback, missing session
    @unlink($jar);
    [, , $loc1] = http('GET', "$FLARUM_URL/auth/ayecode", ['jar' => $jar]);
    [, , $loc2] = http('GET', $loc1, ['cookie' => $cookieFor($bId)]);
    [$c, $body] = http('GET', preg_replace('/state=[^&]+/', 'state=forged-state-value', $loc2), ['jar' => $jar]);
    chk('e. wrong state refused', $c === 400 && $sessionUser() === 0);
    [$c] = http('GET', $loc2, ['jar' => $jar]);
    chk('e. state is single use (the failed attempt consumed it)', $c === 400 && $sessionUser() === 0);
    [, , , $loc2, [$c]] = $login($bId);
    [$c] = http('GET', $loc2, ['jar' => sys_get_temp_dir() . '/ayecode-sso-other-' . getmypid() . '.jar']);
    chk('e. a callback from another browser session is refused', $c === 400);

    // f. open redirect
    [, , , , [$c3, , $loc3]] = $login($bId, 'https://evil.example/steal');
    chk('f. return URL outside the forum ignored', $c3 === 302 && $loc3 === "$FLARUM_URL/", $loc3);

    // g. store refuses (e.g. user cancelled): back with error
    @unlink($jar);
    [, , $loc1] = http('GET', "$FLARUM_URL/auth/ayecode", ['jar' => $jar]);
    parse_str(parse_url($loc1, PHP_URL_QUERY), $q);
    [$c] = http('GET', "$FLARUM_URL/auth/ayecode?error=access_denied&state=" . rawurlencode($q['state']), ['jar' => $jar]);
    chk('g. provider error shows a message, nobody logged in', $c === 400 && $sessionUser() === 0);

    @unlink($jar);
    // Logging in clears the cached answer so a licence bought a minute ago counts.
    $cache->put(Entitlements::cacheKey('sso-email@example.test'), ['stale' => true], 600);
    $login($bId);
    chk('login clears the cached entitlements for that user', $cache->get(Entitlements::cacheKey('sso-email@example.test')) === null);
    @unlink($jar);
}

// Clean up the synthetic API rows.
$del = [];
foreach ($U as $id => [$email, $pid]) {
    if ($pid) $del[] = ['entity' => 'licence', 'op' => 'delete', 'data' => ['key' => "FORUMTEST-$id"]];
    $del[] = ['entity' => 'user', 'op' => 'delete', 'data' => ['id' => $id]];
}
sync($del);

echo "$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
