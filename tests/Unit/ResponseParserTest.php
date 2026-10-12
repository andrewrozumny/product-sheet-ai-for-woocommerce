<?php
/**
 * Response parser tests.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Tests\Unit;

use Brain\Monkey\Functions;
use ProductSheetAI\ParseException;
use ProductSheetAI\ResponseParser;

/**
 * ResponseParser::parse() and price normalization.
 */
final class ResponseParserTest extends TestCase {

	/**
	 * Stand-ins for the WordPress sanitizers.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'sanitize_text_field' )->alias(
			static fn( string $value ): string => trim( (string) preg_replace( '/\s+/', ' ', self::strip( $value ) ) )
		);
		Functions\when( 'wp_kses' )->alias(
			static function ( string $value, array $allowed ): string {
				$value = (string) preg_replace( '#<(script|style)\b.*?</\1>#is', '', $value );
				$tags  = '<' . implode( '><', array_keys( $allowed ) ) . '>';
				return self::strip( $value, $tags );
			}
		);
	}

	/**
	 * Tag stripping stand-in for the WordPress sanitizers.
	 *
	 * @param string $value Text.
	 * @param string $tags  Allowed tags.
	 */
	private static function strip( string $value, string $tags = '' ): string {
		return strip_tags( $value, $tags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- Test stand-in.
	}

	/**
	 * A complete, valid answer.
	 *
	 * @param array<string,mixed> $override Values to replace.
	 * @return array<string,mixed>
	 */
	private function answer( array $override = array() ): array {
		return array_replace(
			array(
				'name'              => 'Trail Runner Backpack 20L',
				'short_description' => '<p>Light backpack.</p>',
				'description'       => '<h3>Details</h3><p>Water resistant.</p>',
				'product_type'      => 'simple',
				'regular_price'     => '79.90',
				'sale_price'        => null,
				'sku'               => 'TR-BP-20',
				'weight'            => '0.65',
				'dimensions'        => array(
					'length' => '48',
					'width'  => '26',
					'height' => '18',
				),
				'categories'        => array( 'Bags' ),
				'tags'              => array( 'hiking' ),
				'attributes'        => array(
					array(
						'name'                => 'color',
						'values'              => array( 'Black', 'Orange' ),
						'used_for_variations' => false,
						'visible'             => true,
					),
				),
				'variations'        => array(),
				'seo_title'         => 'Trail Runner Backpack 20L',
				'seo_description'   => 'A light 20 liter backpack.',
				'image_alts'        => array(
					array(
						'image_id' => 12,
						'alt'      => 'Black backpack',
					),
				),
				'warnings'          => array(),
			),
			$override
		);
	}

	/**
	 * Encode an answer.
	 *
	 * @param array<string,mixed> $data Answer.
	 */
	private function json( array $data ): string {
		return (string) wp_json_encode( $data );
	}

	/**
	 * Run the parser and return the exception.
	 *
	 * @param string $text Model text.
	 */
	private function failure( string $text ): ParseException {
		try {
			ResponseParser::parse( $text );
		} catch ( ParseException $e ) {
			return $e;
		}
		$this->fail( 'ParseException expected' );
	}

	/**
	 * A valid answer comes back normalized.
	 */
	public function test_valid_response(): void {
		$result = ResponseParser::parse( $this->json( $this->answer() ) );
		$this->assertSame( 'Trail Runner Backpack 20L', $result['name'] );
		$this->assertSame( '79.90', $result['regular_price'] );
		$this->assertNull( $result['sale_price'] );
		$this->assertSame( 0, $result['dropped_keys'] );
		$this->assertSame( array( 'Bags' ), $result['categories'] );
		$this->assertSame( 12, $result['image_alts'][0]['image_id'] );
		$this->assertSame( array(), $result['warnings'] );
	}

	/**
	 * Broken JSON is a schema error with a path.
	 */
	public function test_broken_json(): void {
		$e = $this->failure( '{"name": "x", ' );
		$this->assertSame( 'schema', $e->error_code() );
		$this->assertStringContainsString( 'json', $e->detail() );
		$this->assertNotSame( '', $e->getMessage() );
	}

	/**
	 * Empty text and non-object JSON fail.
	 */
	public function test_empty_and_non_object(): void {
		$this->assertSame( '(root) (empty)', $this->failure( '   ' )->detail() );
		$this->assertSame( '(root) (json)', $this->failure( '[1,2]' )->detail() );
	}

	/**
	 * A missing required key names the key.
	 */
	public function test_missing_required(): void {
		$data = $this->answer();
		unset( $data['seo_title'] );
		$this->assertSame( 'seo_title (missing)', $this->failure( $this->json( $data ) )->detail() );
	}

	/**
	 * A missing nested key names the full path.
	 */
	public function test_missing_nested_required(): void {
		$data = $this->answer();
		unset( $data['attributes'][0]['values'] );
		$this->assertSame( 'attributes[0].values (missing)', $this->failure( $this->json( $data ) )->detail() );
	}

	/**
	 * Wrong types are rejected with the JSON path.
	 */
	public function test_wrong_type(): void {
		$data                            = $this->answer();
		$data['attributes'][0]['values'] = 'Black';
		$this->assertSame( 'attributes[0].values (type)', $this->failure( $this->json( $data ) )->detail() );

		$this->assertSame( 'name (type)', $this->failure( $this->json( $this->answer( array( 'name' => 5 ) ) ) )->detail() );
		$this->assertSame( 'regular_price (type)', $this->failure( $this->json( $this->answer( array( 'regular_price' => 19.9 ) ) ) )->detail() );

		$data                              = $this->answer();
		$data['image_alts'][0]['image_id'] = '12';
		$this->assertSame( 'image_alts[0].image_id (type)', $this->failure( $this->json( $data ) )->detail() );
	}

	/**
	 * A bad enum value is rejected.
	 */
	public function test_bad_enum(): void {
		$this->assertSame( 'product_type (enum)', $this->failure( $this->json( $this->answer( array( 'product_type' => 'grouped' ) ) ) )->detail() );
	}

	/**
	 * Unknown keys are dropped at the top and nested levels and counted.
	 */
	public function test_extra_keys_dropped_and_counted(): void {
		$data                                 = $this->answer( array( 'confidence' => 0.9 ) );
		$data['dimensions']['unit']           = 'cm';
		$data['attributes'][0]['group']       = 'x';
		$data['image_alts'][0]['source']      = 'y';
		$data['image_alts'][0]['extra_twice'] = 'z';
		$result                               = ResponseParser::parse( $this->json( $data ) );
		$this->assertSame( 5, $result['dropped_keys'] );
		$this->assertArrayNotHasKey( 'confidence', $result );
		$this->assertArrayNotHasKey( 'unit', $result['dimensions'] );
		$this->assertArrayNotHasKey( 'group', $result['attributes'][0] );
	}

	/**
	 * Nullable fields accept null and strings, and empty strings become null.
	 */
	public function test_nullable_fields(): void {
		$result = ResponseParser::parse(
			$this->json(
				$this->answer(
					array(
						'regular_price' => null,
						'sku'           => '',
						'weight'        => null,
					)
				)
			)
		);
		$this->assertNull( $result['regular_price'] );
		$this->assertNull( $result['sku'] );
		$this->assertNull( $result['weight'] );
		$this->assertSame( array(), $result['warnings'] );
	}

	/**
	 * Price normalization cases.
	 *
	 * @return array<string,array{0:string,1:?string}>
	 */
	public static function price_cases(): array {
		return array(
			'thousands comma'    => array( '1,299.00', '1299.00' ),
			'currency symbol'    => array( '$19.99', '19.99' ),
			'decimal comma'      => array( '19,99', '19.99' ),
			'european'           => array( '1.299,00', '1299.00' ),
			'space grouping'     => array( '1 299,00', '1299.00' ),
			'currency code'      => array( 'USD 79.90', '79.90' ),
			'plain integer'      => array( '25', '25' ),
			'comma thousands'    => array( '1,299', '1299' ),
			'millions'           => array( '1,299,000', '1299000' ),
			'leading zero value' => array( '0,500', '0.500' ),
			'leading dot'        => array( '.99', '0.99' ),
			'trailing text'      => array( '5 kg', '5' ),
			'empty'              => array( '', null ),
			'words'              => array( 'free', null ),
			'negative'           => array( '-5', null ),
			'range'              => array( '10-20', null ),
			'two numbers'        => array( '19.99 24.99', null ),
			'exponent'           => array( '1e5', null ),
		);
	}

	/**
	 * Price normalization.
	 *
	 * @dataProvider price_cases
	 *
	 * @param string  $input    Raw text.
	 * @param ?string $expected Expected result.
	 */
	public function test_normalize_price( string $input, ?string $expected ): void {
		$this->assertSame( $expected, ResponseParser::normalize_price( $input ) );
	}

	/**
	 * Prices go through normalization and invalid ones become null with a warning.
	 */
	public function test_price_fields_in_response(): void {
		$result = ResponseParser::parse(
			$this->json(
				$this->answer(
					array(
						'regular_price' => '$1,299.00',
						'sale_price'    => 'call us',
						'weight'        => '0,65',
					)
				)
			)
		);
		$this->assertSame( '1299.00', $result['regular_price'] );
		$this->assertNull( $result['sale_price'] );
		$this->assertSame( '0.65', $result['weight'] );
		$this->assertCount( 1, $result['warnings'] );
		$this->assertStringContainsString( 'sale_price', $result['warnings'][0] );
	}

	/**
	 * Script tags and disallowed markup are removed.
	 */
	public function test_html_sanitization(): void {
		$result = ResponseParser::parse(
			$this->json(
				$this->answer(
					array(
						'name'              => '<b>Pack</b> <script>alert(1)</script>',
						'short_description' => '<p>Hi</p><script>alert(1)</script><img src=x onerror=alert(1)>',
						'description'       => '<h3>Specs</h3><table><tr><td>a</td></tr></table><iframe src="x"></iframe>',
					)
				)
			)
		);
		$this->assertStringNotContainsString( '<', $result['name'] );
		$this->assertStringNotContainsString( 'script', $result['short_description'] );
		$this->assertStringNotContainsString( '<img', $result['short_description'] );
		$this->assertStringContainsString( '<table>', $result['description'] );
		$this->assertStringNotContainsString( 'iframe', $result['description'] );
	}

	/**
	 * A name that is empty after sanitizing is rejected.
	 */
	public function test_empty_name_fails(): void {
		$this->assertSame( 'name (empty)', $this->failure( $this->json( $this->answer( array( 'name' => '<b></b> ' ) ) ) )->detail() );
	}

	/**
	 * JSON inside a code fence or surrounded by text is accepted.
	 */
	public function test_fenced_and_wrapped_json(): void {
		$json   = $this->json( $this->answer() );
		$fenced = ResponseParser::parse( "```json\n" . $json . "\n```" );
		$this->assertSame( 'Trail Runner Backpack 20L', $fenced['name'] );

		$wrapped = ResponseParser::parse( "Here is the product:\n" . $json . "\nLet me know if you need changes {not json}." );
		$this->assertSame( 'Trail Runner Backpack 20L', $wrapped['name'] );
	}

	/**
	 * Braces inside strings do not confuse the extraction.
	 */
	public function test_braces_in_strings(): void {
		$json   = $this->json( $this->answer( array( 'name' => 'Pack {Pro}' ) ) );
		$result = ResponseParser::parse( 'Sure: ' . $json . ' done' );
		$this->assertSame( 'Pack {Pro}', $result['name'] );
	}

	/**
	 * Over-limit lists are trimmed with warnings.
	 */
	public function test_limits(): void {
		$attributes = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$attributes[] = array(
				'name'                => 'attr' . $i,
				'values'              => array_map( static fn( int $n ): string => 'v' . $n, range( 1, 55 ) ),
				'used_for_variations' => false,
				'visible'             => true,
			);
		}
		$variations = array();
		for ( $i = 1; $i <= 105; $i++ ) {
			$variations[] = array(
				'attributes'    => array(
					array(
						'name'  => 'attr1',
						'value' => 'v' . $i,
					),
				),
				'regular_price' => null,
				'sale_price'    => null,
				'sku'           => null,
			);
		}
		$result = ResponseParser::parse(
			$this->json(
				$this->answer(
					array(
						'product_type' => 'variable',
						'attributes'   => $attributes,
						'variations'   => $variations,
						'categories'   => array_map( static fn( int $n ): string => 'Cat' . $n, range( 1, 25 ) ),
						'tags'         => array_map( static fn( int $n ): string => 'tag' . $n, range( 1, 22 ) ),
					)
				)
			)
		);
		$this->assertCount( ResponseParser::MAX_ATTRIBUTES, $result['attributes'] );
		$this->assertCount( ResponseParser::MAX_VALUES, $result['attributes'][0]['values'] );
		$this->assertCount( ResponseParser::MAX_VARIATIONS, $result['variations'] );
		$this->assertCount( ResponseParser::MAX_CATEGORIES, $result['categories'] );
		$this->assertCount( ResponseParser::MAX_TAGS, $result['tags'] );
		$this->assertGreaterThanOrEqual( 5, count( $result['warnings'] ) );
		$this->assertSame( 'variable', $result['product_type'] );
	}

