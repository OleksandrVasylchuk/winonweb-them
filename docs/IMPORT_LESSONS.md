# What past imports taught the importer

Every rule below was paid for once, by an import that shipped wrong. They are
written down because the fixes live in code where nobody reads them, and the
next person — or the next model — re-derives the same mistake from the same
plausible-looking starting point.

`inc/Support/Lessons.php` is the runtime companion to this file: it journals
what each build actually ran into, per site, and hands that to the next one.
This file is the part that generalises.

---

## 1. The fix goes in the generator, not in what it generated

The single most expensive lesson here. A studio-wide fix was once applied as a
one-off migration over the blocks already on disk; `BlockWriter::block_json()`
still wrote the old shape, so the next archive import resurrected every symptom
and the migration had to be found again from scratch.

**Regeneration is the test.** After fixing anything about a generated block,
delete the block folder and rebuild the page. If the fix does not survive that,
it is not a fix.

## 2. Keep the design's stylesheet whole

The importer used to slice the archive's CSS into a per-block `style.css`. That
re-runs the cascade in page-load order, and rules from other pages of the same
archive clobber the current ones. A stale `.brand-mark` rule from a third,
older stylesheet in the handoff won over both current ones, and the logo
rendered as an outline nobody could find a source for.

A design genuinely has **one** stylesheet — usually the one every reference
page links. So:

- `BlockWriter::write_canonical()` writes `_canonical.css`/`.js` for the
  archive's primary source, and `_canonical-{key}.css`/`.js` for each other
  source, deleting stale ones.
- `inc/Modules/Blocks.php` registers them by glob under `qs-design-canonical`
  handles; every generated `block.json` names its own source's handle in both
  `style` and `viewScript`. No slice is written.
- `DesignStylesheet::routes()` / `compile_sources()` group pages by the
  stylesheets they actually link, because **a handoff can carry more than one
  site**. One that carried two concatenated them, and the older sheet
  re-clobbered `:root`, the header and the brand mark site-wide.

The same applies to the archive's JavaScript: it is imported whole (each file
in an IIFE, so one broken file cannot take the rest down) rather than
reconstructed. Before that, no generated block shipped a `view.js` at all and
the design's own behaviour — a mobile menu, a year filled in by script — was
simply missing.

## 3. The cascade traps, both of them

**`theme.json` element styles beat a block's own stylesheet.**
`styles.elements.heading.color.text` emits `h1,…,h6{color:var(…)}` — specificity
0-0-1, same as a design's bare `.hero h1`, and it loads later in `<head>`.
Imported sections that rely on inherited colour lost their headings to it: dark
text on a dark hero, effectively invisible. `BlockWriter::mark_root()` puts a
`qs-design` class on every generated section root and each stylesheet carries
`.qs-design h1..h6{color:inherit}` — 0-1-1, which wins regardless of order.

**Additional CSS loads last and wins everything.** `DesignStylesheet` can also
install a copy of the archive's CSS into WordPress's user global styles. A
stale copy there beat every corrected per-block rule. When a symptom survives
a correct stylesheet, look in global styles before looking anywhere else;
`DesignStylesheet::reset()` removes the slice.

## 4. `<picture>` fails worse than `<img>`

An archive's `<picture><source srcset="…webp"><img src="…jpg">` renders as
**nothing** when the `<source>` 404s — browsers do not fall back to the `<img>`.
Photos came out as empty black boxes.

`relink_media()` strips any `<source>` whose `srcset` did not resolve to an
absolute URL, so the browser falls through to the already-resolved `<img>`.

**Still open:** nothing crawls a `<source>`'s `srcset` to decide what to
import, so `.webp` variants sitting in the archive are never queued. An image
that exists *only* as `.webp` is not saved by the strip above.

## 5. ACF, and the one thing not to attempt

- **Repeater sub-fields must not carry the block-slug prefix.** ACF stores a
  top-level option at `options_{name}` (one flat namespace, so the prefix is
  right there) but a repeater value at `options_{repeater}_{n}_{name}`, where
  the repeater already scopes it. Prefixed, ACF found nothing, returned the
  field's default, and `render.php` — reading the row by plain name — found no
  key at all. Seven footer links rendered as empty `<a>`s while the correct
  data sat in `wp_options` the whole time. `acf_field()` takes a `$row` flag.
- **Set `collapsed`** to a sub-field key, or every repeater row reads "Link"
  with no way to tell them apart (`BlockWriter::row_summary()`).
