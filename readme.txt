=== KayzArt Landing Pages — Paste & Edit AI-Generated HTML ===
Contributors: ksartoffice
Tags: landing page, ai, landing page builder, custom html, tailwind
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 2.0.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Paste the landing page your AI wrote into WordPress, edit it live with HTML/CSS/JS, and publish — without fighting your theme.

== Description ==
Got a landing page from ChatGPT, Claude, or Gemini and don't know where to put it? KayzArt is the place to paste it.

Drop AI-generated HTML, CSS, and JavaScript straight into WordPress, see it render live as you tweak it, and publish a clean, theme-independent landing page in minutes — no child theme, no page builder, no fighting your active theme's header and footer.

**Who this is for**
You let AI write the code, but you still want full control over the result. You're past pure no-code, but you don't want to set up a build pipeline just to ship one landing page. KayzArt sits exactly there.

**What you can do**
* Paste a full AI-generated page (HTML + CSS + JS) and run it as-is
* Edit everything live with a CodeMirror 6 editor and instant iframe preview
* Click an element in the preview to jump to its code (real-time DOM selection)
* Keep the page theme-independent in Standalone mode, or render inside your theme in Theme mode
* Use plain CSS or TailwindCSS (auto-compiled) per page
* Run modern JavaScript (Classic script or ES Module)
* Restrict external embeds with an allowlist

**Works great with**
ChatGPT, Claude, Gemini, v0, or any tool that hands you HTML/CSS/JS. KayzArt doesn't generate the code — it's where that code becomes a real, publishable WordPress page you can keep editing.

Development repository: https://github.com/ksartoffice/kayzart-live-code-editor

== Installation ==
1. Install and activate KayzArt from Plugins.
2. Go to Pages > Add landing page.
3. Choose Normal or TailwindCSS mode.
4. Paste your AI-generated HTML/CSS/JS (or write your own), watch the live preview, and adjust.
5. Publish. Use Standalone mode for a clean, theme-free landing page.
6. Optional: Settings > Landing page settings to enable KayzArt for posts or custom post types.

== Frequently Asked Questions ==
= I have a page from ChatGPT / Claude. How do I use it in WordPress? =
Create a landing page, then paste the HTML, CSS, and JavaScript into their tabs. The live preview renders it immediately, and you can keep editing before you publish.

= Can AI edit the page for me, right inside WordPress? =
Not yet in this free plugin — today KayzArt is the editor and runtime where you paste and refine AI-generated code. AI-assisted editing inside WordPress is on our roadmap. For now, generate your HTML/CSS/JS in ChatGPT, Claude, or Gemini, then paste it here to publish and keep editing.

= Do I need to know how to code? =
No — most people paste what an AI produced and tweak from there. Basic familiarity with HTML/CSS helps when fine-tuning, but isn't required.

= Can I use TailwindCSS? =
Yes. Choose TailwindCSS mode when creating a page and KayzArt compiles utility classes automatically. It uses TailwindCSS v4, so the latest utility syntax works out of the box.

= What is Standalone mode? =
Standalone mode renders your landing page without the active theme's layout — the theme's header, footer, content template, styles, and scripts are not loaded, so your page isn't affected by theme CSS or JavaScript. KayzArt's own styles and scripts (and your page's CSS/JS) are still loaded, so the editor runtime and your page behave as expected. Use it when you want a clean, theme-independent landing page.

= What is Theme mode? =
Theme mode renders your KayzArt content inside the active theme's template, so the page keeps your theme's header, footer, and styling.

= Where is the code stored? =
HTML is stored in the post content; CSS, JavaScript, Tailwind/template modes, and other KayzArt settings are stored in post meta.

== Changelog ==
= 2.0.3 =
* Add: Show unsaved changes in the editor gutter.
* Fix: Mark auto-indented blank-line edits correctly.
* Improve: Use descendant text for element context.

= 2.0.2 =
* Fix: Bug fixes and stability improvements.

= 2.0.1 =
* Fix: Bug fixes and stability improvements.

= 2.0.0 =
* Refresh: Rebuilt the landing page editor for a simpler workflow.
* Improve: Streamlined page creation, editing, preview, and settings.
* Update: Cleaned up legacy features and internal structure.

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
