# Bundled template assets

## Current package: Business Motion (2026-10-28)

`assets/templates/free/business-motion-ja/` is the distributed source of truth.
It is a Japanese business-support service page, using Noto Sans JP, warm white,
charcoal, orange, and light blue. Its hero is live HTML/CSS, not a flattened image.
The headline is 「その非効率に、終止符を。」. On desktop the hero is two columns: the
category line, headline, description and CTAs on the left; a rounded image with
an offset light-blue panel and the tag 「小さな改善を、着実に積み上げる。」 on the right
(hidden at 880px and below). The "動き出す" process panel draws three rising bars.
The copy column is sized to its content; the image takes the remaining width and
matches the copy height, so the text-to-image space is only the grid gap.
The hero image and the approach section image both reference `{{asset:main-visual}}`,
so a created page imports one placeholder copy. Only the catalog thumbnail shows a
demonstration photo in the hero image.
The catalog no longer offers the old Japanese or English design. A future
English design must be a separate package, not a translation of this one.

Edit `index.html`, `style.css`, `motion.js`, and `manifest.json` directly.

Quality floor for edits: no visible text below 11px; small text at least 4.5:1
contrast on its background (the `subtle` token is tuned for the light sections);
the dark button keeps `focus-visible:outline-ink` so keyboard focus stays visible.

Both Business Motion packages are written with Tailwind CSS v4 utility classes in
the HTML (compiled by the bundled TailwindPHP), so AI edits touch only HTML.
`style.css` holds just the `@theme` tokens (paper, ink, accent, sky, muted, subtle,
line, stone, mist, font-sans, and font-serif in English), one `wrap` container
utility, and a small base layer (smooth scrolling, focus outline, scroll margin,
and the English `<em>` serif accent). Use standard breakpoints: base is phones,
`sm` 640px, `md` 768px, `lg` 1024px (the hero becomes two columns with its photo).
Motion is still configured only through `data-kz-*` attributes; tests select
motion elements by those attributes, not by class names.
Schema 2 adds a local module and declared Google Fonts. The loader still reads
schema 1 from allowlisted legacy source packages, but creation accepts only
current catalog entries. `.distignore` excludes the retired package from ZIPs.

### Fonts and external requests

The manifest declares Noto Sans JP weights 400/500/700/900. PHP generates its
fixed Google Fonts CSS2 link with `display=swap`; arbitrary head snippets or
resource URLs are not accepted from manifest fields. Font loading is announced
on the card before preview/create, without a separate checkbox or setting.
The list and thumbnail make no Google requests. Detailed preview, editor preview,
and published pages load CSS from fonts.googleapis.com and files from
fonts.gstatic.com. No page-text parameter or external JS is used. See the
readme External services section for disclosure and policy links. This notice
and explicit-action design remains a subject of the pre-release org review;
passing Plugin Check is not approval of this disclosure arrangement.

### Motion contract

The library-free ES module exports the existing Kayzart default mount function
and releases resources through `onCleanup`. Source is copied into each new page's
JS tab with `jsMode=module`; custom head and CSS are copied too, including the
complete initial revision. Import requires `unfiltered_html` as well as the
existing page/media permissions. Changes to the source package do not rewrite
created pages.

- Root `data-kz-motion="on|off"` enables/disables effects.
- `data-kz-effect="reveal|float|parallax"` selects an effect.
- Duration: `data-kz-duration`, 100..4500ms; defaults reveal 700 / float 4200.
- Delay: `data-kz-delay`, 0..500ms; default 0.
- Distance: `data-kz-distance`, 0..48px for reveal (default 20), 0..16px otherwise (8).
- Invalid numeric strings use defaults; out-of-range numbers are clamped.

MutationObserver tracks effects, their attributes, root changes, and removals.
Web Animations hold visual state outside serialized HTML. A reveal that is already
on screen when the module mounts is left as painted, because hiding visible content
would flicker; other reveals play as they scroll in. Reveals run once per
configuration, floats make one complete excursion, and pointer parallax runs
only on fine-pointer devices. Reduced motion disables effects. Hidden documents
pause motion. Cleanup cancels animations, observers, listeners, and queued frames.
AI keeps JS read-only and edits the documented attributes; it cannot invent new
effects or introduce remote resources. Already-loaded template fonts are offered
to AI only when the current head actually contains a recognized stylesheet link.

### Thumbnail reproduction

Use `Template_Library::prepare()` and the plugin's Tailwind compiler. Resolve
`{{asset:main-visual}}` to the placeholder, then replace only the hero image with
the demonstration photo `business-motion-hero.png` in this directory. Include the
prepared Google font head, render at 1200×900/device scale 1, await
`document.fonts.ready`, emulate reduced motion, and export the initial viewport
to WebP quality 85. Do not substitute another layout or alter the page copy.
The current screenshot is 51,076 bytes. Check the placeholder layout at
320/390/768/880/881/1024/1440px and also with JS or external fonts unavailable.

