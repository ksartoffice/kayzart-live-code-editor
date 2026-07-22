=== AI Landing Page Editor — Kayzart ===
Contributors: ksartoffice
Tags: landing page, ai editor, custom html, tailwind, live preview
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build clean, theme-independent landing pages with a live HTML/CSS/JS editor — now with built-in AI editing. No page builder, no build pipeline.

== Description ==
Kayzart turns custom HTML, CSS, and JavaScript into a real, publishable WordPress landing page — rendered live as you edit, kept clean and independent from your active theme, with no page builder and no build tooling to set up. And now AI can edit that page for you, right inside WordPress.

Write the page by hand, start from a template, drop in code from a designer, or paste what an AI tool gave you — then ask the built-in AI to refine it. The source doesn't matter — Kayzart is where that code becomes a page you can preview, keep editing, and ship in minutes, without a child theme and without fighting your theme's header, footer, and styles.

**New in 3.0: AI editing inside WordPress**
Describe the change you want in plain language and the AI edits your page's HTML and CSS directly — no copy-pasting between a chatbot and your editor. Select an element in the preview and tell the AI what to change about it. Each request runs as a background job with a running activity log you can watch or cancel, and the result is applied to your editor tabs for you to review before you save.

For safety, the AI edits markup and styles only — your JavaScript is read-only context it can read but never change, so AI editing can't inject or rewrite scripts on your page.

It runs on the WordPress-native AI Client (WordPress 7.0+) using your own AI provider configured through Connectors — you bring your own API key, so there is no per-edit fee to Kayzart, no separate account, and no external service in the loop. Site admins decide which roles and users can use AI editing.

**Who this is for**
People comfortable with HTML/CSS who want full control over the result — freelancers, agencies, and developers who need a clean landing page fast, without a child theme, a page builder, or a build pipeline. Non-developers can still tweak text, links, and images visually from the Elements panel, or ask the AI to make a change in plain language.

**What you can do**
* Edit your page with AI in plain language — it changes the actual HTML and CSS, inside WordPress
* Keep JavaScript AI-safe: the AI reads your JS for context but never edits it
* Point the AI at a selected preview element to refine just that part
* Watch each AI edit run as a background job with a live activity log, and cancel any time
* Review AI changes in the editor before you save — nothing is published automatically
* Edit everything live with a CodeMirror 6 editor and instant iframe preview
* Click an element in the preview to jump to its code (real-time DOM selection)
* Keep the page theme-independent in Standalone mode, or render inside your theme in Theme mode
* Use plain CSS or TailwindCSS (auto-compiled) per page
* Run modern JavaScript (Classic script or ES Module)
* Bring a full HTML/CSS/JS page from anywhere and run it as-is
* Duplicate an existing landing page as a draft from the page list
* Restrict external embeds with an allowlist

**Works great with**
Bring HTML from anywhere — hand-written, a template, a designer, or an AI tool like ChatGPT, Claude, Gemini, or v0 — then keep editing it with the AI built into Kayzart. Whether the code starts as a paste from a chatbot or a hand-written draft, Kayzart is where it becomes a real, publishable WordPress page you can refine by hand or by prompt.

Development repository: https://github.com/ksartoffice/kayzart-live-code-editor

The admin editor bundle (assets/dist/) is compiled from the TypeScript/React sources in src/ with Vite. To reproduce the build from the repository: install dependencies with `npm install` and `composer install`, then run `npm run build` to generate the bundled assets. `npm run plugin-zip` produces the distributable package.

== Installation ==
1. Install and activate Kayzart from Plugins.
2. Go to Pages > Add landing page to create a new landing page, or open Pages and choose Convert to landing page for an existing page.
3. Choose Normal or TailwindCSS mode.
4. Paste your HTML/CSS/JS from any source (or keep editing the existing page content), watch the live preview, and adjust.
5. Publish or update. Use Standalone mode for a clean, theme-free landing page.
6. Optional: Settings > Landing page settings to enable Kayzart for posts or custom post types.
7. Optional: To use AI editing, run WordPress 7.0+ and configure an AI provider (your own API key) in Connectors, then open the AI tab in the editor. Admins can choose which roles and users may use it.

== Frequently Asked Questions ==
= What is Kayzart and what can I build with it? =
Kayzart is a live HTML/CSS/JavaScript editor for WordPress. You build clean, theme-independent landing pages: write or paste your HTML, CSS, and JavaScript, watch the live preview render as you edit, and publish — without a page builder, a child theme, or a build pipeline. Use Standalone mode to keep the page free of your theme's header, footer, and styles, or Theme mode to render inside your theme.

= I already have HTML/CSS/JS (hand-written, a template, or from an AI tool). How do I use it in WordPress? =
Create a landing page, then paste the HTML, CSS, and JavaScript into their tabs. You can also convert an existing WordPress page from the page list or edit screen; Kayzart keeps the existing post content as the initial HTML. If you have one complete HTML document, use the full HTML import feature to split it into the right fields. The live preview renders it immediately, and you can keep editing before you publish.

= Can AI edit the page for me, right inside WordPress? =
Yes. In the editor's AI tab, describe the change you want and the AI edits your page's HTML and CSS directly — you don't copy code back and forth from a chatbot. You can also select an element in the preview and ask the AI to change just that part. Each request runs as a background job you can watch or cancel, and the result is applied to your editor tabs for you to review. Nothing is published until you save. For safety, the AI edits markup and styles only and treats your JavaScript as read-only context, so it can't add or rewrite scripts. AI editing needs WordPress 7.0+ and an AI provider you configure (see the setup question below).

