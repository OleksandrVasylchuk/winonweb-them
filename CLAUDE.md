# Qwerty Soft — Signal — Claude Instructions

## Project overview

Full Site Editing WordPress block theme built and maintained by the studio
**Qwerty Soft** as internal tooling: the base every client site starts from,
and the machinery that turns a delivered design into that site without hand
work. It is not sold.

**There is no build step.** No webpack, no SCSS. The files in the repo are the
files that run. Never introduce a bundler without being asked — the "works on
upload, no npm install" property is what lets the theme be dropped onto any
project host, including ones the studio does not control.

**ACF is used for imported sections.** A section from a design becomes a block
whose markup and stylesheet are the design's own, with a field per editable
element. That is a decision of the studio's, taken over the alternative of
native block attributes, and it makes ACF a dependency of any site built from
an import.

## Stack

- PHP 8.1+ · WordPress 6.7+ · ACF for imported sections, no other plugin required
- Design tokens live in `theme.json`; the only CSS outside it is per-block
  `style.css` and the `inline_style` on registered block styles
- Block editor JS is hand-written against the global `wp` object (no JSX, no
  imports), with a matching `*.asset.php` listing dependencies

## Core commands

```
npm run test             # contrast + block markup + PHPCS + unit tests
npm run audit:contrast   # WCAG 2.2 AA over theme.json and every styles/*.json
npm run lint:blocks      # template/pattern markup parses, references resolve
npm run lint:php         # WordPress-Extra, must be zero
npm run lint:html        # validates artifacts/html/*.html
npm run make:pot         # regenerate languages/qwerty-soft-signal.pot
```

`npm run lint:php` needs `composer install` once.

## Naming — do not deviate

| Thing | Pattern | Example |
|---|---|---|
| Text domain | `qwerty-soft-signal` | `__( 'Send', 'qwerty-soft-signal' )` |
| PHP namespace | `Qwerty\Soft\…` → `inc/` | `Qwerty\Soft\Modules\Seo` |
| Constants | `QSOFT_*` | `QSOFT_DIR` |
| Hooks | `qwerty_soft/…` | `qwerty_soft/contact_recipient` |
| Block name | `qs/{slug}` | `qs/faq-item` |
| CSS root class | `qs-{slug}` | `qs-faq__question` |
| Pattern slug | `qwerty-soft-signal/{slug}` | `qwerty-soft-signal/hero-home` |
| Local PHP vars in render.php / patterns | `$qsoft_` prefix | `$qsoft_heading` |

PHP globals use `qsoft` rather than `qs` because PHPCS `PrefixAllGlobals`
rejects prefixes shorter than three characters. Block names and CSS classes
are not PHP globals, so they stay `qs`.

## Rules that are not negotiable

1. **Escape everything.** The only unescaped echoes allowed are
   `get_block_wrapper_attributes()`, already-rendered inner blocks, and
   `wp_json_encode()` inside a `<script>` — each needs a `phpcs:ignore` with a
   reason on the same line. Never pass `JSON_UNESCAPED_SLASHES` to
   `wp_json_encode()` inside a script element: slash escaping is what stops
   `</script>` breaking out.
2. **No hard-coded colours, sizes or spacing — in the theme.** Everything the
   theme itself ships resolves to `var(--wp--preset--*)` or
   `var(--wp--custom--*)`. If a token does not exist, add it to `theme.json`
   rather than writing a literal.

   **An imported design is the exception, and deliberately so.** A design is a
   brief, not a suggestion: the importer keeps the archive's own class names,
   installs the archive's own stylesheet, and leaves the archive's own values
   alone.

   **A section is wrapped, not translated.** `inc/Support/SectionPlan.php`
   reads a section and says what is editable in it; `inc/Support/BlockWriter.php`
   writes that section out as a block of its own under `blocks/design/{slug}/`
   — `render.php` holding the archive's markup verbatim, `style.css` holding
   the rules that target it, `fields.json` holding one ACF field per editable
   thing. Markup that is copied cannot lose a class, which is the whole point:
   the translating pipeline dropped 190 of the 253 class names the design's
   stylesheet targets, and the header and hero rendered as neither the design
   nor the theme.

   Generated blocks are ordinary registered blocks in the **Qwerty Soft
   blocks** category, so a section can be reused on any page from the
   inserter, carrying its stylesheet with it. They are excluded from the
   release ZIP and never touched by a theme update.

   `BlockConverter::faithful()` still draws the line for the older structural
   path, and it now reaches the model as well: when it is on, the conversion
   brief requires the archive's class names to be kept verbatim. Both halves
   used to disagree, and the disagreement was what produced unstyled pages —
   and what made the review pass run every round it was allowed. Filter
   `qwerty_soft/faithful_styles` to false for the old behaviour, where an
   import wears the theme's tokens instead.
3. **Re-run `npm run audit:contrast` after touching any palette.** It fails the
   build on a single pair below its minimum. `contrast` on `accent` is 1.88:1 —
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
  block.json        apiVersion 3, "name": "qs/{slug}", "category": "qs"
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
`esc_html_x( '…', 'Pattern placeholder text', 'qwerty-soft-signal' )`. Categories are
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
