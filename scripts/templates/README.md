# Bundled template assets

`assets/templates/free/general-service-lp/` is the distributed source of truth.
Edit `index.html` (Japanese), `index.en.html` (English), `style.css`, and `manifest.json` directly. Use the same PHP
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
   (quality 85) as the package's `thumbnail.webp` (Japanese) or `thumbnail.en.webp`
   (English). Render each actual language's HTML, without replacing its text
   in an image editor. Both use the same original photograph and license above.
4. Render the original placeholder version at desktop and 390px mobile widths.
   Confirm the layout matches and every page anchor exists.

The source photograph and these instructions are excluded by `.distignore`.
Only the final thumbnail, local placeholder, page sources, manifest, and license
are shipped. No photograph is imported into a customer's media library.

## Content languages

The catalog contains one design with `ja` and `en` variants. The base manifest
files are Japanese; `localizations.en` selects English HTML and its thumbnail.
CSS and placeholder assets are shared. UI language, site language, and template
content language are separate: the site's `WPLANG` selects the initial variant
(Japanese for `ja`, English otherwise), while the author can change it in the card.
English copy is bundled, not translated by an API. Each variant has a `lang`
attribute on its content root; the isolated preview also sets the document language.
Created pages record the selected locale and keep an independent HTML/CSS copy.

## Placeholder

`images/landscape.png` is a 768 × 768, 162,784-byte neutral grayscale placeholder,
with one bold picture pictogram and no text. It uses no template-specific color.
Its icon sits above the lower 35% to stay clear of the hero badge at mobile widths;
there is no inset border that gets cut off by the arch-shaped display mask.

The artwork was generated on 2026-10-27 with the built-in `image_gen` tool, then
resampled to 768px and normalized to grayscale for the distributed PNG. Every
pixel was checked to have equal red, green and blue channels. No CLI/API fallback
was used. The exact final prompt is in `placeholder-prompt.txt` beside this file.
K's Art Office distributes this original asset under GPL-2.0-or-later, to the
extent copyright applies. The page uses empty alt text until an actual photo
and appropriate alternative text are supplied by the page author.

The photographic catalog thumbnails remain demonstration images. The placeholder
is used in the detailed preview and copied into a page's media on creation.

## Local verification

Run `Test_Template_Library` through the existing WordPress PHPUnit setup and
`template-library.test.ts` through Vitest. The `template-library.spec.ts` E2E
requires a disposable WordPress site and `KAYZART_TEMPLATE_E2E=1`.

Keep test databases, disposable WordPress installations, credentials, and helper
scripts **outside Playwright's `test-results/` output directory**: Playwright
clears that directory when starting a run. The E2E uses the shared temporary-page
helper and deletes its own page and attachments in `finally`.
