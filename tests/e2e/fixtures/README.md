# AI onboarding fixture

Use a disposable WordPress installation with its own database. Copy
`ai-onboarding.php` into that installation's `wp-content/mu-plugins/` directory
and define `KAYZART_ONBOARDING_E2E` as `true` in its `wp-config.php`.

Set `WP_BASE_URL`, `WP_ADMIN_USER`, and `WP_ADMIN_PASS` to that installation,
then run:

```powershell
$env:KAYZART_ONBOARDING_E2E = '1'
npx playwright test tests/e2e/ai-onboarding.spec.ts
```

The fixture uses cookies to simulate WordPress 6.9, WordPress 7.0 with
Connectors, and WordPress 7.0 without the AI Client. It supplies only fake
credentials and blocks outgoing WordPress HTTP requests. The spec also stubs
AI job submissions and blocks external browser requests, so no paid generation
takes place.

Temporary content is created as fixed pages through the shared helpers and
deleted in `finally`, with deletion responses checked. The settings save test
changes only this disposable installation's settings.
