# Qwerty Soft — Signal · Management guide

Internal. For scoping projects, quoting them, and understanding what this tool
buys the studio.

**Version 1.4.0 · WordPress 6.7+ · PHP 8.1+ · no required plugins**

---

## 1. What this is, and why it exists

A Full Site Editing WordPress block theme, built and maintained in-house. Not a
product for sale — **the base every client site starts from, and the machinery
that turns a delivered design into that site without hand work.**

It exists because the economics of the work changed. Claude Code moved the
bottleneck: the slow part of a WordPress build is no longer writing templates,
it is the repetitive translation of somebody else's markup into ours — section
by section, page by page, for the third client this quarter. That is exactly
the kind of work a model does well and a senior developer resents. So it was
automated, and the automation was put in the one place every project already
has: the theme.

Two properties do the work.

**No build step.** Upload and it runs. No `npm install`, no compile, no licence
server. A client's hosting needs PHP 8.1 and nothing else — which matters
because we do not choose the hosting.

**A design importer.** Point it at a ZIP of an HTML design, or of a React or
Vue application, and it produces WordPress pages: real headings in order, real
lists, real buttons, alt text, one `h1` per page, plus a menu, a header, a
footer and a front page. Optionally each section is read and corrected by
Claude; optionally rendered and compared against the original until the two
agree.

What is in the theme:

| | |
|---|---|
| Templates | 17, including the full WooCommerce set |
| Patterns | 17 |
| Custom blocks | 6 — FAQ accordion, FAQ item, metric counter, case slider, contact form, colophon |
| Style variations | 6 |
| Palette | 13 colours, all pairs verified to WCAG 2.2 AA |
| Languages | English, Ukrainian complete |
| Plugins required | none |

Accessibility, performance and SEO are enforced by automated gates, not by
intention: 217 contrast checks, ~7 800 automated checks in total, all passing.
That is the point of putting them in the theme — the floor holds on every
project without anyone remembering to check.

---

## 2. What it automates, concretely

| Was | Is now |
|---|---|
| Reading a handoff ZIP by hand to find the site | Unpacked, indexed, nested archives opened, admin screens separated |
| Copying markup section by section into blocks | One press; each section converted, optionally corrected and verified |
| Retyping the design's palette and spacing | Read out of the design's own CSS into `theme.json` |
| Building menu, header, footer, front page | Built from the design's own navigation |
| Rewriting `page.html` links so they resolve | Relinked automatically; unresolved targets reported |
| Hoping the contrast passes | 217 pairs checked on every run; the build fails otherwise |
| Accessibility found at review | Enforced continuously; axe-core in a real browser on demand |
| A React prototype declared "not importable" | Routes read out of the source, rendered to HTML, then imported |

---

## 3. When to reach for it

**Good fit**

- Any project where the client's team must edit content themselves, without a
  page builder and without calling us.
- A rebuild where the client delivered a static HTML design or a React
  prototype and it has to become WordPress.
- A project with an accessibility obligation — public sector, regulated
  industry, or a tender with WCAG in the requirements.
- A multilingual handoff: the importer understands language folders and builds
  one language or all of them.
- A WooCommerce shop that needs the templates but not eleven plugins.

**Poor fit**

- An application rather than a site — a dashboard, a booking engine, anything
  where most screens sit behind a login and are driven by state.
- A project that already depends on ACF, Elementor or a page-builder workflow
  the client will not give up.
- A design that exists only as Figma with no export. The importer reads markup
  or component source; it does not read a design file.
- Hosting on PHP under 8.1.

**Decide with care**

- Heavy custom post type work. The theme does not block it; it simply does not
  help, so budget it as ordinary development.
- Sites that are mostly video or interactive embeds. Conversion is reliable for
  document-shaped pages and needs review elsewhere.

---

## 4. Typical cases

| Case | Shape | Where the time goes |
|---|---|---|
| **Static HTML → WordPress** | 8–15 pages, one language | Import, then reviewing and correcting each page |
| **React prototype → WordPress** | 8–15 routes | Reading routes out of source, then the same review |
| **Multilingual handoff** | 3 pages × 3 languages | One language built and reviewed, the rest largely mechanical |
| **Theme-only delivery** | No import | Tokens, patterns, templates, training |
| **Rescue** | Existing WP site | Audit first — the estimate depends entirely on what is found |

---

## 5. Estimating

### The unit is the section, not the page

A "page" means nothing across projects. A section — a hero, a metrics band, a
card grid, a FAQ, a form — is a consistent unit of work, and the importer
counts them for you before anything is built. The import screen shows the
section count per page and the total for the build.

**Rule of thumb: 15 minutes of developer time per section**, covering review of
the converted output, correction, and a responsive check.

That figure is for review after an automated import. Building a section by hand
from a design, with no importer, is 45–90 minutes. **That gap is the whole
argument for the tool.**

### Machine time is not developer time

Measured on a real 2026 handoff, Claude Opus at high effort with the review
pass on:

| Page | Sections | Machine time |
|---|---|---|
| Reports & Store | 10 | 18 min |
| Oat Products report | 5 | 8 min |
| Robert AI | 9 | 19 min |

That is **roughly 110 seconds per section**, unattended. Nobody sits and
watches it — the build runs on the server and the screen rejoins it later. Do
not put it in the estimate as labour; put it in the schedule as elapsed time.

The structural mode, by contrast, finishes a whole site in seconds and costs
nothing.

### Base pages

