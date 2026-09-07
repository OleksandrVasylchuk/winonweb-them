# Qwerty Soft — Signal · Technical reference

For developers who will extend, maintain or hand off this theme.

**Version 1.4.0 · WordPress 6.7+ · PHP 8.1+ · no required plugins for the
theme itself; ACF Pro on any site built from a design import**

For the day-to-day workflow — adding a block, adding a pattern, running the
gates — see [`GUIDE.md`](GUIDE.md). This document is the map underneath it:
what the pieces are, why they are arranged this way, and which decisions are
not yours to change.

---

## 1. The property that shapes everything

**There is no build step.** No webpack, no SCSS, no ACF, no `npm install` on
the server. The files in the repository are the files that run.

This is deliberate, not an oversight. The theme is dropped onto whatever host a
project lands on — including ones the studio does not control, where there is
no Node, no shell and no patience for a pipeline. Introducing a bundler breaks
that, so editor JavaScript is written against the global `wp` object with no
JSX and no imports, and every block ships a hand-written `*.asset.php` beside
it.

Node is used only for the quality gates in `tools/`, which never run on a
customer's server.

---

## 2. Layout

```
theme.json                 design tokens — the single source of every value
styles/*.json              6 style variations
templates/*.html           10 templates — no shop; see shop-kit/
parts/*.html               header, footer, post-meta
patterns/*.php             17 patterns, PHP so copy can be translated
blocks/{slug}/             6 custom blocks, auto-discovered
shop-kit/                  the folded shop half — 7 templates + the module
inc/Modules/               18 modules — one concern each, booted from functions.php
inc/Support/               30 classes — the import pipeline and its parts
tools/                     the quality gates
tests/wp/                  integration tests against a real WordPress
artifacts/                 gate output, git-ignored
```

Roughly 35 000 lines of PHP, most of it the design importer.

### Modules

A module is a class implementing `Qwerty\Soft\Contracts\Module` with a
`boot()` method. `functions.php` instantiates the list; nothing else registers
hooks. Adding a concern means adding a file and a line.

| Module | Owns |
|---|---|
| `Setup` | `add_theme_support`, image sizes, nav menus |
| `Assets` | enqueueing, conditional loading, `defer` |
| `Blocks` | discovers `blocks/*/block.json`, registers block styles |
| `Patterns` | pattern categories |
| `Markup` | output filters — the theme's own HTML corrections |
| `Accessibility` | skip links, focus management, landmark rules |
| `Performance` | preloads, lazy-loading policy, emoji removal |
| `Security` | headers, XML-RPC, enumeration, login hardening |
| `Seo` | title, description, canonical, OpenGraph, JSON-LD |
| `Cleanup` | what core emits that this theme does not want |
| `Branding` | logo, colours, the customiser surface that remains |
| `ContactForm` | the POST handler behind `qs/contact-form` |
| `WooCommerce` | templates and conditional shop assets — ships folded in `shop-kit/`, so on a site with no shop the class does not exist and `Theme` asks before it boots it |
| `SiteHealth` | the theme's own Site Health checks |
| `Updates` | update checks, checksum verification |
| `Credit` | the designer credit, filterable |
| `Onboarding` | the four-step setup wizard under Appearance |
| `Importer` | the design import screen and its REST API |

### Naming — not negotiable

| Thing | Pattern | Example |
|---|---|---|
| Text domain | `qwerty-soft-signal` | `__( 'Send', 'qwerty-soft-signal' )` |
| PHP namespace | `Qwerty\Soft\…` → `inc/` | `Qwerty\Soft\Modules\Seo` |
| Constants | `QSOFT_*` | `QSOFT_DIR` |
| Hooks | `qwerty_soft/…` | `qwerty_soft/contact_recipient` |
| Block name | `qs/{slug}` | `qs/faq-item` |
| CSS root class | `qs-{slug}` | `qs-faq__question` |
| Pattern slug | `qwerty-soft-signal/{slug}` | `qwerty-soft-signal/hero-home` |
| Local vars in `render.php` | `$qsoft_` prefix | `$qsoft_heading` |

PHP globals use `qsoft` rather than `qs` because PHPCS `PrefixAllGlobals`
rejects prefixes under three characters. Block names and CSS classes are not
PHP globals, so they stay `qs`.

---

## 3. Design tokens

`theme.json` holds 13 palette colours, 8 spacing steps, 8 font sizes, 2 font
families, and custom groups for `focusRing`, `radius`, `transition`,
`tapTarget`, `hairline`, `icon`, `breakpoint` and `slider`.

