=== Qwerty Soft — Signal ===

Contributors: qwertysoft
Requires at least: 6.7
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 1.4.0
License: GNU General Public License v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: full-site-editing, block-patterns, block-styles, accessibility-ready, translation-ready, custom-colors, custom-logo, custom-menu, editor-style, featured-images, wide-blocks, one-column, two-columns, right-sidebar, blog, portfolio, e-commerce

A fast, accessible Gutenberg block theme for studios, agencies and service businesses. Built by Qwerty Soft.

== Description ==

Qwerty Soft — Signal is a Full Site Editing theme for people who care what the numbers say after launch.

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

Seven palettes: the dark **Signal** default plus six style variations — **Signal Light**, **Signal Midnight**, **Signal Ember**, **Signal Forest**, **Signal Sand** and **Signal High Contrast**. Every one of them is held to the same contrast contract by the audit that ships with the theme, so a palette cannot be added without passing it.

= WooCommerce =

The theme ships without a shop and grows one when a design turns out to need it. Product, catalogue, cart and checkout templates travel folded in shop-kit/, and the design import screen unfolds them in the same click that installs WooCommerce. So a site that sells nothing never carries seven templates about carts, and a site that sells something gets templates built on WooCommerce's own blocks, supported across plugin updates. Shop CSS and JavaScript are dequeued on pages with no shop content on them.

== Installation ==

1. In wp-admin go to Appearance → Themes → Add New → Upload Theme.
2. Upload the ZIP and click Activate.
3. Go to Appearance → Editor to edit templates, or create a page and pick one of the "Qwerty Soft — Full pages" patterns.

No build step, no Composer install, no required plugins.

== Frequently Asked Questions ==

= Do I need a page builder? =

No. The theme is built on the block editor that ships with WordPress.

= Where do contact form messages go? =

To the site administrator address under Settings → General. A developer can route a form elsewhere with the `qwerty_soft/contact_recipient` filter, which resolves the recipient on the server — the address is never read from the request.

= How do I turn the designer credit on or off? =

Appearance → Customize → Footer credit. It is off by default.

= Can I change the colours? =

Appearance → Editor → Styles. Every pattern and block style reads its colours from theme.json, so changing a palette colour updates the whole site. If you change the palette, re-run `npm run audit:contrast` to confirm the new pairs still clear AA.

== Upgrade Notice ==

= 1.4.0 =
* The theme ships from zero: no shop in it. The product, catalogue, cart and checkout templates and the WooCommerce module now travel folded in shop-kit/, one directory to the side of where WordPress looks, so nothing about a cart appears in the Site Editor of a site that has nothing to sell.
* Design import: a shop in the design is one button. A product catalogue in the archive, or an add-to-cart button in the markup, is reported on the import screen with the evidence for it; the button installs WooCommerce, unfolds the theme's own shop half and sets the shop up, in that order.
* Design import: a form in the design needs no plugin. The section keeps the design's own markup, class for class, and is wired to the theme's contact handler — a plain POST with a honeypot, a time trap and a per-IP rate limit — so an imported contact page sends mail as soon as it is built.
Adds a guided design import: Claude corrects each converted section, either through your Anthropic API key or through Claude Code on the machine, using its subscription. Off unless you turn it on, and the offline import is unchanged.

= 1.3.0 =
Adds self-hosted updates, Site Health checks and a safer design import. Your colours, logo, pages and menus live in the database and are not touched by the update.

== Changelog ==

