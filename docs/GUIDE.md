# WOW — Signal — using the theme

This is the guide for the person running the site. It assumes you know your way
around WordPress and does not assume you write code — nothing here requires
any.

Developer documentation is in `README.md`; the accessibility report is in
`ACCESSIBILITY.md`.

---

## Contents

1. [Installing](#installing)
2. [First-run setup](#first-run-setup)
3. [Changing how the site looks](#changing-how-the-site-looks)
4. [Building pages with patterns](#building-pages-with-patterns)
5. [The theme's own blocks](#the-themes-own-blocks)
6. [The contact form](#the-contact-form)
7. [Search engines and social cards](#search-engines-and-social-cards)
8. [Importing a design](#importing-a-design)
9. [What the theme stores on your site](#what-the-theme-stores-on-your-site)
10. [Updating](#updating)
11. [Getting help](#getting-help)

---

## Installing

**Requirements:** WordPress 6.7 or newer, PHP 8.1 or newer. No plugins are
required, and there is nothing to build or compile.

1. **Appearance → Themes → Add New → Upload Theme**
2. Choose `wow-signal-*.zip` and press **Install Now**
3. Press **Activate**

If your host rejects the upload for size, unzip it and copy the `wow-signal`
folder into `wp-content/themes/` over SFTP instead.

**Will it run on my hosting?** Any host that runs WordPress on PHP 8.1 or newer
runs the theme — there is nothing to compile and nothing to configure. The
design importer additionally needs the PHP `zip` extension and permission to
make outgoing connections, both of which almost every shared host provides.
After activating, open **Tools → Site Health**: the entries beginning
"WOW — Signal" tell you in plain words whether anything is missing and what it
would affect.

**Language.** The theme is in English by default. It switches to Ukrainian only
if your site language (**Settings → General → Site Language**) or your own
profile language is set to Ukrainian.

---

## First-run setup

Activating the theme puts a notice at the top of the dashboard, and adds
**Appearance → Theme setup**. Four steps, all optional, all reversible:

### 1. Pick a look

Six palettes. They differ in character, not just colour:

| | |
|---|---|
| **Signal** | Cyan on near-black. The default. |
| **Signal Midnight** | Deep blue dark — quieter, more corporate. |
| **Signal Ember** | Warm dark — amber on near-black. |
| **Signal Light** | White page, near-black text, the same cyan. |
| **Signal Forest** | Paper white with a deep green accent. |
| **Signal Sand** | Warm off-white with terracotta. |
| **Signal High Contrast** | Black on white with saturated accents, for work that has to defend its accessibility. |

Every one of them has been checked against WCAG 2.2 AA by a script before the
theme shipped — you cannot pick an inaccessible one.

### 2. Your brand

Upload a logo, choose light or dark, and give it your brand colour. The theme
works out the other twelve colours from it.

**Your colour may be adjusted, and that is deliberate.** If the exact colour you
enter would be hard to read as a link or a button label, the theme keeps your
hue and moves the lightness until it is legible — usually not far. This is what
stops a brand colour from producing an unreadable site.

Leave **"Work them out from my brand colour"** ticked unless your brand really
has three colours. Ticked, the theme picks two companions that belong to the
same family as yours. Unticked, it uses the two you choose.

**Headings** offers only the two typefaces the theme carries. Adding a third
would mean a font file the theme does not ship and a request your visitors
would have to wait for.

### 3. Starter pages

Creates a home, services and contact page from the theme's own patterns, with
real copy rather than placeholder text, sets the home page as your front page,
and fills in the header menu.

Safe to press twice — it will not duplicate anything. If a page with one of
those addresses already exists, it leaves it alone.

### 4. Finish

Hands you to the Site Editor.

> **Everything on these screens can be changed or undone later.** Nothing is
> written to the theme's own files, so a theme update cannot overwrite your
> colours or your logo. To remove the starter pages again, use **Appearance →
> Design import → Remove everything this added**.

---

## Changing how the site looks

**Appearance → Editor → Styles.**

Everything visual comes from a small set of named colours, sizes and spacings.
Change one there and it changes everywhere it is used — you never hunt through
pages fixing a colour by hand.

- **Colours** — the six palettes above are here too, plus every individual
  colour if you want to adjust one.
- **Typography** — sizes for body text and each heading level.
- **Layout** — page width and the spacing scale.

To change your palette again after setup, the quickest route is **Appearance →
Theme setup → Look**.

> **A warning worth taking seriously.** If you edit individual colours by hand,
> the theme can no longer promise the contrast guarantee — that promise covers
> the palettes it ships and the ones the brand screen derives. Changing "muted
> text" to a paler grey because it looks nicer is exactly how a site becomes
> unreadable for some of its visitors.

### The header and footer are partly locked

You can edit every piece of text, swap the logo, change menu items and edit
footer links. You cannot drag the structure apart or delete the pieces that hold
it together.

This is on purpose. It is the difference between a site that still works in six
months and one that quietly broke the first time somebody dragged a block into
the wrong place.

---

## Building pages with patterns

A **pattern** is a ready-made section you drop into a page and then edit like
anything else.

**Add a pattern:** edit a page → press **+** → **Patterns** tab → pick a
category on the left.

The theme's patterns are filed under five headings:

| Category | What is in it |
|---|---|
| **WOW — Hero** | Opening sections for the top of a page |
| **WOW — Content** | Services, process, features, text |
| **WOW — Proof** | Case studies, metrics, testimonials, client logos |
| **WOW — Conversion** | Pricing, FAQ, contact, calls to action |
| **WOW — Full pages** | Complete page layouts to drop in and edit |

Once inserted, a pattern is just blocks. Edit the text, swap the images, delete
what you do not need.

### The landing template

Pages that open with a full-width hero should use the **Landing page (no title,
full width)** template — otherwise WordPress prints the page title above your
hero and squeezes it into a column.

Set it in the page sidebar under **Page → Template**. The starter pages already
use it.

---

## The theme's own blocks

Six blocks beyond the WordPress ones. All of them work with JavaScript switched
off, which matters more often than people expect.

| Block | What it does |
|---|---|
| **FAQ** | An accordion. Add **FAQ question** blocks inside it. Also tells Google your questions and answers so they can appear in search results. |
| **Case slider** | A row of cards that scrolls sideways. Arrows appear only once the browser confirms it can drive them. |
| **Metric** | A large figure that counts up. The final number is in the page from the start, so it is correct even if the animation never runs. |
| **Contact form** | See below. |
| **Colophon** | The small print line at the bottom of the footer. |

---

## The contact form

Drop the **Contact form** block onto a page. It works immediately — there is no
plugin to install and nothing to configure.

**Where messages go:** your site's admin email address, under **Settings →
General**. Change it there.

**What it does about spam,** without a CAPTCHA and without sending your
visitors' details to a third party:

- a hidden field a person never sees and a robot fills in;
- a three-second minimum, because nobody reads and answers a form that fast;
- five submissions per ten minutes from one address.

**If messages are not arriving,** the form is almost never the cause — WordPress
sends mail through your server, and most hosts either block it or land it in
spam. Install an SMTP plugin and point it at a real mail service. This is worth
doing before launch, not after the first missed enquiry.

---

## Search engines and social cards

The theme handles page titles, descriptions, social sharing images and the
structured data that lets Google show your FAQ answers.

**If you install an SEO plugin** — Yoast, Rank Math, SEOPress, All in One — the
theme stands down automatically and lets the plugin take over. You do not need
to configure anything, and you will not get two sets of tags fighting.

---

## Importing a design

**Appearance → Design import.** If you have a finished HTML design, this turns
it into editable WordPress blocks.

Three routes, and the difference between them is what they cost:

| Route | Cost | Good for |
|---|---|---|
| **Structural** | Free, no account | Getting the whole site in one press, then tidying |
| **Copy the brief** | Your existing Claude subscription | Careful section-by-section work without an API key |
| **API key** | Pay per section, via Anthropic | The same, automated |

**About the money.** With an API key, every section you convert is a paid
request. The screen shows what each one cost, keeps a running total, and tells
you roughly what "convert every section" will cost *before* you press it. Those
figures are estimates from published prices — treat them as a guide, not a bill.

**You will not lose work.** Converted sections are saved as they arrive. If you
close the tab halfway through, reopening the page brings them back and
converting the rest skips what is already done rather than paying for it twice.

**Nothing reaches your site until you press Keep.** Every converted section is
checked before you can preview it and again before it can be saved.

**To undo an import,** use **Remove everything this added**. It removes only
what the import created.

### Designs exported from Claude Design

If your design came out of Claude Design as an HTML archive, drop that archive
in as it is. Here is what to expect.

**What carries across:**

- every page in the design, with its text;
- the images, including SVG logos;
- the header and footer components, which become the site's header and footer;
- the design's Google Fonts, which are loaded for you;
- the design's colours, which are written to the site's styles so buttons,
  links and backgrounds match.

**What deliberately does not:**

- anything drawn by JavaScript — charts, counters, typing effects;
- animated canvases and other moving backgrounds;
- template loops filled with placeholder data (a "repeat this card six times"
  block with made-up names). You get one real card to copy, not six fake ones.

None of those can be turned into blocks a client can edit, so leaving them out
is the safer choice. The screen tells you when it has left something out.

**Drafts first.** The one-press build creates every page as a draft, so nothing
appears on the live site until you have looked at it. If you would rather the
pages go live straight away, tick **Publish immediately** before you press the
button.

**Starting over.** Whenever an import has left content on the site, a panel
called **Delete everything this import added** sits at the top of the Design
import screen. It removes only what the import created — your own pages are not
touched — and you can then drop a new archive in.

---

## What the theme stores on your site

Useful to know before a handover, an audit, or a privacy review.

| What | Where | Why |
|---|---|---|
| Your palette, logo and type choices | Site global styles (the same record the Site Editor writes) | So a theme update cannot overwrite them |
| Whether setup is finished | Site option `wow_signal_setup` | So the notice stops appearing |
| Anthropic API key, model and effort | Site options | Only if you use the design import with a key |
| Design import spend and work in progress | Your own user profile | So the figures and the unfinished work are yours, not the site's |
| Contact form rate limiting | Temporary records that expire on their own | Spam protection |

The theme sends nothing anywhere on its own. The only outbound requests it ever
makes come from the design import screen: to Anthropic when you convert a
section with a key entered, and to Google Fonts when a design you import uses
typefaces from there (the files are downloaded once and then served from your
own site, so visitors never contact Google). **Tools → Site Health** shows
whether your host allows both, under the entries beginning "WOW — Signal".

**A note on the API key.** Stored in the database, it is readable by anyone with
administrator access to the site. If that matters to you, put it in
`wp-config.php` instead and the theme will use it from there and never store it:

```php
define( 'WOW_SIGNAL_ANTHROPIC_KEY', 'sk-ant-…' );
```

---

## Updating

New versions show up the same way they do for any other theme. When one is
available you will see it under **Appearance → Themes** (a badge on the theme)
and under **Dashboard → Updates**. Press **Update now** and WordPress fetches
the package, checks it against the checksum we publish with it, and installs
it. If the checksum does not match, nothing is installed and you are told so.

The site looks for a new version at most twice a day. To make it look right
now, press **Check again** on Dashboard → Updates.

**Your work is safe.** Pages, posts, menus, your colours, your logo, your type
choices and the contact form's settings all live in the database — in the same
global-styles record the Site Editor writes — not in the theme's files.
Updating replaces the files only, and the theme is written so that a new
version never overwrites that record. Anything you changed in the Site Editor
is still there afterwards.

**If the update is held back.** A new version may need a newer PHP or
WordPress than the site has. In that case you see a notice saying which one;
update WordPress, or ask your host about PHP, and the theme update follows.

**Doing it by hand.** If the site cannot reach the update server — a firewall,
an intranet, a host that blocks outgoing connections — download the new ZIP
from your account and upload it over the old one: **Appearance → Themes → Add
New → Upload Theme**, choose the file, and confirm **Replace active with
uploaded** when asked. The result is identical to the automatic route.

**What the check sends.** The version you have installed and a scrambled
(one-way) form of the site address, so the server can tell one site from
another. Nothing else. A developer can switch the check off entirely with the
`wow_signal/check_updates` filter.

**If you have edited theme files directly, your edits will be lost.** Use a
child theme if you need to change the code.

---

## Getting help

There is a **WOW — Signal** panel on your dashboard with links to everything
above, and a **Help** tab at the top right of the setup screen.

If something is wrong, the most useful message tells us: the WordPress and PHP
versions (**Tools → Site Health → Info**), what you did, what happened, and what
you expected instead.

**If you find something inaccessible in this theme, please report it.** We treat
that as a defect, not a feature request.

<https://www.winonweb.dev/>

---

© WOW — Win On Web. The theme is GPL-2.0-or-later; Manrope is bundled under the
SIL Open Font License 1.1.