- **The block preview/edit toggle cannot be fixed from the theme. Do not try
  again.** ACF Pro forces preview mode when the editor canvas is iframed, and
  the canvas is iframed whenever every registered block is apiVersion 3.
  Registering one apiVersion-2 block would un-iframe it — and then the design's
  canonical CSS, which styles `:root`, `body` and `a`, would repaint wp-admin
  itself. What is done instead: every generated block's description ends with
  a line saying its text is edited in the Block tab of the sidebar.

- **A field's name is positional, and the option key is the whole of a row's
  identity.** SectionPlan calls a footer's labels `label`, `label_2`, `label_3`
  in the order it meets them, and ACF stores each at `options_{block}_{name}`.
  So a generator that learns to see one more element — version 9 made the word
  inside `<a class="brand">` a field of its own — shifts every later name down
  a place, while the rows written by the earlier build stay where they are,
  under names that now mean something else. On a live site the footer's brand
  read "© Chalir. All rights reserved." and the copyright line showed the
  tagline twice, for weeks, with the correct words all present in `wp_options`.
  `SiteOptions::seed()` therefore keeps a print of what it wrote
  (`SiteOptions::SEEDED`): a row still holding exactly that is the design
  talking to itself and is refreshed, a row holding anything else is somebody's
  and is left alone. A row seeded before that record existed cannot be told
  apart and is left alone — which is why a site built before this needs its
  chrome rows put right by hand once.
- **A copyright line is words, not markup.** `plant()` renders a dated field
  through `esc_html( DesignField::dated( … ) )` so the year can be written into
  the words; a design that fills its own year in the browser writes
  `© <span id="year"></span> Name`. Version 9 taught text fields to keep their
  inline markup, and the two rules met: the span was kept, `dated()` wrote the
  year in beside it, and the page printed "© 2026 <span id="year"></span>
  Chalir" with the tags spelled out. A field that renders as `dated` is read as
  words. More generally, a field is offered as rich only when the design
  actually put markup in it — promising an editor that `<strong>` survives when
  the page escapes it is worse than saying nothing.

## 6. Listings, records and the empty grid

- **A listing with no usable singular is not a listing.** The review could
  return `kind: listing` without one; then nothing seeded records and the
  section rendered an empty grid. Fresh plans downgrade it to `repeat`.
- **`BlockWriter::adopt()` takes the kind from the block on disk** (a repeater
  means repeat, source + limit means listing) instead of re-guessing on every
  re-run.
- **Rows that diverge are not rows.** Three pricing tiers with different bullet
  lists, a thirteen-row spec table — stamping them from row one loses the
  content. `SectionPlan::rows_diverge()` flips a would-be repeat (never a
  listing) to `single`, keeping the markup verbatim with flat fields.
- **Card links must be the record's permalink.** Frozen from the example card,
  every card on the site pointed at the first record's root-absolute address —
  a 404 on any subdirectory install. `DesignListing::row()` supplies
  `permalink`; `render_php()` plants it on the row-root anchor.
- **A theme record type must not claim a rewrite base another post type owns.**
  A `product` slug hijacked WooCommerce's product permalinks;
  `DesignType::slug_for()` falls back to the `qs-` prefixed slug.
- **Multilingual:** records are stamped with the page's language and deduped
  per title **and** language — otherwise a translated card carrying an
  untranslated product name matches the original record and seeds nothing.
  `DesignType::key()` needs its hash fallback for non-Latin singulars, which
  sanitise to an empty string and turn a grid into a listing over post type
  `''`, i.e. blog posts.

## 7. Small ones that cost an afternoon each

- **A copyright year filled in by script has no literal year to find.**
  `is_dated()` triggers on the copyright mark alone, and `dated()` writes the
  year in when there is none to replace — otherwise the footer reads "©  Name"
  with a hole in it forever.
- **A slug truncated to a fixed length can cut the digest off the end**, and
  two different sections collide. The digest is appended after the cut.
- **A rendered SPA page needs its stylesheet linked.** `SourceRenderer` writes
  pages from a router; without a `<link>` the markup is pure utility classes
  over nothing, and the build groups those pages into a stylesheet-less source.
  `stylesheet_links()` prefers compiled build output (`dist`, `build`, `out`,
  `_site`, never `node_modules`), plain source CSS as a fallback, and never
  raw Tailwind sources.
- **A SPA's shell is not its first page.** `chrome()` and `menu_from_design()`
  took `pages[0]`, which for an application handoff is the empty `index.html`
  — so no header, no footer, no menu. Both use the first page whose `shell` is
  false.
- **Match navigation links by slugified whole path, not basename.** A rendered
  SPA header links routes (`/services`, `/my-project/projects`); basename
  matching found nothing, and the real header was discarded in favour of a nav
  file that did not exist.

## 8. Builds that look stalled

