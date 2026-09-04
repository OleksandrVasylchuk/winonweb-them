<?php
/**
 * The theme explained, inside the admin, to the two people who ask.
 *
 * @package Qwerty\Soft
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Qwerty\Soft\Modules;

use Qwerty\Soft\Contracts\Module;
use Qwerty\Soft\Support\Lessons;

defined( 'ABSPATH' ) || exit;

/**
 * One screen, two audiences.
 *
 * The person running the company asks what this theme is worth and how to
 * use it; the developer who inherits the site asks how it works and what to
 * do when it misbehaves. Both used to be answered by whoever set the site up,
 * from memory, months later. Now the answers ship with the theme, under
 * Appearance, written for each reader in their own terms — plain words for
 * the team, the pipeline and the failure modes for the developer.
 */
final class Handbook implements Module {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	private const PAGE = 'qwerty-soft-signal-handbook';

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
	 * theme: the team half of this page exists precisely for the person who
	 * will never open a settings screen.
	 *
	 * @return void
	 */
	public function add_page(): void {
		add_theme_page(
			__( 'Handbook — Qwerty Soft — Signal', 'qwerty-soft-signal' ),
			__( 'Handbook', 'qwerty-soft-signal' ),
			'edit_posts',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * The screen.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'team'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Choosing which help text to read changes nothing.
		$tab = 'developers' === $tab ? 'developers' : 'team';

		$base = admin_url( 'themes.php?page=' . self::PAGE );

		?>
		<div class="wrap qs-handbook">
			<style>
				.qs-handbook { max-width: 860px; }
				.qs-handbook h1 { margin-bottom: 0.4em; }
				.qs-handbook .nav-tab-wrapper { margin-bottom: 1.2em; }
				.qs-handbook h2 { margin-top: 1.6em; font-size: 1.25em; }
				.qs-handbook ul { list-style: disc; padding-left: 1.3em; }
				.qs-handbook ol { padding-left: 1.3em; }
				.qs-handbook ol li, .qs-handbook ul li { margin-bottom: 0.45em; line-height: 1.55; }
				.qs-handbook p { font-size: 13.5px; line-height: 1.6; max-width: 72ch; }
				.qs-handbook table { border-collapse: collapse; margin-top: 0.6em; }
				.qs-handbook td, .qs-handbook th { padding: 0.45em 1em 0.45em 0; text-align: left; vertical-align: top; }
				.qs-handbook code { font-size: 12px; }
				.qs-handbook .qs-handbook__lesson { padding: 0.7em 1em; background: #f6f7f7; border-left: 4px solid #2271b1; }
				.qs-handbook figure { margin: 0.7em 0 1.5em; }
				.qs-handbook figure img { max-width: 100%; height: auto; border: 1px solid #dcdcde; border-radius: 4px; box-shadow: 0 1px 3px rgba( 0, 0, 0, 0.07 ); }
				.qs-handbook figcaption { margin-top: 0.4em; color: #646970; font-size: 12px; }
			</style>

			<h1><?php esc_html_e( 'Qwerty Soft — Signal, explained', 'qwerty-soft-signal' ); ?></h1>

			<nav class="nav-tab-wrapper">
				<a class="nav-tab <?php echo 'team' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $base . '&tab=team' ); ?>"><?php esc_html_e( 'For the team', 'qwerty-soft-signal' ); ?></a>
				<a class="nav-tab <?php echo 'developers' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( $base . '&tab=developers' ); ?>"><?php esc_html_e( 'For developers', 'qwerty-soft-signal' ); ?></a>
			</nav>

			<?php if ( 'team' === $tab ) : ?>
				<?php $this->team(); ?>
			<?php else : ?>
				<?php $this->developers(); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * The plain-words half.
	 *
	 * @return void
	 */
	private function team(): void {
		?>
		<h2><?php esc_html_e( 'What this theme does', 'qwerty-soft-signal' ); ?></h2>
		<p><?php esc_html_e( 'A designer hands over a ZIP file. This theme turns it into a working website — the pages, the menu, the header and footer, the pictures — usually within the hour, looking the way the designer drew it. Nothing about it is a mock-up: every page is real, editable, and yours.', 'qwerty-soft-signal' ); ?></p>

		<h2><?php esc_html_e( 'Why it matters to the company', 'qwerty-soft-signal' ); ?></h2>
		<ul>
			<li><?php esc_html_e( 'Speed: a delivered design becomes a reviewable site the same day, not after weeks of hand work.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'Faithfulness you can check: after every build the theme measures each page against the design and says what share of the design’s content it renders. A weak page is flagged, not discovered by a client.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'Anyone can edit: texts on the pages, the words in the header and footer, the menu — all through normal WordPress screens. No developer needed for day-to-day changes.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'No lock-in: it runs on any ordinary hosting. One plugin is required (ACF); WooCommerce is added only when the design turns out to have a shop in it, and the theme’s own shop templates come with it in the same step. Contact forms need no plugin at all.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'Reversible: one button removes everything an import created and touches nothing else.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'It learns: every import is written into a journal — what the archive was, what went wrong, how faithful the result measured — and the next import starts from that knowledge.', 'qwerty-soft-signal' ); ?></li>
		</ul>

		<?php $shots = get_template_directory_uri() . '/assets/images/handbook'; ?>

		<h2><?php esc_html_e( 'How an import goes, in four steps', 'qwerty-soft-signal' ); ?></h2>
		<ol>
			<li>
				<?php esc_html_e( 'Upload the design ZIP on Appearance → Design import. A design already uploaded appears as a card — click it to continue with it.', 'qwerty-soft-signal' ); ?>
				<figure>
					<img src="<?php echo esc_url( $shots . '/step-1-upload.png' ); ?>" alt="<?php esc_attr_e( 'The upload form with an already-uploaded design below it', 'qwerty-soft-signal' ); ?>" width="1082" height="320" loading="lazy">
					<figcaption><?php esc_html_e( 'Step 1 — the upload form, with designs already on the server listed under it.', 'qwerty-soft-signal' ); ?></figcaption>
				</figure>
			</li>
			<li>
				<?php esc_html_e( 'Choose. The screen lists every page the build will make, the language, and — when the archive carries more than one site — a box per site. Untick what you do not want; nothing here is one-way.', 'qwerty-soft-signal' ); ?>
				<figure>
					<img src="<?php echo esc_url( $shots . '/step-2-choose.png' ); ?>" alt="<?php esc_attr_e( 'The build panel: conversion mode, language picker, the archive’s sites, and the list of pages the build will make', 'qwerty-soft-signal' ); ?>" width="1568" height="720" loading="lazy">
					<figcaption><?php esc_html_e( 'Step 2 — mode, language, which of the archive’s sites, and exactly which pages.', 'qwerty-soft-signal' ); ?></figcaption>
				</figure>
			</li>
			<li><?php esc_html_e( 'Build. It runs on the server; the tab can be closed. The screen narrates every step, shows a progress bar, and flags anything worth checking.', 'qwerty-soft-signal' ); ?></li>
			<li>
				<?php esc_html_e( 'Review and publish. The pages arrive as drafts with View and Edit beside each; publishing sets the front page and the menu for you. The list below the table says what the build measured and what deserves a look.', 'qwerty-soft-signal' ); ?>
				<figure>
					<img src="<?php echo esc_url( $shots . '/step-4-review.png' ); ?>" alt="<?php esc_attr_e( 'The Pages built table with View and Edit actions and the Worth checking list', 'qwerty-soft-signal' ); ?>" width="1568" height="727" loading="lazy">
					<figcaption><?php esc_html_e( 'Step 4 — every page the build made, its status, and what the build suggests checking.', 'qwerty-soft-signal' ); ?></figcaption>
				</figure>
			</li>
		</ol>

		<h2><?php esc_html_e( 'Languages and translations', 'qwerty-soft-signal' ); ?></h2>
		<p><?php esc_html_e( 'A design that ships in several languages (en, ru, zh…) is imported one language at a time — or all at once. To add a language later: open Design import, click the design, set Language to the one you want (or to “All”), and press Build again. The new pages are created beside the existing ones, and the language switcher in the header connects to them by itself. The pages already built are left exactly as they are.', 'qwerty-soft-signal' ); ?></p>
		<p><?php esc_html_e( 'Translating what YOU wrote (an edited heading, a new paragraph) is ordinary page editing: open the page in the other language and change its text. The theme’s own admin screens are translation-ready too — a translation file per language under languages/ is all they need.', 'qwerty-soft-signal' ); ?></p>

		<h2><?php esc_html_e( 'Where to change what', 'qwerty-soft-signal' ); ?></h2>
		<table>
			<tr><td><strong><?php esc_html_e( 'Text on a page', 'qwerty-soft-signal' ); ?></strong></td><td><?php esc_html_e( 'Open the page in the editor; every section is a block with its texts in the sidebar.', 'qwerty-soft-signal' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Header and footer words', 'qwerty-soft-signal' ); ?></strong></td><td><?php esc_html_e( 'Appearance → Site content. One edit reaches every page.', 'qwerty-soft-signal' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'The menu', 'qwerty-soft-signal' ); ?></strong></td><td><?php esc_html_e( 'Appearance → Editor → Navigation.', 'qwerty-soft-signal' ); ?></td></tr>
			<tr><td><strong><?php esc_html_e( 'Products', 'qwerty-soft-signal' ); ?></strong></td><td><?php esc_html_e( 'The Products menu (WooCommerce), when the site has a catalogue.', 'qwerty-soft-signal' ); ?></td></tr>
		</table>

		<figure>
			<img src="<?php echo esc_url( $shots . '/site-content.png' ); ?>" alt="<?php esc_attr_e( 'The Site content screen with the footer’s texts and links as plain form fields', 'qwerty-soft-signal' ); ?>" width="1400" height="853" loading="lazy">
			<figcaption><?php esc_html_e( 'Appearance → Site content: the header’s and footer’s words as ordinary form fields.', 'qwerty-soft-signal' ); ?></figcaption>
		</figure>

		<?php $learned = Lessons::brief(); ?>
		<?php if ( '' !== $learned ) : ?>
			<h2><?php esc_html_e( 'What this site has learned so far', 'qwerty-soft-signal' ); ?></h2>
			<p class="qs-handbook__lesson"><?php echo esc_html( $learned ); ?></p>
		<?php endif; ?>
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
		<p><?php esc_html_e( 'DesignArchive unpacks and indexes the ZIP. A JavaScript application first gets its pages read out of the router (SourceProject finds the routes, SourceRenderer has the model render each into a static page). SectionSplitter cuts every page into sections; SectionPlan reads each section structurally and, on a smart build, PlanReview asks the model only what code cannot know — field names and whether a repeat is the site’s records. BlockWriter then writes each section as its own block under blocks/design/: render.php holds the archive’s markup verbatim with fields planted into it, fields.json one ACF field per editable thing, and every block names the canonical stylesheet of ITS source — a handoff carrying several sites gets one canonical file per source, loaded only where its pages stand. SiteAssembler makes the pages, the chrome and the menu; BuildRunner drives it in bursts on WP-Cron; finish() rewrites every link to the built pages, measures each page against the design, and writes the Lessons journal entry.', 'qwerty-soft-signal' ); ?></p>

		<h2><?php esc_html_e( 'The rules that keep it faithful', 'qwerty-soft-signal' ); ?></h2>
		<ul>
			<li><?php esc_html_e( 'Markup is copied, never translated; classes are never renamed. The stylesheet stays whole per source — slicing it per block re-runs the cascade in block order.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'A listing that cannot name its record is a repeater; repeated rows whose unplanned text differs become one verbatim block; a listing shows as many records as the design drew cards, and its cards link to their records’ permalinks.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'Chrome and menu come from the first page that actually has a body — never an application shell. Directory links resolve to that language’s home, inside the site.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'A record type never claims a URL base another post type already owns; it takes its qs- prefixed slug instead.', 'qwerty-soft-signal' ); ?></li>
			<li><?php esc_html_e( 'Blocks are written once and never regenerated (bumping BlockWriter::VERSION regenerates all of them) — editing a generated render.php by hand is expected and survives.', 'qwerty-soft-signal' ); ?></li>
		</ul>

		<h2><?php esc_html_e( 'When it misbehaves', 'qwerty-soft-signal' ); ?></h2>
		<table>
			<tr>
				<td><strong><?php esc_html_e( 'The build goes quiet', 'qwerty-soft-signal' ); ?></strong></td>
				<td><?php esc_html_e( 'WP-Cron lost the hand-off between ticks. Any visit to the site revives it (a patrol runs on every request; after 15 quiet minutes the screen offers to continue in-request). Check: is there a qwerty_soft_build_tick booking in the cron option?', 'qwerty-soft-signal' ); ?></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'A page measures thin', 'qwerty-soft-signal' ); ?></strong></td>
				<td><?php esc_html_e( 'Read the build’s concerns — the fidelity check names the page and the likely section kind. Usually a repeat/listing judgement: delete that one block’s folder and rebuild the page; the fresh plan applies the current rules.', 'qwerty-soft-signal' ); ?></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Pages render unstyled', 'qwerty-soft-signal' ); ?></strong></td>
				<td><?php esc_html_e( 'The pages being built must link their stylesheet — everything downstream reads a page’s styling from its own link tags. For an application, the compiled build CSS (dist/) is the stylesheet of record.', 'qwerty-soft-signal' ); ?></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Record URLs 404', 'qwerty-soft-signal' ); ?></strong></td>
				<td><?php esc_html_e( 'A rewrite-slug collision with a plugin (WooCommerce owns product/). The guard prefixes new types automatically; for an old one, remove the block’s type.json and flush permalinks.', 'qwerty-soft-signal' ); ?></td>
			</tr>
			<tr>
				<td><strong><?php esc_html_e( 'Everything else', 'qwerty-soft-signal' ); ?></strong></td>
				<td><?php esc_html_e( 'The import log narrates every step (Appearance → Design import); the Lessons journal holds the last twenty builds’ measurements. Start there, not in the database.', 'qwerty-soft-signal' ); ?></td>
			</tr>
		</table>

		<h2><?php esc_html_e( 'The gates', 'qwerty-soft-signal' ); ?></h2>
		<p><code>npm run test</code> — <?php esc_html_e( 'contrast, block markup, PHPCS (WordPress-Extra, zero tolerance) and the unit suite. Run it after touching anything; the build’s own fidelity measure covers what the gates cannot.', 'qwerty-soft-signal' ); ?></p>
		<p><code>npm run audit:pixels</code> — <?php esc_html_e( 'renders every built page and its design file in the same browser and reports the share of pixels that differ, with a red-overlay image per page (artifacts/pixels/report.html). Words and structure are measured by the build itself; this is the last mile — colour, spacing, fonts. It reads artifacts/pixel-manifest.json: name, live URL, absolute origin path per page.', 'qwerty-soft-signal' ); ?></p>
		<?php
	}
}
