# How the import works — specification

Internal. The agreed model for turning a delivered design into a WordPress
site. Written to be confirmed before the code follows it.

**Status: implemented, with one deliberate departure.** Sections are wrapped
(§2, §3, §4a, §5, §8 are what the code does). §4 is where the code departs from
the first draft: editing on the canvas is done on the section's own markup,
not through `InnerBlocks` — see §4 for why. §10's questions are answered at
the end.

---

## 1. The decision this replaces

Until now the importer *translated* a design: every section was read and
rewritten as core blocks wearing the theme's presets — `core/columns`,
`backgroundColor`, `fontSize`, spacing steps.

That approach is abandoned. It cannot produce the design, and the code proves
why: `BlockConverter::undress()` strips `align`, `layout`, `backgroundColor`,
`textColor`, `gradient` and `fontSize` from every block in faithful mode, while
the conversion brief simultaneously tells the model to drop the design's own
class names. Both halves ran on the same section. The result was a page of 77
groups and 102 paragraphs with no layout attribute anywhere — every two-column
section flattened into a stack.

**The new rule: a section is not translated. It is wrapped.**

---

## 2. What the importer produces

One generated block per section, in the theme, under a directory that is never
part of a release:

```
blocks/design/{page}-{section}-{hash}/
  block.json     name qs/design-{slug}; acf.renderTemplate; acf.mode preview
  fields.json    ACF local JSON — one field per editable element
  render.php     the design's own markup, verbatim, with field values echoed
  style.css      the CSS rules this section needs, lifted unchanged
```

The page itself becomes a short list of those blocks:

```html
<!-- wp:qs/design-home-hero-a3f1 /-->
<!-- wp:qs/design-home-stats-7b2c /-->
<!-- wp:qs/design-home-reports-1f9d /-->
```

`inc/Modules/Blocks.php` already discovers any directory under `blocks/`, so
nothing registers them by hand.

---

## 3. Styles are not touched

The section's CSS is **lifted, not rewritten**. `CssIndex::rules_for( $html )`
already returns the rules a given fragment needs; those rules go into the
block's own `style.css` with their selectors and values unchanged, and are
enqueued only on pages where that block appears.

Consequences, all deliberate:

- The design's class names stay on the markup. They are what its CSS targets.
- No mapping onto theme presets. No "nearest colour", no "closest spacing
  step", no dropped `font-family`. The gold is `#c7a85d` because that is what
  the design says.
- `BlockConverter::undress()` has nothing to remove, because nothing puts theme
  presets on these blocks in the first place.
- The 42 KB of Additional CSS the importer used to inline on every page is
  replaced by per-section files, loaded only where used.

---

## 4. Editing: both, always

Every generated block is editable **two ways at once**. This is a requirement,
not a preference.

**In the block, on the canvas.** The section is drawn as the design drew it,
and its editable elements are editable where they stand. The writer marks
every field's element in `render.php` — `data-qs-field="heading"` on the
heading, `data-qs-row="items"` on each repeated row — and
`assets/js/design-canvas.js` reads those marks after ACF renders the
preview and makes the elements themselves editable: click the heading and
type; click the picture and choose another from the Media Library; click a
button to change its words, with its address in a small bar beneath it;
hover a repeated row for "add a row" and "remove this row". What is typed is
written to the same block data the sidebar edits. On the front end the marks
are stripped (`DesignField::unmarked()`).

This is not `InnerBlocks`, and deliberately. The first draft put the flow of
a section into core blocks placed by an `<InnerBlocks>` template. Two things
ruled it out. ACF Pro allows one `<InnerBlocks />` per block, and a section's
editable text is scattered through its tree — a heading in one column, a lead
in another, a list of points inside each card — so one slot cannot hold it
without moving the design's markup around. And a core heading is a
`<h2 class="wp-block-heading">`, not the design's `<h2>` inside its own
wrapper with its own classes; the stylesheet would have had nothing to
target. Editing the design's own element keeps the markup verbatim, which is
the whole point of wrapping.

ACF's own form on the canvas is not available and cannot be made so from the
theme: ACF Pro forces preview mode whenever the editor canvas is an iframe,
which since WordPress 6.3 is every screen (`IMPORT_LESSONS.md` §5).

**In the panel on the right.** Every field is also an ACF field in the Block
tab of the sidebar: the words, the pictures, the links, and every repeatable
group as a repeater with "add row". Structural changes made on the canvas
reselect the block so the sidebar form is redrawn from the new data.

Neither half is optional. A block that can only be edited from the sidebar is
not accepted; neither is one whose repeatable parts can only be edited by
duplicating markup.

