=== KayzArt Live Code Editor ===
Contributors: ksartoffice
Tags: live preview, code editor, codemirror, tailwind, shortcode
Requires at least: 6.6
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 1.3.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

KayzArt Live Code Editor - Live HTML/CSS/JS Editor with Tailwind CSS v4 support.

== Description ==
KayzArt Live Code Editor provides a dedicated editor for building HTML, CSS, and JavaScript snippets with a live preview. It adds a "KayzArt" custom post type, opens new KayzArt posts in the editor, and adds an "Edit with KayzArt" button to the standard editor.

Features:
* Custom KayzArt post type and dedicated editor
* CodeMirror 6 editor with HTML/CSS/JS tabs and live iframe preview
* JavaScript ES Module support with execution type switch (Classic / Module)
* Setup wizard (Normal/Tailwind/Import JSON) with per-post mode lock
* Tailwind mode with on-demand Tailwind CSS v4 compilation
* Import/export JSON projects
* Per-post template mode control: Default/Standalone/Theme
* External scripts/styles (https only), live edit highlight, real-time DOM selection, and optional Shadow DOM isolation
* External embedding (enable in settings): [kayzart post_id="123"]
* Allowlist for shortcode execution inside external embeds (one shortcode tag per line)
* Optional single-page disable for external-embed output

External connections and privacy:
* By default, KayzArt does not load external scripts or styles and does not send telemetry.
* External requests happen only when an authorized user explicitly adds external HTTPS URLs in KayzArt settings.
* Added external resources are requested both in preview and on front-end output where the KayzArt content is rendered.
* Add only trusted URLs.

Development repository and build:
* Source repository: https://github.com/ksartoffice/kayzart-live-code-editor
* Build steps:
* 1) npm install
* 2) composer install
* 3) npm run build
* 4) npm run plugin-zip
* The production files used by WordPress are generated into assets/dist/.

== Installation ==
1. Upload the plugin folder to /wp-content/plugins/kayzart-live-code-editor/.
2. Activate KayzArt Live Code Editor through the Plugins screen.
3. Go to KayzArt in the admin menu and create a new KayzArt item.

== Frequently Asked Questions ==
= Who can edit KayzArt posts? =
Users who can edit the post can use the editor. JavaScript, external scripts/styles, shadow DOM, external embedding, and single-page settings require the unfiltered_html capability.

= Does KayzArt contact external servers by default? =
No. External requests are disabled by default. Requests are made only when you explicitly configure external HTTPS script/style URLs in KayzArt settings.

= How do I embed a page created with KayzArt? =
Enable external embedding in KayzArt settings, then use [kayzart post_id="123"] with the post ID of the page you created in KayzArt. For shortcode execution inside the embedded content, add allowed tags in KayzArt settings (one tag per line). Non-allowlisted tags remain plain text.

= Can I disable the single page view? =
Yes. Enable external embedding and turn on "Do not publish as single page." Disabled single pages are marked noindex and excluded from search/archives, and the single-page request is redirected (or can be forced to 404 via the kayzart_single_page_redirect filter).

= Can I switch between Normal and Tailwind modes? =
The setup wizard lets you choose Normal or Tailwind. The choice is locked per KayzArt post.

= Which Tailwind CSS version is supported? =
Tailwind mode supports Tailwind CSS v4.

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

Build commands:
1. npm install
2. composer install
3. npm run build
4. npm run plugin-zip

== Changelog ==
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
* Add allowlist settings for shortcode execution in external embeds.
* Other: Internal improvements and maintenance updates.

= 1.0.1 =
* Initial release.

== Credits ==
This plugin bundles third-party libraries:
* CodeMirror - MIT License - https://github.com/codemirror
* Emmet CodeMirror 6 Plugin - MIT License - https://github.com/emmetio/codemirror6-plugin
* TailwindPHP (fork) - MIT License - https://github.com/ksartoffice/tailwindphp (upstream: https://github.com/dnnsjsk/tailwindphp)
