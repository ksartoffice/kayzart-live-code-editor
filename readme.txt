=== Codellia ===
Contributors: ksartoffice
Tags: live preview, code editor, monaco, tailwind, shortcode
Requires at least: 6.6
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 1.0.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Codellia Editor - Live HTML/CSS/JS Editor with Tailwind CSS v4 support.

== Description ==
Codellia Editor provides a dedicated editor for building HTML, CSS, and JavaScript snippets with a live preview. It adds a "Codellia" custom post type, opens new Codellia posts in the editor, and adds an "Edit with Codellia" button to the standard editor.

Features:
* Custom Codellia post type and dedicated editor
* Monaco Editor with HTML/CSS/JS tabs and live iframe preview
* Setup wizard (Normal/Tailwind/Import JSON) with per-post mode lock
* Tailwind mode with on-demand Tailwind CSS v4 compilation
* Import/export JSON projects
* Per-post template mode control: Default/Standalone/Frame/Theme
* External scripts/styles (https only), live edit highlight, real-time DOM selection, and optional Shadow DOM isolation
* Shortcode embedding (enable in settings): [codellia post_id="123"]
* Optional single-page disable for shortcode-based output

External connections and privacy:
* By default, Codellia does not load external scripts or styles and does not send telemetry.
* External requests happen only when an authorized user explicitly adds external HTTPS URLs in Codellia settings.
* Added external resources are requested both in preview and on front-end output where the Codellia content is rendered.
* Add only trusted URLs.

Development repository and build:
* Source repository: https://github.com/ksartoffice/codellia
* Build steps:
* 1) npm install
* 2) composer install
* 3) npm run build
* 4) npm run plugin-zip
* The production files used by WordPress are generated into assets/dist/.

== Installation ==
1. Upload the plugin folder to /wp-content/plugins/codellia/.
2. Activate Codellia Editor through the Plugins screen.
3. Go to Codellia in the admin menu and create a new Codellia item.

== Frequently Asked Questions ==
= Who can edit Codellia posts? =
Users who can edit the post can use the editor. JavaScript, external scripts/styles, shadow DOM, shortcode, and single-page settings require the unfiltered_html capability.

= Does Codellia contact external servers by default? =
No. External requests are disabled by default. Requests are made only when you explicitly configure external HTTPS script/style URLs in Codellia settings.

= How do I embed a page created with Codellia? =
Enable the shortcode in Codellia settings, then use [codellia post_id="123"] with the post ID of the page you created in Codellia.

= Can I disable the single page view? =
Yes. Enable the shortcode and turn on "Do not publish as single page." Disabled single pages are marked noindex and excluded from search/archives, and the single-page request is redirected (or can be forced to 404 via the codellia_single_page_redirect filter).

= Can I switch between Normal and Tailwind modes? =
The setup wizard lets you choose Normal or Tailwind. The choice is locked per Codellia post.

= Which Tailwind CSS version is supported? =
Tailwind mode supports Tailwind CSS v4.

= How does template mode work? =
Each Codellia post can use Default, Standalone, Frame, or Theme template mode. Default follows Codellia > Settings > Default template mode. If Theme mode does not expose the_content in your theme, Codellia preview prompts to switch to Frame.

= Can I change the Codellia URL slug? =
Yes. Go to Codellia > Settings and update the Codellia slug.

= Can I set a default template mode for new previews? =
Yes. Go to Codellia > Settings and set the Default template mode (Standalone/Frame/Theme).

= Does the plugin delete data on uninstall? =
By default, Codellia posts are kept when the plugin is uninstalled. You can enable data removal from the Codellia > Settings screen.

= Where is the code stored? =
HTML is stored in the post content. CSS/JS and other settings are stored in post meta.

= Where is the development repository and how do I build the plugin? =
Development repository: https://github.com/ksartoffice/codellia

Build commands:
1. npm install
2. composer install
3. npm run build
4. npm run plugin-zip

== Changelog ==
= 1.0.1 =
* Initial release.

== Credits ==
This plugin bundles third-party libraries:
* Monaco Editor - MIT License (see assets/monaco/LICENSE) - https://github.com/microsoft/monaco-editor
* TailwindPHP (fork) - MIT License - https://github.com/ksartoffice/tailwindphp (upstream: https://github.com/dnnsjsk/tailwindphp)
