<?php

namespace AyeCode\FlarumRestrictions;

use Flarum\Foundation\Config;
use Flarum\Settings\SettingsRepositoryInterface;

/**
 * Where each value comes from, first match wins:
 *   1. Flarum's config.php:   'ayecode' => ['entitlements_token' => '...', 'sso_client_secret' => '...', ...]
 *   2. environment variable:  AYECODE_ENTITLEMENTS_TOKEN, AYECODE_SSO_CLIENT_SECRET, ...
 *   3. admin setting:         ayecode-restrictions.entitlements_token, ...
 *   4. the default below
 * Secrets never go in this repository.
 */
class Settings
{
    const PREFIX = 'ayecode-restrictions.';

    const DEFAULTS = [
        'api_base' => 'https://api.ayecode.io',
        'entitlements_token' => '',
        'sso_url' => 'https://account.wpgeodirectory.com',
        'sso_client_id' => 'forum',
        'sso_client_secret' => '',
    ];

    protected $settings;
    protected $config;

    public function __construct(SettingsRepositoryInterface $settings, Config $config)
    {
        $this->settings = $settings;
        $this->config = $config;
    }

    public function get(string $key): string
    {
        $v = $this->config['ayecode.' . $key] ?? null;
        if ($v === null || $v === '') {
            $v = getenv('AYECODE_' . strtoupper($key));
        }
        if ($v === false || $v === null || $v === '') {
            $v = $this->settings->get(self::PREFIX . $key);
        }
        if ($v === null || $v === '') {
            $v = self::DEFAULTS[$key] ?? '';
        }
        return trim((string) $v);
    }

    public function url(string $key): string
    {
        return rtrim($this->get($key), '/');
    }

    public function ssoReady(): bool
    {
        return $this->get('sso_client_secret') !== '' && $this->get('sso_client_id') !== '' && $this->get('sso_url') !== '';
    }
}
