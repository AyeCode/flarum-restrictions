# AyeCode Flarum Restrictions

A Flarum extension that restricts content by AyeCode licence and logs people in with their AyeCode store account.

## Installation

```bash
composer require ayecode/flarum-restrictions
```

## Features

- Restricts starting and replying to discussions per tag by product licence. Licences come from the AyeCode API (`GET /v1/entitlements?email=...`, server side, by the user's confirmed email), cached per user for 10 minutes. If the API cannot be reached paid areas stay closed but pages still load (3 second timeout, then the API is skipped for a minute).
- Log in with AyeCode: login and sign up go to the store (`account.wpgeodirectory.com`, plugin `ayecode-sso`) by OAuth 2 with PKCE. Existing accounts are matched by the WordPress user id the old `flarum-wp-users` login recorded, then by confirmed email; new people get an account. Forum passwords stay empty.

## Configuration

Values are read from Flarum's `config.php`, then `AYECODE_*` environment variables, then the extension's admin settings. Secrets never go in this repository.

```php
'ayecode' => [
    'entitlements_token' => '...',   // the API Worker secret ENTITLEMENTS_TOKEN
    'sso_client_secret'  => '...',   // the 'forum' client secret in AYECODE_SSO_CLIENTS on the store
    // optional: 'api_base' => 'https://api.ayecode.io', 'sso_url' => 'https://account.wpgeodirectory.com', 'sso_client_id' => 'forum'
],
```

The store must list `<forum url>/auth/ayecode` as the client's redirect URI. Login switches to the store as soon as the client secret is set.

## Tests

`tests/integration-local.php` runs against a throwaway Flarum, the local dev Worker and a local WordPress with `ayecode-sso` (see the header of the file).

## Release
- Run 'npm run build'
- Update version number in composer.json
- Update version number in README.md

## License

This extension is licensed under the MIT License.

## Changelog

= 0.2.0 =
* Paid access now comes from the AyeCode API instead of the `ayecode_licenses` cookie, which was never verified and could be forged - CHANGED
* Log in with AyeCode (OAuth 2 with PKCE against the store) replaces the WordPress database login - ADDED
* Log out is Flarum's own again; it no longer calls the old WordPress server - CHANGED
* Admin settings for the API and the SSO client - ADDED

= 0.1.14 =
* Home page start discussion button should be disabled for users with no licenses.

= 0.1.11 =
* Fixed bug where forum access was not being checked.

= 0.1.10 =
* Removed posting access to all forums unless a paid member has a license.

= 0.1.9 =
* added GD core forum to open forums

= 0.1.7 =
* Alert notice shows on general forum and topics - FIXED
* Remove debugging - CHANGED