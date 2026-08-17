# Block & pattern spec — WOW — Signal

Authoritative conventions for this theme. Read it before adding a block, a
pattern or a template. Anything that deviates needs a reason stated in a code
comment.

> **This file replaced an earlier spec for an ACF-based theme.** The theme no
> longer uses ACF, SCSS or a bundler. If you find a reference to `acf/qwed-*`,
> `get_field()`, `get-d()` or `npm run build` anywhere, it is stale — delete it.

---

## 1. The shape of the theme

| Concern | Where it lives |
|---|---|
| Colours, type, spacing, shadows, radii | `theme.json` → `settings` |
| Element and core-block styling | `theme.json` → `styles` |
| Critical CSS that tokens cannot express | `theme.json` → `styles.css` (inlined by WordPress) |
| Style variants of core blocks | `inc/Modules/Blocks.php` → `block_style_definitions()` |
| Per-block CSS | `blocks/{slug}/style.css`, declared in `block.json` |
| Page structure | `templates/*.html`, `parts/*.html` |
| Reusable sections | `patterns/*.php` |

There is **no** global stylesheet and **no** build step.

---

## 2. Naming

| Thing | Pattern | Example |
|---|---|---|
| Block name | `wow/{slug}` | `wow/case-highlight` |
| CSS root class | `wow-{slug}` | `wow-case-highlight` |
| BEM element | `wow-{slug}__{el}` | `wow-case-highlight__stat` |
| BEM modifier | `is-{state}` / `has-{thing}` | `is-exclusive` |
| PHP local in render.php | `$wow_` prefix | `$wow_heading` |
| Pattern slug | `wow-signal/{slug}` | `wow-signal/cases-slider` |
| Pattern category | `wow-{group}` | `wow-proof` |
| Text domain | `wow-signal` | everywhere, no exceptions |

Slugs are lowercase and hyphenated, and describe **content, not style**:
`pricing`, `timeline`, `case-highlight`. Never `section-2`, never `blue-band`.

---

## 3. Files a block needs

```
blocks/{slug}/
├── block.json        apiVersion 3
├── render.php        server render
├── edit.js           editor UI, no JSX
├── edit.asset.php    dependencies for edit.js       ← required, or the block will not register
├── style.css         front end + editor
├── editor.css        optional, editor-only
├── view.js           optional, front-end behaviour
└── view.asset.php    required if view.js exists
```

`inc/Modules/Blocks.php` globs `blocks/*/block.json` and registers everything it
finds. No registration code is ever needed.

### block.json skeleton

```json
{
  "$schema": "https://schemas.wp.org/trunk/block.json",
  "apiVersion": 3,
  "name": "wow/{slug}",
  "title": "{Title}",
  "category": "wow",
  "icon": "{dashicon}",
  "description": "One sentence a non-technical client understands.",
  "keywords": [ "{slug}", "{synonym}" ],
  "textdomain": "wow-signal",
  "attributes": {},
  "supports": {
    "html": false,
    "anchor": true,
    "align": [ "wide", "full" ],
    "color": { "text": true, "background": true },
    "spacing": { "padding": true, "margin": [ "top", "bottom" ] }
  },
  "editorScript": "file:./edit.js",
  "style": "file:./style.css",
  "render": "file:./render.php"
}
```

### edit.asset.php

```php
<?php
return array(
	'dependencies' => array( 'wp-block-editor', 'wp-blocks', 'wp-components', 'wp-element', 'wp-i18n' ),
	'version'      => '1.0.0',
);
```

WordPress looks for `{script}.asset.php` next to every script named in
`block.json`. **If it is missing the script silently fails to register** and the
block appears broken in the editor with no error.

### edit.js

No JSX and no imports — the theme has no compiler. Write against the globals:

```js
( function ( wp ) {
	'use strict';
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var useBlockProps = wp.blockEditor.useBlockProps;

	wp.blocks.registerBlockType( 'wow/{slug}', {
		edit: function ( props ) {
			var blockProps = useBlockProps( { className: 'wow-{slug}' } );
			return el( 'div', blockProps, /* … */ );
		},
		save: function () {
			return null; // dynamic block, render.php owns the markup
		},
	} );
} )( window.wp );
```

Title, category, attributes and supports come from the server-registered
`block.json` automatically — do not repeat them here.

---

## 4. render.php rules

```php
<?php
/**
 * Server render for wow/{slug}.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 *
 * @var array<string, mixed> $attributes Block attributes.
 * @var string               $content    Inner blocks.
 * @var WP_Block             $block      Block instance.
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;
```

