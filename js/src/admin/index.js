const app = flarum.core.compat['admin/app'];

// Settings for the AyeCode API (paid access) and Log in with AyeCode. A value set in config.php ('ayecode' => [...])
// or as an AYECODE_* environment variable wins over these fields; see src/Settings.php.
app.initializers.add('ayecode-restrictions', function () {
    const p = 'ayecode-restrictions.';
    app.extensionData
        .for('ayecode-restrictions')
        .registerSetting({ setting: p + 'api_base', type: 'text', label: 'AyeCode API URL', placeholder: 'https://api.ayecode.io' })
        .registerSetting({ setting: p + 'entitlements_token', type: 'password', label: 'Entitlements token', help: 'The API Worker secret ENTITLEMENTS_TOKEN. Without it paid areas stay closed.' })
        .registerSetting({ setting: p + 'sso_url', type: 'text', label: 'Store URL (Log in with AyeCode)', placeholder: 'https://account.wpgeodirectory.com' })
        .registerSetting({ setting: p + 'sso_client_id', type: 'text', label: 'SSO client id', placeholder: 'forum' })
        .registerSetting({ setting: p + 'sso_client_secret', type: 'password', label: 'SSO client secret', help: 'The secret for this client in AYECODE_SSO_CLIENTS on the store. Login and sign up go to the store once it is set.' });
});