= 1.4.0 =
* Design import: a guided conversion. The structural conversion still runs first and offline; with it turned on, Claude is then handed that result, a brief of what the design's CSS actually resolves to for every element, and a screenshot where the archive has one, and corrects what is wrong. A section it cannot improve is kept exactly as the structural conversion made it — the offline result is the floor, and nothing the model returns is used until it has passed the same block validator as everything else.
* Design import: two ways to reach the model. An Anthropic API key works on any host. Where Claude Code is installed and PHP is allowed to start it — a developer's own machine rather than a client's hosting — the theme runs the conversion through the `claude` command instead, which uses the subscription that command is signed in to and adds nothing to a bill. Which route a build will take, and what it will cost, is on the screen before the button.
* Design import: an optional second pass. The produced blocks are rendered on the server with `do_blocks()` and compared with the design, which catches what a conversion written blind cannot see. It doubles the time and the cost, and it says so.
* Design import: a rejected answer is retried once, with the validator's complaint and the original brief attached. What usually fails is the typing rather than the judgement.
* Design import: the language picker now builds the language it shows. A multilingual archive was being built in full — every language, three times the pages — while the picker read as one.
* Design import: the report says what the model changed, what it could not carry, and what came back unusable. A section that fell back to the structural conversion says so rather than looking like one the model approved of.

= 1.3.0 =
* Updates. The theme now checks the studio's server for new versions and offers them under Appearance → Themes like any other theme. Every package is verified against a published SHA-256 checksum before it is unpacked. What leaves the site is the installed version and a one-way hash of the site address, nothing more; the `qwerty_soft/check_updates` filter switches the check off entirely.
* Site Health. Tools → Site Health reports whether the host has what the design importer needs — the Zip and DOM extensions, a writable uploads folder and outbound HTTPS to Anthropic and Google Fonts — before a job fails half-way through.
* Design import: the design's typefaces come with it. Google Fonts a design uses are fetched once, stored in the Media Library and registered in the Font Library, so the site sets in the design's own type and visitors never contact Google.
* Design import: SVG and inline images. Images embedded as data: URIs are written out to files, SVG files are sanitised down to drawing instructions (no scripts, no external references) and given real dimensions in the Media Library.
* Design import: designs exported from Claude Design. Components and templates the export repeats across pages are recognised and converted once; a section painted with a photograph becomes a Cover block the editor can swap the picture on; an empty custom element says so instead of leaving a silent gap.
* Design import: section styles. Colours, spacing and type the design's own stylesheet gives a section are carried onto the converted blocks, so the result looks like the design rather than like the theme's defaults.
* Design import: stepwise build. Convert and preview one section at a time, see what each one cost, and build the page from the sections you have approved. Work is banked per page, so a closed tab resumes rather than restarts.
* Design import: drafts by default. A one-press build creates every page as a draft; publishing is a separate, deliberate step.
* Design import: a clean-up panel that works after a reload. What a previous import left on the site — pages, parts, menus, media, fonts — is counted and can be removed in one press, behind a confirmation, whether or not a design is loaded right now.
* Contact form: the success message is now focused and announced after a submission, and the page lands on it so it is in view on a phone. Failed attempts no longer eat into the rate limit; only a message that actually went out counts.
* Contact form: a stale nonce is no longer fatal for logged-out visitors, whose page came out of a full-page cache; the honeypot, time trap and rate limit carry the load there. Logged-in users are still held to the nonce.
* Contact form: the feedback token survives a plugin rendering the content early (SEO plugins building og:description), so the error summary cannot vanish before the visible render.
* SEO: nothing behind a password reaches the head or the structured data. Meta descriptions are cut at 160 characters on a word boundary, and headings are dropped before the content is flattened so an h2 no longer runs into the paragraph after it.
* Performance: the header's images are never lazy-loaded and core's default threshold is left alone, so a wide logo can no longer push the real LCP image into lazy loading. On WordPress 6.8+ the theme tunes core's own speculation rules instead of printing a second set.
* Performance: the Cyrillic font subset is preloaded on Cyrillic-locale sites, where the first word on the page needs it; the `qwerty_soft/preload_fonts` filter now takes subset slugs.
* Security: `Cross-Origin-Opener-Policy` is `same-origin-allow-popups`, so payment and sign-in popups (PayPal, Stripe, "Sign in with…") keep their opener.
* Accessibility: the slider's arrows use `aria-disabled` rather than `disabled`, so an arrow that runs out while focused does not drop keyboard focus to the page. The slider works in right-to-left documents, and its sizes come from theme.json.
* Accessibility: the page list core falls back to before a menu exists is folded into the navigation list instead of being nested inside it; `aria-current` is left to core, which sets it itself.
* Metric: what counts as a countable number is one rule shared by PHP, the editor and the animation (digits, optional thousands spaces, a dot decimal), so "4,9" and "24/7" are shown as-is everywhere.
* WooCommerce: the shop templates are hidden from the Site Editor when the plugin is not active, so they cannot be picked by mistake.
* Login screen: painted with the active palette read from global styles, so a style variation or a brand palette repaints it too.
* Importer REST routes validate the design slug and page path before any callback sees them, and a design folder is confirmed to be strictly inside the designs directory.
* Developer: `npm run build:zip` now writes the update manifest next to the archive, a release workflow publishes both on a version tag, and `composer.lock` pins the coding-standards toolchain.