- A build started from the screen **without "run on server"** is driven by the
  browser tab (`unattended: false`); cron ticks decline it silently, so walking
  away from the tab is indistinguishable from a stall. Flip
  `$job['unattended'] = true` and call `BuildRunner::schedule()` to hand it to
  the server.
- The old WP-Cron hand-off loss — a tick that lost the step claim consumed a
  scheduled event without re-booking it, so every stall landed exactly on a
  tick boundary — is fixed: the claim-loser re-books, and a burst that runs out
  with work left calls `schedule()` explicitly. Symptom to recognise if it ever
  returns: the last log line is "Built <page>", no "Building <next>" for
  minutes, and no `qwerty_soft_build_tick` booking in the `cron` option.

## 9. What the wrap must keep, and how it is edited

- **A field claims its subtree, so what is inside it travels with the words
  or is lost.** `plant()` wiped the children to write a text field, and
  `<h1><span class="gradient-text">Your Security Program,</span><br>Built and
  Managed.</h1>` rendered as "Program,Built" with the highlight's rule left
  targeting nothing. A field whose element holds inline markup is *rich*: its
  value is the inner HTML reduced to `SectionPlan::RICH_TAGS`, echoed through
  `DesignField::inline()` (`wp_kses()` over the same list). Decoration
  without words — an icon before a bullet — stays in the template and the
  words are planted around it (`plant_in_text()`).
- **libxml parses HTML 4.** `<source>` is not void to it, so the `<img>` of a
  `<picture>` arrives *inside* the last `<source>`. Anything that walks to
  "the picture's img" has to walk up, not sideways.
- **The outermost list is the list.** `repeating_group()` picked the largest
  run of identical siblings, and three cards each holding four bullets lost
  to the first card's four `<li>`s: one card got a repeater, two got flat
  fields. A candidate group nested inside another candidate's row is that
  row's content and is dropped before the largest is chosen.
- **A residual that differs by a glyph is still a difference.** Three cards
  whose only non-field difference was the icon in each one's box passed
  `rows_diverge()` (under the 30-character bar) and came out wearing the
  first card's icon three times. Any element with words of its own is now a
  field — the glyph, an eyebrow in a `<div>`, a table cell — so the
  difference is a value, not a residual.
- **The holder of a repeat is not only rows.** `keep_first_row()` and
  `values()` took every element child of the holder; a grid's heading became
  a row, or was deleted. Both match the row signature now
  (`SectionPlan::is_row()`).
- **Measure from the block's own data.** `Fidelity` rendered through
  `get_field()` in the same request that rewrote the field groups, so ACF
  answered from last version's groups and a good page measured 74%.
  `DesignField::$raw` makes the reading come from the block for the
  duration of the measurement. And the structure score has to be a
  multiset: with `array_diff()`, one rendered `div.card` "covered" nine.
- **The design owns html, body and :root under wrapping.** They used to keep
  only their custom properties, a rule from the translating era, and every
  dark design rendered on the theme's white body. The editor canvas is an
  iframe, so a body rule there paints the canvas and nothing else.
- **theme.json elements beat a design's bare tag rules, so lift the design.**
  `DesignStylesheet::scoped()` appends `:is(.qs-design, .qs-design *)` to the
  subject of every non-root selector — one class, uniformly, so the design's
  rules keep their order among themselves and all sit above the theme's.
  `write_canonical()` puts resets *before* the sheet (`revert` on what
  `elements.heading/link/button` set), so the design's later rules win ties.
- **`@import` is a file, not a statement to drop.** Inline what is in the
  archive; hoist what is not to the top of the sheet, ahead of the resets,
  because an `@import` anywhere else is ignored.
- **The theme's block gap is 12px the design never had.** Every section
  measured the same height as the design's and the page was still 156px
  taller: `.is-layout-flow > * + *{margin-block-start:var(--wp--style--block-gap)}`
  between every pair of blocks, and again between the header part and the
  content. The resets zero `margin-block` on any child of a layout container
  that is, or holds, a `.qs-design` element. Found by measuring landmark
  offsets in Playwright, not by reading CSS — the review model reasoned it
  was `body{font-size}`, and it was not.
- **Global styles print after the design's sheet.** `body{font-size…}` from
  theme.json wins every tie with a design's own `body{…}`, so the design's
  body rule is lifted onto `body:is(.qs-design-site, .editor-styles-wrapper)`
  (the site's body carries the first class via `body_class`; the editor
  canvas already carries the second). The resets use the same selector.
- **What differs between rows in an attribute is content.** A card numbered
  by `data-no` and drawn by `content:attr()`, a bar sized by an inline
  `style="width:82%"`: no text to be a field, so every row wore the first
  row's. `SectionPlan::varying_attributes()` walks the first row's subtree,
  and any `data-*` or `style` whose value differs at the same path in another
  row becomes a row field written back into that attribute.