**What is a field.** Any element with words of its own — a heading, a
paragraph, a list item, and equally an eyebrow in a `<div>`, a glyph in an
icon box, a table cell, a button. Words with inline markup inside them (a
highlighted `<span>`, a `<br>`) are one *rich* field that keeps the markup,
reduced on both write and read to the tags in `SectionPlan::RICH_TAGS`.
Words beside decoration (an icon before a bullet) are a plain field planted
around the decoration. A link that is a whole card keeps its address as a
field and leaves what it holds to become fields of their own. An element
holding a block with words of its own — `<li>Plan<ul>…</ul></li>` — is a
frame, not a field.

---

## 4a. Three kinds of section

Not every section holds its own content. Which kind a section is decides what
its block contains, and getting this wrong is the difference between a site
somebody can run and a wall of hard-typed markup.

**One-off.** A hero, an about band, a call to action. Content lives in the
block: fields for the frame, `InnerBlocks` for the flow. One instance, one
page.

**Short fixed list.** Four fact tiles, five chips, three pricing tiers. An ACF
repeater on the block. The list is part of the section's design, not of the
site's data — nobody will ever want to filter or paginate four facts.

**A listing off a post type.** Six report cards, a grid of case studies, the
media mentions. These are *not* content of the section — they are the site's
own records, and the design's card is a template for one of them.

For these the importer creates a custom post type, turns each card in the
design into one post of it, and the block holds a **selection, not content**:
which post type, how many, which taxonomy, or an explicit list of chosen posts.
The design's card markup becomes the loop's item template, unchanged, with the
post's fields echoed into it.

The consequence worth stating: adding a seventh report is then publishing a
post, not editing a page. That is the difference between handing over a site
and handing over a mockup.

How a listing is recognised: sibling elements under one parent with the same
tag and class shape and differing text. The model confirms it and names the
type — that is the second of its three questions.

---

## 5. Build order

Reversed from what it is today, so the site looks like itself from the first
minute rather than the last:

```
1. tokens        colours, type and spacing read from the design
2. media         fonts, pictures, and the design's stylesheet
3. chrome        header and footer, built from the design
4..N pages       home first, then the rest
N+1. links       the menu resolved, links rewritten, front page set
```

The header currently depends on the menu, and the menu on built page IDs. It is
broken by making the menu on the design's own hrefs at step 3 and rewriting
them to real pages at the last step — which is what `relink_pages()` already
does for links inside pages, and what the menu already does for drafts.

---

## 6. What the model is for now

Not conversion. Its job shrinks to three questions per section, which is a
shorter and far more reliable request:

1. Which nodes are editable content, and what should each field be called?
2. Which parts repeat, and what is one row of the repeat?
3. What is this section, in three words, for the block's title?

It no longer decides layout, colour, spacing or typography, because none of
those are being changed. Most of the caveats the old pipeline produced — "the
kicker maps to accent-ink", "aurora may not be dark enough", "Georgia was
dropped" — stop existing rather than being reported.

---

## 7. Dependency

**ACF Pro is required** on any site built from an import. The repeater and the
image and link pickers are what remove the need to write an editor interface.

This is a change to what the theme claims. `zero required plugins` in
`CLAUDE.md`, `docs/TECHNICAL.md` and `docs/MANAGEMENT.md` must be rewritten to
say so, and the release ZIP must not pretend otherwise.

Settled: ACF Pro is on **every** site this theme is installed on. There is no
fallback path and none needs writing. Site Health says so if it is missing, and
that is the whole of the handling.

---

## 8. Removal

Generated blocks are files in the theme, not rows in the database. That breaks
the promise that an import can be taken back out in one press, so:

- they live under `blocks/design/`, which is excluded from the release ZIP and
  from any theme update;
- `Delete everything this import added` removes that directory as well as the
  pages, media and menus;
- a theme update never touches it.

---

## 9. What stays exactly as it is

- The theme's own six blocks, patterns, templates and style variations.
- `theme.json` and the token system, for everything the studio builds by hand.
- The quality gates: contrast audit, block lint, PHPCS, unit and integration
  tests. A generated block is subject to all of them.
- The structural, offline conversion — as the free mode, and as the floor when
  no model can be reached.

---

## 10. Confirmed

1. ACF Pro on every site the theme is installed on (§7). Without it a block
   still draws from its own data; only the editing is missing.
2. `blocks/design/` in the theme, excluded from the release ZIP and never
   touched by an update (§8).
3. A section with no editable content is still a block, so it can be placed
   on another page from the inserter; it simply has no fields.