**Nothing the theme ships hard-codes a colour, size or spacing.** Everything
resolves to `var(--wp--preset--*)` or `var(--wp--custom--*)`. If a token does
not exist, add it to `theme.json` rather than writing a literal.

The only CSS outside `theme.json` is per-block `style.css` and the
`inline_style` on registered block styles.

### The exception, and it is deliberate

An **imported** design keeps its own values. `BlockConverter::faithful()`
takes the theme's palette slugs, spacing steps, layout containers and width
alignments back out of the finished blocks, so an imported page renders as the
design did rather than as the theme would have preferred. What the conversion
improves is the markup underneath — real headings in order, real lists, real
buttons, alt text, one `h1`.

Filter `qwerty_soft/faithful_styles` to `false` for the old behaviour.

### Contrast

`npm run audit:contrast` checks every pair across `theme.json` and all six
style variations — 217 enforced checks — and fails the build on a single pair
below its minimum. Re-run it after touching any palette.

`contrast` on `accent` is 1.88:1, so labels on accent fills are always `base`.

---

## 4. Blocks

```
blocks/{slug}/
  block.json        apiVersion 3, "name": "qs/{slug}", "category": "qs"
  render.php        server render; $attributes, $content, $block in scope
  edit.js           (function(wp){ … wp.blocks.registerBlockType(…) })(window.wp)
  edit.asset.php    dependencies for edit.js
  style.css         front end and editor, tokens only
  view.js           optional; needs view.asset.php
```

`inc/Modules/Blocks.php` discovers the directory automatically. There is no
registration code to write.

**A new block needs `*.asset.php`.** WordPress refuses to register a block
script when the sibling asset file is missing. Copy an existing one.

The six blocks are `colophon`, `contact-form`, `faq`, `faq-item`, `metric`,
`slider`.

### Progressive enhancement is a hard rule

Every interactive thing works without JavaScript:

- the FAQ is native `<details>`
- the slider is a scroll container; its arrows ship `hidden` and are revealed
  by `view.js`
- the contact form is a plain POST
- counters render their final figure server-side

And: **never trust `requestAnimationFrame` to finish.** It stops in background
tabs. Anything animating a value needs a `setTimeout` that snaps to the real
value regardless — see `blocks/metric/view.js`.

---

## 5. Escaping

The only unescaped echoes allowed are `get_block_wrapper_attributes()`,
already-rendered inner blocks, and `wp_json_encode()` inside a `<script>`.
Each needs a `phpcs:ignore` with a reason on the same line.

Never pass `JSON_UNESCAPED_SLASHES` to `wp_json_encode()` inside a script
element: slash escaping is what stops `</script>` breaking out.

---

## 6. The design importer

The largest subsystem, and the one most likely to need work. It takes a ZIP of
an HTML design — or of a React/Vue application — and produces WordPress pages,
a menu, a header, a footer and a front page.

### The pipeline

```
ZIP
 ↓  DesignArchive::unpack()      vet every entry, open nested archives
 ↓  DesignArchive::index()       pages, stylesheets, images, languages
 ↓  SourceProject::all()         applications inside the archive, if any
 ↓  SourceRenderer               one model call per route → HTML on disk
 ↓  DesignTokens / DesignFonts   palette, type and spacing out of the CSS
 ↓  SectionSplitter              one page → its sections
 ↓  BlockConverter               section markup → block markup (offline)
 ↓  SmartConverter               the same section, corrected by a model
 ↓  BlockMarkupValidator         the reply parses and references resolve
 ↓  SiteAssembler                pages, menu, header, footer, front page
```

Every step is checkable on its own and every step has a floor: when a model
cannot be reached, or answers something the validator rejects, the structural
conversion stands. A build never produces nothing because a model was
unavailable.

### The four conversion routes

1. **Structure only** — offline, free, seconds. No key, no network.
2. **Your Claude subscription** — copy the brief into a chat, paste the reply
   back. Better judgement than the button.
3. **An Anthropic API key** — one click per page, billed per conversion.
4. **Claude Code on this machine** — the same automatic route through the
   `claude` binary, using the subscription it is signed in to.

`ModelGateway` chooses; `AnthropicClient` and `ClaudeCli` are the transports.
`Spend` does the cost arithmetic and the estimates.

### Three build modes

| Mode | What it does | Cost |
|---|---|---|
| Straight through | Sections wrapped, fields named by code | Free, seconds |
| Corrected by Claude | Each fresh section's fields named and its kind judged by a model (`PlanReview`) | One small call per section |
| Corrected and checked | The same, then every page photographed beside its design in headless Chromium and its blocks corrected as files by Claude Code until the two agree (`PixelReview`) | One agent turn per look, at most two looks per page |

