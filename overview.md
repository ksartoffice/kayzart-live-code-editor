KayzArt Landing Page Editor - Overview
===================================

Overview
--------
- WordPress pages and legacy `kayzart` custom post type entries can be edited in the dedicated KayzArt editor.
- The editor provides CodeMirror 6 HTML/CSS/JavaScript tabs and a live iframe preview.
- New landing page work is page-based. Public output is the page permalink, not shortcode embedding.

Editor UI
---------
- Toolbar: Back, Undo/Redo, title/status controls, preview visibility, viewport presets, save, Copy All, settings, and front-end view links.
- Settings tab: page template, external resources, and display settings.
- Elements tab: selected element text/attribute editing.

Preview
-------
- Preview requests use `?kayzart_preview=1&post_id=...&token=...`.
- `kayzart_template_mode` can override the template mode during preview.
- Theme mode requires the active theme template to output `the_content`; otherwise the editor prompts to switch to standalone.

Copy All
--------
- Copy All copies the current HTML, CSS, and JavaScript editor contents to the clipboard as three labeled text blocks.

Front-End Output
----------------
- KayzArt-managed pages output post content normally and enqueue/inline CSS, JS, and configured external assets.
- `wpautop` and `shortcode_unautop` are removed for KayzArt front-end output to avoid unwanted paragraph insertion.
- The legacy `[kayzart]` shortcode remains registered only as a compatibility stub and returns empty output.

Stored Data
-----------
- Main content: `post_content`
- Post meta: `_kayzart_css`, `_kayzart_js`, `_kayzart_js_mode`, `_kayzart_tailwind`, `_kayzart_tailwind_locked`, `_kayzart_generated_css`
- Template/display: `_kayzart_template_mode`, `_kayzart_live_highlight`
- External assets: `_kayzart_external_scripts`, `_kayzart_external_styles`
- Legacy ignored meta: `_kayzart_shadow_dom`, `_kayzart_shortcode_enabled`, `_kayzart_single_page_enabled`

Admin Settings
--------------
- `kayzart_post_slug`: slug for legacy KayzArt CPT URLs.
- `kayzart_default_template_mode`: default template mode, Standalone or Theme.

REST API
--------
- `/kayzart/v1/save`: save HTML/CSS/JS and settings updates.
- `/kayzart/v1/settings`: update editor settings.

Extension API
-------------
- PHP hook: `kayzart_editor_enqueue_assets`
- JS settings tab API: `window.KAYZART_EXTENSION_API.registerSettingsTab(tab)`
- `tab`: `id`, `label`, optional `order`, and `mount(container)` returning optional cleanup.

Security
--------
- Editing requires `edit_post`.
- JavaScript and external script/style updates require `unfiltered_html`.
- Preview requests use nonce tokens and origin checks.
