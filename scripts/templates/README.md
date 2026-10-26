# Bundled template assets

`assets/templates/free/general-service-lp/` is the distributed source of truth.
Edit `index.html`, `style.css`, and `manifest.json` directly. Use the same PHP
`Tailwind_Compiler` as the plugin to render both the placeholder preview and the
photographic demonstration. No external resources are needed at runtime.

## Photograph and screenshot

`demo-workspace.png` is an original image produced on 2026-10-26 with the built-in
image generation tool, following the imagegen skill. It depicts a fictional,
unbranded interior, with no people, logos, or real business information.
K's Art Office distributes this production asset and the resulting screenshot
under GPL-2.0-or-later, to the extent copyright applies. No third-party stock
photograph or font is used.

Final generation prompt:

> Create one photorealistic editorial photograph for the demonstration thumbnail
> of a generic Japanese business service landing page. A calm, sunlit contemporary
> workspace: warm oak meeting table, two simple chairs, a small green plant,
> softly textured pale plaster wall and large window. No people, no logos, no
> text, no screens showing interfaces, no recognizable branded products. Natural
> daylight, muted sage green and cream palette, premium architectural photography,
> subtle real material texture. Wide landscape composition, suitable for cropping
> into a square arch-shaped hero image. This is an original generic interior
> scene, not a real business or a branded location.

To reproduce the thumbnail:

1. Read the manifest, HTML, and CSS; compile with `Tailwind_Compiler`.
2. Resolve only `{{asset:main-visual}}` to a local copy of `demo-workspace.png`.
   Do not change the template's HTML structure or CSS.
3. Render at 1200 × 900 CSS pixels, device scale 1, with system fonts, and capture
   the initial viewport using a local browser. Export the screenshot to WebP
   (quality 85) as the package's `thumbnail.webp`.
4. Render the original placeholder version at desktop and 390px mobile widths.
   Confirm the layout matches and every page anchor exists.

The source photograph and these instructions are excluded by `.distignore`.
Only the final thumbnail, local placeholder, page sources, manifest, and license
are shipped. No photograph is imported into a customer's media library.

## Placeholder

`images/landscape.png` is an original 1600 × 1000 neutral placeholder graphic,
created with simple geometric lines and the label `YOUR IMAGE`. It is licensed
under GPL-2.0-or-later. The page uses empty alt text until an actual photo and
appropriate alternative text are supplied by the page author.

## Local verification

Run `Test_Template_Library` through the existing WordPress PHPUnit setup and
`template-library.test.ts` through Vitest. The `template-library.spec.ts` E2E
requires a disposable WordPress site and `KAYZART_TEMPLATE_E2E=1`.

Keep test databases, disposable WordPress installations, credentials, and helper
scripts **outside Playwright's `test-results/` output directory**: Playwright
clears that directory when starting a run. The E2E uses the shared temporary-page
helper and deletes its own page and attachments in `finally`.
