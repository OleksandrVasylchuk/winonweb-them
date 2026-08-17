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

---

## Layout

```
theme.json            design tokens, element styles, critical CSS (inlined by WP)
styles/light.json     the "Signal Light" style variation
templates/*.html      16 block templates, incl. WooCommerce
parts/*.html          header, footer, post-meta
patterns/*.php        17 patterns, incl. 3 whole-page layouts
blocks/<slug>/        6 custom blocks — block.json, render.php, edit.js, style.css
inc/                  PHP: Wow\Signal\* (PSR-4, autoloaded by functions.php)
  Contracts/          the Module interface
  Modules/            one concern per file, booted by inc/Theme.php
  Support/            small helpers with no hooks
assets/fonts/         Manrope, three self-hosted woff2 subsets
tools/                the quality gates (see below)
languages/            wow-signal.pot and the Ukrainian translation
artifacts/            gate output — git-ignored, safe to delete
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

npm run audit:contrast   # every colour pair in theme.json vs WCAG 2.2 AA
npm run lint:blocks      # template/pattern block markup parses and resolves
npm run lint:php         # WordPress-Extra, zero tolerance
npm run lint:html        # W3C-grade validation of rendered pages
npm run test             # contrast + blocks + php
```

`lint:html` validates files in `artifacts/html/`. Generate them by fetching the
rendered pages from a running site:

```bash
for p in "/:home" "/services/:services" "/contact/:contact"; do
  curl -s -o "artifacts/html/${p##*:}.html" "http://your-site.test${p%%:*}"
done
```

### What the gates deliberately ignore

`.htmlvalidate.json` disables two rules, both with a reason:

- **`valid-id`** is relaxed to the HTML5 definition. WordPress core emits script
  module ids such as `@wordpress/interactivity-js-modulepreload`. HTML5 permits
  any non-empty id without whitespace, so these are valid; the rule's default is
  the stricter HTML4 rule.
- **`no-redundant-role`** is off. `.wow-slider__track` is a `<ul>` with
  `list-style: none`, which makes Safari drop list semantics; `role="list"`
  restores them. The role is redundant per spec and necessary in practice.

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

## Performance

The critical path contains **no render-blocking stylesheet and no blocking
script**:

- theme.json tokens and `styles.css` are inlined by WordPress into the global
  styles element that is already on the page;
- per-block CSS is declared in `block.json`, so a block that does not render
  costs nothing, and core inlines what is left because each handle carries a
  `path`;
- `should_load_separate_core_block_assets` and `should_load_block_assets_on_demand`
  are both on;
- block view scripts are `defer`;
- one 25 KB Latin woff2 subset is preloaded; Latin-Extended and Cyrillic load
  only when a page actually contains those glyphs.

Speculative prerendering is enabled for logged-out visitors, excluding
`wp-admin`, `wp-*.php`, nonce URLs, `rel="nofollow"` and anything marked
`.no-prerender`.

---

## Security

| Concern | Where it is answered |
|---|---|
| Output escaping | Every dynamic echo passes through `esc_html`/`esc_attr`/`esc_url`/`wp_kses`. The three unescaped echoes are `get_block_wrapper_attributes()` (escaped by core), rendered inner blocks, and `wp_json_encode()` output — each annotated in place. |
| CSRF | `wp_nonce_field()` + `wp_verify_nonce()` on the only form that writes. |
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
```

`tools/make-pot.mjs` extracts gettext calls from PHP plus the strings WordPress
translates out of `block.json`, style variation JSON and pattern headers. The
theme text domain is `wow-signal`.

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
| `wow_signal/needs_woocommerce_assets` | Keep shop assets on a page outside the shop templates. |

---

## Licence

GPL-2.0-or-later. Manrope is bundled under the SIL Open Font License 1.1.

© WOW — Win On Web — https://www.winonweb.dev/