= How do I set up AI editing? What does it cost? =
AI editing uses the WordPress-native AI Client, so it needs WordPress 7.0 or newer and an AI provider configured through Connectors — you add your own API key from a provider such as OpenAI, Anthropic, or Google. Because it uses your key and runs inside your site, there is no per-edit fee to Kayzart, no separate Kayzart account, and no Kayzart server in the loop; you pay only your provider's usage for the requests you make. If no provider is configured, the editor points you to the Connectors setup. Kayzart itself never stores your API key.

= Who can use AI editing, and can I turn it off? =
Access is controlled by a dedicated capability. Administrators get it on activation, and a site admin decides which roles and users may use AI editing — useful when an agency configures the key and enables it for specific client accounts. Users without permission don't see the AI features at all. Site owners can also disable the feature entirely with a filter.

= Which AI model does it use? =
The model list comes from whatever provider you configure in Connectors, not from Kayzart, so new models appear without a plugin update. You can pick a model in settings or leave it on automatic and let the AI Client choose. More capable models generally produce more reliable edits.

= Do I need to know how to code? =
Basic familiarity with HTML/CSS helps when fine-tuning, and Kayzart gives you full control when you want it. But you don't have to start from scratch — paste existing code (hand-written, a template, or AI output) and adjust from there, ask the built-in AI to make a change in plain language, or edit text, links, and images visually from the Elements panel.

= Can I use shortcodes? =
Yes. You can place WordPress shortcodes directly in the HTML editor. They are not expanded inside the live preview iframe, but they are processed normally on the published page or front-end view.

= Can I duplicate an existing landing page? =
Yes. From the Pages list, choose Duplicate landing page for a Kayzart-managed page. Kayzart creates a new draft copy with the page content, Kayzart settings, featured image, and taxonomy terms carried over.

= Can I use TailwindCSS? =
Yes. Choose TailwindCSS mode when creating or converting a page and Kayzart compiles utility classes automatically. It uses TailwindCSS v4, so the latest utility syntax works out of the box.

= What is Standalone mode? =
Standalone mode renders your landing page without the active theme's layout — the theme's header, footer, content template, styles, and scripts are not loaded, so your page isn't affected by theme CSS or JavaScript. Kayzart's own styles and scripts (and your page's CSS/JS) are still loaded, so the editor runtime and your page behave as expected. Use it when you want a clean, theme-independent landing page.

= What is Theme mode? =
Theme mode renders your Kayzart content inside the active theme's template, so the page keeps your theme's header, footer, and styling.

= Where is the code stored? =
HTML is stored in the post content; CSS, JavaScript, Tailwind/template modes, and other Kayzart settings are stored in post meta.

== Screenshots ==
1. Choose Normal HTML/CSS mode or TailwindCSS before editing the landing page.
2. Start from a clean split editor with HTML, CSS, JavaScript, and live preview panes.
3. Open the full HTML import dialog for a complete HTML document.
4. Paste a complete HTML document from any source — hand-written, a template, or an AI tool.
5. Review the detected HTML, head, CSS, and JavaScript sections before importing.
6. Edit the imported code while the live preview renders the landing page immediately.
7. Select preview text and refine the matching element from the Elements panel.
8. Ask the AI to edit the page in plain language from the AI tab, right inside WordPress.
9. Watch an AI edit run as a background job with a live activity log, then review the result before saving.

== Changelog ==
= 3.0.0 =
* Add: AI editing inside WordPress — describe a change in plain language and the AI edits your page's HTML and CSS directly.
* Add: Point the AI at a selected preview element to refine just that part.
* Security: AI editing treats JavaScript as read-only context and never modifies it, so it can't inject or rewrite scripts.
* Add: Run AI edits as background jobs with a live activity log, cancel support, and a per-page edit history; results are applied for review and never published without saving.
* Add: Bring your own AI provider through Connectors — no Kayzart account, no per-edit fee, and no external Kayzart server.
* Add: Per-role and per-user permission controls for who can use AI editing.
* Infrastructure: Require WordPress 7.0 and run AI editing on the WordPress-native AI Client with an Action Scheduler job runtime.

= 2.3.0 =
* Add: Keep full-page revision history for HTML, CSS, JavaScript, and page settings.
* Add: Load complete saved versions from the editor settings.

= 2.2.3 =
* Add: Duplicate an existing landing page as a draft from the page list.

= 2.2.2 =
* Add: Show placeholders for shortcodes in the live preview.

= 2.2.1 =
* Add: Edit preview images from the Elements panel.
* Fix: Keep nested text edits stable.

= 2.2.0 =
* Add: Show link and button editing in the Elements panel.
* Add: Let admins hide code panels by default.
* Improve: Make Elements text editing easier for non-coders.

= 2.1.1 =
* Fix: Apply element inner HTML edits to the live preview safely.

= 2.1.0 =
* Add: Convert existing posts into landing pages.
* Add: Format HTML, CSS, and JavaScript from the editor.
* Add: Replace preview images from the media library.
* Improve: Reduce preview flicker and preserve scroll position.

= 2.0.7 =
* Fix: Resolve front page preview returning a 404.

= 2.0.6 =
* Add: Export full HTML from the editor.
* Improve: Refine element attribute field layout.

= 2.0.5 =
* Add: Select parent elements from the preview tools.
* Fix: Bug fixes and stability improvements.

= 2.0.4 =
* Update: Rename visible brand text to Kayzart.

= 2.0.3 =
* Add: Show unsaved changes in the editor gutter.

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
* js-beautify - MIT License - https://github.com/beautify-web/js-beautify
* Lucide - ISC License - https://github.com/lucide-icons/lucide
* parse5 - MIT License - https://github.com/inikulin/parse5
* TailwindPHP - MIT License - https://github.com/ksartoffice/tailwindphp
* Action Scheduler - GPL-3.0-or-later - https://actionscheduler.org