= 1.2.0 =
* First-run setup. Activating the theme now offers a four-step wizard: pick a look, upload a logo and enter brand colours, install the starter pages. Every step is optional and every step can be undone. The screen works with JavaScript switched off.
* Brand kit. Enter one to three brand colours and the theme derives the whole thirteen-colour palette from them, keeping your hue and moving only the lightness until every pair clears WCAG 2.2 AA. Verified over 127 brand colours in both light and dark modes.
* Five new style variations: Signal Midnight, Signal Ember, Signal Forest, Signal Sand and Signal High Contrast. Six palettes in total with the original, all of them audited.
* Starter content. A home, services and contact page built from the theme's own patterns, with a menu and a front page. Removing it again uses the same one-press reset the design import already had.
* A dashboard panel and contextual help tabs, so documentation and support are one click from wherever the client is working.
* Colours and brand settings are written to the site's global styles, never to theme.json, so a theme update cannot overwrite a client's customisation.
* The header and footer are structurally locked: the layout cannot be dismantled by accident, while menus, footer links and every piece of text stay editable.
* Table header cells are given a scope attribute at render time when the author has not set one (WCAG technique H63).
* The contrast audit now discovers style variations from the styles/ folder, so a palette cannot be added without being held to the contract. 217 checks across 7 palettes.
* New: `npm run fetch:html` pulls real rendered pages off a running site so the markup gate runs against real content, and `npm run build:zip` packages a release with the development files stripped out.
* New: docs/ACCESSIBILITY.md — a WCAG 2.2 AA conformance report, including what has not been tested.
* Starter pages are created on the landing template, so the hero runs full width and the page title is not printed above it.
* The starter menu replaces the placeholder menu WordPress creates by itself, which otherwise puts "Sample Page" in the header.
* Gradients and shadows are derived from your brand colours too, so they cannot be left over from a style variation you tried first.
* Ukrainian translation complete: every string in the catalogue is translated, and `npm run make:mo` reports the coverage so a gap cannot ship unnoticed.
* Design import now shows what it costs. Each conversion reports its tokens and price, the screen keeps a running total, and the convert-everything button says roughly what it will cost before you press it — estimated from the real size of each section, not an average.
* Design import survives a closed tab. Converted sections are kept, so reopening the page resumes instead of restarting, and converting everything skips what is already done rather than paying for it twice.
* The hourly conversion limit now tells you how many are left and when it resets, instead of only announcing itself when you hit it.
* Unit tests ship with the theme (`npm run test:unit`) and a CI workflow runs every gate on PHP 8.1.
* New: docs/GUIDE.md — a guide for the person running the site, covering setup, styles, patterns, the contact form, the design import and what the theme stores.
* Performance figures in the documentation are now measured in a browser rather than asserted, including an honest note about the one render-blocking stylesheet, which belongs to WordPress core rather than to this theme.

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

Qwerty Soft — Signal, Copyright 2026 Qwerty Soft
Qwerty Soft — Signal is distributed under the terms of the GNU GPL v2 or later.

Manrope font
Copyright the Manrope Project Authors
Licensed under the SIL Open Font License 1.1
Source: https://github.com/sharanda/manrope
License: https://scripts.sil.org/OFL

All demonstration copy in the bundled patterns was written for this theme and
contains no third-party content. The theme bundles no images.
