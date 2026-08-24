=== Kayzart — AI Landing Page Builder with Real HTML ===
Contributors: ksartoffice
Tags: landing page, ai editor, custom html, tailwind, live preview
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Build landing pages with AI inside WordPress — bring your own AI key. Real HTML/CSS you can edit live. No drag-and-drop, no builder lock-in.

== Description ==
Kayzart builds landing pages the way a developer would: it writes real HTML, CSS, and JavaScript, renders them live as you edit, and publishes them as clean pages independent from your active theme. It is not a drag-and-drop page builder — no blocks, and no builder markup left behind in your database. What you get is code you own, and AI that writes and edits it for you, right inside WordPress.

**New in 3.0: AI builds the page, you keep the code**
Start from Kayzart > Add new: give the page a title, describe the page you want in plain language, and choose Generate with AI. Page type and CSS mode sit right below in Page settings, and Start with a blank page remains available whenever you want to write or paste the code yourself.

From there, keep going by prompt or by hand. Describe a change and the AI edits your page's HTML and CSS directly — no copy-pasting between a chatbot and your editor. Select an element in the preview and tell the AI what to change about just that part. Each request runs as a background job with a running activity log you can watch or cancel, and the result is applied to your editor tabs for you to review before you save.

The AI is told which fonts your site can actually render — the families registered by your theme and the WordPress Font Library, plus system font stacks — so the type it picks renders on your visitors' devices instead of silently falling back to the browser default.

For safety, the AI edits markup and styles only — your JavaScript is read-only context it can read but never change, so AI editing can't inject or rewrite scripts on your page.

On WordPress 7.0 and later, Kayzart uses the WordPress AI Client and your configured Connector. On WordPress 5.9 through 6.9, it connects directly to OpenAI with the API key you configure in Kayzart and uses gpt-5.6-luna. WordPress 7.0 sites can also keep using a saved direct OpenAI key when no Connector is ready. There is no per-edit fee to Kayzart and no Kayzart server in the loop. AI editing is available by default to administrators and editors.

**Or bring the code yourself**
Write the page by hand, start from a template, drop in code from a designer, or paste what an AI tool gave you — then ask the built-in AI to refine it. The source doesn't matter — Kayzart is where that code becomes a page you can preview, keep editing, and ship in minutes, without a child theme and without fighting your theme's header, footer, and styles.

**Who this is for**
Freelancers, agencies, and developers who need a clean landing page fast and want full control over the result — without a child theme, a drag-and-drop builder, or a build pipeline. If you don't write code, you can still get a page: describe it and let the AI build it, ask for changes in plain language, and edit text, links, and images visually from the Elements panel. The difference is that when you do want the code, it is right there and it is yours.

**What you can do**
* Describe a page from Kayzart > Add new and have the AI build it as the editor opens
* Edit your page with AI in plain language — it changes the actual HTML and CSS, inside WordPress
* Keep JavaScript AI-safe: the AI reads your JS for context but never edits it
* Point the AI at a selected preview element to refine just that part
* Let the AI style with fonts your site actually has — your theme's and Font Library families, plus system stacks
* Watch each AI edit run as a background job with a live activity log, and cancel any time
* Review AI changes in the editor before you save — nothing is published automatically
* Edit everything live with a CodeMirror 6 editor and instant iframe preview
* Click an element in the preview to jump to its code (real-time DOM selection)
* Keep the page theme-independent in Standalone mode, or render inside your theme in Theme mode
* Use plain CSS or TailwindCSS (auto-compiled) per page, and switch a page between the two later
* Run modern JavaScript (Classic script or ES Module)
* Bring a full HTML/CSS/JS page from anywhere and run it as-is
* Open Kayzart straight from the block or classic editor, which shows a Kayzart card instead of the usual content area
* Duplicate an existing landing page as a draft from the page list
* Tune AI editing site-wide: default model, maximum turns per request, and maximum instruction length
* Restrict external embeds with an allowlist

**Works great with**
Bring HTML from anywhere — hand-written, a template, a designer, or an AI tool like ChatGPT, Claude, Gemini, or v0 — then keep editing it with the AI built into Kayzart. Whether the code starts as a paste from a chatbot or a hand-written draft, Kayzart is where it becomes a real, publishable WordPress page you can refine by hand or by prompt.

Development repository: https://github.com/ksartoffice/kayzart-live-code-editor

