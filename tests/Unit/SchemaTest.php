<?php
/**
 * Schema tests.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Tests\Unit;

use ProductSheetAI\Schema;

/**
 * Schema invariants required by structured outputs.
 */
final class SchemaTest extends TestCase {

	/**
	 * Walk the schema and collect the problems.
	 *
	 * @param array<string,mixed> $node Schema node.
	 * @param string              $path Path for messages.
	 * @return list<string>
	 */
	private function problems( array $node, string $path ): array {
		$found = array();
		foreach ( array( 'minLength', 'maxLength', 'minimum', 'maximum', 'minItems', 'maxItems', 'pattern', 'format' ) as $unsupported ) {
			if ( array_key_exists( $unsupported, $node ) ) {
				$found[] = $path . ' uses ' . $unsupported;
			}
		}
		if ( isset( $node['anyOf'] ) ) {
			foreach ( $node['anyOf'] as $index => $branch ) {
				$found = array_merge( $found, $this->problems( $branch, $path . '.anyOf[' . $index . ']' ) );
			}
		}
		if ( isset( $node['items'] ) ) {
			$found = array_merge( $found, $this->problems( $node['items'], $path . '[]' ) );
		}
		if ( 'object' === ( $node['type'] ?? '' ) ) {
			if ( false !== ( $node['additionalProperties'] ?? null ) ) {
				$found[] = $path . ' lacks additionalProperties false';
			}
			$properties = array_keys( $node['properties'] ?? array() );
			if ( ( $node['required'] ?? array() ) !== $properties ) {
				$found[] = $path . ' required is not all properties';
			}
			foreach ( $node['properties'] as $key => $child ) {
				$found = array_merge( $found, $this->problems( $child, $path . '.' . $key ) );
			}
		}
		return $found;
	}

	/**
	 * Every object is closed and fully required, recursively.
	 */
	public function test_objects_are_closed_and_fully_required(): void {
		$this->assertSame( array(), $this->problems( Schema::json_schema(), '$' ) );
	}

	/**
	 * Top level keys match the contract.
	 */
	public function test_top_level_properties(): void {
		$schema = Schema::json_schema();
		$this->assertSame(
			array(
				'name',
				'short_description',
				'description',
				'product_type',
				'regular_price',
				'sale_price',
				'sku',
				'weight',
				'dimensions',
				'categories',
				'tags',
				'attributes',
				'variations',
				'seo_title',
				'seo_description',
				'image_alts',
				'warnings',
			),
			array_keys( $schema['properties'] )
		);
		$this->assertSame( array( 'simple', 'variable' ), $schema['properties']['product_type']['enum'] );
	}

	/**
	 * Optional values are string or null.
	 */
	public function test_nullable_fields(): void {
		$properties = Schema::json_schema()['properties'];
		foreach ( array( 'regular_price', 'sale_price', 'sku', 'weight' ) as $key ) {
			$this->assertSame(
				array(
					array( 'type' => 'string' ),
					array( 'type' => 'null' ),
				),
				$properties[ $key ]['anyOf'],
				$key
			);
		}
	}

	/**
	 * The schema encodes to JSON.
	 */
	public function test_schema_is_json_encodable(): void {
		$this->assertNotFalse( wp_json_encode( Schema::json_schema() ) );
	}
}
