<?php
/**
 * Approximate request cost from provider list prices.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-million-token price table and cost estimate.
 */
final class Pricing {

	/**
	 * Default list prices in USD per million tokens.
	 *
	 * @return array<string,array{in:float,out:float}>
	 */
	public static function default_prices(): array {
		return array(
			'claude-sonnet-5-5' => array(
				'in'  => 2.00,
				'out' => 10.00,
			),
			'claude-sonnet-5'   => array(
				'in'  => 2.00,
				'out' => 10.00,
			),
			'claude-opus-5-5'   => array(
				'in'  => 4.00,
				'out' => 20.00,
			),
			'claude-haiku-5-5'  => array(
				'in'  => 0.10,
				'out' => 0.50,
			),
			'gpt-4o-mini'       => array(
				'in'  => 0.15,
				'out' => 0.60,
			),
			'gpt-4o'            => array(
				'in'  => 2.50,
				'out' => 10.00,
			),
		);
	}

	/**
	 * The price table after the filter.
	 *
	 * @return array<string,array{in:float,out:float}>
	 */
	public static function prices(): array {
		/**
		 * Filters the per-million-token prices, keyed by model name.
		 *
		 * @param array<string,array{in:float,out:float}> $prices Prices in USD per million tokens.
		 */
		$prices = apply_filters( 'product_sheet_ai_model_prices', self::default_prices() );
		$clean  = array();
		if ( ! is_array( $prices ) ) {
			return self::default_prices();
		}
		foreach ( $prices as $model => $row ) {
			if ( is_string( $model ) && is_array( $row ) && isset( $row['in'], $row['out'] ) && is_numeric( $row['in'] ) && is_numeric( $row['out'] ) ) {
				$clean[ $model ] = array(
					'in'  => (float) $row['in'],
					'out' => (float) $row['out'],
				);
			}
		}
		return $clean;
	}

	/**
	 * Estimated cost in USD, or null when the model has no known price.
	 *
	 * @param string $model Model name.
	 * @param int    $in    Input tokens.
	 * @param int    $out   Output tokens.
	 */
	public static function estimate( string $model, int $in, int $out ): ?float {
		$prices = self::prices();
		if ( ! isset( $prices[ $model ] ) ) {
			return null;
		}
		$row = $prices[ $model ];
		return ( max( 0, $in ) * $row['in'] + max( 0, $out ) * $row['out'] ) / 1000000;
	}
}
