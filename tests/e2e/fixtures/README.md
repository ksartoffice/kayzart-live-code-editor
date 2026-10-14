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

# Deactivation feedback fixture

Use a disposable local WordPress installation and its own database. Copy
`deactivation-feedback.php` into `wp-content/mu-plugins/` and define
`KAYZART_DEACTIVATION_E2E` as `true` in `wp-config.php`. Use an administrator
whose interface language is English, and set `WP_BASE_URL`, `WP_ADMIN_USER`,
and `WP_ADMIN_PASS` to this installation.

```powershell
$env:KAYZART_DEACTIVATION_E2E = '1'
npx playwright test tests/e2e/deactivation-feedback.spec.ts
```

The spec requires localhost or 127.0.0.1 and checks for the fixture marker.
The fixture blocks external WordPress requests and simulates feedback delivery
locally; no answers are sent to the real API. Cookie `kayzart_delivery_mode`
selects successful, closed, or malformed responses. Test-only options record
delivery count and payload. The spec exercises actual plugin deactivation and
always reactivates it in `afterEach`, including after failed assertions. It
creates no posts or pages. Discard the installation and database afterwards.

For package verification, install the release ZIP into this installation and
run the same spec rather than sharing the development vendor directory while
packaging is in progress.
