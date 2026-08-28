# How the import works — specification

Internal. The agreed model for turning a delivered design into a WordPress
site. Written to be confirmed before the code follows it.

**Status: awaiting confirmation.** Nothing in the importer has been rewritten
to this yet.

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

**In the block, on the canvas.** Everything in the flow of the section —
headings, paragraphs, buttons, images — is a real core block placed by an
`<InnerBlocks>` template inside the ACF render template, with
`templateLock="all"` so the structure cannot drift from the design.

```php
<section class="section-dark hero">
  <div class="hero-grid">
    <div class="hero-copy"><InnerBlocks /></div>
    <?php // the frame around it comes from fields ?>
  </div>
</section>
```

Click the heading and type. Click the photo and press Replace. Select the
button and paste a URL.

**In the panel on the right.** Everything that is not in the flow is an ACF
field: the section background image, the decorative SVG, the anchor, the
variant, and every repeatable group — the four fact tiles, the six report
cards, the five chips. ACF Pro's repeater gives those an "add row" interface
with no editor JavaScript to write.

Neither half is optional. A block that can only be edited from the sidebar is
not accepted; neither is one whose repeatable parts can only be edited by
duplicating markup.

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

## 10. To confirm

1. ACF Pro everywhere, or only on imported sites?
2. `blocks/design/` in the repository, or in `wp-content/uploads`? In the theme
   is simpler and survives a deploy; in uploads keeps the theme clean.
3. When a design section has no editable content at all — a divider, a
   decorative band — generate a block anyway, or inline it as static markup?