	/**
	 * Duplicates are removed and empty entries ignored.
	 */
	public function test_names_deduplicated(): void {
		$result = ResponseParser::parse( $this->json( $this->answer( array( 'categories' => array( 'Bags', 'bags', ' ', 'Packs' ) ) ) ) );
		$this->assertSame( array( 'Bags', 'Packs' ), $result['categories'] );
	}

	/**
	 * Variable without variations falls back to simple with a warning.
	 */
	public function test_variable_without_variations_becomes_simple(): void {
		$result = ResponseParser::parse( $this->json( $this->answer( array( 'product_type' => 'variable' ) ) ) );
		$this->assertSame( 'simple', $result['product_type'] );
		$this->assertCount( 1, $result['warnings'] );
	}

	/**
	 * A real variable product keeps attributes and variations.
	 */
	public function test_variable_product(): void {
		$result = ResponseParser::parse(
			$this->json(
				$this->answer(
					array(
						'product_type' => 'variable',
						'variations'   => array(
							array(
								'attributes'    => array(
									array(
										'name'  => 'color',
										'value' => 'Black',
									),
								),
								'regular_price' => '$79,90',
								'sale_price'    => null,
								'sku'           => 'TR-BP-20-BLK',
							),
						),
					)
				)
			)
		);
		$this->assertSame( 'variable', $result['product_type'] );
		$this->assertSame( '79.90', $result['variations'][0]['regular_price'] );
		$this->assertSame( 'TR-BP-20-BLK', $result['variations'][0]['sku'] );
	}

	/**
	 * The model warnings are kept.
	 */
	public function test_model_warnings_kept(): void {
		$result = ResponseParser::parse( $this->json( $this->answer( array( 'warnings' => array( 'Price per box or per piece?' ) ) ) ) );
		$this->assertSame( array( 'Price per box or per piece?' ), $result['warnings'] );
	}

	/**
	 * Image alts need a positive id and a text, and are unique.
	 */
	public function test_image_alts_filtered(): void {
		$result = ResponseParser::parse(
			$this->json(
				$this->answer(
					array(
						'image_alts' => array(
							array(
								'image_id' => 0,
								'alt'      => 'bad id',
							),
							array(
								'image_id' => 5,
								'alt'      => 'first',
							),
							array(
								'image_id' => 5,
								'alt'      => 'again',
							),
							array(
								'image_id' => 6,
								'alt'      => ' ',
							),
						),
					)
				)
			)
		);
		$this->assertSame(
			array(
				array(
					'image_id' => 5,
					'alt'      => 'first',
				),
			),
			$result['image_alts']
		);
	}
}