1. **Cast every attribute immediately.** `$wow_heading = (string) ( $attributes['heading'] ?? '' );`
2. **Bail early on empty content.** `if ( '' === trim( $wow_heading ) ) { return; }` — an empty block should render nothing, not an empty shell.
3. **Wrapper attributes** come from `get_block_wrapper_attributes( array( 'class' => 'wow-{slug}' ) )`. It is already escaped; print it with a `phpcs:ignore` and a reason. Never add a second `id` next to it — anchor support already puts one there.
4. **Escaping table**

   | Data | Function |
   |---|---|
   | plain text | `esc_html()` |
   | attribute | `esc_attr()` |
   | URL | `esc_url()` |
   | textarea value | `esc_textarea()` |
   | editor rich text | `wp_kses()` with an explicit allow-list |
   | rendered inner blocks | print raw, with `phpcs:ignore` |

   `echo $var` without escaping is never acceptable.
5. **JSON-LD**: `wp_json_encode( $data, JSON_UNESCAPED_UNICODE )`. Never add
   `JSON_UNESCAPED_SLASHES` — slash escaping is what prevents a `</script>` in
   editor content from breaking out of the element.
6. **Images**: the first image on a page gets no `loading` attribute and
   `fetchpriority="high"`; everything else is left to core. `Performance` already
   sets `wp_omit_loading_attr_threshold` to 1.

---

## 5. CSS rules

- One `style.css` per block, loaded only when the block renders.
- **Tokens only.** Every value is `var(--wp--preset--*)` or `var(--wp--custom--*)`.
  A literal is allowed only for something with no design meaning: a 1px hairline,
  a `rotate(45deg)`, a clip-path.
- Layout is intrinsic — `grid`, `flex`, `clamp()`, `min()`. The theme has no
  breakpoint variables and no media-query mixins; add a media query only when
  intrinsic sizing genuinely cannot express the layout.
- Always include a `@media (forced-colors: active)` block if the design relies on
  a background, a gradient or a border to convey anything.

---

## 6. Accessibility checklist — every block, every pattern

- [ ] Headings descend without skipping: one `h1` per page, sections `h2`, cards `h3`.
- [ ] Interactive elements are real `<button>`, `<a href>`, `<summary>` or form controls — never a `<div>` with a click handler.
- [ ] Every control is at least 24×24 CSS px, or has 24px of spacing between target centres (WCAG 2.2 SC 2.5.8).
- [ ] Focus is visible and contrasts with **both** the element and the page. Never `outline: none` without a replacement.
- [ ] Decorative glyphs are `aria-hidden="true"`; anything that carries meaning has a text equivalent.
- [ ] A number that animates has its final value in a `.screen-reader-text` and the animating element `aria-hidden`.
- [ ] Form controls have a `<label for>`, `required` + `aria-required`, and on error `aria-invalid` plus `aria-describedby` pointing at their own message.
- [ ] `role="list"` on any `<ul>` that has `list-style: none` (Safari drops list semantics otherwise).
- [ ] `<section>` elements do **not** get `aria-labelledby` by default. Making every section a landmark is a regression; name a region only when it is genuinely a navigational target.
- [ ] It all works with JavaScript disabled.

---

## 7. Patterns

Header block WordPress reads:

```php
<?php
/**
 * Title: Services — three card grid
 * Slug: wow-signal/services-grid
 * Categories: wow-content
 * Description: What an editor sees before inserting it.
 * Keywords: services, features, cards
 * Viewport Width: 1400
 *
 * @package Wow\Signal
 */

defined( 'ABSPATH' ) || exit;
?>
```

Categories: `wow-hero`, `wow-content`, `wow-proof`, `wow-conversion`, `wow-page`.
Whole-page patterns add `Block Types: core/post-content` and `Post Types: page`
so they appear when a page is created.

Rules:

- All visible copy goes through `esc_html_x( '…', 'Pattern placeholder text', 'wow-signal' )`, and text inside a block attribute through `esc_attr_x()`.
- Block markup must match what the editor would save, or the Site Editor flags
  the block as invalid. Keep style attributes to **one** group per block (spacing
  only, most of the time) and prefer `backgroundColor` / `textColor` / `fontSize`
  class attributes over inline style.
- Run `npm run lint:blocks` afterwards. It checks the comments balance, the
  attribute JSON parses, and every referenced block, pattern and template part
  actually exists.
- A pattern that is only used by a template gets `Inserter: no` and a
  `hidden-` filename prefix.

---

## 8. Definition of done

```
npm run test        # contrast + block markup + PHPCS all clean
```

plus, against a running site:

```
npm run lint:html   # zero errors on the rendered pages
```

plus an axe-core pass in a real browser with zero violations, and a manual
keyboard walk of anything interactive.