| Page | Typical sections | Review hours |
|---|---|---|
| Home | 8–10 | 2.0 – 2.5 |
| Services / offering | 6–8 | 1.5 – 2.0 |
| About | 5–7 | 1.25 – 1.75 |
| Contact | 3–4 | 0.75 – 1.0 + form wiring |
| Blog index | 2–3 | 0.5 – 0.75 |
| Single post | 3–4 | 0.75 – 1.0 |
| Report / case detail | 8–12 | 2.0 – 3.0 |
| Legal (privacy, terms) | 1–2 | 0.25 – 0.5 |
| 404 and search | 1–2 each | 0.5 total |

### A new page not on that list

```
hours = sections × 0.25
        + 0.5   if it needs a pattern that does not exist yet
        + 1.0   if it needs a new custom block
        + 0.25  per additional language
```

Round to the nearest quarter hour. Under one hour, quote one hour.

### Project-level work, on top of pages

| Task | Hours |
|---|---|
| Setup, theme install, environments | 2 – 3 |
| Design tokens from the client's brand | 2 – 4 |
| Header and footer to the design | 2 – 3 |
| Menu, front page, permalinks | 1 – 2 |
| Contact form: fields, recipient, spam, testing | 2 – 3 |
| WooCommerce, if in scope | 8 – 16 |
| Accessibility pass with a screen reader | 4 – 6 |
| Performance pass to Lighthouse 95+ | 3 – 5 |
| SEO: metadata, sitemap, structured data | 2 – 3 |
| Client training and handover documentation | 3 – 4 |
| Contingency | 15% of the total |

### A worked example

A 12-page marketing site, one language, roughly 80 sections:

```
Sections        80 × 0.25                        20.0 h
Setup                                             2.5 h
Tokens from brand                                 3.0 h
Header and footer                                 2.5 h
Menu, front page, permalinks                      1.5 h
Contact form                                      2.5 h
Accessibility pass                                5.0 h
Performance pass                                  4.0 h
SEO                                               2.5 h
Training and handover                             3.5 h
                                                 ------
Subtotal                                         47.0 h
Contingency 15%                                   7.0 h
                                                 ------
Total                                            54.0 h
```

Elapsed machine time for the import itself: about 2.5 hours, unattended,
overlapping the rest.

The same site built by hand is 90–120 hours.

### Model cost

Nothing, when run through Claude Code on a machine signed in to a subscription
— which is the normal case for our own work.

Through an Anthropic API key, on a client's hosting: roughly **$1–3 for a whole
site**. The import screen estimates it per mode before anything runs. It is a
rounding error against the hours.

---

## 6. What the tool returns to the studio

The case for having built it, in the numbers above:

- **40–50% off a design-to-WordPress build.** 54 hours against 90–120 on a
  twelve-page site. The saving is in conversion, not review — review stays at
  full rate, which is why the estimate is defensible.
- **A quality floor that does not depend on who is on the project.** Contrast,
  markup validity, escaping and accessibility are gates, not habits. A junior
  shipping on a Friday clears the same bar as a senior.
- **Estimates that hold.** Section counts come from the archive before anything
  is built, so a quote is arithmetic rather than a guess — and the same number
  is on the screen when the work starts.
- **Onboarding measured in days.** A new developer learns one theme, not one
  per project.
- **Every project starts from the same base.** Fixes and improvements land once
  and reach every site the next time it is touched.

Ongoing cost is maintenance of the theme itself: WordPress releases twice a
year, and the importer needs attention when a new kind of handoff turns up.
Budget it as a standing line, not as a project.

---

## 7. Risks worth naming in a quote

| Risk | Effect | What to do |
|---|---|---|
| Design delivered as Figma only | The importer cannot read it | Get an HTML or code export, or quote a hand build |
| Design is a React app with no router | Routes cannot be discovered | Budget 1–2 h to inspect it before quoting |
| Handoff contains several applications | Wrong one gets imported | The screen lists them and asks; confirm with the client which is the site |
| Archive full of admin screens | Pages nobody wants | The screen separates them; confirm scope |
| Very deep folder names on Windows | Nested archives silently skipped | Fixed in 1.4.0; the log now names anything left unopened |
| Client wants a page builder | Wrong tool | Say so before the contract, not after |
| Content not final at import time | Rework | Import structure early, pour content late |

---

## 8. What to tell the client

The theme is ours and stays unnamed on their site. What they hear is the
outcome:

- **"Your team changes text without calling us."** Real Site Editor blocks.
- **"It will still open in five years."** Core blocks, no builder, no plugin
  sprawl, no licence to renew.
- **"We can prove it is accessible."** Automated gates plus a manual pass, with
  the report.
- **"Your design, not a template."** The importer keeps the design's own
  colours, spacing and class names rather than repainting it.
- **"Fast on launch day."** No bundle, no builder overhead.

The client owns the site and the code we deliver. Nothing phones home and
nothing depends on us staying involved.

---

## 9. Where to see the results

| What | Where |
|---|---|
| The theme running | Any local WordPress with the theme active; `npm run fetch:html` snapshots pages into `artifacts/html/` |
| The look | `screenshot.png` in the theme root — the real front page at 1200×900 |
| Gate results | `npm run test` and `npm run test:wp` in the terminal |
| Accessibility | `npm run audit:a11y` — axe-core in a real browser |
| An import, end to end | Appearance → Design import: upload a ZIP, choose a mode, watch the log |
| What a build made | The report on the import screen — every page with its address, status and links to view, edit and publish |
| The code | This repository; `docs/TECHNICAL.md` for the map |
