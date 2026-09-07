<?php
/**
 * The theme explained, inside the admin, to the three people who ask.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Modules;

use Qwerty\Soft\Contracts\Module;
use Qwerty\Soft\Support\BlockWriter;
use Qwerty\Soft\Support\Lessons;
use Qwerty\Soft\Support\SiteOptions;

defined( 'ABSPATH' ) || exit;

/**
 * One screen, three readers.
 *
 * The person running the company asks what this theme is worth and how to
 * use it; the person who edits the site asks how to change a heading or a
 * picture; the developer who inherits the site asks how it works and what to
 * do when it misbehaves. All three used to be answered by whoever set the
 * site up, from memory, months later. Now the answers ship with the theme,
 * under Appearance, written for each reader in their own terms.
 */
final class Handbook implements Module {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	private const PAGE = 'qwerty-soft-signal-handbook';

	/**
	 * The readers, in the order they are offered.
	 *
	 * @var array<int, string>
	 */
	private const TABS = array( 'team', 'editing', 'developers' );

	/**
	 * Attach the module's hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ) );
	}

	/**
	 * Add the screen under Appearance.
	 *
	 * Readable by anyone who edits content, not only by whoever manages the
	 * theme: two of the three halves of this page exist precisely for the
	 * person who will never open a settings screen.
	 *
	 * @return void
	 */
	public function add_page(): void {
		$hook = add_theme_page(
			__( 'Handbook — Qwerty Soft — Signal', 'qwerty-soft-signal' ),
			__( 'Handbook', 'qwerty-soft-signal' ),
			'edit_posts',
			self::PAGE,
			array( $this, 'render_page' )
		);

		if ( is_string( $hook ) && '' !== $hook ) {
			add_action( 'admin_print_styles-' . $hook, array( $this, 'enqueue' ) );
		}
	}

