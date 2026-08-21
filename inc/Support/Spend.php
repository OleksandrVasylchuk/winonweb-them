<?php
/**
 * What the design import has cost, and what the next press will cost.
 *
 * @package Wow\Signal
 * @license GPL-2.0-or-later
 */

declare( strict_types = 1 );

namespace Wow\Signal\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Tracks token usage and cost per user, and estimates a conversion before it runs.
 *
 * The import screen spends real money on every press. Until now the API
 * returned its token counts and the screen threw them away, so the person
 * deciding whether to convert fourteen sections had no way to know whether that
 * was forty cents or four dollars — and no running total afterwards.
 *
 * Two deliberate choices:
 *
 * - **Tokens are exact; money is an estimate, and says so.** The token counts
 *   come from the API. The prices are Anthropic's published list rates, which
 *   can change and which a site's own contract may not match, so every figure
 *   derived from them is labelled as an estimate and the rates are filterable.
 * - **The running total is per user, not per site.** It answers "what have I
 *   spent on this screen", which is the question the person at the keyboard is
 *   actually asking.
 */
final class Spend {

	/**
	 * User meta holding the running total.
	 */
	private const META = '_wow_signal_spend';

	/**
	 * Published list prices in US dollars per million tokens, as [ input, output ].
	 *
	 * Anthropic publishes these per model; they are not a contract. A site with
	 * negotiated pricing, or reading this after a price change, filters them.
	 *
	 * @var array<string, array{0:float,1:float}>
	 */
	private const PRICES = array(
		'claude-opus-5'    => array( 5.00, 25.00 ),
		'claude-sonnet-5'  => array( 3.00, 15.00 ),
		'claude-haiku-4-5' => array( 1.00, 5.00 ),
	);

	/**
	 * Multiplier applied to tokens served from the prompt cache.
	 */
	private const CACHE_READ = 0.1;

	/**
	 * Multiplier applied to tokens written to the prompt cache.
	 */
	private const CACHE_WRITE = 1.25;

	/**
	 * Characters of prompt per token, for estimating before a call is made.
	 *
	 * Four is the usual rule of thumb for English prose and is close enough for
	 * markup. It is only ever used for the word "about" in the interface.
	 */
	private const CHARS_PER_TOKEN = 4;

	/**
	 * Output tokens a converted section typically costs.
	 *
	 * Block markup for one section, measured across the theme's own patterns.
	 * The estimate is bounded by this rather than by max_tokens, which is a
	 * ceiling nobody reaches and would overstate the figure by an order of
	 * magnitude.
	 */
	private const TYPICAL_OUTPUT_TOKENS = 2200;

	/**
	 * Prices for one model, or null when no rate is known for it.
	 *
	 * An unknown model is not silently priced as Opus: the API reports which
	 * model actually answered, and a fallback could land on one this table has
	 * never heard of. Pretending to know its rate would put a confident wrong
	 * figure on the screen; callers treat null as "rate unknown" instead.
	 *
	 * @param string $model Model ID.
	 * @return array{0:float,1:float}|null Dollars per million input and output tokens.
	 */
	public static function price( string $model ): ?array {
		/**
		 * Filter the per-model token prices used to estimate cost.
		 *
		 * Published list rates change, and a site with its own agreement pays
		 * something else. Returning different numbers here changes every figure
		 * the import screen shows; it does not change what is billed.
		 *
		 * @since 1.2.0
		 *
		 * @param array<string, array{0:float,1:float}> $prices Model ID => [ input, output ] per million tokens.
		 */
		$prices = (array) apply_filters( 'wow_signal/anthropic_prices', self::PRICES );

		/*
		 * The API reports the model that answered as a dated snapshot id
		 * ("claude-opus-5-2026…") while prices are listed by alias. The
		 * longest alias that prefixes the reported id is the same model.
		 */
		if ( ! isset( $prices[ $model ] ) ) {
			$best = '';

			foreach ( array_keys( $prices ) as $alias ) {
				$alias = (string) $alias;

				if ( str_starts_with( $model, $alias ) && strlen( $alias ) > strlen( $best ) ) {
					$best = $alias;
				}
			}

			if ( '' === $best ) {
				return null;
			}

			$model = $best;
		}

		if ( ! is_array( $prices[ $model ] ) ) {
			return null;
		}

		$price = $prices[ $model ];

		return array( (float) ( $price[0] ?? 0.0 ), (float) ( $price[1] ?? 0.0 ) );
	}

