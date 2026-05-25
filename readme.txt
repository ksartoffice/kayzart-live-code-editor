=== KayzArt Landing Page Editor ===
Contributors: ksartoffice
Tags: live preview, code editor, codemirror, landing page
Requires at least: 5.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.3.6
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build theme-independent landing pages with live HTML, CSS, and JavaScript editing.

== Description ==
KayzArt Landing Page Editor helps you build landing pages in WordPress with a dedicated live HTML, CSS, and JavaScript editor. It is designed for theme-independent landing pages, product pages, campaign pages, and other standalone layouts where you want direct control over the markup, styles, and behavior.

KayzArt works with regular WordPress pages by default. Site administrators can also enable it for posts or other supported custom post types from the plugin settings.

Features:
* Create landing pages as regular WordPress pages by default
* Optional support for posts and supported custom post types
* CodeMirror 6 editor with HTML, CSS, and JavaScript tabs
* Live iframe preview while editing
* Template modes: Standalone / Theme
* Theme-independent Standalone mode for landing pages that should not use the active theme layout
* Theme mode for rendering content inside the active theme template
* Normal / TailwindCSS setup per landing page
* JavaScript ES Module support with execution type switch (Classic / Module)
* Copy All button for copying the current HTML/CSS/JS as labeled text blocks
* Live edit highlight and real-time DOM selection
* Optional trusted external scripts/styles (https only)

External connections and privacy:
* By default, KayzArt does not contact external servers and does not send telemetry.
* External requests happen only when an authorized user explicitly adds external HTTPS script or stylesheet URLs in the KayzArt settings for a page.
* Added external resources are requested by the visitor's browser in the editor preview and on the front-end output where that KayzArt content is rendered.
* Add only URLs that you trust.

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
2. Activate KayzArt Landing Page Editor through the Plugins screen.
3. Go to Pages > Add landing page.
4. Choose Normal or TailwindCSS mode for the new landing page.
5. Edit HTML, CSS, and JavaScript with the live preview, then save.
6. Optional: go to Settings > Landing page settings to enable KayzArt for posts or supported custom post types.

== Screenshots ==
1. Live HTML/CSS/JS editor with preview.
2. Landing page settings and template mode controls.

== Frequently Asked Questions ==
= How do I create a landing page? =
Go to Pages > Add landing page. KayzArt creates a draft WordPress page, asks you to choose Normal or TailwindCSS mode, and then opens the live editor.

= Can I use KayzArt with regular WordPress pages? =
Yes. Regular WordPress pages are the default content type for KayzArt landing pages.

= Can I use KayzArt with posts or custom post types? =
Yes. Site administrators can enable KayzArt for posts or supported custom post types under Settings > Landing page settings. After a post type is enabled, KayzArt adds an Add landing page action for that post type.

= What is Standalone mode? =
Standalone mode renders the landing page independently from the active theme layout. Use it when you want a page built mainly from your HTML, CSS, and JavaScript without the theme header, footer, or content template.

= What is Theme mode? =
Theme mode renders the KayzArt content through the active theme template. Use it when you want the landing page content to appear inside your current theme layout.

= Which mode should I choose? =
Choose Standalone for theme-independent landing pages. Choose Theme if you want the page to keep the active theme's layout and styling.

= Can I use TailwindCSS? =
Yes. When creating a landing page, choose TailwindCSS mode to use utility classes with automatic CSS compilation.

= Who can edit JavaScript and external resources? =
Users who can edit the page can use the editor. JavaScript and external scripts/styles require the unfiltered_html capability.

= Does KayzArt contact external servers by default? =
No. KayzArt does not contact external servers by default and does not send telemetry. External requests happen only when an authorized user adds trusted HTTPS script or stylesheet URLs in the page settings.

= Where is the code stored? =
HTML is stored in the WordPress post content. CSS, JavaScript, TailwindCSS mode, template mode, external resource URLs, and other KayzArt settings are stored in post meta.

= Where is the source code for the generated assets? =
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
* Update: Minor changes.

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
