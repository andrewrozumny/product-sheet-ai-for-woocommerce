<?php
/**
 * Builds the preview shown before anything is saved.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Mapping;

use ProductSheetAI\Frontend\Seo;
use ProductSheetAI\Schema;
use ProductSheetAI\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns parsed product data into plain-text preview rows plus the list of new items that need a confirmation.
 *
 * Every string in the result is plain text. The browser must show it with textContent only.
 */
final class PreviewBuilder {

	/**
	 * Build the preview.
	 *
	 * @param array<string,mixed> $data       Parsed product data.
	 * @param int                 $product_id Product being edited, 0 for a new product.
	 * @param array<string,mixed> $usage      Token usage: input_tokens, output_tokens, model.
	 * @return array<string,mixed>
	 * @throws \InvalidArgumentException When the product to edit does not exist.
	 */
	public static function build( array $data, int $product_id = 0, array $usage = array() ): array {
		$product = null;
		if ( $product_id > 0 ) {
			$product = wc_get_product( $product_id );
			if ( ! $product instanceof \WC_Product ) {
				throw new \InvalidArgumentException( esc_html__( 'The product to update could not be found.', 'product-sheet-ai-for-woocommerce' ) );
			}
		}

		$matcher    = new AttributeMatcher();
		$new_items  = array();
		$warnings   = self::string_list( $data['warnings'] ?? array() );
		$attributes = self::attribute_rows( $data, $matcher, $new_items );
		$variations = self::variation_rows( $data );
		$overwrite  = Settings::get()['overwrite_fields'];
		$unit_w     = (string) get_option( 'woocommerce_weight_unit', '' );
		$unit_d     = (string) get_option( 'woocommerce_dimension_unit', '' );

		$proposed = self::proposed_values( $data, $attributes, $variations, $unit_w, $unit_d );
		$current  = null === $product ? array_fill_keys( Schema::FIELD_KEYS, '' ) : self::current_values( $product, $unit_w, $unit_d );

		$sku_conflict = self::sku_conflict( $data, $product_id );
		$labels       = Schema::labels();
		$fields       = array();
		foreach ( Schema::FIELD_KEYS as $key ) {
			$has_proposal = '' !== $proposed[ $key ];
			$checked      = $has_proposal && ( '' === $current[ $key ] || in_array( $key, $overwrite, true ) );
			$disabled     = ! $has_proposal;
			$note         = $has_proposal ? '' : __( 'The sheet does not provide this.', 'product-sheet-ai-for-woocommerce' );
			if ( 'sku' === $key && $sku_conflict ) {
				$checked  = false;
				$disabled = true;
				$note     = __( 'This SKU is already used by another product.', 'product-sheet-ai-for-woocommerce' );
			}
			$fields[] = array(
				'key'      => $key,
				'label'    => $labels[ $key ] ?? $key,
				'current'  => $current[ $key ],
				'proposed' => $proposed[ $key ],
				'checked'  => $checked,
				'disabled' => $disabled,
				'note'     => $note,
			);
		}

		self::add_category_items( $data, $new_items );

		return array(
			'product_id'   => $product_id,
			'is_new'       => null === $product,
			'product_type' => array(
				'current'  => null === $product ? '' : $product->get_type(),
				'proposed' => self::proposed_type( $data ),
			),
			'fields'       => $fields,
			'attributes'   => $attributes,
			'variations'   => $variations,
			'new_items'    => array_values( $new_items ),
			'warnings'     => $warnings,
			'usage'        => array(
				'input_tokens'  => isset( $usage['input_tokens'] ) && is_numeric( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : 0,
				'output_tokens' => isset( $usage['output_tokens'] ) && is_numeric( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : 0,
				'model'         => isset( $usage['model'] ) && is_string( $usage['model'] ) ? $usage['model'] : '',
			),
		);
	}

	/**
	 * Plain text from simple HTML: block ends become line breaks, list items are separated, tags are removed.
	 *
	 * @param string $html HTML from the model or from the product.
	 */
	public static function to_plain( string $html ): string {
		$text = (string) preg_replace( '#<\s*br\s*/?\s*>|</\s*(p|div|h[1-6]|tr|ul|ol)\s*>#i', "\n", $html );
		$text = (string) preg_replace( '#</\s*(li|td|th)\s*>#i', ', ', $text );
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = (string) preg_replace( '/[ \t\x{00A0}]+/u', ' ', $text );
		$text = (string) preg_replace( '/,\s*(\n|$)/', '$1', $text );
		$text = (string) preg_replace( '/\s*\n\s*/', "\n", $text );
		return trim( $text );
	}

	/**
	 * Dimensions as "L x W x H unit". Missing parts are shown as a hyphen. Empty when nothing is known.
	 *
	 * @param array<string,mixed> $dimensions Keys length, width, height.
	 * @param string              $unit       Store dimension unit.
	 */
	public static function format_dimensions( array $dimensions, string $unit ): string {
		$parts = array();
		$any   = false;
		foreach ( array( 'length', 'width', 'height' ) as $axis ) {
			$value   = isset( $dimensions[ $axis ] ) && is_scalar( $dimensions[ $axis ] ) ? trim( (string) $dimensions[ $axis ] ) : '';
			$any     = $any || '' !== $value;
			$parts[] = '' === $value ? '-' : $value;
		}
		if ( ! $any ) {
			return '';
		}
		return trim( implode( ' x ', $parts ) . ' ' . $unit );
	}

	/**
	 * Label of a variation, for example "Color: Red, Size: M".
	 *
	 * @param mixed $attributes List of array{name:string,value:string}.
	 */
	public static function variation_label( $attributes ): string {
		$parts = array();
		foreach ( is_array( $attributes ) ? $attributes : array() as $pair ) {
			if ( is_array( $pair ) && isset( $pair['name'], $pair['value'] ) && is_string( $pair['name'] ) && is_string( $pair['value'] ) ) {
				$parts[] = $pair['name'] . ': ' . $pair['value'];
			}
		}
		return implode( ', ', $parts );
	}

	/**
	 * Proposed product type: simple or variable.
	 *
	 * @param array<string,mixed> $data Parsed data.
	 */
	public static function proposed_type( array $data ): string {
		return isset( $data['product_type'] ) && 'variable' === $data['product_type'] ? 'variable' : 'simple';
	}

	/**
	 * Preview rows of the attributes, with the mapping to global attributes and terms.
	 *
	 * @param array<string,mixed>                         $data      Parsed data.
	 * @param AttributeMatcher                            $matcher   Matcher.
	 * @param array<string,array{id:string,label:string}> $new_items New items collector.
	 * @return list<array<string,mixed>>
	 */
	private static function attribute_rows( array $data, AttributeMatcher $matcher, array &$new_items ): array {
		$rows = array();
		foreach ( self::list_of_arrays( $data['attributes'] ?? array() ) as $attribute ) {
			$name = isset( $attribute['name'] ) && is_string( $attribute['name'] ) ? trim( $attribute['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$values   = self::string_list( $attribute['values'] ?? array() );
			$matched  = $matcher->match_attribute( $name );
			$taxonomy = '';
			$match    = 'local';
			$row      = array();

			if ( null !== $matched ) {
				$taxonomy = $matched['taxonomy'];
				$match    = 'global';
				foreach ( $values as $value ) {
					$exists = null !== $matcher->match_term( $taxonomy, $value );
					$row[]  = array(
						'value'  => $value,
						'exists' => $exists,
					);
					if ( ! $exists ) {
						$id               = 'term:' . $taxonomy . ':' . $value;
						$new_items[ $id ] = array(
							'id'    => $id,
							/* translators: 1: attribute name, 2: new value. */
							'label' => sprintf( __( 'New %1$s value: %2$s', 'product-sheet-ai-for-woocommerce' ), $matched['label'], $value ),
						);
					}
				}
			} else {
				foreach ( $values as $value ) {
					$row[] = array(
						'value'  => $value,
						'exists' => false,
					);
				}
				if ( AttributeMatcher::can_be_global( $name ) ) {
					$match            = 'new_global';
					$id               = 'attribute:' . sanitize_title( $name );
					$new_items[ $id ] = array(
						'id'    => $id,
						/* translators: %s: attribute name. */
						'label' => sprintf( __( 'New global attribute: %s', 'product-sheet-ai-for-woocommerce' ), $name ),
					);
				}
			}

			$rows[] = array(
				'name'                => $name,
				'taxonomy'            => $taxonomy,
				'match'               => $match,
				'values'              => $row,
				'used_for_variations' => ! empty( $attribute['used_for_variations'] ),
			);
		}
		return $rows;
	}

	/**
	 * Preview rows of the variations.
	 *
	 * @param array<string,mixed> $data Parsed data.
	 * @return list<array{label:string,regular_price:string,sale_price:string,sku:string}>
	 */
	private static function variation_rows( array $data ): array {
		$fallback = self::scalar_string( $data['regular_price'] ?? null );
		$rows     = array();
		foreach ( self::list_of_arrays( $data['variations'] ?? array() ) as $variation ) {
			$regular = self::scalar_string( $variation['regular_price'] ?? null );
			$rows[]  = array(
				'label'         => self::variation_label( $variation['attributes'] ?? array() ),
				'regular_price' => '' === $regular ? $fallback : $regular,
				'sale_price'    => self::scalar_string( $variation['sale_price'] ?? null ),
				'sku'           => self::scalar_string( $variation['sku'] ?? null ),
			);
		}
		return $rows;
	}

	/**
	 * Add categories that do not exist yet to the new items.
	 *
	 * @param array<string,mixed>                         $data      Parsed data.
	 * @param array<string,array{id:string,label:string}> $new_items New items collector.
	 */
	private static function add_category_items( array $data, array &$new_items ): void {
		$seen = array();
		foreach ( self::string_list( $data['categories'] ?? array() ) as $name ) {
			$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			if ( null === Applier::find_category( $name ) ) {
				$id               = 'category:' . $name;
				$new_items[ $id ] = array(
					'id'    => $id,
					/* translators: %s: category name. */
					'label' => sprintf( __( 'New category: %s', 'product-sheet-ai-for-woocommerce' ), $name ),
				);
			}
		}
	}

	/**
	 * Proposed display strings for every field key.
	 *
	 * @param array<string,mixed>       $data       Parsed data.
	 * @param list<array<string,mixed>> $attributes Attribute rows.
	 * @param list<array<string,mixed>> $variations Variation rows.
	 * @param string                    $unit_w     Weight unit.
	 * @param string                    $unit_d     Dimension unit.
	 * @return array<string,string>
	 */
	private static function proposed_values( array $data, array $attributes, array $variations, string $unit_w, string $unit_d ): array {
		$weight     = self::scalar_string( $data['weight'] ?? null );
		$dimensions = isset( $data['dimensions'] ) && is_array( $data['dimensions'] ) ? $data['dimensions'] : array();

		$alts = array();
		foreach ( self::list_of_arrays( $data['image_alts'] ?? array() ) as $item ) {
			if ( isset( $item['image_id'], $item['alt'] ) && is_numeric( $item['image_id'] ) && is_string( $item['alt'] ) && '' !== trim( $item['alt'] ) ) {
				$alts[] = '#' . (int) $item['image_id'] . ': ' . trim( $item['alt'] );
			}
		}

		return array(
			'name'              => self::scalar_string( $data['name'] ?? null ),
			'short_description' => self::to_plain( self::scalar_string( $data['short_description'] ?? null ) ),
			'description'       => self::to_plain( self::scalar_string( $data['description'] ?? null ) ),
			'regular_price'     => self::scalar_string( $data['regular_price'] ?? null ),
			'sale_price'        => self::scalar_string( $data['sale_price'] ?? null ),
			'sku'               => self::scalar_string( $data['sku'] ?? null ),
			'weight'            => '' === $weight ? '' : trim( $weight . ' ' . $unit_w ),
			'dimensions'        => self::format_dimensions( $dimensions, $unit_d ),
			'categories'        => implode( ', ', self::string_list( $data['categories'] ?? array() ) ),
			'tags'              => implode( ', ', self::string_list( $data['tags'] ?? array() ) ),
			'attributes'        => self::attributes_summary( self::proposed_type( $data ), $attributes, count( $variations ) ),
			'seo_title'         => self::scalar_string( $data['seo_title'] ?? null ),
			'seo_description'   => self::scalar_string( $data['seo_description'] ?? null ),
			'image_alts'        => implode( '; ', $alts ),
		);
	}

	/**
	 * Current display strings of an existing product.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $unit_w  Weight unit.
	 * @param string      $unit_d  Dimension unit.
	 * @return array<string,string>
	 */
	private static function current_values( \WC_Product $product, string $unit_w, string $unit_d ): array {
		$keys     = Seo::meta_keys();
		$weight   = $product->get_weight();
		$cat_ids  = array_map( 'intval', $product->get_category_ids() );
		$tag_ids  = array_map( 'intval', $product->get_tag_ids() );
		$rows     = array();
		$alts     = array();
		$image_id = (int) $product->get_image_id();
		$images   = array_map( 'intval', $product->get_gallery_image_ids() );
		if ( $image_id > 0 ) {
			array_unshift( $images, $image_id );
		}
		foreach ( array_unique( $images ) as $attachment_id ) {
			$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			if ( is_string( $alt ) && '' !== trim( $alt ) ) {
				$alts[] = '#' . $attachment_id . ': ' . trim( $alt );
			}
		}

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof \WC_Product_Attribute ) {
				continue;
			}
			$values = array();
			if ( $attribute->is_taxonomy() ) {
				foreach ( $attribute->get_terms() ?? array() as $term ) {
					$values[] = $term->name;
				}
			} else {
				$values = array_map( 'strval', $attribute->get_options() );
			}
			$rows[] = array(
				'name'   => wc_attribute_label( $attribute->get_name() ),
				'values' => array_map(
					static fn( string $value ): array => array( 'value' => $value ),
					$values
				),
			);
		}
		$variation_count = $product instanceof \WC_Product_Variable ? count( $product->get_children() ) : 0;

		return array(
			'name'              => $product->get_name(),
			'short_description' => self::to_plain( $product->get_short_description() ),
			'description'       => self::to_plain( $product->get_description() ),
			'regular_price'     => $product instanceof \WC_Product_Variable ? '' : $product->get_regular_price(),
			'sale_price'        => $product instanceof \WC_Product_Variable ? '' : $product->get_sale_price(),
			'sku'               => $product->get_sku(),
			'weight'            => '' === (string) $weight ? '' : trim( $weight . ' ' . $unit_w ),
			'dimensions'        => self::format_dimensions(
				array(
					'length' => $product->get_length(),
					'width'  => $product->get_width(),
					'height' => $product->get_height(),
				),
				$unit_d
			),
			'categories'        => implode( ', ', self::term_names( $cat_ids, 'product_cat' ) ),
			'tags'              => implode( ', ', self::term_names( $tag_ids, 'product_tag' ) ),
			'attributes'        => self::attributes_summary( $product->get_type(), $rows, $variation_count ),
			'seo_title'         => self::meta_text( $product, $keys['title'] ),
			'seo_description'   => self::meta_text( $product, $keys['description'] ),
			'image_alts'        => implode( '; ', $alts ),
		);
	}

	/**
	 * One-line summary of the type, attributes and variation count.
	 *
	 * @param string                    $type            Product type.
	 * @param list<array<string,mixed>> $attributes      Rows with name and values (each value is an array with a 'value' key).
	 * @param int                       $variation_count Number of variations.
	 */
	private static function attributes_summary( string $type, array $attributes, int $variation_count ): string {
		$parts = array();
		foreach ( $attributes as $attribute ) {
			$values = array();
			foreach ( isset( $attribute['values'] ) && is_array( $attribute['values'] ) ? $attribute['values'] : array() as $value ) {
				if ( is_array( $value ) && isset( $value['value'] ) && is_string( $value['value'] ) ) {
					$values[] = $value['value'];
				}
			}
			$name    = isset( $attribute['name'] ) && is_string( $attribute['name'] ) ? $attribute['name'] : '';
			$parts[] = $name . ': ' . implode( ', ', $values );
		}
		$label   = 'variable' === $type ? __( 'Variable product', 'product-sheet-ai-for-woocommerce' ) : __( 'Simple product', 'product-sheet-ai-for-woocommerce' );
		$summary = $label;
		if ( array() !== $parts ) {
			$summary .= '. ' . implode( '; ', $parts );
		}
		if ( $variation_count > 0 ) {
			/* translators: %d: number of variations. */
			$summary .= '. ' . sprintf( _n( '%d variation', '%d variations', $variation_count, 'product-sheet-ai-for-woocommerce' ), $variation_count );
		}
		return $summary;
	}

	/**
	 * Whether the proposed SKU belongs to another product.
	 *
	 * @param array<string,mixed> $data       Parsed data.
	 * @param int                 $product_id Product being edited.
	 */
	private static function sku_conflict( array $data, int $product_id ): bool {
		$sku = self::scalar_string( $data['sku'] ?? null );
		if ( '' === $sku ) {
			return false;
		}
		$owner = (int) wc_get_product_id_by_sku( $sku );
		return $owner > 0 && $owner !== $product_id;
	}

	/**
	 * Names of terms by id.
	 *
	 * @param array<int> $ids      Term ids.
	 * @param string     $taxonomy Taxonomy.
	 * @return list<string>
	 */
	private static function term_names( array $ids, string $taxonomy ): array {
		$names = array();
		foreach ( $ids as $id ) {
			$term = get_term( $id, $taxonomy );
			if ( $term instanceof \WP_Term ) {
				$names[] = $term->name;
			}
		}
		return $names;
	}

	/**
	 * Plain text of a product meta value.
	 *
	 * @param \WC_Product $product  Product.
	 * @param string      $meta_key Meta key.
	 */
	private static function meta_text( \WC_Product $product, string $meta_key ): string {
		$value = $product->get_meta( $meta_key, true );
		return is_string( $value ) ? trim( wp_strip_all_tags( $value ) ) : '';
	}

	/**
	 * A scalar as trimmed string, anything else as empty string.
	 *
	 * @param mixed $value Value.
	 */
	private static function scalar_string( $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Non-empty trimmed strings of a list.
	 *
	 * @param mixed $value List.
	 * @return list<string>
	 */
	private static function string_list( $value ): array {
		$out = array();
		foreach ( is_array( $value ) ? $value : array() as $item ) {
			if ( is_string( $item ) && '' !== trim( $item ) ) {
				$out[] = trim( $item );
			}
		}
		return $out;
	}

	/**
	 * Array items of a list.
	 *
	 * @param mixed $value List.
	 * @return list<array<string,mixed>>
	 */
	private static function list_of_arrays( $value ): array {
		$out = array();
		foreach ( is_array( $value ) ? $value : array() as $item ) {
			if ( is_array( $item ) ) {
				$out[] = $item;
			}
		}
		return $out;
	}
}
