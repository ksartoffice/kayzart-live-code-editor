# Style Guide

This document captures project-wide naming conventions. Keep it in sync with the codebase.

## PHP
- Namespace: KayzArt
- Class names: StudlyCaps with underscores for compound words (e.g., Post_Type, Rest_Save)
- Methods and variables: snake_case
- Constants: UPPER_SNAKE
- Files: class-kayzart-*.php for classes, includes/rest for REST handlers
- CPT: kayzart (slug: kayzart)
- Option keys: kayzart_*
- Post meta keys: _kayzart_*

## JS/TS
- Local variables and functions: camelCase
- React components: PascalCase
- File names: kebab-case
- JS/CSS/DOM prefix: kayzart- (no cd- legacy prefix)

## API / Payload Keys
- Identifiers: post_id
- Booleans: *Enabled suffix (jsEnabled, shadowDomEnabled, shortcodeEnabled, liveHighlightEnabled, tailwindEnabled)
- Tailwind flag: tailwindEnabled (not tailwind)

## JS Internal vs API Boundary
- JS/TS internal identifiers use camelCase (postId).
- API boundary (REST payloads, URLs, shortcode attrs) uses snake_case (post_id).

## Shortcodes
- Attributes: post_id

