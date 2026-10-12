<?php
/**
 * Pricing tests.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Tests\Unit;

use Brain\Monkey\Filters;
use ProductSheetAI\Pricing;

/**
 * Pricing::estimate() and the price table.
 */
final class PricingTest extends TestCase {

	/**
	 * Known models are priced per million tokens.
	 */
	public function test_known_model_cost(): void {
		$this->assertEqualsWithDelta( 0.0140, (float) Pricing::estimate( 'claude-sonnet-5-5', 2000, 1000 ), 0.0000001 );
		$this->assertEqualsWithDelta( 0.0000075, (float) Pricing::estimate( 'claude-haiku-5-5', 50, 5 ), 0.0000001 );
		$this->assertEqualsWithDelta( 12.5, (float) Pricing::estimate( 'gpt-4o', 5000000, 0 ), 0.0000001 );
	}

	/**
	 * The table has the six default models.
	 */
	public function test_default_table(): void {
		$prices = Pricing::default_prices();
		$this->assertSame(
			array( 'claude-sonnet-5-5', 'claude-sonnet-5', 'claude-opus-5-5', 'claude-haiku-5-5', 'gpt-4o-mini', 'gpt-4o' ),
			array_keys( $prices )
		);
		$this->assertSame( 4.0, $prices['claude-opus-5-5']['in'] );
		$this->assertSame( 0.6, $prices['gpt-4o-mini']['out'] );
	}

	/**
	 * Unknown model gives null.
	 */
	public function test_unknown_model_is_null(): void {
		$this->assertNull( Pricing::estimate( 'mystery-model', 100, 100 ) );
		$this->assertNull( Pricing::estimate( '', 100, 100 ) );
	}

	/**
	 * The filter can add and change prices, and bad rows are ignored.
	 */
	public function test_filter_extends_table(): void {
		Filters\expectApplied( 'product_sheet_ai_model_prices' )->andReturn(
			array(
				'my-model' => array(
					'in'  => 1,
					'out' => 3,
				),
				'broken'   => array( 'in' => 1 ),
			)
		);
		$this->assertEqualsWithDelta( 0.004, (float) Pricing::estimate( 'my-model', 1000, 1000 ), 0.0000001 );
		$this->assertNull( Pricing::estimate( 'broken', 1000, 1000 ) );
		$this->assertNull( Pricing::estimate( 'gpt-4o', 1000, 1000 ) );
	}
}
