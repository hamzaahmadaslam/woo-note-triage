# Security policy

## Reporting a vulnerability

Please report security problems privately through GitHub: open the repository's **Security** tab and choose
**Report a vulnerability**. Do not open a public issue for a security problem.

You will get a reply within seven days. Fixes are released as a new version with a note in the
[changelog](CHANGELOG.md).

## What this project does with your data

- It reads your TypeSafe API key from a `TYPESAFE_API_KEY` constant (usually in `wp-config.php`), from the
  `TYPESAFE_API_KEY` environment variable, or from its settings page, where the key is stored in a WordPress option
  that is not autoloaded. It sends the key only to `https://api.typesafe.ai`, in the `Authorization` header. It
  never logs, prints or stores the key anywhere else, and the settings page shows only its last four characters.
- It sends only the text described in the README's "What leaves your site" section, and only once a key is set.
- It makes no other network requests: no telemetry, no update checks.
- The settings page needs the `manage_woocommerce` capability and a nonce. Everything the plugin prints in the admin
  is escaped.

## Supported versions

Only the latest release receives fixes.
