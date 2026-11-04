# Template assets

The current packages are `assets/templates/free/business-motion-ja/` and
`assets/templates/free/business-motion-en/`. Their manifests define the files,
fonts, and assets. Registrations live in `includes/class-kayzart-template-registry.php`.
Edit the package HTML, CSS, and module directly; the module documents its
`data-kz-*` motion attributes. Update shared assets in both packages together.

## Asset sources

K's Art Office created the following original assets with an image-generation
tool. They are distributed under GPL-2.0-or-later, to the extent copyright applies.
See each package's `LICENSE.txt` for package and font notices.

- `images/landscape.png`: neutral grayscale placeholder generated on 2026-10-27,
  centered on 2026-10-28, and exported at 768 x 768. It contains no text or logos.
  Template previews use it; page creation copies it into the media library.
- `scripts/templates/business-motion-hero.png`: abstract studio render generated
  on 2026-10-28, with charcoal and pale-blue blocks and an orange sphere. Used only
  to produce the catalog thumbnails; it is not imported into created pages.
- `scripts/templates/demo-workspace.png`: fictional, unbranded interior generated
  on 2026-10-26 for the retired `general-service-lp` thumbnail. No people, logos,
  or third-party stock photograph were used.

Google Fonts are loaded remotely, not bundled. The Japanese package uses Noto
Sans JP; the English package uses Instrument Sans and Instrument Serif. Allowed
families are defined by `Template_Library::GOOGLE_FONTS`. External connections
are described in `readme.txt`.

## Thumbnail reproduction

1. Prepare the package with `Template_Library::prepare()` and the Tailwind compiler.
2. Resolve `{{asset:main-visual}}` to the placeholder. For the current packages,
   replace only the hero image with `business-motion-hero.png`. The retired
   package used `demo-workspace.png` instead.
3. Render the actual HTML and font head at 1200 x 900 CSS pixels, device scale 1.
   Wait for `document.fonts.ready`, enable reduced motion, and capture the initial
   viewport. Export WebP at quality 85 as `thumbnail.webp`.
4. Check the original placeholder version on desktop and mobile. Current hero
   images appear at the `lg` breakpoint (1024px).

The `scripts/` directory and retired package are excluded from release ZIPs.
