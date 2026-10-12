<?php
/**
 * Attribute and term matching tests.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Tests\Unit\Mapping;

use Brain\Monkey\Functions;
use ProductSheetAI\Mapping\AttributeMatcher;
use ProductSheetAI\Tests\Unit\TestCase;

/**
 * Pure matching rules of AttributeMatcher.
 */
final class AttributeMatcherTest extends TestCase {

	/**
	 * Stub sanitize_title like WordPress for plain ASCII text.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'sanitize_title' )->alias(
			static fn( string $title ): string => trim( (string) preg_replace( '/[^a-z0-9%]+/', '-', strtolower( $title ) ), '-' )
		);
	}

	/**
	 * The store's global attributes used in the tests.
	 *
	 * @return list<array{slug:string,label:string}>
	 */
	private function attributes(): array {
		return array(
			array(
				'slug'  => 'color',
				'label' => 'Color',
			),
			array(
				'slug'  => 'size',
				'label' => 'Size',
			),
			array(
				'slug'  => 'fabric-type',
				'label' => 'Fabric',
			),
		);
	}

	/**
	 * Default aliases.
	 *
	 * @return array<string,string>
	 */
	private function aliases(): array {
		return array(
			'colour'  => 'color',
			'colours' => 'color',
			'sizes'   => 'size',
		);
	}

	/**
	 * Label, slug, case and aliases all match.
	 */
	public function test_attribute_matches_by_label_slug_and_alias(): void {
		$match = static fn( string $name ) => AttributeMatcher::find_attribute(
			$name,
			array(
				array(
					'slug'  => 'color',
					'label' => 'Color',
				),
				array(
					'slug'  => 'fabric-type',
					'label' => 'Fabric',
				),
			),
			array( 'colour' => 'color' )
		);

		$this->assertSame( 'pa_color', $match( 'Color' )['taxonomy'] ?? '' );
		$this->assertSame( 'pa_color', $match( ' COLOR ' )['taxonomy'] ?? '' );
		$this->assertSame( 'pa_color', $match( 'Colour' )['taxonomy'] ?? '' );
		$this->assertSame( 'pa_fabric-type', $match( 'Fabric Type' )['taxonomy'] ?? '' );
		$this->assertSame( 'pa_fabric-type', $match( 'fabric' )['taxonomy'] ?? '' );
		$this->assertNull( $match( 'Material' ) );
		$this->assertNull( $match( '' ) );
	}

	/**
	 * The plural alias maps to the canonical attribute.
	 */
	public function test_plural_alias(): void {
		$match = AttributeMatcher::find_attribute( 'Sizes', $this->attributes(), $this->aliases() );
		$this->assertSame( 'pa_size', $match['taxonomy'] ?? '' );
	}

	/**
	 * A matcher built with lists works without WooCommerce.
	 */
	public function test_instance_with_injected_lists(): void {
		$matcher = new AttributeMatcher( $this->attributes(), $this->aliases() );
		$this->assertSame( 'pa_color', $matcher->match_attribute( 'Colours' )['taxonomy'] ?? '' );
		$this->assertContains( 'color', $matcher->keys( 'Colour' ) );
	}

	/**
	 * Terms match by name or slug, case-insensitive.
	 */
	public function test_term_matching(): void {
		$terms = array(
			array(
				'term_id' => 16,
				'name'    => 'Blue',
				'slug'    => 'blue',
			),
			array(
				'term_id' => 21,
				'name'    => 'Extra Large',
				'slug'    => 'xl',
			),
		);
		$this->assertSame( 16, AttributeMatcher::find_term( 'blue', $terms )['term_id'] ?? 0 );
		$this->assertSame( 16, AttributeMatcher::find_term( ' BLUE ', $terms )['term_id'] ?? 0 );
		$this->assertSame( 21, AttributeMatcher::find_term( 'XL', $terms )['term_id'] ?? 0 );
		$this->assertSame( 21, AttributeMatcher::find_term( 'extra large', $terms )['term_id'] ?? 0 );
		$this->assertNull( AttributeMatcher::find_term( 'Purple', $terms ) );
		$this->assertNull( AttributeMatcher::find_term( '', $terms ) );
	}

	/**
	 * Names that cannot be a taxonomy slug are not offered as global attributes.
	 */
	public function test_can_be_global(): void {
		$this->assertTrue( AttributeMatcher::can_be_global( 'Material' ) );
		$this->assertFalse( AttributeMatcher::can_be_global( '' ) );
		$this->assertFalse( AttributeMatcher::can_be_global( str_repeat( 'long ', 10 ) ) );
	}
}