`business-motion-hero.png` (1254×1254) is an original produced on 2026-10-28 with
an image-generation tool by the plugin author. It shows only abstract stacked
blocks and a sphere, with no people, logos, or text. It is a production asset for
the thumbnail only: `/scripts` is excluded from ZIPs and the photo is never
imported into a customer's media library. The detailed preview and created pages
use the grayscale placeholder. Prompt for the adopted image:

> Create one photorealistic studio render, square 1:1 composition. A precise,
> minimal sculpture of stacked rectangular blocks forming an ascending structure,
> in matte charcoal (#202322), pale sky blue (#c9ddf1) and warm off-white, with a
> single glossy vivid orange (#ef5734) sphere resting on the top block.
> Warm off-white seamless background, soft directional light, clean soft shadows.
> Centered subject within the middle 70% of the frame for a circular crop.
> Modern, confident, architectural feeling. No text, no logos, no people.

The CSS focuses the cover crop at 50% 30% so the sphere stays visible when the
hero image becomes wider than tall.

## English package: Business Motion EN (2026-10-28)

`assets/templates/free/business-motion-en/` is a separate English design, not a
translation. It keeps the Japanese package's structure, motion module, hero photo
approach, and placeholder, but rewrites the copy and the typography for Latin text:
Instrument Sans (400/500/600/700) for text and headings, and Instrument Serif
italic for accents (`<em>` in headings, the orange hero line, step numbers).
Headline: "Less busywork. / More momentum." Line-heights, letter-spacing, and the
size scale are tuned for English; headings use `text-wrap: balance`.

`motion.js` and `images/landscape.png` are byte-identical copies of the Japanese
package (packages must be self-contained). Change both copies together.

Allowed fonts live in `Template_Library::GOOGLE_FONTS` (family, axis, values,
fallback stack). A manifest may declare at most two of them, using `weights` for
`wght` families and `italic` for `ital` families. The same list decides which
already-loaded fonts AI may use. Adding a family means adding it there, plus the
readme External services text.

The thumbnail is rendered the same way as the Japanese one, with the same
`business-motion-hero.png` photo, waiting for both font families; it is
56,604 bytes.

## Retired package production history

The following describes `general-service-lp`, kept in source history and excluded
from distribution. Its old no-external-resources design does not describe the
current Google Fonts template.

## Service introduction example (2026-10-28)

The free template uses a fictional small web and design studio as its concrete
example. Its structure remains reusable: services, approach, pricing, process,
questions, and contact details. Replace the sample copy for another business;
no industry-specific integration is required. Prices and the reserved
`hello@example.com` address are explicitly examples, with no outgoing mail link.

The layout uses warm paper, charcoal, and a blue accent, with a typographic hero,
numbered service rows, and three packages showing their scope. The image is a
smaller 6:5 rectangle instead of the previous large arch and overlapping badge.
The neutral placeholder remains the imported asset. Japanese and English share
the layout and CSS; both photographic thumbnails must be recaptured after edits.

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
content language are separate: the site's `WPLANG` selects a matching catalog
locale (exact match, then base language). Unsupported site languages fall back to
English, or the first available language if English is not offered. This does
not use the administrator's personal UI language. The author changes it with a
labeled native dropdown above the list. Its options and native language labels
come from the catalog's variants, so new languages do not require more buttons.
Cards do not contain a language selector. The
list filters by each card's declared variants, hiding unsupported languages
instead of translating or substituting another language. Matching cards update
their thumbnail, preview URL, and hidden creation locale together, preserving
the entered title. The list also synchronizes on `pageshow` if the browser
restores the dropdown value. An empty result shows a short message.
English copy is bundled, not translated by an API. Each variant has a `lang`
attribute on its content root; the isolated preview also sets the document language.
Created pages record the selected locale and keep an independent HTML/CSS copy.

## Placeholder

`images/landscape.png` is a 768 × 768, 182,484-byte neutral grayscale placeholder,
with one bold picture pictogram and no text. It uses no template-specific color.
Its pictogram is centered horizontally and vertically, with balanced margins.
The old composition reserved the lower 35% for a badge; that requirement was
removed along with the badge, and the asset was centered on 2026-10-28.
The current rectangular 6:5 display crops the square asset slightly at the top
and bottom; the complete pictogram stays visible. There is no text or inset border.

The artwork was generated on 2026-10-27 and edited on 2026-10-28 with the built-in
`image_gen` tool, then resampled to 768px and normalized to grayscale for the
distributed PNG. The pictogram bounds are centered within 3px of the canvas
center, and remain centered in the 6:5 crop. Every
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
