# Accessibility conformance report — Qwerty Soft — Signal

**Product:** Qwerty Soft — Signal, a WordPress block theme
**Version covered:** 1.2.0
**Report date:** 17 August 2026
**Standard:** WCAG 2.2, Level AA
**Prepared by:** Qwerty Soft — <https://qwerty-soft.com/>

---

## What this document is, and is not

This is a statement about **the theme**. It is not a statement about any site
built with it.

A theme supplies markup, colour and behaviour. It cannot supply alt text,
heading order inside a page a client wrote, caption tracks on a video somebody
embedded, or a PDF a client uploaded. A site built on this theme can fail WCAG
in ways the theme cannot prevent, and this report does not claim otherwise.

What the theme is responsible for, and what has been tested, is set out below —
including the parts that are still open. A conformance report that lists no
open items is usually a report where nobody looked.

---

## Summary

| | |
|---|---|
| Colour contrast contract | Automated, enforced on every build — 217 checks across 7 palettes, 0 failures |
| HTML validity and WCAG techniques | Automated, 13 rendered pages of real content, 0 errors |
| axe-core 4.10, real browser | 0 WCAG violations · 1 best-practice finding · 1 area machine testing cannot decide |
| Manual keyboard and screen reader testing | **Not yet performed for this release — see Open items** |

---

## 1. Colour and contrast

Every colour the theme renders comes from a token in `theme.json` or in one of
the six style variations. `tools/contrast-audit.mjs` runs on every build and
checks each pair the theme actually puts on screen against the ratio that pair
needs:

- body and secondary text on page, card and raised-card backgrounds — **4.5:1**
  (WCAG 1.4.3);
- link, accent, success and warning text on those same backgrounds — **4.5:1**;
- button labels on every accent fill — **4.5:1**;
- form control borders and focus indicators — **3:1** (WCAG 1.4.11).

The audit discovers style variations from the `styles/` folder, so a palette
cannot be added without being held to the same contract. **A single pair below
its minimum fails the build.** Current result: **217 enforced checks across 7
palettes, 0 failures.**

Two design rules follow from the audit and are load-bearing:

- **A label on an accent fill is always `base`, never `contrast`.** On the
  default palette `contrast` on `accent` measures 1.65:1 and must never be used.
- **The focus indicator is a two-tone ring** — an `accent` outline separated
  from the element by a `base`-coloured gap — so it keeps 3:1 on light fills,
  dark fills and accent fills alike.

Card fills and hairline dividers are decorative. WCAG 1.4.11 exempts them, and
the audit reports them as INFO rather than enforcing a ratio, so a regression is
still visible without failing the build for something the standard does not ask
for.

Brand colours entered in the setup screen are **not used exactly as given.**
`Qwerty\Soft\Support\BrandKit` keeps the hue and walks the lightness until the
derived colour clears the same contract, with a small margin. This was swept
over 127 brand colours across both light and dark modes — 6,858 pairs, 0 below
threshold — so a client cannot produce an inaccessible palette from that screen.

## 2. Markup

`npm run lint:html` validates rendered pages with `html-validate`, with these
WCAG techniques switched on as errors: **H30, H32, H36, H37, H63, H67, H71.**

The pages are fetched from a running site with real content, not from fixtures
(`npm run fetch:html`). For this release that was 13 pages — front page, nine
content pages, a search results page and a 404. **Result: 0 errors.**

Eight rules are deliberately relaxed, each for a stated reason. Three concern
the theme's own markup:

- **`valid-id`** is relaxed to the HTML5 definition. WordPress core emits script
  module ids such as `@wordpress/interactivity-js-modulepreload`. HTML5 permits
  any non-empty id without whitespace; the rule's default is the stricter HTML4
  one.
- **`no-redundant-role`** is off. `.qs-slider__track` is a `<ul>` with
  `list-style: none`, which makes Safari drop list semantics; `role="list"`
  restores them. The role is redundant per spec and necessary in practice.
- **`long-title`** is off. It measures the length of a `<title>`, which is
  written by whoever wrote the page, not by the theme. It is an SEO guideline
  rather than a WCAG requirement, and failing a theme build over a client's page
  title reports the wrong party.

Five are coding-style rules with no WCAG bearing that fail only on markup
WordPress core prints, which the theme does not control. Each was re-enabled
against the fetched pages to confirm that before it was kept off:

