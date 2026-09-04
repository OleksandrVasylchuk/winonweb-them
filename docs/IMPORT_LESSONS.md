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
