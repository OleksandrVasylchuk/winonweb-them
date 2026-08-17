=== WOW — Signal ===

Contributors: winonweb
Requires at least: 6.7
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.1.0
License: GNU General Public License v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: full-site-editing, block-patterns, block-styles, accessibility-ready, translation-ready, custom-colors, custom-logo, custom-menu, editor-style, featured-images, wide-blocks, one-column, two-columns, right-sidebar, blog, portfolio, e-commerce

A fast, accessible Gutenberg block theme for studios, agencies and service businesses. Built by WOW — Win On Web.

== Description ==

WOW — Signal is a Full Site Editing theme for people who care what the numbers say after launch.

Everything is editable in the block editor. There is no page builder to license, no framework to learn and no build step to run: what you download is what runs on the server.

Built for outcomes:

* **Accessible by default.** Every colour pair in the theme clears WCAG 2.2 AA, verified by a contrast script that ships with the theme. The accordion is a native `<details>` element, the slider works without JavaScript, and the contact form reports errors on the fields they belong to.
* **Fast by construction.** No global stylesheet and no render-blocking JavaScript. Design tokens and critical CSS are inlined by WordPress with the global styles it already emits; per-block CSS loads only where that block renders. One 25 KB font subset is preloaded; the other subsets load only if a page needs those glyphs.
* **Editable by the client.** Seventeen block patterns covering heroes, services, case studies, metrics, process, testimonials, pricing, FAQ, calls to action and contact — plus three complete page layouts you can insert and edit like any other content.
* **No plugin dependency.** The contact form, the FAQ structured data, the social cards and the JSON-LD are all in the theme. If you install an SEO plugin, the theme's metadata stands down automatically.

= Custom blocks =

* **FAQ accordion** with a matching FAQ question block. Publishes FAQPage structured data, works with JavaScript switched off.
* **Case slider** — a scroll-snapping row of cards with keyboard-reachable controls that only appear once the script confirms it can drive them.
* **Metric** — a number that counts up when it scrolls into view, and always settles on the real figure.
* **Contact form** — nonce, honeypot, time trap, per-IP rate limit, per-field error messages.
* **Colophon** — the footer copyright line, with a year that never goes stale.

= Style variations =

Ships with a dark default and a **Signal Light** daylight variation. Both palettes pass the same contrast contract.

= WooCommerce =

Product, catalogue, cart and checkout templates are included and use WooCommerce's own block templates, so shop markup stays supported across plugin updates. Shop CSS and JavaScript are dequeued on pages with no shop content on them.

== Installation ==

1. In wp-admin go to Appearance → Themes → Add New → Upload Theme.
2. Upload the ZIP and click Activate.
3. Go to Appearance → Editor to edit templates, or create a page and pick one of the "WOW — Full pages" patterns.

No build step, no Composer install, no required plugins.

== Frequently Asked Questions ==

= Do I need a page builder? =

No. The theme is built on the block editor that ships with WordPress.

= Where do contact form messages go? =

To the site administrator address under Settings → General. A developer can route a form elsewhere with the `wow_signal/contact_recipient` filter, which resolves the recipient on the server — the address is never read from the request.

= How do I turn the designer credit on or off? =

Appearance → Customize → Footer credit. It is off by default.

= Can I change the colours? =

Appearance → Editor → Styles. Every pattern and block style reads its colours from theme.json, so changing a palette colour updates the whole site. If you change the palette, re-run `npm run audit:contrast` to confirm the new pairs still clear AA.

== Changelog ==

= 1.1.0 =
* Design import: drop in an HTML archive and build the whole site in one press — every page, the images, the menu, the header and footer, and the front page.
* The imported design's own colours and type are read from its stylesheet and written to the site's global styles, so two designs produce two different-looking sites on the same theme.
* Three conversion routes: structural (free, no account), your own Claude subscription (copy the brief, paste the reply), or an Anthropic API key.
* Everything an import creates can be removed again in one press; nothing else on the site is touched.
* New palette slug "accent-ink" — the brand colour adjusted until it clears 4.5:1 as text, so a pale brand colour cannot make links unreadable.

= 1.0.0 =
* Initial release.
* Full Site Editing: 16 templates, 3 template parts, 17 patterns, 6 custom blocks.
* WCAG 2.2 AA contrast contract with an automated auditor.
* Dark default palette plus the Signal Light variation.
* WooCommerce templates for product, catalogue, cart and checkout.
* Ukrainian translation included.

== Copyright ==

WOW — Signal, Copyright 2026 WOW — Win On Web
WOW — Signal is distributed under the terms of the GNU GPL v2 or later.

Manrope font
Copyright the Manrope Project Authors
Licensed under the SIL Open Font License 1.1
Source: https://github.com/sharanda/manrope
License: https://scripts.sil.org/OFL

All demonstration copy in the bundled patterns was written for this theme and
contains no third-party content. The theme bundles no images.