- **`void-style`** — core writes `<link />`, `<meta />`, `<img/>`, `<input/>`.
- **`no-trailing-whitespace`** — the core navigation block prints indented,
  whitespace-only lines.
- **`attr-quotes`** — core's robots meta tag uses single quotes.
- **`no-inline-style`** — block supports are written by core as `style`
  attributes on rendered blocks.
- **`require-sri`** — core prints its own same-origin assets without
  `integrity`; the theme loads nothing from a third-party origin.

## 3. Automated testing in a browser

axe-core 4.10.2, Chrome, tested against `/`, a content page, and search results
on a real installation. The WordPress admin bar and browser extensions were
excluded from the run: neither is theme output.

**Violations: 0 at any WCAG level.**

**Best-practice finding, accepted:** axe's `region` rule reports that the skip
link is not inside a landmark. The skip link is the first element in `<body>`,
before the header — that is where a skip link belongs, and wrapping it in a
landmark to satisfy the rule would add a meaningless landmark to every page.
The rule is tagged `best-practice`, not WCAG. **No change made; recorded here
deliberately.**

**Incomplete, resolved by hand:** axe reports `color-contrast` as *incomplete*
for up to 28 elements per page. Every one is text over the hero gradient or over
an image, where axe cannot determine the effective backdrop. Those pairs are
covered mathematically by the contrast audit against the gradient's own stops.
"Incomplete" means the machine could not decide, not that the check failed.

## 4. Interaction, without JavaScript

Every interactive component in the theme works with JavaScript disabled. This is
a structural property, not a fallback:

| Component | How |
|---|---|
| FAQ accordion | Native `<details>` / `<summary>` |
| Case slider | A scroll container. Arrow controls ship `hidden` and are revealed by `view.js` only once it confirms it can drive them |
| Contact form | A plain POST. Errors are reported on the field they belong to |
| Metric counters | The final figure is rendered server-side; animation is an enhancement |
| Navigation overlay | Core navigation block, with `type="button"` added to controls the core block leaves untyped |

Animated counters snap to their true value on a `setTimeout` regardless of
whether `requestAnimationFrame` ever runs, so a background tab cannot leave a
figure stuck part-way.

## 5. Structure

- One `<h1>` per page, from the template.
- Sections are `<section>` elements without forced `aria-labelledby`. Turning
  every section into a named landmark is an accessibility regression, not an
  improvement; named regions belong to the interactive components that need
  them.
- Table header cells are given `scope="col"` or `scope="row"` at render time
  when the author has not set one (WCAG technique H63). The core table block
  does not do this.
- A skip link to `#qs-main` is the first focusable element on every page.

---

## Open items

Stated plainly, because the value of this document depends on it.

1. **Manual screen reader testing has not been performed for 1.2.0.** NVDA on
   Windows and VoiceOver on macOS/iOS are the intended targets. Automated tools
   detect roughly a third to a half of real accessibility barriers; nothing in
   this report should be read as a substitute.
2. **Manual keyboard walkthrough is not yet documented per release.** The
   components are built to be keyboard-operable and the focus indicator is
   audited, but there is no recorded pass confirming tab order end to end on
   every template.
3. **WooCommerce templates are untested here.** The theme ships templates for
   cart, checkout and product pages. Most of that markup is the plugin's, and it
   was not part of this test round.
4. **The `region` best-practice finding above is accepted, not fixed.**
5. **Reduced motion** is honoured in the theme's own CSS. It has not been
   verified against every core block the theme may host.

## How to reproduce this report

```bash
npm run audit:contrast                       # the colour contract
npm run test:unit                            # the brand-kit sweep behind the claim below
npm run fetch:html -- http://your-site.test  # real rendered pages
npm run lint:html                            # markup and WCAG techniques
```

The brand-kit figure quoted above is not a number from somebody's notes: it is
what `npm run test:unit` prints. It ships with the theme so the claim can be
checked rather than taken on trust, and it runs in CI on every change.

axe-core was run in Chrome against a live installation, excluding `#wpadminbar`.

---

## Feedback

If you find a barrier in this theme, tell us and we will treat it as a defect
rather than a feature request: <https://qwerty-soft.com/>

© Qwerty Soft
