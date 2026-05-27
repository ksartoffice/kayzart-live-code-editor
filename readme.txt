=== KayzArt Landing Page Editor ===
Contributors: ksartoffice
Tags: live preview, code editor, codemirror, landing page
Requires at least: 5.9
Tested up to: 7.0
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
* Live edit highlight and real-time DOM selection

Development repository: https://github.com/ksartoffice/kayzart-live-code-editor

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

= Where is the code stored? =
HTML is stored in the WordPress post content. CSS, JavaScript, TailwindCSS mode, template mode, and other KayzArt settings are stored in post meta.

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