	/**
	 * The screen's own stylesheet.
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$file = QSOFT_DIR . '/assets/css/admin-handbook.css';

		if ( is_readable( $file ) ) {
			wp_enqueue_style( 'qs-admin-handbook', QSOFT_URI . '/assets/css/admin-handbook.css', array(), (string) filemtime( $file ) );
		}
	}

	/**
	 * The screen.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'team'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing which help text to read changes nothing.
		$tab = in_array( $tab, self::TABS, true ) ? $tab : 'team';

		$base = admin_url( 'themes.php?page=' . self::PAGE );

		$readers = array(
			'team'       => array(
				'title' => __( 'For the team', 'qwerty-soft-signal' ),
				'line'  => __( 'What the theme is worth, and how an import goes.', 'qwerty-soft-signal' ),
			),
			'editing'    => array(
				'title' => __( 'Editing pages', 'qwerty-soft-signal' ),
				'line'  => __( 'Change text, links and pictures right in the section.', 'qwerty-soft-signal' ),
			),
			'developers' => array(
				'title' => __( 'For developers', 'qwerty-soft-signal' ),
				'line'  => __( 'The pipeline, the rules, and what to do when it misbehaves.', 'qwerty-soft-signal' ),
			),
		);

		?>
		<div class="wrap qs-handbook">
			<h1><?php esc_html_e( 'Handbook', 'qwerty-soft-signal' ); ?></h1>

			<div class="qs-handbook__masthead">
				<div class="qs-handbook__masthead-body">
					<p class="qs-handbook__brand">
						<span class="qs-handbook__mark" aria-hidden="true">Q</span>
						<?php esc_html_e( 'Qwerty Soft — Signal', 'qwerty-soft-signal' ); ?>
					</p>

					<h1><?php esc_html_e( 'The theme, explained', 'qwerty-soft-signal' ); ?></h1>

					<p class="qs-handbook__lede">
						<?php esc_html_e( 'A design as a ZIP becomes a site; every section of it stays editable where it is drawn. Three short reads, one per reader — pick yours on the right.', 'qwerty-soft-signal' ); ?>
					</p>
				</div>

				<nav aria-label="<?php esc_attr_e( 'Handbook sections', 'qwerty-soft-signal' ); ?>">
					<ol class="qs-handbook__readers">
						<?php $number = 0; ?>
						<?php foreach ( $readers as $key => $reader ) : ?>
							<?php ++$number; ?>
							<li>
								<a href="<?php echo esc_url( $base . '&tab=' . $key ); ?>" class="<?php echo $tab === $key ? 'is-current' : ''; ?>" <?php echo $tab === $key ? 'aria-current="page"' : ''; ?>>
									<span class="qs-handbook__num" aria-hidden="true"><?php echo esc_html( (string) $number ); ?></span>
									<span>
										<strong><?php echo esc_html( $reader['title'] ); ?></strong>
										<span><?php echo esc_html( $reader['line'] ); ?></span>
									</span>
								</a>
							</li>
						<?php endforeach; ?>
					</ol>
				</nav>
			</div>

			<div class="qs-handbook__body">
				<?php if ( 'editing' === $tab ) : ?>
					<?php $this->editing(); ?>
				<?php elseif ( 'developers' === $tab ) : ?>
					<?php $this->developers(); ?>
				<?php else : ?>
					<?php $this->team(); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * The plain-words half.
	 *
	 * @return void
	 */
	private function team(): void {
		$base = admin_url( 'themes.php?page=' . self::PAGE );

		?>
		<h2><?php esc_html_e( 'What this theme does', 'qwerty-soft-signal' ); ?></h2>
		<p class="qs-handbook__lead"><?php esc_html_e( 'A designer hands over a ZIP file. This theme turns it into a working website — the pages, the menu, the header and footer, the pictures — usually within the hour, looking the way the designer drew it. Nothing about it is a mock-up: every page is real, editable, and yours.', 'qwerty-soft-signal' ); ?></p>

		<h2><?php esc_html_e( 'Why it matters to the company', 'qwerty-soft-signal' ); ?></h2>
		<ul>
			<li><?php esc_html_e( 'Speed: a delivered design becomes a reviewable site the same day, not after weeks of hand work.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'Faithfulness you can check: after every build the theme measures each page against the design — how much of its copy renders, and, when asked, how many pixels differ — and says so. A weak page is flagged, not discovered by a client.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'Anyone can edit: click a heading, a button or a picture right on the page and change it, or use the fields in the sidebar. No developer needed for day-to-day changes.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'No lock-in: it runs on any ordinary hosting. One plugin is required (ACF Pro); WooCommerce is added only when the design turns out to have a shop in it, and the theme’s own shop templates come with it in the same step. Contact forms need no plugin at all.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'Reversible: one button removes everything an import created and touches nothing else.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'It learns: every import is written into a journal — what the archive was, what went wrong, how faithful the result measured — and the next import starts from that knowledge.', 'qwerty-soft-signal' ); ?></li>
		</ul>

		<?php $shots = get_template_directory_uri() . '/assets/images/handbook'; ?>

		<h2><?php esc_html_e( 'How an import goes, in four steps', 'qwerty-soft-signal' ); ?></h2>
		<ol class="qs-handbook__steps">
			<li>
				<div>
					<?php esc_html_e( 'Upload the design ZIP on Appearance → Design import. A design already uploaded appears as a card — click it to continue with it.', 'qwerty-soft-signal' ); ?>
					<figure>
						<img src="<?php echo esc_url( $shots . '/step-1-upload.png' ); ?>" alt="<?php esc_attr_e( 'The upload form with an already-uploaded design below it', 'qwerty-soft-signal' ); ?>" width="1082" height="320" loading="lazy">
						<figcaption><?php esc_html_e( 'Step 1 — the upload form, with designs already on the server listed under it.', 'qwerty-soft-signal' ); ?></figcaption>
					</figure>
				</div>
			</li>
			<li>
				<div>
					<?php esc_html_e( 'Choose. The screen lists every page the build will make, the language, and — when the archive carries more than one site — a box per site. Pick how carefully to build: straight through, with fields named by Claude, or checked pixel by pixel against the design. Untick what you do not want; nothing here is one-way.', 'qwerty-soft-signal' ); ?>
					<figure>
						<img src="<?php echo esc_url( $shots . '/step-2-choose.png' ); ?>" alt="<?php esc_attr_e( 'The build panel: conversion mode, language picker, the archive’s sites, and the list of pages the build will make', 'qwerty-soft-signal' ); ?>" width="1568" height="720" loading="lazy">
						<figcaption><?php esc_html_e( 'Step 2 — mode, language, which of the archive’s sites, and exactly which pages.', 'qwerty-soft-signal' ); ?></figcaption>
					</figure>
				</div>
			</li>
			<li>
				<div><?php esc_html_e( 'Build. It runs on the server; the tab can be closed. The screen narrates every step, shows a progress bar, and flags anything worth checking.', 'qwerty-soft-signal' ); ?></div>
			</li>
			<li>
				<div>
					<?php esc_html_e( 'Review and publish. The pages arrive as drafts with View and Edit beside each; publishing sets the front page and the menu for you. The list below the table says what the build measured and what deserves a look.', 'qwerty-soft-signal' ); ?>
					<figure>
						<img src="<?php echo esc_url( $shots . '/step-4-review.png' ); ?>" alt="<?php esc_attr_e( 'The Pages built table with View and Edit actions and the Worth checking list', 'qwerty-soft-signal' ); ?>" width="1568" height="727" loading="lazy">
						<figcaption><?php esc_html_e( 'Step 4 — every page the build made, its status, and what the build suggests checking.', 'qwerty-soft-signal' ); ?></figcaption>
					</figure>
				</div>
			</li>
		</ol>

		<h2><?php esc_html_e( 'Languages and translations', 'qwerty-soft-signal' ); ?></h2>
		<p><?php esc_html_e( 'A design that ships in several languages (en, ru, zh…) is imported one language at a time — or all at once. To add a language later: open Design import, click the design, set Language to the one you want (or to “All”), and press Build again. The new pages are created beside the existing ones, and the language switcher in the header connects to them by itself. The pages already built are left exactly as they are.', 'qwerty-soft-signal' ); ?></p>
		<p><?php esc_html_e( 'Translating what YOU wrote (an edited heading, a new paragraph) is ordinary page editing: open the page in the other language and change its text. The theme’s own admin screens are translation-ready too — a translation file per language under languages/ is all they need.', 'qwerty-soft-signal' ); ?></p>

		<h2><?php esc_html_e( 'Where to change what', 'qwerty-soft-signal' ); ?></h2>
		<div class="qs-handbook__table-wrap">
			<table class="qs-handbook__table">
				<tbody>
					<tr><td><?php esc_html_e( 'Text, links and pictures on a page', 'qwerty-soft-signal' ); ?></td><td><?php esc_html_e( 'Open the page in the editor and click the thing itself, or press “Fields” on the section. The same fields are in the Block tab of the sidebar.', 'qwerty-soft-signal' ); ?> <a href="<?php echo esc_url( $base . '&tab=editing' ); ?>"><?php esc_html_e( 'How editing works →', 'qwerty-soft-signal' ); ?></a></td></tr>
					<tr><td><?php esc_html_e( 'Header and footer words', 'qwerty-soft-signal' ); ?></td><td><?php esc_html_e( 'Appearance → Site content. One edit reaches every page.', 'qwerty-soft-signal' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'The menu', 'qwerty-soft-signal' ); ?></td><td><?php esc_html_e( 'Appearance → Editor → Navigation.', 'qwerty-soft-signal' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Products', 'qwerty-soft-signal' ); ?></td><td><?php esc_html_e( 'The Products menu (WooCommerce), when the site has a catalogue.', 'qwerty-soft-signal' ); ?></td></tr>
				</tbody>
			</table>
		</div>

		<figure>
			<img src="<?php echo esc_url( $shots . '/site-content.png' ); ?>" alt="<?php esc_attr_e( 'The Site content screen: the header’s box above the footer’s, and the footer divided into a tab per column of the design', 'qwerty-soft-signal' ); ?>" width="1568" height="363" loading="lazy">
			<figcaption><?php esc_html_e( 'Appearance → Site content: the header first, then the footer divided into a tab per column of the design.', 'qwerty-soft-signal' ); ?></figcaption>
		</figure>

		<?php $learned = Lessons::brief(); ?>
		<?php if ( '' !== $learned ) : ?>
			<h2><?php esc_html_e( 'What this site has learned so far', 'qwerty-soft-signal' ); ?></h2>
			<p class="qs-handbook__lesson"><?php echo esc_html( $learned ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * The editing half: how a section is changed where it is drawn.
	 *
	 * @return void
	 */
	private function editing(): void {
		$shots = get_template_directory_uri() . '/assets/images/handbook';

		?>
		<h2><?php esc_html_e( 'Editing sections right on the canvas', 'qwerty-soft-signal' ); ?></h2>
		<p class="qs-handbook__lead"><?php esc_html_e( 'A section built from a design used to be edited only through fields in the right-hand sidebar. Now, much as in a page builder, the text, links, pictures and list rows are changed right in the section — and the sidebar stays as the second way to the same fields.', 'qwerty-soft-signal' ); ?></p>

		<h2><?php esc_html_e( 'What you see', 'qwerty-soft-signal' ); ?></h2>
		<p><?php esc_html_e( 'A section on the canvas looks exactly as it does on the site. Every element that is a field gets a dashed outline when the pointer is over it, and a “Fields” button appears in the section’s top-right corner.', 'qwerty-soft-signal' ); ?></p>

		<div class="qs-handbook__mock" aria-label="<?php esc_attr_e( 'A schematic of a section in the editor', 'qwerty-soft-signal' ); ?>">
			<span class="qs-handbook__mock-open">✎ <?php esc_html_e( 'Fields', 'qwerty-soft-signal' ); ?></span>
			<span class="qs-handbook__mock-eye qs-handbook__mock-field" data-name="eyebrow">vCISO-led managed security program</span>
			<p class="qs-handbook__mock-h qs-handbook__mock-field is-active" data-name="heading · rich"><span class="is-grad">Your Security Program,</span><br>Built and Managed.</p>
			<p class="qs-handbook__mock-p qs-handbook__mock-field" data-name="lead">Chalir acts as an extension of your team, operating cybersecurity, compliance, privacy, and AI risk.</p>
			<span class="qs-handbook__mock-cta qs-handbook__mock-field" data-name="primary_cta · link">Book a Security Program Review →</span>
			<div class="qs-handbook__mock-bar"><span class="is-input">mailto:contact@chalir.com?subject=…</span><span>☐ <?php esc_html_e( 'Open in a new tab', 'qwerty-soft-signal' ); ?></span><span class="is-ok"><?php esc_html_e( 'Apply', 'qwerty-soft-signal' ); ?></span></div>
		</div>
		<p><small><?php esc_html_e( 'Blue outline: the field being edited. The label above it names the field and its type. The bar under the button appears while a link is being edited.', 'qwerty-soft-signal' ); ?></small></p>

		<figure>
			<img src="<?php echo esc_url( $shots . '/canvas-fields.png' ); ?>" alt="<?php esc_attr_e( 'The block editor: a design section on the canvas, the Fields panel open over it with the fields in columns, and the same fields as named parts in the sidebar', 'qwerty-soft-signal' ); ?>" width="1568" height="645" loading="lazy">
			<figcaption><?php esc_html_e( 'The real thing: a section on the canvas with the “Fields” panel open over it — every field of the section in columns, and the same fields in the Block tab of the sidebar.', 'qwerty-soft-signal' ); ?></figcaption>
		</figure>

		<h2><?php esc_html_e( 'Two ways in, one set of data', 'qwerty-soft-signal' ); ?></h2>
		<div class="qs-handbook__cards">
			<div class="qs-handbook__card">
				<h3><?php esc_html_e( 'On the canvas', 'qwerty-soft-signal' ); ?> <span class="qs-handbook__tag"><?php esc_html_e( 'New', 'qwerty-soft-signal' ); ?></span></h3>
				<ul>
					<li><?php esc_html_e( 'Click a heading, a paragraph or a caption and type.', 'qwerty-soft-signal' ); ?> <kbd>Enter</kbd> <?php esc_html_e( 'saves,', 'qwerty-soft-signal' ); ?> <kbd>Esc</kbd> <?php esc_html_e( 'cancels.', 'qwerty-soft-signal' ); ?></li>
					<li><?php esc_html_e( 'A heading with a highlighted word or a line break keeps that formatting;', 'qwerty-soft-signal' ); ?> <kbd>Shift</kbd>+<kbd>Enter</kbd> <?php esc_html_e( 'adds a line break.', 'qwerty-soft-signal' ); ?></li>
					<li><?php esc_html_e( 'Click a button or a link to change its words; a bar under it holds the address and an “open in a new tab” switch.', 'qwerty-soft-signal' ); ?></li>
					<li><?php esc_html_e( 'Click a picture to replace it from the Media Library.', 'qwerty-soft-signal' ); ?></li>
					<li><?php esc_html_e( 'Hover a row of a list (a card, a logo, a bullet) for “+” to add a row after it and “×” to remove it.', 'qwerty-soft-signal' ); ?></li>
					<li><?php esc_html_e( 'The “Fields” button opens a panel over the canvas with every field of the section at once: texts, links, pictures, and the rows with add and remove.', 'qwerty-soft-signal' ); ?></li>
				</ul>
			</div>
			<div class="qs-handbook__card">
				<h3><?php esc_html_e( 'In the sidebar', 'qwerty-soft-signal' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'The Block tab on the right shows this block’s fields — the same fields, the same labels, a repeated list with its rows.', 'qwerty-soft-signal' ); ?></li>
					<li><?php esc_html_e( 'A section with a lot in it is divided into named parts, and the names are the design’s own: a column headed “AI Security” in the design is the “AI Security” part here. Click a name to open or close it; several can be open at once.', 'qwerty-soft-signal' ); ?></li>
					<li><?php esc_html_e( 'Every field runs the full width of the sidebar, which is a narrow column — the side-by-side rows belong on the wide screens.', 'qwerty-soft-signal' ); ?></li>
					<li><?php esc_html_e( 'Handy for long texts and for changing many things at once.', 'qwerty-soft-signal' ); ?></li>
				</ul>
			</div>
		</div>
		<div class="qs-handbook__callout is-good"><p><?php esc_html_e( 'Both ways write the same block data. A change on the canvas shows in the sidebar at once, and the other way round. Saving is the ordinary “Update” button.', 'qwerty-soft-signal' ); ?></p></div>

		<h2><?php esc_html_e( 'The Fields panel, and how wide you want it', 'qwerty-soft-signal' ); ?></h2>
		<p><?php esc_html_e( 'The panel opens at half the canvas and puts the fields in as many columns as fit, so a section of twenty fields is read at a glance rather than scrolled through one field at a time. The rows of a repeated list sit across the full width of it, under the rest.', 'qwerty-soft-signal' ); ?></p>
		<ul>
			<li><?php esc_html_e( 'The arrow in the panel’s corner cycles three widths: half the canvas, the whole of it, and a narrow column for when you want to watch the section change behind it. Which one you left it on is remembered for the next section and the next day.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'It can also be dragged to any width in between, by its left edge.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'The × closes it. Nothing is lost by closing it: every field in it is also in the Block tab of the sidebar, and both write the same data.', 'qwerty-soft-signal' ); ?></li>
		</ul>

		<h2><?php esc_html_e( 'What exactly can be changed', 'qwerty-soft-signal' ); ?></h2>
		<div class="qs-handbook__table-wrap">
			<table class="qs-handbook__table">
				<thead><tr><th><?php esc_html_e( 'In the design', 'qwerty-soft-signal' ); ?></th><th><?php esc_html_e( 'Becomes a field', 'qwerty-soft-signal' ); ?></th><th><?php esc_html_e( 'On the canvas', 'qwerty-soft-signal' ); ?></th></tr></thead>
				<tbody>
					<tr><td><?php esc_html_e( 'Headings, paragraphs, captions', 'qwerty-soft-signal' ); ?></td><td><?php esc_html_e( 'text, or a longer text', 'qwerty-soft-signal' ); ?></td><td><?php esc_html_e( 'click and type', 'qwerty-soft-signal' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Text with a highlight, a line break, a span', 'qwerty-soft-signal' ); ?></td><td><?php esc_html_e( 'text with formatting', 'qwerty-soft-signal' ); ?></td><td><?php esc_html_e( 'click and type; the formatting stays', 'qwerty-soft-signal' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Words beside an icon', 'qwerty-soft-signal' ); ?></td><td><?php esc_html_e( 'text', 'qwerty-soft-signal' ); ?></td><td><?php esc_html_e( 'the words are edited, the icon stays', 'qwerty-soft-signal' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Buttons, links, cards that are links', 'qwerty-soft-signal' ); ?></td><td><?php esc_html_e( 'link (words, address, new tab)', 'qwerty-soft-signal' ); ?></td><td><?php esc_html_e( 'click the words; the address in the bar below', 'qwerty-soft-signal' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Pictures, including inside <picture>', 'qwerty-soft-signal' ); ?></td><td><?php esc_html_e( 'image', 'qwerty-soft-signal' ); ?></td><td><?php esc_html_e( 'click opens the Media Library', 'qwerty-soft-signal' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'An eyebrow in a div, an icon glyph, a table cell, a button label', 'qwerty-soft-signal' ); ?></td><td><?php esc_html_e( 'text', 'qwerty-soft-signal' ); ?></td><td><?php esc_html_e( 'click and type', 'qwerty-soft-signal' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'Three or more alike cards, logos, bullets', 'qwerty-soft-signal' ); ?></td><td><?php esc_html_e( 'rows of a repeater', 'qwerty-soft-signal' ); ?></td><td><?php esc_html_e( '“+” and “×” on the row, or the Fields panel', 'qwerty-soft-signal' ); ?></td></tr>
					<tr><td><?php esc_html_e( 'A card’s number in data-no, a bar’s width in style', 'qwerty-soft-signal' ); ?></td><td><?php esc_html_e( 'a field of the row', 'qwerty-soft-signal' ); ?></td><td><?php esc_html_e( 'the Fields panel or the sidebar', 'qwerty-soft-signal' ); ?></td></tr>
				</tbody>
			</table>
		</div>

		<h2><?php esc_html_e( 'A short how-to', 'qwerty-soft-signal' ); ?></h2>
		<ol class="qs-handbook__steps">
			<li><div><?php esc_html_e( 'Open the page in the editor and click the section.', 'qwerty-soft-signal' ); ?></div></li>
			<li><div><?php esc_html_e( 'Hover a text: a dashed outline appears. Click, change it, press Enter.', 'qwerty-soft-signal' ); ?></div></li>
			<li><div><?php esc_html_e( 'For a button, change the words in place and the address in the bar under it, then press Apply.', 'qwerty-soft-signal' ); ?></div></li>
			<li><div><?php esc_html_e( 'For a picture, click it and choose another from the Media Library.', 'qwerty-soft-signal' ); ?></div></li>
			<li><div><?php esc_html_e( 'To add a card or a logo, hover a neighbouring row and press “+”, or open Fields and press “Add row”.', 'qwerty-soft-signal' ); ?></div></li>
			<li><div><?php esc_html_e( 'For many changes at once, press “Fields” and widen the panel with the arrow in its corner: every field of the section, in columns, on one screen.', 'qwerty-soft-signal' ); ?></div></li>
			<li><div><?php esc_html_e( 'Press Update in the editor’s top-right corner.', 'qwerty-soft-signal' ); ?></div></li>
		</ol>

		<h2><?php esc_html_e( 'The header and the footer are edited somewhere else', 'qwerty-soft-signal' ); ?></h2>
		<p>
			<?php esc_html_e( 'They are on every page, so their words belong to the site rather than to one page: a telephone number changed in two places is a telephone number that disagrees with itself. They live under Appearance → Site content, and one edit there reaches every page at once.', 'qwerty-soft-signal' ); ?>
			<?php
			/*
			 * Linked only where there is something to link to. The screen is
			 * registered by an import that wrote a header or a footer; on a
			 * site without one the link would lead to a page WordPress does
			 * not have.
			 */
			?>
			<?php if ( SiteOptions::has_fields() ) : ?>
				<a href="<?php echo esc_url( admin_url( 'themes.php?page=' . BlockWriter::OPTIONS_PAGE ) ); ?>"><?php esc_html_e( 'Open Site content →', 'qwerty-soft-signal' ); ?></a>
			<?php endif; ?>
		</p>
		<ul>
			<li><?php esc_html_e( 'The header’s box comes first on that screen, the footer’s under it — the way the page reads.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'A footer of three columns of links is divided into a tab per column, named as the design named them — “Managed Program”, “AI Security”, “Contact” — with the brand and the small print as tabs of their own.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'The line explaining a kind of field is printed once per tab rather than under every one of eleven links, so the labels that do differ sit next to each other.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'The list of pages in the header is an ordinary WordPress menu, not text: Appearance → Editor → Navigation.', 'qwerty-soft-signal' ); ?></li>
		</ul>
		<div class="qs-handbook__callout"><p><?php esc_html_e( 'Rebuilding the same design does not undo an edit made here. A row still holding exactly the words the import put in it is refreshed by the next build; a row somebody has rewritten is left alone.', 'qwerty-soft-signal' ); ?></p></div>

		<h2><?php esc_html_e( 'How it differs from a page builder', 'qwerty-soft-signal' ); ?></h2>
		<div class="qs-handbook__cards">
			<div class="qs-handbook__card">
				<h3><?php esc_html_e( 'Alike', 'qwerty-soft-signal' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'Click an element and change it on the spot.', 'qwerty-soft-signal' ); ?></li>
					<li><?php esc_html_e( 'A panel of fields over the section, as wide as you want it.', 'qwerty-soft-signal' ); ?></li>
					<li><?php esc_html_e( 'List rows are added and removed from the canvas.', 'qwerty-soft-signal' ); ?></li>
				</ul>
			</div>
			<div class="qs-handbook__card">
				<h3><?php esc_html_e( 'Different', 'qwerty-soft-signal' ); ?></h3>
				<ul>
					<li><?php esc_html_e( 'The markup and the styles stay the designer’s, from the design. Nothing is rebuilt into widgets.', 'qwerty-soft-signal' ); ?></li>
					<li><?php esc_html_e( 'Blocks are not dragged around and a section’s layout is not changed: content is edited, structure is not.', 'qwerty-soft-signal' ); ?></li>
					<li><?php esc_html_e( 'No builder plugin: these are ordinary WordPress blocks with ACF fields.', 'qwerty-soft-signal' ); ?></li>
				</ul>
			</div>
		</div>

		<div class="qs-handbook__callout is-warn"><p><?php esc_html_e( 'One limit: ACF’s own form cannot be shown inside the block. ACF Pro forces the preview whenever the editor runs in an iframe, which is every screen since WordPress 6.3. So “fields in the block” are the design’s own elements made editable, plus the Fields panel — not ACF’s standard form.', 'qwerty-soft-signal' ); ?></p></div>
		<?php
	}

	/**
	 * The technical half.
	 *
	 * @return void
	 */
	private function developers(): void {
		?>
		<h2><?php esc_html_e( 'The pipeline, in one paragraph', 'qwerty-soft-signal' ); ?></h2>
		<p><?php esc_html_e( 'DesignArchive unpacks and indexes the ZIP. A JavaScript application first gets its pages read out of the router (SourceProject finds the routes, SourceRenderer has the model render each into a static page). SectionSplitter cuts every page into sections; SectionPlan reads each section structurally and, on a “Named by Claude” build, PlanReview asks the model only what code cannot know — field names and whether a repeat is the site’s records. BlockWriter then writes each section as its own block under blocks/design/: render.php holds the archive’s markup verbatim with fields planted into it and every field’s element marked with data-qs-field, fields.json one ACF field per editable thing, and every block names the canonical stylesheet of ITS source — lifted by one class so it beats theme.json, with the design owning html and body. SiteAssembler makes the pages, the chrome and the menu; BuildRunner drives it in bursts on WP-Cron through one BuildStep the REST screen shares; finish() rewrites every link to the built pages, measures each page against the design, writes artifacts/pixel-manifest.json, and — on a “Checked” build — runs PixelReview: both pages photographed in headless Chromium, the difference handed to Claude Code with write access to that page’s blocks, and another look.', 'qwerty-soft-signal' ); ?></p>

		<h2><?php esc_html_e( 'Editing on the canvas, under the hood', 'qwerty-soft-signal' ); ?></h2>
		<ol class="qs-handbook__steps">
			<li><div><?php esc_html_e( 'render.php marks every field’s element with data-qs-field and data-qs-type, and every repeated row with data-qs-row and data-qs-index. The front end strips the marks (DesignField::unmarked()).', 'qwerty-soft-signal' ); ?></div></li>
			<li><div><?php esc_html_e( 'ACF draws the block as its preview. After each render, assets/js/design-canvas.js (hooked on ACF’s render_block_preview) reads the marks and makes the elements editable in place, adds the Fields button, and draws the panel in the canvas document’s body so a redraw does not take it away.', 'qwerty-soft-signal' ); ?></div></li>
			<li><div><?php esc_html_e( 'A change is written with updateBlockAttributes into the same data ACF’s sidebar form edits. Mind the spelling: the importer writes data by field name, flat; once the editor has loaded the block, ACF rewrites it by field key, with a repeater as { "row-0": { key: value } }. The script addresses values by key (DesignBlocks::field_labels() ships the keys) and still reads the flat spelling for a block ACF has not touched.', 'qwerty-soft-signal' ); ?></div></li>
			<li><div><?php esc_html_e( 'A field group knows which screen it is drawn on. Kept with the site (the chrome, on Appearance → Site content) it gets tabs across the top and a run of three links in a row; kept with the block, in a sidebar 280 pixels wide, it gets accordions down the page and nothing side by side. Either way a group of ten fields or more is divided by the design’s own structure — the addresses SectionPlan records — and each part is named by the holder’s class or by the words in it.', 'qwerty-soft-signal' ); ?></div></li>
			<li><div><?php esc_html_e( 'Fix problems in the generator (BlockWriter, SectionPlan), never in a generated block: delete the block’s folder and rebuild the page; regeneration is the test.', 'qwerty-soft-signal' ); ?></div></li>
		</ol>

		<h2><?php esc_html_e( 'The rules that keep it faithful', 'qwerty-soft-signal' ); ?></h2>
		<ul>
			<li><?php esc_html_e( 'Markup is copied, never translated; classes are never renamed. The stylesheet stays whole per source — slicing it per block re-runs the cascade in block order. Every selector is lifted by :is(.qs-design, .qs-design *) so the design’s rules sit above theme.json’s element styles; the design owns html and body; resets with revert are written before the sheet.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'A field claims its subtree: a heading with a highlighted span and a line break is one rich field that keeps the markup (reduced to SectionPlan::RICH_TAGS on both write and read). Words beside decoration are planted around it. Any element with words of its own is a field — an eyebrow in a div, an icon glyph, a table cell — and an attribute that differs between rows (data-no, an inline style) is a field of the row.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'The outermost list is the list; a listing that cannot name its record is a repeater; repeated rows whose unplanned text differs become one verbatim block; a listing shows as many records as the design drew cards, and its cards link to their records’ permalinks.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'Chrome and menu come from the first page that actually has a body — never an application shell. Directory links resolve to that language’s home, inside the site.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'A record type never claims a URL base another post type already owns; it takes its qs- prefixed slug instead.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'Blocks are written once and never regenerated (bumping BlockWriter::VERSION regenerates all of them) — editing a generated render.php by hand is expected and survives.', 'qwerty-soft-signal' ); ?></li>
		</ul>

		<h2><?php esc_html_e( 'When it misbehaves', 'qwerty-soft-signal' ); ?></h2>
		<div class="qs-handbook__table-wrap">
			<table class="qs-handbook__table">
				<tbody>
					<tr>
						<td><?php esc_html_e( 'The build goes quiet', 'qwerty-soft-signal' ); ?></td>
						<td><?php esc_html_e( 'WP-Cron lost the hand-off between ticks. Any visit to the site revives it (a patrol runs on every request; after 15 quiet minutes the screen offers to continue in-request). Check: is there a qwerty_soft_build_tick booking in the cron option? A second Build while one runs is refused with a 409 until Stop is pressed.', 'qwerty-soft-signal' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'A page measures thin', 'qwerty-soft-signal' ); ?></td>
						<td><?php esc_html_e( 'Read the build’s concerns — the fidelity check names the page and the likely section kind. Usually a repeat/listing judgement: delete that one block’s folder and rebuild the page; the fresh plan applies the current rules.', 'qwerty-soft-signal' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'The page looks right but sits lower than the design', 'qwerty-soft-signal' ); ?></td>
						<td><?php esc_html_e( 'Measure, do not reason: npm run audit:pixels, or a “Checked” build. The last such drift was the theme’s 12px block gap between blocks, now zeroed for design blocks in the canonical resets.', 'qwerty-soft-signal' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Editing on the canvas changes nothing', 'qwerty-soft-signal' ); ?></td>
						<td><?php esc_html_e( 'The block’s data is keyed by field key and the script wrote by name, or the field group on disk no longer matches the block (a rebuilt block with an old page). Open the browser console for design-canvas.js errors, then delete the block folder and rebuild the page.', 'qwerty-soft-signal' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'The footer says the wrong things in the wrong places', 'qwerty-soft-signal' ); ?></td>
						<td><?php esc_html_e( 'A field’s name is positional — label, label_2, label_3 in the order SectionPlan meets them — and the option key options_{block}_{name} is the whole of a row’s identity. A generator that learns to see one more element shifts every later name down a place, and rows written by an earlier build go on sitting under names that now mean something else. SiteOptions::seed() keeps a print of what it wrote and refreshes only rows still holding it; rows seeded before that record existed cannot be told from somebody’s edit and are left alone, so a site built before it needs its chrome rows put right once by hand.', 'qwerty-soft-signal' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Pages render unstyled', 'qwerty-soft-signal' ); ?></td>
						<td><?php esc_html_e( 'The pages being built must link their stylesheet — everything downstream reads a page’s styling from its own link tags. For an application, the compiled build CSS (dist/) is the stylesheet of record.', 'qwerty-soft-signal' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Record URLs 404', 'qwerty-soft-signal' ); ?></td>
						<td><?php esc_html_e( 'A rewrite-slug collision with a plugin (WooCommerce owns product/). The guard prefixes new types automatically; for an old one, remove the block’s type.json and flush permalinks.', 'qwerty-soft-signal' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Everything else', 'qwerty-soft-signal' ); ?></td>
						<td><?php esc_html_e( 'The import log narrates every step (Appearance → Design import); the Lessons journal holds the last twenty builds’ measurements; docs/IMPORT_LESSONS.md in the theme holds every rule an import taught it. Start there, not in the database.', 'qwerty-soft-signal' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>

		<h2><?php esc_html_e( 'The gates', 'qwerty-soft-signal' ); ?></h2>
		<p><code>npm run test</code> — <?php esc_html_e( 'contrast, block markup, PHPCS (WordPress-Extra, zero tolerance) and the unit suite. Run it after touching anything; the build’s own fidelity measure covers what the gates cannot.', 'qwerty-soft-signal' ); ?></p>
		<p><code>npm run test:wp</code> — <?php esc_html_e( 'the integration suite against a real WordPress, found through QSOFT_WP_PATH. Point it at a disposable install.', 'qwerty-soft-signal' ); ?></p>
		<p><code>npm run audit:pixels</code> — <?php esc_html_e( 'renders every built page and its design file in the same browser and reports the share of pixels that differ, with a red-overlay image per page (artifacts/pixels/report.html). Words and structure are measured by the build itself; this is the last mile — colour, spacing, fonts. Every build writes the manifest it reads, artifacts/pixel-manifest.json.', 'qwerty-soft-signal' ); ?></p>
		<?php
	}
}
