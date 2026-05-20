=== KayzArt Live Code Editor ===
Contributors: ksartoffice
Tags: live preview, code editor, codemirror, landing page
Requires at least: 5.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.3.6
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

KayzArt Live Code Editor - Live HTML/CSS/JS Editor for WordPress.

== Description ==
KayzArt Live Code Editor provides a dedicated editor for building HTML, CSS, and JavaScript snippets with a live preview. It adds a "KayzArt" custom post type, opens new KayzArt posts in the editor, and adds an "Edit with KayzArt" button to the standard editor.

Features:
* Custom KayzArt post type and dedicated editor
* CodeMirror 6 editor with HTML/CSS/JS tabs and live iframe preview
* JavaScript ES Module support with execution type switch (Classic / Module)
* Copy All button for copying the current HTML/CSS/JS as labeled text blocks
* Per-post template mode control: Default/Standalone/Theme
* External scripts/styles (https only), live edit highlight, and real-time DOM selection

External connections and privacy:
* By default, KayzArt does not load external scripts or styles and does not send telemetry.
* External requests happen only when an authorized user explicitly adds external HTTPS URLs in KayzArt settings.
* Added external resources are requested both in preview and on front-end output where the KayzArt content is rendered.
* Add only trusted URLs.

Development repository and build:
* Source repository: https://github.com/ksartoffice/kayzart-live-code-editor
* Generated files used by WordPress: assets/dist/main.js and assets/dist/style.css
* Source files for generated assets: src/admin/main.ts, src/admin/style.css, and related files under src/admin/
* Build configuration files: package.json, package-lock.json, vite.config.ts, tsconfig.json
* Build steps:
1. npm install
2. composer install
3. npm run build
4. npm run plugin-zip

== Installation ==
1. Upload the plugin folder to /wp-content/plugins/kayzart-live-code-editor/.
2. Activate KayzArt Live Code Editor through the Plugins screen.
3. Go to KayzArt in the admin menu and create a new KayzArt item.

== Screenshots ==
1. Editor screen.
2. Settings and preview controls.

== Frequently Asked Questions ==
= Who can edit KayzArt posts? =
Users who can edit the post can use the editor. JavaScript and external scripts/styles require the unfiltered_html capability.

= Does KayzArt contact external servers by default? =
No. External requests are disabled by default. Requests are made only when you explicitly configure external HTTPS script/style URLs in KayzArt settings.

= How does template mode work? =
Each KayzArt post can use Default, Standalone, or Theme template mode. Default follows KayzArt > Settings > Default template mode. If Theme mode does not expose the_content in your theme, KayzArt preview prompts to switch to Standalone.

= Can I change the KayzArt URL slug? =
Yes. Go to KayzArt > Settings and update the KayzArt slug.

= Can I set a default template mode for new previews? =
Yes. Go to KayzArt > Settings and set the Default template mode (Standalone/Theme).

= Does the plugin delete data on uninstall? =
By default, KayzArt posts are kept when the plugin is uninstalled. You can enable data removal from the KayzArt > Settings screen.

= Where is the code stored? =
HTML is stored in the post content. CSS/JS and other settings are stored in post meta.

= Where is the development repository and how do I build the plugin? =
Development repository: https://github.com/ksartoffice/kayzart-live-code-editor

Generated files in the distributed plugin:
* assets/dist/main.js
* assets/dist/style.css

Source files and build configuration in this repository:
* src/admin/main.ts and src/admin/style.css (plus related source files under src/admin/)
* package.json, package-lock.json, vite.config.ts, tsconfig.json

Build commands:
1. npm install
2. composer install
3. npm run build
4. npm run plugin-zip

== Changelog ==
= 1.3.6 =
* Remove external embedding and single-page disable settings. Existing [kayzart] shortcodes no longer render content.
* Remove Tailwind CSS mode and convert legacy generated CSS to normal CSS.

= 1.3.5 =
* Docs: Add screenshots section.

= 1.3.4 =
* Update: Dependency maintenance.

= 1.3.3 =
* Improve: Add resizable settings panel with width persistence.
* Add: Introduce preview override action events.

= 1.3.2 =
* Security: Security update and hardening improvements.

= 1.3.1 =
* Fix: Bug fixes and stability improvements.

= 1.3.0 =
* Introduce CodeMirror 6 editor runtime and remove legacy bundled loader assets.

= 1.2.1 =
* Fix: Minor internal code cleanup

= 1.2.0 =
* Add JavaScript execution mode selector (Classic script / Module) in the JavaScript tab.
* Add ES Module runtime contract support with context injection (`root`, `document`, `host`, `onCleanup`).

= 1.1.3 =
* Fix: Bug fixes and stability improvements.

= 1.1.2 =
* Security: Security update and hardening improvements.

= 1.1.1 =
* Security: Implemented security-related improvements and hardening updates.

= 1.1.0 =
* Add external embed allowlist settings.
* Other: Internal improvements and maintenance updates.

= 1.0.1 =
* Initial release.

== Credits ==
This plugin bundles third-party libraries:
* CodeMirror - MIT License - https://github.com/codemirror
* Emmet CodeMirror 6 Plugin - MIT License - https://github.com/emmetio/codemirror6-plugin
