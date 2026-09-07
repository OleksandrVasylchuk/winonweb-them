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
npm run test:wp          # integration tests against a real WordPress
npm run audit:pixels     # Playwright screenshot diff, design vs built page
npm run build:zip        # the release archive + update.json
```

`npm run lint:php` needs `composer install` once; `npm install` covers the
rest. `npm run test` is the gate that must be green and needs no WordPress —
it is what CI runs. `npm run test:wp` finds the install through
`QSOFT_WP_PATH`, or by looking for a `wp-load.php` above the theme, and skips
rather than fails when there is none.

PHP does not have to be on PATH: `tools/php.mjs` looks there first, then in
the usual XAMPP and Laragon locations. Add a path to it rather than working
around it — every gate asks that one file where PHP is.

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
   release ZIP, because they belong to one site.

   **That exclusion is also why a theme update destroys them.** WordPress
   updates a theme by deleting its folder and unpacking the new one
   (`Theme_Upgrader::upgrade()` passes `clear_destination => true`), so
   everything the new package does not carry goes — `blocks/design/` and the
   unfolded `shop-kit` copies alike. `Support\BlockRepair` can rebuild the
   blocks from the site that is using them, and `Modules\BlockRecovery`
   catches the moment: an update or a theme switch raises a flag, the next
   admin page rebuilds what it can and says so, and where the design is no
   longer unpacked the notice stays up. Do not write anything a site owns
   into the theme folder without giving it the same treatment.

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
7. **The theme ships from zero — no shop, no forms plugin.** The seven shop
   templates and `Modules\WooCommerce` live folded in `shop-kit/`, outside
   where WordPress looks; `Support\ShopKit` copies them into place when an
   import finds a shop, in the same click that installs WooCommerce. So
   `Theme` asks `class_exists( Modules\WooCommerce::class )` before it boots
   the module, and `build-zip.mjs` excludes the unfolded copies — a studio
   machine that ran a shop import must not ship one client's cart to the next.
   A form is answered the same way, by not installing anything:
   `BlockWriter::wire_forms()` points the design's own markup — every class of
   it — at `Modules\ContactForm`, and `Support\DesignForm` prints the hidden
   half that handler reads.

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

## Working on the importer

**Read `docs/IMPORT_LESSONS.md` before changing anything in the import
pipeline.** It is every rule an import taught this codebase the expensive way —
why the design's stylesheet is kept whole, which cascade beats which, why a
repeater sub-field must not carry the block prefix, why the ACF preview toggle
must not be attempted again. Each one looks like an odd choice in the code and
like an obvious mistake to repeat.

Two of them decide how to work rather than what to write, so they are here too:

- **A fix lands in the generator, not in what it generated.** A studio-wide fix
  applied only to the blocks on disk was undone by the next import, which is
  how it was found twice. After fixing a generated block, delete its folder and
  rebuild the page: regeneration is the test.
- **Verify against the site, not against `artifacts/`.** Those files are one-off
  fetches from a past pass and go stale as soon as blocks are rebuilt. Refetch
  the page. One theme checkout can also serve more than one local install, each
  with its own database — confirm which one is being looked at before
  concluding anything about content.

## Editing a generated block on the canvas

ACF Pro forces preview mode in the iframed editor, so its form never appears
on the canvas. Generated `render.php` marks every field's element
(`data-qs-field`, `data-qs-type`) and every repeated row (`data-qs-row`,
`data-qs-index`); `assets/js/design-canvas.js` reads the marks after ACF
renders the preview and makes those elements editable in place, writing to
the same block data the sidebar edits. `DesignField::unmarked()` strips the
marks on the front end. Keep the marks in the generator, not in the blocks.

## Verifying against a real site

The repo has no WordPress in it. To test, point a local WordPress at the theme,
then fetch pages into `artifacts/html/` and run `npm run lint:html`.

On this machine two installs serve one checkout: `winonweb-them` (real
content, do not run the integration suite against it) and `wow-sandbox`
(database `wow_signal_wizard_test`). Run integration tests as
`QSOFT_WP_PATH=D:/Work/XAMPP/htdocs/wow-sandbox npm run test:wp`. The PHP with
mysqli is `D:/Work/XAMPP/php/php.exe` with its own `php.ini`. Accessibility
is checked with axe-core in a real browser — structure rules only tell half the
story, and colour-contrast over the hero gradient has to be reasoned about
mathematically because axe reports it as "incomplete".

There is no WP-CLI entry point: the importer is a REST-backed wp-admin screen.
To drive it from a terminal, run PHP against the install's `wp-load.php` with
`WP_USE_THEMES` defined false and `$_SERVER['HTTP_HOST']`, `SERVER_NAME` and
`REQUEST_URI` set. A PHP binary without mysqli cannot do this — use the one
from the local server stack, with its own `php.ini`.

One shell nuance, because it has eaten a namespace more than once: heredocs and
`sed` in this environment swallow backslashes, so `Qwerty\Soft` comes out as
`QwertySoft` and the file no longer parses. Write PHP through the Edit and
Write tools, or through `perl -0pe`; check with `php -l` when unsure.

## Directory layout

```
theme.json · styles/light.json     tokens + style variation
templates/ · parts/ · patterns/    the site, editable in the Site Editor
blocks/{slug}/                     custom blocks, auto-discovered
shop-kit/                          the shop half, folded until an import needs it
inc/Modules/                       one concern per file
tools/                             the quality gates
artifacts/                         gate output, git-ignored
```