- **Nothing measured in words can see a colour.** `Fidelity` counts words and
  elements; a page can score 97% and still be the wrong colour on the wrong
  background. `PixelReview` photographs the built page and the design in the
  same headless browser, hands both pictures and the difference to Claude
  Code with write access to that page's blocks only, and looks again. The
  draft is admitted to the browser by a one-time key that is accepted from
  the loopback address alone and dies with the photograph. It runs where
  the `claude` binary, Node and Playwright are — a studio machine — and is
  skipped, with a line in the log, anywhere else.
- **In the editor, ACF spells a block's data by field key, not by name.**
  The importer writes `heading` and a flat `items_0_point`; the moment the
  editor loads the block, ACF rewrites it to `field_qs_…_heading` and
  `{ "row-0": { "field_qs_…_row_point": … } }`. Writing by name into that
  is silently ignored — the post went dirty and nothing changed. The canvas
  script addresses values by key (`DesignBlocks::field_labels()` ships the
  keys) and still reads the flat spelling for a block ACF has not touched.
- **Editing on the canvas is done on the design's own elements.** ACF forces
  preview mode in the iframed editor (§5), so `render.php` carries
  `data-qs-field`/`data-qs-row` marks and `assets/js/design-canvas.js` makes
  the marked elements editable after each preview render, writing to the same
  block data the sidebar edits. Front-end output strips the marks. Do not
  reach for `InnerBlocks`: one slot per block, scattered text, and a core
  heading is not the design's heading.
- **A field group long enough to be a wall is divided by the design's own
  structure, not by an invented one.** Nineteen fields in one list told an
  editor which link they were looking at and nothing about which of three
  columns it stood in. Every field carries the address `path_of()` recorded, so
  the columns divide themselves: `BlockWriter::tabbed()` descends those
  addresses to the first level that branches and makes each branch a tab, named
  by the holder's class where it reads as a name (`.footer-brand` is "Footer
  brand"), else by the words in it (a column headed "AI Security" is the AI
  Security tab). Three rules keep it from making things worse — under ten
  fields nothing is divided, a part of one field means the markup is offering
  the layout's structure rather than the page's and the group stays flat, and a
  class that names every part (three cards all held by a `.task`) names none of
  them and gives way to the words. A design whose columns are a repeat never
  reaches any of this: SectionPlan reads them as rows first, which is right.
- **A field group is drawn on two screens of very different widths, and has to
  know which.** The Site content screen is as wide as the page: tabs across the
  top, and `side_by_side()` putting a run of three links in a row. The block
  sidebar is a column about 280 pixels across, where five tabs wrap into a
  stack of stubs and a field given a third of the width is a 90-pixel box under
  a two-line label — "Approve privileged-access remediation plan" was being
  typed into one. So `self::$scope` decides: option scope gets tabs and widths,
  block scope gets accordions (open on the first, several at once) and nothing
  side by side. A divider's label is cut to 28 characters either way; it is a
  handle, and the design's whole sentence is still in the field under it.
- **The canvas panel is half the canvas, not a strip beside it.** At 340px it
  was narrower than the sidebar it exists to replace. It now opens at 50% of
  the canvas with the fields in as many columns as fit, cycles half → whole →
  narrow on one button, remembers which in `localStorage`, and can be dragged
  wider (`resize: horizontal` with `direction: rtl`, which is what puts the
  grip on the left where a right-anchored panel needs it).

---

## Verifying a claim about a running site

- **Refetch. Never reason from `artifacts/html/*`** — those are one-off fetches
  from a past verification pass and go stale the moment blocks are rebuilt or
  the front page changes.
- **Confirm which install you are looking at.** One theme checkout can serve
  several local WordPress installs (a junction into more than one `themes/`
  directory), each with its own database. A REST check against one says
  nothing about the other's content, and a wrong-install check once produced a
  confident "the site was never published".
- When `wp-json/wp/v2/…` 404s because pretty permalinks do not resolve under a
  subdirectory install, use the query-var form:
  `?rest_route=/wp/v2/pages&per_page=100&_fields=id,slug,link,title`.
- **There is no WP-CLI entry point** — the importer is a REST-backed wp-admin
  screen. To drive it from a terminal, run PHP against the install's
  `wp-load.php` with `WP_USE_THEMES` defined false and
  `$_SERVER['HTTP_HOST']`, `SERVER_NAME` and `REQUEST_URI` set; a PHP binary
  without mysqli will fail here, so use the one that ships with the local
  server stack and pass its `php.ini`.