The admin editor bundle (assets/dist/) is compiled from the TypeScript/React sources in src/ with Vite. To reproduce the build from the repository: install dependencies with `npm install` and `composer install`, then run `npm run build` to generate the bundled assets. `npm run plugin-zip` produces the distributable package.

== Installation ==
1. Install and activate Kayzart from Plugins.
2. To use AI on WordPress 7.0+, configure a provider in Connectors. On WordPress 5.9–6.9, add your OpenAI API key in Kayzart > Settings. The same settings screen shows whether AI editing is ready. AI editing is available by default to administrators and editors.
3. Go to Kayzart > Add new. Enter an optional title and describe the page you want. Page type and CSS mode are set in Page settings on the same screen.
4. Choose Generate with AI. The editor opens with the AI panel ready while the page is built; watch the activity log and review the result. To work from your own code instead, choose Start with a blank page.
5. Keep refining by prompt or by hand — or paste your own HTML/CSS/JS from any source — while the live preview renders as you edit.
6. Publish or update. Use Standalone mode for a clean, theme-free landing page.
7. For an existing page, open Pages and choose Edit with Kayzart, or open the page in the block or classic editor and use the Kayzart card.
8. Optional: Kayzart > Settings to enable Kayzart for posts or custom post types, and to set the default AI model, maximum AI turns, and maximum instruction length.

== Frequently Asked Questions ==
= What is Kayzart and what can I build with it? =
Kayzart is an AI-assisted, live HTML/CSS/JavaScript editor for WordPress. You build clean, theme-independent landing pages: describe the page and let the AI write it, or write and paste your own HTML, CSS, and JavaScript — then watch the live preview render as you edit, and publish. No drag-and-drop builder, no child theme, no build pipeline. Use Standalone mode to keep the page free of your theme's header, footer, and styles, or Theme mode to render inside your theme.

= How do I create a page with AI from scratch? =
Go to Kayzart > Add new, enter an optional title, and describe the page you want in plain language — for example, a landing page for a new service with a hero section, features, pricing, and a contact form. Choose Generate with AI: the page is created, the editor opens with code hidden and the AI panel visible, and the AI begins building immediately. You review the result and nothing is published until you save.

= How does Kayzart remember the editor layout? =
When you open an existing Kayzart page, Kayzart restores your last code visibility, tools-panel state, selected tab, and panel width for that site, WordPress user, browser, and screen size. An AI-created page opens with code hidden and the AI panel visible; a blank page opens with code visible and the tools panel closed. Those entry-specific starting states do not overwrite your saved preference. Compact screens keep a separate layout and start with the tools panel closed until you choose otherwise.

= I already have HTML/CSS/JS (hand-written, a template, or from an AI tool). How do I use it in WordPress? =
Create a landing page, then paste the HTML, CSS, and JavaScript into their tabs. You can also convert an existing WordPress page from the page list or edit screen; Kayzart keeps the existing post content as the initial HTML. If you have one complete HTML document, use the full HTML import feature to split it into the right fields. The live preview renders it immediately, and you can keep editing before you publish.

= Can AI edit the page for me, right inside WordPress? =
Yes. In the editor's AI tab, describe the change you want and the AI edits your page's HTML and CSS directly — you don't copy code back and forth from a chatbot. You can also select an element in the preview and ask the AI to change just that part. Each request runs as a background job you can watch or cancel, and the result is applied to your editor tabs for you to review. Nothing is published until you save. For safety, the AI edits markup and styles only and treats your JavaScript as read-only context, so it can't add or rewrite scripts. AI editing works on every supported WordPress version after an AI connection is configured.

= How do I set up AI editing? What does it cost? =
On WordPress 7.0 and later, configure a provider such as OpenAI, Anthropic, or Google through WordPress Connectors. On WordPress 5.9–6.9, enter an OpenAI API key in Kayzart > Settings; it is stored as a non-autoloaded WordPress option unless you provide KAYZART_OPENAI_API_KEY as an environment variable or PHP constant. WordPress 7.0 prefers a ready Connector and falls back to that direct key when necessary. There is no Kayzart account, per-edit Kayzart fee, or Kayzart server in the loop; you pay your selected provider for usage.

= Who can use AI editing, and can I turn it off? =
Access is controlled by a dedicated capability. Administrators and editors receive it on activation. Sites using standard WordPress capability-management tools can grant or remove it for other roles or individual users. Users without permission don't see the AI features at all. Site owners can also disable the feature entirely with a filter.

= Which AI model does it use? =
Connector-based editing follows the models exposed by the configured WordPress provider and can use Auto or a selected model. Direct OpenAI editing uses gpt-5.6-luna.

