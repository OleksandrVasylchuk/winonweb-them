# WOW — Signal

A Full Site Editing WordPress theme by [WOW — Win On Web](https://www.winonweb.dev/).

Fast, accessible, editable in the block editor, and shipped with the checks that
prove it. No page builder, no ACF, no build step.

---

## Requirements

| | |
|---|---|
| WordPress | 6.7+ (tested on 7.0) |
| PHP | 8.1+ |
| Node | 18+ — **only** for the quality gates, never to build the theme |
| Composer | dev-only, for PHPCS |

**There is no build step.** The files in this repository are the files that run
in production. Copy the folder into `wp-content/themes/`, activate, done.

### Hosting

The front end and the editor need nothing beyond a stock WordPress install on
PHP 8.1 — no extension, no cron, no outbound request. Only the design importer
(`Appearance → Design import`) depends on the environment:

| Needs | For | If missing |
|---|---|---|
| `zip` extension (`ZipArchive`) | unpacking the uploaded design | upload refused with a clear message |
| `dom` extension | reading the design's HTML | importer unavailable |
| writable `wp-content/uploads` | design files, images, fonts | same failure as any media upload |
| outbound HTTPS to `api.anthropic.com` | model-assisted conversions | the one-press structural build still works |
| outbound HTTPS to `fonts.googleapis.com` / `fonts.gstatic.com` | a design's Google Fonts | site falls back to the theme's Manrope; reported in the build |
| outbound HTTPS to `www.winonweb.dev` | the twelve-hourly update check (see Updates) | no update is offered; upload the new ZIP by hand |

`inc/Modules/SiteHealth.php` reports each of these under **Tools → Site Health**
(two direct tests and one asynchronous outbound check behind
`GET /wow-signal/v1/health/outbound`, gated on `view_site_health_checks`), and
adds a **WOW — Signal** section to the Info tab with the theme version, PHP
version, whether a key is configured (marked private) and how many design font
families are installed. WordPress itself refuses to activate the theme on PHP
below the `Requires PHP` header, so there is no runtime version guard.

**Language.** Every string in the theme is written in English and is the
default. The Ukrainian catalogue in `languages/` is loaded only when the site
language (`Settings → General`) or the user's profile language is Ukrainian;
nothing in the theme switches locale on its own.

---

## Layout

```
theme.json            design tokens, element styles, critical CSS (inlined by WP)
styles/*.json         6 style variations — light, midnight, ember, forest, sand, contrast
templates/*.html      16 block templates, incl. WooCommerce
parts/*.html          header, footer, post-meta
patterns/*.php        17 patterns, incl. 3 whole-page layouts
blocks/<slug>/        6 custom blocks — block.json, render.php, edit.js, style.css
inc/                  PHP: Wow\Signal\* (PSR-4, autoloaded by functions.php)
  Contracts/          the Module interface
  Modules/            one concern per file, booted by inc/Theme.php
  Support/            small helpers with no hooks
assets/fonts/         Manrope, three self-hosted woff2 subsets
docs/                 GUIDE.md + ACCESSIBILITY.md ship · BLOCK_SPEC.md is internal
tools/                the quality gates (see below)
languages/            wow-signal.pot and the Ukrainian translation
artifacts/            gate output and packaged releases — git-ignored, safe to delete
```

### How PHP is wired

`functions.php` registers a PSR-4 autoloader for `Wow\Signal\` → `inc/`, then
calls `Theme::instance()->boot()`. `Theme` instantiates each module in
`module_classes()` and calls `register()` on it; every module does all of its
hooking there and nothing in its constructor.

To drop or add a module from a child theme:

```php
add_filter( 'wow_signal/modules', function ( array $classes ): array {
    return array_values( array_diff( $classes, [ Wow\Signal\Modules\Branding::class ] ) );
} );
```

---

## Quality gates

```bash
npm install          # html-validate only
composer install     # PHPCS + WordPress Coding Standards

npm run audit:contrast   # every colour pair in theme.json and styles/*.json vs WCAG 2.2 AA
npm run lint:blocks      # template/pattern block markup parses and resolves
npm run lint:php         # WordPress-Extra, zero tolerance
npm run lint:html        # W3C-grade validation of rendered pages
npm run test:unit        # BrandKit and Spend, without WordPress
npm run test             # contrast + blocks + php + unit

npm run test:wp          # integration tests against a real WordPress (tests/wp/)
npm run test:all         # test + test:wp
npm run audit:a11y -- http://your-site.test [pages]   # axe-core, WCAG 2.2 AA, in Chromium
npm run fixture:zip      # rebuild tests/fixtures/design.zip from tests/fixtures/design/

npm run fetch:html       # pull real rendered pages off a running site
npm run build:zip        # package a release into artifacts/release/
npm run release          # test, then package
```

Composer still installs PHPCS, but `lint:php` no longer needs `composer` on
PATH: `tools/php.mjs` finds an interpreter — PATH first, then the usual XAMPP
and Laragon locations — and `tools/lint-php.mjs` runs `vendor/bin/phpcs` with
it. A machine without Composer globally installed should not fail the PHP gate
for a reason that has nothing to do with the PHP.

`test:unit` runs `tools/test-support.php` — plain PHP, no WordPress, no
database. It covers the two Support classes that hold real logic rather than
plumbing: **BrandKit** (a sweep of ~6,800 colour pairs proving the derivation
cannot produce an inaccessible palette, plus the hue-family and gradient
checks) and **Spend** (cost arithmetic against known list prices, and the
estimate's behaviour at the edges). That sweep is what backs the accessibility
claim in `docs/ACCESSIBILITY.md`, so it belongs in the repository rather than
in somebody's terminal history.

`test:wp` runs `tests/wp/*.php` against a **real WordPress install** — the one
found by `WOW_WP_PATH`, or the first `wp-load.php` above the theme folder. It
borrows the site rather than owning it: every test runs inside a database
transaction that is always rolled back (the harness refuses to start on
anything but InnoDB, and checks after each rollback that no commit sneaked
in), and every file written under `uploads/` is removed afterwards. The first
file, `isolation.php`, proves exactly that before anything relies on it. The
others cover the contact form's POST flow (redirects, field errors, bot traps,
the per-IP limit, a failing mailer), the design importer from
`tests/fixtures/design.zip` to a built site and back through `reset()`, the
section splitter, the Site Health tests and the SEO head. Without a WordPress
install it prints `skipped: no WordPress found (set WOW_WP_PATH)` and exits 0,
which is why `npm test` does not include it and `npm run test:all` does.

`tests/fixtures/design/` is a small Claude-Design-style export — `<x-dc>`
pages, `<dc-import>` components, an `<sc-for>` loop over `data.js`, inline
background images, a `data:` PNG, Google Fonts in the helmet. Its images are
generated by `tests/fixtures/build-fixture.mjs`, which also packs the archive;
`npm run fixture:zip` reruns it after any change to the folder.

`audit:a11y` drives Chromium with Playwright, loads the front page, a search
results page, a 404 and up to N pages from the site's sitemap (or REST API),
injects axe-core and runs the WCAG 2.2 AA rules. Any violation fails the run.
`color-contrast` results that axe marks *incomplete* — text over the hero
gradient, which it cannot measure — are printed as INFO; those pairs are
checked mathematically in `docs/ACCESSIBILITY.md`. One-time setup:
`npm install && npx playwright install chromium`.

`.github/workflows/ci.yml` runs all four gates plus `build:zip` on every push
and pull request, on **PHP 8.1** — the theme's own stated floor. A second job,
`integration`, installs a throwaway WordPress with WP-CLI against a MySQL 8
service, symlinks the checkout in as the active theme, runs `test:wp`, then
serves the site with `php -S` and runs `audit:a11y` against it. `lint:html` is
deliberately not in CI: it validates pages fetched from a running WordPress
install with real content.

`audit:contrast` discovers style variations from `styles/`, so a palette cannot
be added without being held to the contract.

`lint:html` validates files in `artifacts/html/`. Fill that folder from a
running site — real content, not fixtures:

```bash
npm run fetch:html -- http://your-site.test 10
```

It takes the front page, up to ten pages listed in the site's own sitemap (or
REST API, if permalinks are plain), a search results page and a 404. Anything
that does not answer with the expected status is skipped and reported rather
than silently written.

`build:zip` refuses to run if `style.css`, `readme.txt` and `package.json`
disagree about the version number, and prints the SHA-256 of what it produced.
It strips `node_modules/`, `vendor/`, `artifacts/`, `designs/`, `tests/`,
`design-reference/`, `tools/` and every development config file, so what a
client downloads cannot accidentally be the repository.

### What the gates deliberately ignore

`.htmlvalidate.json` relaxes eight rules, each with a reason. Three concern the
theme's own choices:

- **`valid-id`** is relaxed to the HTML5 definition. WordPress core emits script
  module ids such as `@wordpress/interactivity-js-modulepreload`. HTML5 permits
  any non-empty id without whitespace, so these are valid; the rule's default is
  the stricter HTML4 rule.
- **`no-redundant-role`** is off. `.wow-slider__track` is a `<ul>` with
  `list-style: none`, which makes Safari drop list semantics; `role="list"`
  restores them. The role is redundant per spec and necessary in practice.
- **`long-title`** is off. It measures a `<title>` written by whoever wrote the
  page, not by the theme. It is an SEO guideline rather than a WCAG rule, and
  failing the theme's build over a client's page title reports the wrong party.

Five are style rules that only ever fail on markup WordPress core prints, which
the theme cannot change. Each was re-enabled against the fetched pages before
being kept off, and none of them is a WCAG requirement:

- **`void-style`** — core's `wp_head()` writes `<link />` and `<meta />`, and the
  block serialiser writes `<img/>` and `<input/>`.
- **`no-trailing-whitespace`** — the core navigation block's responsive container
  is printed with indented, whitespace-only lines.
- **`attr-quotes`** — core's `wp_robots()` meta tag uses single quotes.
- **`no-inline-style`** — block supports (spacing, typography, the spacer's
  height) are written by core as `style=""` attributes on the rendered block.
- **`require-sri`** — core prints its own same-origin stylesheet and script
  tags without `integrity`; SRI is for third-party origins, and the theme loads
  none.

`attribute-boolean-style` was also off and now passes, so it is back on.

---

## Design tokens

Everything visual comes from `theme.json`. Nothing in the theme hard-codes a
colour, a font size or a spacing value — patterns and block styles all resolve
to `var(--wp--preset--*)`, so changing a palette entry in the Site Editor
re-themes the whole site.

**The contrast contract** (enforced by `tools/contrast-audit.mjs`):

- text pairs clear 4.5:1;
- form control borders and focus indicators clear 3:1;
- a label on an accent fill is always `base`, never `contrast` — `contrast` on
  `accent` is only 1.65:1 and must never be used;
- the focus indicator is a two-tone ring: an `accent` outline separated from the
  element by a `base`-coloured gap, so it contrasts on both light and dark fills.

Card fills and hairline dividers are decorative and exempt from 1.4.11; the
auditor reports them as INFO so a regression is still visible.

---

## First-run setup

`Appearance → Theme setup` (`inc/Modules/Onboarding.php`). Activating the theme
raises a notice pointing at it; there is a dashboard panel and contextual help
tabs on the same screen.

Four steps, all optional, all reversible:

1. **Look** — apply one of the six style variations, or go back to the default.
2. **Brand** — upload a logo, choose light or dark, enter one to three brand
   colours and a heading family.
3. **Content** — install home, services and contact pages built from the
   theme's own page patterns, plus a menu and a front page. The pages are
   created against the `page-landing` template: the default one prints the post
   title above the content and constrains it, which puts the word "Home" above
   the headline and squeezes a full-bleed hero into a column.
4. **Finish** — hands over to the Site Editor.

Three properties it is worth not breaking:

- **Plain POST forms, no JavaScript.** The theme's rule that everything
  interactive works without JS applies to the admin too.
- **Nothing is written to `theme.json`.** `StyleVariations` and
  `DesignTokens::apply()` write to the site's *user global styles* — the record
  the Site Editor itself writes. A theme update cannot overwrite a client's
  brand, and a read-only theme directory cannot break the screen.
- **Undo already exists.** Starter pages carry `SiteAssembler::OWNED_META`, so
  the design importer's "remove everything this added" takes them out too.

### The brand kit

`Wow\Signal\Support\BrandKit::derive()` turns one to three brand colours into
all thirteen tokens. It keeps the client's hue and walks the *lightness* — on a
dark palette toward white, on a light one toward black — until the colour clears
the same ratios `tools/contrast-audit.mjs` enforces, plus a small margin so a
derived pair never lands exactly on 4.50:1.

That is what makes the screen safe to hand to a client: swept over 127 brand
colours in both modes, 6,858 pairs, none below threshold. If you change the
neutrals in `neutrals()`, re-run that sweep.

Two details that are easy to get wrong and were:

- **Companion accents rotate toward blue-violet, not always forwards.** A fixed
  `+42°` sends amber to yellow-green and then green, which reads as a traffic
  light rather than a brand. `rotation()` picks the direction by hue so both
  warm and cool brands land in the same harmonious arc.
- **Gradients and shadows follow the palette.** WordPress replaces preset sets
  wholesale, so applying a brand palette without also replacing them leaves the
  previous variation's — Signal Ember's amber gradient over a pink site.
  `BrandKit::gradients()` and `::shadows()` derive both, and
  `StyleVariations::set_presets()` writes them.

### Menus and core's fallback

The first time an unreferenced `core/navigation` block renders — long before
anybody opens the setup screen — WordPress silently creates a menu whose entire
content is `wp:page-list`. So "does a menu already exist?" is always true, and
the page list shows every top-level page alphabetically, which on a fresh site
means WordPress's own *Sample Page* sits in the header and Home comes second.
`DemoContent::menu()` recognises that exact fallback and fills it in with real
links; anything somebody has edited is left alone.

### Structural locking

`parts/header.html` and `parts/footer.html` carry `"templateLock":"insert"` on
their outer group: blocks inside cannot be added or removed, so the layout
cannot be dismantled by accident. Two containers deliberately override it with
`"templateLock":false` — the header's navigation group and the footer's columns
— because menus and footer links are exactly what a client does need to edit.

---

## The design import screen

`Appearance → Design import` (`inc/Modules/Importer.php`). It converts an
uploaded HTML design into blocks, one section at a time, using the Anthropic
API — which means every press spends the site owner's money. Three things exist
because of that, and should not be quietly removed:

- **`Wow\Signal\Support\Spend`** turns the token counts the API already returns
  into a running total and a before-you-press estimate. The estimate is built
  from the *actual* prompt size for each section — its markup plus the CSS rules
  that match it — not from an average, which is why it lands within a cent of
  the real figure. Prices are Anthropic's published list rates and every figure
  derived from them is labelled an estimate; a site with its own agreement
  filters them with `wow_signal/anthropic_prices`.
- **`Wow\Signal\Support\ImportSession`** banks each converted section in user
  meta as it arrives. Closing the tab on section eight of fourteen used to throw
  away both the work and what it cost; now reopening the page brings it back,
  and "convert every section" skips what is already done rather than paying for
  it twice. Rendered previews are not stored — they are rebuilt from the markup
  that is.
- **The hourly limit reports itself.** It always existed; the only way to learn
  about it was to hit it mid-run. The remaining count and reset time now ride
  along on every conversion. The window start is stored *inside* the transient
  rather than read off its expiry, because a site with a persistent object cache
  does not expose that expiry at all.

**The unpacked designs are not web-accessible.** Archives are extracted into
`wp-content/uploads/wow-signal-designs/`, and that folder ships with an
`.htaccess` (Apache) and a `web.config` (IIS) that deny every request, plus an
empty `index.php`. nginx reads neither file, so a site on nginx needs the
equivalent in its server block:

```nginx
location ^~ /wp-content/uploads/wow-signal-designs/ { deny all; }
```

---

## Accessibility

`docs/ACCESSIBILITY.md` is a WCAG 2.2 AA conformance report written to be given
to a client. It states the method, the results, and — deliberately — the things
that have not been tested. Keep it honest: it is worth more as a document that
lists open items than as a document that claims none.

---

## Performance

**Measured, not assumed.** The figures below come from profiling the front page
in Chrome against a real install; re-measure rather than trusting them after any
change to how assets load.

| | |
|---|---|
| Render-blocking scripts | **0** |
| Render-blocking stylesheets | **1** — core's navigation block style (see below) |
| Cumulative layout shift | **0.0000** |
| Requests for a full page | 5 |
| Transferred | ~175 KB including fonts |

**The one blocking stylesheet is core's, not the theme's.** WordPress inlines
block styles under a size budget and links the rest; `navigation/style.min.css`
is 20,709 bytes against a 20,000-byte default, so it gets a `<link>`. That
budget is *cumulative across every inlined sheet*, not per file — raising it
enough to capture the navigation sheet means inlining roughly 40 KB of
uncacheable CSS into every page to save one cacheable request, which is a worse
trade for anyone who visits a second page. The theme therefore leaves core's
default alone. There is a note in `Performance.php` so this does not get
"fixed" again.

Everything the theme itself controls is off the critical path:

- theme.json tokens and `styles.css` are inlined by WordPress into the global
  styles element that is already on the page;
- per-block CSS is declared in `block.json`, so a block that does not render
  costs nothing, and core inlines what is left because each handle carries a
  `path`;
- `should_load_separate_core_block_assets` and `should_load_block_assets_on_demand`
  are both on;
- block view scripts are `defer`;
- one 25 KB Latin woff2 subset is preloaded (the list is filterable with
  `wow_signal/preload_fonts`); Latin-Extended and Cyrillic load only when a page
  actually contains those glyphs.

**Lazy-loading is left to core.** WordPress already skips `loading="lazy"` on
the first three content images (`wp_omit_loading_attr_threshold` at its
default), which is where the LCP candidate lives. The theme used to force that
threshold down to one, which lazy-loaded a second above-the-fold image for no
gain; it now leaves the threshold alone and only exempts images inside the
header template part, which core cannot know are above the fold.

**Speculative loading.** On WordPress 6.8+ the theme configures core's own
Speculation Rules API through `wp_speculation_rules_configuration` — mode
`prerender`, eagerness `moderate` — and prints nothing of its own, so it
composes with any plugin that also uses the API. On 6.7, which has no such API,
the theme prints an equivalent rule set by hand for logged-out visitors,
excluding `wp-admin`, `wp-*.php`, nonce URLs, `rel="nofollow"` and anything
marked `.no-prerender`.

---

## Security

| Concern | Where it is answered |
|---|---|
| Output escaping | Every dynamic echo passes through `esc_html`/`esc_attr`/`esc_url`/`wp_kses`. The three unescaped echoes are `get_block_wrapper_attributes()` (escaped by core), rendered inner blocks, and `wp_json_encode()` output — each annotated in place. |
| CSRF | Every form that writes carries a nonce: the contact form, the four setup-screen forms (look, brand, content, reset) and the design importer's admin form; the importer's REST routes check the REST nonce and a capability on every request. |
| Mail header injection | The submitter's address is validated with `is_email()`; the display name has `<>",;:` and line breaks stripped before it reaches `Reply-To`. |
| Open relay | The recipient comes from site options and the `wow_signal/contact_recipient` filter. It is never read from the request. |
| Spam | Off-screen honeypot, three-second time trap, five-per-ten-minutes per-IP rate limit. |
| Headers | `X-Content-Type-Options`, `Referrer-Policy`, `X-Frame-Options`, `Cross-Origin-Opener-Policy`, `Permissions-Policy`. |

No Content-Security-Policy is set by the theme: a theme cannot know which
plugins a site runs, and a broken CSP is worse than none. Apply one at the
server or CDN. A reasonable starting point for a site running this theme alone:

```
Content-Security-Policy: default-src 'self'; img-src 'self' data:; font-src 'self';
  style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'self';
  base-uri 'self'; form-action 'self'
```

`style-src 'unsafe-inline'` is required because WordPress inlines global styles.

---

## Translation

```bash
npm run make:pot     # regenerate languages/wow-signal.pot
npm run i18n         # pot, then compile every catalogue
```

**`languages/uk.po` and `uk.mo` are generated — do not edit them.** The source
is `tools/i18n/uk.json`, keyed by gettext context (`(code)` for strings with
none) and then by the English original. `make:mo` rewrites the `.po` from the
POT plus that dictionary, so a translation typed into the `.po` disappears on
the next build. It also reports coverage, which is the figure to watch: the
last line of `make:mo` output names every string still missing a translation,
and the percentage must read 100% before a release.

Strings read out of a JSON file at runtime — style variation titles and
descriptions — are not translated by WordPress's own theme.json pass, because
the theme reads those files directly. `StyleVariations::all()` runs them through
`translate_with_gettext_context()` for that reason.

`tools/make-pot.mjs` extracts gettext calls from PHP plus the strings WordPress
translates out of `block.json`, style variation JSON and pattern headers. The
theme text domain is `wow-signal`.

---

## Updates

The theme is not in the WordPress.org directory, so core has nobody to ask
about new versions. `inc/Modules/Updates.php` asks a JSON manifest on the
studio's server instead and feeds the answer into the same `update_themes`
transient core reads for every other theme. From there everything is standard:
the badge on Appearance → Themes, the one-click install, Dashboard → Updates,
WP-CLI's `wp theme update wow-signal`.

**How a check works.** At most once every twelve hours (transient
`wow_signal_update_check`), from wp-admin, cron or WP-CLI only, the module
fetches

```
https://www.winonweb.dev/themes/signal/update.json?version=<installed>&site=<hash>
```

with a ten-second timeout. `site` is the first sixteen hex characters of
`sha256( site_url() )` — enough for the server to rate-limit and count
installs, not enough to recover the URL. Nothing else is sent, and there is no
licence key. A failed fetch is cached for the same twelve hours so a server
that is down is not asked on every admin page load. "Check again" on
Dashboard → Updates clears core's transient, and the module clears its own at
the same moment, so that button really does reach the server.

**The manifest** is what `npm run build:zip` writes to
`artifacts/release/update.json`:

```json
{
  "version": "1.3.0",
  "download_url": "https://www.winonweb.dev/downloads/wow-signal-1.3.0.zip",
  "requires": "6.7",
  "requires_php": "8.1",
  "tested": "7.0",
  "sha256": "…64 hex characters…",
  "details_url": "https://www.winonweb.dev/themes/signal/changelog/"
}
```

Every field is validated before it is believed: versions must parse, both
URLs must be `https://`, `download_url` must end in `.zip` and sit on an
allow-listed host (by default the manifest's own host, so a tampered manifest
cannot point sites at a package elsewhere), `sha256` must be 64 hex
characters. Anything off and the manifest is treated as absent.

An update is offered only when `version` is newer than the installed one
**and** the site meets `requires` / `requires_php`. When it is newer but the
site falls short, the entry goes into `no_update` and an administrator sees a
single notice saying which requirement is missing.

**The package is verified.** When the manifest carries `sha256`, the module
hooks `upgrader_pre_download`, downloads the ZIP itself with
`download_url()`, hashes it, and hands the local file to core only if the hash
matches. A mismatch returns a `WP_Error` and the upgrade stops before anything
is unpacked. Packages that are not ours — plugins, other themes, core — pass
through untouched.

**Hosting it.** Serve the ZIP at the `download_url` and the manifest at the
manifest URL, both over HTTPS, both as plain static files. The release
workflow below produces both; copying them to the server is the one manual
step. Downloads may live on a CDN — add its host with
`wow_signal/update_download_hosts`.

**Disabling it.** A site that deploys from version control should not be
offered updates by the admin:

```php
add_filter( 'wow_signal/check_updates', '__return_false' );
```

**Moving it.** A reseller or an agency hosting its own builds points the
check at its own manifest with `wow_signal/update_manifest_url`; that host is
then automatically the one packages are allowed from.

**Offline and errors.** Nothing here can fatal. No network, a 500, a body that
is not JSON, a field that does not validate — each results in "no update" and
a cached null until the next window.

---

## Releasing

The version is declared in three places and all three must agree:

| File | Field |
|---|---|
| `style.css` | `Version:` header — what WordPress and `WOW_SIGNAL_VERSION` read |
| `readme.txt` | `Stable tag:` plus a new `= x.y.z =` changelog entry |
| `package.json` | `"version"` |

`npm version` is **not** used: it would bump `package.json` alone and leave the
two files WordPress actually reads behind. Edit all three by hand, then:

```bash
npm run build:zip -- --download-base https://www.winonweb.dev/downloads/
```

`build:zip` refuses to run when the three disagree, and writes three things to
`artifacts/release/`: `wow-signal-x.y.z.zip`, `wow-signal-x.y.z.zip.sha256`
and `update.json` with that checksum already in it. Without `--download-base`
(or a `DOWNLOAD_BASE` environment variable) the manifest carries a
`downloads.example.invalid` placeholder that the theme's host allow-list
refuses — a forgotten flag cannot produce a working-looking but broken
manifest.

**Tagging.** Commit the bump, tag it `vx.y.z` and push the tag.
`.github/workflows/release.yml` checks out that tag, fails unless the tag
equals `v` + the `style.css` version, runs `npm test`, packages with
`--download-base` taken from the repository variable `DOWNLOAD_BASE`, and
creates a GitHub Release carrying the ZIP, the `.sha256` file and
`update.json`, with the checksum in the release body. Upload the ZIP and
`update.json` to the download host and every installed copy sees the new
version within twelve hours.

---

## Filters

| Filter | Purpose |
|---|---|
| `wow_signal/modules` | Add or remove theme modules. |
| `wow_signal/contact_recipient` | Route a contact form to a different address, by form id. |
| `wow_signal/show_credit` | Force the footer designer credit on or off in code. |
| `wow_signal/seo_delegated` | Tell the theme an SEO plugin owns page metadata. |
| `wow_signal/schema_organization` | Extend the Organization JSON-LD node. |
| `wow_signal/preconnect_origins` | Add origins to preconnect to. |
| `wow_signal/preload_fonts` | Change which font files get a `<link rel="preload">`. |
| `wow_signal/contact_client_ip` | Override the address the contact form rate-limits on, for a site behind a proxy or CDN that sets a forwarding header. |
| `wow_signal/anthropic_prices` | Replace the published list prices the design importer's spend estimate is calculated from. |
| `wow_signal/needs_woocommerce_assets` | Keep shop assets on a page outside the shop templates. |
| `wow_signal/check_updates` | Return `false` to switch the update check off entirely — no request is made, no update is offered. |
| `wow_signal/update_manifest_url` | Fetch the update manifest from a different HTTPS URL (a reseller or an agency hosting its own builds). |
| `wow_signal/update_download_hosts` | Hosts a package may be downloaded from. Defaults to the manifest's own host; add a CDN host here. |

---

## Licence

GPL-2.0-or-later. Manrope is bundled under the SIL Open Font License 1.1.

© WOW — Win On Web — https://www.winonweb.dev/