The third mode runs only where it can: the `claude` binary on this machine,
Node and Playwright beside the theme. Anywhere else it is skipped with a line
in the log. It writes `artifacts/pixel-manifest.json` on every build either
way, so `npm run audit:pixels` compares the finished site with one command.

### Running a build unattended

`BuildRunner` continues a build from WP-Cron rather than from the browser: one
step per tick, each step idempotent, the next tick booked *before* the work so
a step that kills the process still leaves a tick behind.

Constants worth knowing: `INTERVAL` 5 s, `LEASE` 300 s of silence before a step
is treated as abandoned, `MAX_ATTEMPTS` 3, `TICK_SECONDS` 240 of work per tick.

`BuildRunner::patrol()` runs on `wp_loaded` at most once a minute and revives
any build that has stopped. `BuildRunner::diagnose()` reports a stall to the
screen; `BuildRunner::resume()` runs the next step in the request rather than
waiting for a scheduler that may never come.

**The honest limit:** WP-Cron fires on requests. A site nobody visits advances
only when somebody opens it — usually the import screen polling its own log.

### Things that have bitten, and the guards that now exist

- **Nested archives.** A handoff is a box of boxes. Each inner ZIP is opened in
  place and vetted like the outer one. One archive that fails no longer aborts
  the rest, and anything still boxed at the end is named in the log.
- **Windows path length.** Windows refuses a path over 260 characters unless
  the binary has opted out, and Apache's PHP usually has not. The design slug
  is capped at 28 characters and a nested archive gets a short folder name when
  the readable one would not fit. Silence here once cost an entire website.
- **Slug collisions.** Two `reports.html` in one package both want `/reports/`.
  WordPress lets a draft and a published page share a slug, and the live URL
  then resolves to neither. The build list marks colliding addresses before the
  press.
- **Admin screens.** A handoff ships dashboards, queues and upload forms.
  `SiteAssembler::is_utility()` keeps them out unless asked for — and the same
  test runs on the screen, so the list and the build cannot disagree.

### Extending the importer

- `qwerty_soft/faithful_styles` — false to make imports wear the theme's tokens
- `SourceProject` — add a router dialect in `declared_routes()`
- `DesignDocs` — change how the handoff's own writing is ranked and budgeted
- `BlockConverter` — add an element mapping; keep the offline path working

---

## 7. Quality gates

```
npm run test             contrast + block markup + PHPCS + unit tests
npm run audit:contrast   WCAG 2.2 AA over theme.json and every styles/*.json
npm run lint:blocks      template and pattern markup parses, references resolve
npm run lint:php         WordPress-Extra, must be zero
npm run lint:html        validates artifacts/html/*.html
npm run test:unit        pure PHP under tools/test-support.php
npm run test:wp          integration tests against a real WordPress
npm run audit:a11y       axe-core in a real browser
npm run make:pot         regenerate the POT
npm run i18n             POT and MO together
npm run release          test + build the distributable ZIP
```

`npm run lint:php` needs `composer install` once.

Current state: **PHPCS 96/96 · 6 987 unit checks · 817 integration checks · 0
failures · uk catalogue 956/956.**

### Testing against a real site

The repository has no WordPress in it. Point a local WordPress at the theme,
then `npm run fetch:html -- http://localhost` and `npm run lint:html`.

Accessibility is checked with axe-core in a real browser. Structure rules tell
half the story; colour contrast over the hero gradient has to be reasoned about
mathematically because axe reports it as *incomplete*.

---

## 8. Translation

Strings live in `tools/i18n/{locale}.json`, grouped by gettext context, so a
translator edits readable JSON rather than PO syntax. `npm run make:mo` builds
`languages/uk.po`, `uk.mo` and the per-script JSON catalogues from the POT plus
that dictionary. The binary artefacts are always regenerated, never edited.

A plural is keyed as `msgid|plural|N`. A context is its own top-level group.

Ukrainian is complete at 956/956.

---

## 9. Where to start reading

| Question | File |
|---|---|
| How is a request set up? | `functions.php`, `inc/Modules/Setup.php` |
| How does a block get registered? | `inc/Modules/Blocks.php` |
| How is a design turned into blocks? | `inc/Support/BlockConverter.php` |
| How does a long build survive? | `inc/Support/BuildRunner.php` |
| What does the import screen do? | `assets/js/admin-import.js` |
| What is the REST surface? | `inc/Modules/Importer.php`, `register_routes()` |
| What does a new page cost? | `inc/Support/Spend.php` |