= Which fonts will the AI use? =
Only fonts your site can really render. Kayzart passes the AI the font families registered on your site — those defined by your theme (theme.json) and any added through the WordPress Font Library — along with three system font stacks that need no download. That is why AI-styled pages keep their typography for visitors instead of falling back to the browser default. The AI cannot load remote fonts or external stylesheets. If you want a specific typeface, add it to the Font Library first and it becomes available to the AI.

= Can I control how much the AI does per request? =
Yes. Kayzart > Settings has a maximum number of AI turns per request — the AI works in steps, and this caps how many steps one request may take — and a maximum instruction length in characters. The same screen reports the selected AI backend, connection state, Action Scheduler, and required PHP extensions.

= What happens when I open a Kayzart page in the block or classic editor? =
Instead of the usual content area you get a Kayzart card, so the page's HTML is never edited in two places at once. You can still change the page title and all the normal WordPress page settings there, view the page, or jump straight into the Kayzart editor from the card.

= Do I need to know how to code? =
No, not to get a page. Describe what you want from Kayzart > Add new and the AI writes the HTML and CSS for you, then keep asking for changes in plain language or edit text, links, and images visually from the Elements panel. Basic familiarity with HTML/CSS helps when fine-tuning, and Kayzart gives you that full control the moment you want it — the code is right there, and it is yours.

= Can I use shortcodes? =
Yes. You can place WordPress shortcodes directly in the HTML editor. They are not expanded inside the live preview iframe, but they are processed normally on the published page or front-end view.

= Can I duplicate an existing landing page? =
Yes. From the Pages list, choose Duplicate with Kayzart for a Kayzart-managed page. Kayzart creates a new draft copy with the page content, Kayzart settings, featured image, and taxonomy terms carried over.

= Can I use TailwindCSS? =
Yes, and it is the recommended mode — the AI reads and edits utility classes more reliably than long custom stylesheets. Choose TailwindCSS when you create a page or when you first open an existing page with Kayzart, and Kayzart compiles the utility classes for you. You can also switch a page between Normal HTML/CSS and TailwindCSS later from the editor settings panel. It uses TailwindCSS v4, so the latest utility syntax works out of the box.