	/**
	 * Whether a cost can be put on replies from this model.
	 *
	 * @param string $model Model ID.
	 * @return bool
	 */
	public static function knows( string $model ): bool {
		return null !== self::price( $model );
	}

	/**
	 * What one reply cost, in dollars.
	 *
	 * Zero for a model with no known rate — the tokens are still recorded, so
	 * nothing is lost, but no money figure is invented for them.
	 *
	 * @param array<string, mixed> $usage Usage block from the API.
	 * @param string               $model Model that produced it.
	 * @return float
	 */
	public static function cost( array $usage, string $model ): float {
		$price = self::price( $model );

		if ( null === $price ) {
			return 0.0;
		}

		list( $in, $out ) = $price;

		$input       = (int) ( $usage['input_tokens'] ?? 0 );
		$output      = (int) ( $usage['output_tokens'] ?? 0 );
		$cache_read  = (int) ( $usage['cache_read_input_tokens'] ?? 0 );
		$cache_write = (int) ( $usage['cache_creation_input_tokens'] ?? 0 );

		$dollars =
			( $input * $in )
			+ ( $output * $out )
			+ ( $cache_read * $in * self::CACHE_READ )
			+ ( $cache_write * $in * self::CACHE_WRITE );

		return $dollars / 1000000;
	}

	/**
	 * Add one conversion to the current user's running total.
	 *
	 * @param array<string, mixed> $usage    Usage block from the API.
	 * @param string               $model    Model that produced it.
	 * @param bool                 $billable Whether the tokens were actually charged.
	 *                                       A conversion run through the local
	 *                                       Claude Code CLI on a subscription
	 *                                       is not: its tokens are counted, so
	 *                                       the size of the work is still
	 *                                       visible, but adding money to the
	 *                                       total would claim a charge that
	 *                                       never appeared on an invoice.
	 * @return array{conversions:int,input:int,output:int,cost:float} The new totals.
	 */
	public static function record( array $usage, string $model, bool $billable = true ): array {
		$totals = self::totals();

		$totals['conversions'] = $totals['conversions'] + 1;
		$totals['input']       = $totals['input'] + (int) ( $usage['input_tokens'] ?? 0 ) + (int) ( $usage['cache_read_input_tokens'] ?? 0 ) + (int) ( $usage['cache_creation_input_tokens'] ?? 0 );
		$totals['output']      = $totals['output'] + (int) ( $usage['output_tokens'] ?? 0 );
		$totals['cost']        = $totals['cost'] + ( $billable ? self::cost( $usage, $model ) : 0.0 );

		update_user_meta( get_current_user_id(), self::META, $totals );

		return $totals;
	}

	/**
	 * The current user's running total.
	 *
	 * @return array{conversions:int,input:int,output:int,cost:float}
	 */
	public static function totals(): array {
		$stored = get_user_meta( get_current_user_id(), self::META, true );
		$stored = is_array( $stored ) ? $stored : array();

		return array(
			'conversions' => (int) ( $stored['conversions'] ?? 0 ),
			'input'       => (int) ( $stored['input'] ?? 0 ),
			'output'      => (int) ( $stored['output'] ?? 0 ),
			'cost'        => (float) ( $stored['cost'] ?? 0.0 ),
		);
	}

	/**
	 * Forget the running total.
	 *
	 * @return void
	 */
	public static function reset(): void {
		delete_user_meta( get_current_user_id(), self::META );
	}

	/**
	 * What converting a section of this size would cost, roughly.
	 *
	 * The prompt is the section's own markup plus the CSS rules that match it,
	 * so the character count the sections endpoint already computes is a real
	 * basis for the estimate rather than a guess at an average.
	 *
	 * @param int    $chars Characters of prompt.
	 * @param string $model Model that would run it.
	 * @return float Dollars.
	 */
	public static function estimate( int $chars, string $model ): float {
		$price = self::price( $model );

		if ( null === $price ) {
			return 0.0;
		}

		list( $in, $out ) = $price;

		$input = (int) ceil( max( 0, $chars ) / self::CHARS_PER_TOKEN );

		return ( ( $input * $in ) + ( self::TYPICAL_OUTPUT_TOKENS * $out ) ) / 1000000;
	}
}
