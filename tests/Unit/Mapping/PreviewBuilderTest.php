<?php
/**
 * Preview helper tests.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Tests\Unit\Mapping;

use Brain\Monkey\Functions;
use ProductSheetAI\Mapping\Applier;
use ProductSheetAI\Mapping\PreviewBuilder;
use ProductSheetAI\Tests\Unit\TestCase;

/**
 * Plain-text display helpers and price rules.
 */
final class PreviewBuilderTest extends TestCase {

	/**
	 * Strip tags for real in this test class.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
	}

	/**
	 * HTML descriptions become readable plain text.
	 */
	public function test_to_plain(): void {
		$html = '<p>Hand <strong>glazed</strong> mug.</p><ul><li>Dishwasher safe</li><li>Holds 350 ml</li></ul>';
		$this->assertSame( "Hand glazed mug.\nDishwasher safe, Holds 350 ml", PreviewBuilder::to_plain( $html ) );
		$this->assertSame( 'A & B', PreviewBuilder::to_plain( '<p>A &amp; B</p>' ) );
		$this->assertSame( '', PreviewBuilder::to_plain( '' ) );
	}

	/**
	 * Dimensions read "L x W x H unit".
	 */
	public function test_format_dimensions(): void {
		$this->assertSame(
			'9 x 9 x 10 cm',
			PreviewBuilder::format_dimensions(
				array(
					'length' => '9',
					'width'  => '9',
					'height' => '10',
				),
				'cm'
			)
		);
		$this->assertSame(
			'20 x - x - in',
			PreviewBuilder::format_dimensions(
				array(
					'length' => '20',
					'width'  => null,
					'height' => null,
				),
				'in'
			)
		);
		$this->assertSame( '', PreviewBuilder::format_dimensions( array( 'length' => null ), 'cm' ) );
	}

	/**
	 * Variation labels list name: value pairs.
	 */
	public function test_variation_label(): void {
		$label = PreviewBuilder::variation_label(
			array(
				array(
					'name'  => 'Color',
					'value' => 'Red',
				),
				array(
					'name'  => 'Size',
					'value' => 'M',
				),
				'garbage',
			)
		);
		$this->assertSame( 'Color: Red, Size: M', $label );
		$this->assertSame( '', PreviewBuilder::variation_label( 'nope' ) );
	}

	/**
	 * Only "variable" makes a variable product.
	 */
	public function test_proposed_type(): void {
		$this->assertSame( 'variable', PreviewBuilder::proposed_type( array( 'product_type' => 'variable' ) ) );
		$this->assertSame( 'simple', PreviewBuilder::proposed_type( array( 'product_type' => 'simple' ) ) );
		$this->assertSame( 'simple', PreviewBuilder::proposed_type( array() ) );
	}

	/**
	 * A sale price must be a number below the regular price.
	 */
	public function test_sale_or_null(): void {
		Functions\when( 'wc_format_decimal' )->returnArg();
		$this->assertSame( '9.90', Applier::sale_or_null( '12.50', '9.90' ) );
		$this->assertNull( Applier::sale_or_null( '12.50', '12.50' ) );
		$this->assertNull( Applier::sale_or_null( '12.50', '30.00' ) );
		$this->assertNull( Applier::sale_or_null( '', '9.90' ) );
		$this->assertNull( Applier::sale_or_null( '12.50', 'abc' ) );
	}
}
