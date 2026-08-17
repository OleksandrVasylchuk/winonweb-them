# WOW — Signal — Claude Instructions

## Project overview

Full Site Editing WordPress block theme for [winonweb.dev](https://www.winonweb.dev/),
sold as a commercial product by the studio **WOW — Win On Web**.

**There is no build step.** No webpack, no SCSS, no ACF. The files in the repo
are the files that run. Never introduce a bundler without being asked — the
"works on upload, no npm install" property is a deliberate product decision.

## Stack

- PHP 8.1+ · WordPress 6.7+ · zero required plugins
- Design tokens live in `theme.json`; the only CSS outside it is per-block
  `style.css` and the `inline_style` on registered block styles
- Block editor JS is hand-written against the global `wp` object (no JSX, no
  imports), with a matching `*.asset.php` listing dependencies

## Core commands

```
npm run test             # contrast + block markup + PHPCS
npm run audit:contrast   # WCAG 2.2 AA over theme.json and styles/light.json
npm run lint:blocks      # template/pattern markup parses, references resolve
npm run lint:php         # WordPress-Extra, must be zero
npm run lint:html        # validates artifacts/html/*.html
npm run make:pot         # regenerate languages/wow-signal.pot
```

`npm run lint:php` needs `composer install` once.

## Naming — do not deviate

| Thing | Pattern | Example |
|---|---|---|
| Text domain | `wow-signal` | `__( 'Send', 'wow-signal' )` |
| PHP namespace | `Wow\Signal\…` → `inc/` | `Wow\Signal\Modules\Seo` |
| Constants | `WOW_SIGNAL_*` | `WOW_SIGNAL_DIR` |
| Hooks | `wow_signal/…` | `wow_signal/contact_recipient` |
| Block name | `wow/{slug}` | `wow/faq-item` |
| CSS root class | `wow-{slug}` | `wow-faq__question` |
| Pattern slug | `wow-signal/{slug}` | `wow-signal/hero-home` |
| Local PHP vars in render.php / patterns | `$wow_` prefix | `$wow_heading` |

## Rules that are not negotiable

1. **Escape everything.** The only unescaped echoes allowed are
   `get_block_wrapper_attributes()`, already-rendered inner blocks, and
   `wp_json_encode()` inside a `<script>` — each needs a `phpcs:ignore` with a
   reason on the same line. Never pass `JSON_UNESCAPED_SLASHES` to
   `wp_json_encode()` inside a script element: slash escaping is what stops
   `</script>` breaking out.
2. **No hard-coded colours, sizes or spacing.** Everything resolves to
   `var(--wp--preset--*)` or `var(--wp--custom--*)`. If a token does not exist,
   add it to `theme.json` rather than writing a literal.
3. **Re-run `npm run audit:contrast` after touching any palette.** It fails the
   build on a single pair below its minimum. `contrast` on `accent` is 1.65:1 —
   labels on accent fills are always `base`.
4. **Every interactive thing works without JavaScript.** The FAQ is native
   `<details>`. The slider is a scroll container; its arrows ship `hidden` and
   are revealed by `view.js`. The contact form is a plain POST. Counters render
   their final figure server-side.
5. **Never trust `requestAnimationFrame` to finish.** It stops in background
   tabs. Anything animating a value needs a `setTimeout` that snaps to the real
   value regardless — see `blocks/metric/view.js`.
6. **A new block needs `*.asset.php`.** WordPress refuses to register a block
   script when the sibling asset file is missing. Copy an existing one.

## Adding a block

```
blocks/{slug}/
  block.json        apiVersion 3, "name": "wow/{slug}", "category": "wow"
  render.php        server render; $attributes, $content, $block in scope
  edit.js           (function(wp){ ... wp.blocks.registerBlockType(...) })(window.wp)
  edit.asset.php    dependencies for edit.js
  style.css         front end + editor, tokens only
  view.js           optional; needs view.asset.php
```

`inc/Modules/Blocks.php` discovers it automatically — no registration code.

## Adding a pattern

One file in `patterns/`, with the header block WordPress reads (`Title`, `Slug`,
`Categories`, `Description`, `Keywords`). Wrap all visible copy in
`esc_html_x( '…', 'Pattern placeholder text', 'wow-signal' )`. Categories are
registered in `inc/Modules/Patterns.php`.

Section markup uses `core/group` with `tagName: "section"`. Do **not** add
`aria-labelledby` to every section: turning each one into a landmark is an
accessibility regression. Named regions belong to the interactive blocks.

## Verifying against a real site

The repo has no WordPress in it. To test, point a local WordPress at the theme,
then fetch pages into `artifacts/html/` and run `npm run lint:html`. Accessibility
is checked with axe-core in a real browser — structure rules only tell half the
story, and colour-contrast over the hero gradient has to be reasoned about
mathematically because axe reports it as "incomplete".

## Directory layout

```
theme.json · styles/light.json     tokens + style variation
templates/ · parts/ · patterns/    the site, editable in the Site Editor
blocks/{slug}/                     custom blocks, auto-discovered
inc/Modules/                       one concern per file
tools/                             the quality gates
artifacts/                         gate output, git-ignored
```