= What is Standalone mode? =
Standalone mode renders your landing page without the active theme's layout — the theme's header, footer, content template, styles, and scripts are not loaded, so your page isn't affected by theme CSS or JavaScript. Kayzart's own styles and scripts (and your page's CSS/JS) are still loaded, so the editor runtime and your page behave as expected. Use it when you want a clean, theme-independent landing page.

= What is Theme mode? =
Theme mode renders your Kayzart content inside the active theme's template, so the page keeps your theme's header, footer, and styling.

= Where is the code stored? =
HTML is stored in the post content; CSS, JavaScript, Tailwind/template modes, and other Kayzart settings are stored in post meta.

= Does Kayzart send usage data to its own servers? =
No usage data is sent automatically. Kayzart includes an optional administrator survey under Kayzart > Settings > Feedback. Every question in it is optional, and answers are sent to Kayzart only when an administrator presses Send feedback to Kayzart. The survey does not include the site URL, administrator email address, page content, or an installation identifier. AI editing also does not pass through a Kayzart server; it continues to use the AI connection configured by the site.

== Screenshots ==
1. Describe the page you want on the create screen, then pick TailwindCSS or Normal HTML/CSS mode.
2. The AI builds the page as soon as the editor opens, with a live activity log you can watch or cancel.
3. Edit the result in the split editor with HTML, CSS, JavaScript, and live preview panes.
4. Ask the AI for a change in plain language from the AI tab, right inside WordPress.
5. Select an element in the preview and have the AI refine just that part.
6. Select preview text and refine the matching element from the Elements panel.
7. Import a complete HTML document from any source and review the detected sections before importing.
8. Open Kayzart from the block editor, which shows a Kayzart card in place of the content area.
9. Set the default AI model, turn and instruction limits, and check the AI requirements in Settings.

== Changelog ==
= 3.0.0 =
* Add: AI editing inside WordPress — describe a change in plain language and the AI edits your page's HTML and CSS directly.
* Add: Create a page from a prompt. Describe the page on Kayzart > Add new and the AI starts building it as soon as the editor opens.
* Add: Point the AI at a selected preview element to refine just that part.
* Add: Offer the AI the fonts your site can actually render — theme.json and Font Library families plus system font stacks — so AI-chosen typography no longer falls back to the browser default.
* Security: AI editing treats JavaScript as read-only context and never modifies it, so it can't inject or rewrite scripts.
* Add: Run AI edits as background jobs with a live activity log, cancel support, and a per-page edit history; results are applied for review and never published without saving.
* Add: Use WordPress AI Client/Connectors on WordPress 7.0+, or connect directly to OpenAI on WordPress 5.9–6.9.
* Security: Protect AI editing with a dedicated capability, available by default to administrators and editors.
* Infrastructure: Support WordPress 5.9+ and run AI editing with an Action Scheduler job runtime.
* Add: Settings for AI editing — default model, maximum AI turns per request, and maximum instruction length — alongside a requirements checklist that reports whether AI editing can run on your site.
* Add: A Kayzart menu in the admin sidebar that opens the create screen directly.
* Add: Switch a page between Normal HTML/CSS and TailwindCSS after it was created, from the editor settings panel.
* Improve: Compile TailwindCSS reliably on large HTML documents.
* Change: Show a Kayzart card in the block and classic editors for Kayzart-managed pages, with the page title, a view link, and a way into the Kayzart editor.
* Change: Rename the plugin to Kayzart — AI Landing Page Builder with Real HTML.
* Change: Make Add new AI-first with Generate with AI and Start with a blank page actions, with page type and CSS mode grouped under Page settings.
* Change: Open AI-created pages with code hidden and the AI panel visible, and blank pages with code visible and the tools panel closed. Normal visits restore each user's last desktop or compact layout.
* Change: Remove the site-wide Default editor layout setting while retaining its saved value as an upgrade fallback.
* Improve: Add accessible panel and tab semantics, keyboard tab navigation, compact Escape handling, focus restoration, and keyboard panel resizing.
* Change: Creating a page no longer happens the instant you click a menu item. Choose the title, AI instruction, and optional advanced settings first, then create — so the editor opens ready to use and stray drafts are not left behind.
* Change: Move Kayzart settings from Settings to the Kayzart menu.
* Change: Rename the page list actions to Edit with Kayzart, Add with Kayzart, Duplicate with Kayzart, and Start editing with Kayzart, and show them ahead of the default row actions. Opening an existing page with Kayzart shows a confirmation screen that states the page content is kept.
* Change: Mark Kayzart-managed pages in the page list with a Kayzart label.

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

== External services ==
Kayzart sends AI requests only after a site administrator configures an AI connection and a permitted user requests an AI operation. Requests can include the user's instruction, page title, HTML, custom head markup, CSS, read-only JavaScript context, selected element context, available font names, and recent Kayzart AI edit context.

On WordPress 5.9–6.9, and as a fallback on newer versions, requests are sent directly to the OpenAI Responses API using the site's API key and gpt-5.6-luna. OpenAI terms: https://openai.com/policies/terms-of-use/ — privacy policy: https://openai.com/policies/privacy-policy/

On WordPress 7.0+, a configured WordPress Connector is preferred. Data handling and terms are determined by the provider selected in WordPress Connectors.

Kayzart also offers administrators an optional product feedback survey. Every question in it is optional. No request is made merely by installing, activating, updating, or viewing the plugin or survey. When an administrator explicitly submits the form, the selected answers, optional free-text comments, survey version, plugin version, WordPress version, and administrator interface locale are sent to https://feedback.kayzart.com/ and retained for two years. An administrator can review what they sent and send a correction, which replaces the earlier answer. The survey does not send the site URL, administrator name or email address, page content, installed plugin list, or a persistent installation identifier. The receiving server may process IP addresses in short-lived security and abuse-prevention logs. The survey stops being offered after 31 August 2027, or earlier if the service reports that it has closed.

Kayzart privacy policy: https://kayzart.com/privacy-policy/ — terms: https://kayzart.com/terms/

== Credits ==
This plugin bundles third-party libraries:
* CodeMirror - MIT License - https://github.com/codemirror
* Emmet CodeMirror 6 Plugin - MIT License - https://github.com/emmetio/codemirror6-plugin
* js-beautify - MIT License - https://github.com/beautify-web/js-beautify
* Lucide - ISC License - https://github.com/lucide-icons/lucide
* parse5 - MIT License - https://github.com/inikulin/parse5
* TailwindPHP - MIT License - https://github.com/ksartoffice/tailwindphp
* Action Scheduler - GPL-3.0-or-later - https://actionscheduler.org
