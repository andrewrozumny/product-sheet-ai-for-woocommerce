<?php
/**
 * Collects store context and builds the prompt.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds array{system:string,user:string} for a sheet.
 */
final class PromptBuilder {

	public const MAX_TERMS = 50;

	public const MAX_CATEGORIES = 200;

	/**
	 * Tone presets sent to the model.
	 */
	private const TONES = array(
		'neutral'  => 'Tone: plain, factual and clear.',
		'friendly' => 'Tone: warm and approachable, still precise.',
		'premium'  => 'Tone: refined, confident and understated, without hype.',
	);

	/**
	 * Build the prompt.
	 *
	 * @param string              $sheet      Sheet text.
	 * @param int                 $product_id Product being edited, 0 for a new product.
	 * @param array<string,mixed> $settings   Plugin settings (Settings::get()).
	 * @return array{system:string,user:string}
	 */
	public static function build( string $sheet, int $product_id, array $settings ): array {
		$context = self::context( $sheet, $product_id, $settings );

		$builder = require PRODUCT_SHEET_AI_PATH . 'includes/Prompts/product-sheet.php';
		/**
		 * Prompt builder function.
		 *
		 * @var callable(array<string,mixed>):array{system:string,user:string} $builder
		 */
		$prompt = $builder( $context );

		/**
		 * Filters the prompt before it is sent to the provider.
		 *
		 * @param array{system:string,user:string} $prompt  System and user text.
		 * @param array<string,mixed>              $context Store context used to build it.
		 */
		$filtered = apply_filters( 'product_sheet_ai_prompt', $prompt, $context );
		if ( is_array( $filtered ) && isset( $filtered['system'], $filtered['user'] ) && is_string( $filtered['system'] ) && is_string( $filtered['user'] ) ) {
			return array(
				'system' => $filtered['system'],
				'user'   => $filtered['user'],
			);
		}
		return $prompt;
	}

	/**
	 * Store context for the prompt.
	 *
	 * @param string              $sheet      Sheet text.
	 * @param int                 $product_id Product id or 0.
	 * @param array<string,mixed> $settings   Plugin settings.
	 * @return array<string,mixed>
	 */
	public static function context( string $sheet, int $product_id, array $settings ): array {
		$tone     = isset( $settings['tone'] ) && is_string( $settings['tone'] ) && isset( self::TONES[ $settings['tone'] ] ) ? $settings['tone'] : 'neutral';
		$language = isset( $settings['store_language'] ) && is_string( $settings['store_language'] ) && '' !== $settings['store_language'] ? $settings['store_language'] : 'English';

		$context = array(
			'store_language' => $language,
			'tone'           => $tone,
			'tone_text'      => self::TONES[ $tone ],
			'currency'       => (string) get_woocommerce_currency(),
			'weight_unit'    => (string) get_option( 'woocommerce_weight_unit', 'kg' ),
			'dimension_unit' => (string) get_option( 'woocommerce_dimension_unit', 'cm' ),
			'attributes'     => self::global_attributes(),
			'categories'     => self::category_names(),
			'product'        => array(),
			'images'         => array(),
			'product_id'     => $product_id,
			'sheet'          => self::wrap_safe( $sheet ),
		);

		if ( $product_id > 0 ) {
			$product = wc_get_product( $product_id );
			if ( $product instanceof \WC_Product ) {
				$context['product'] = self::current_values( $product );
				$context['images']  = self::images( $product );
			}
		}
		return $context;
	}

	/**
	 * Stop the sheet from closing the data tag early.
	 *
	 * @param string $sheet Sheet text.
	 */
	private static function wrap_safe( string $sheet ): string {
		return (string) preg_replace( '/<(\/?)\s*product_sheet/i', '&lt;$1product_sheet', $sheet );
	}

	/**
	 * Global attributes with up to MAX_TERMS terms each.
	 *
	 * @return list<array{name:string,slug:string,terms:list<string>}>
	 */
	private static function global_attributes(): array {
		$out = array();
		foreach ( (array) wc_get_attribute_taxonomies() as $attribute ) {
			if ( ! $attribute instanceof \stdClass ) {
				continue;
			}
			$slug  = (string) $attribute->attribute_name;
			$terms = get_terms(
				array(
					'taxonomy'   => wc_attribute_taxonomy_name( $slug ),
					'hide_empty' => false,
					'number'     => self::MAX_TERMS,
					'fields'     => 'names',
				)
			);
			$out[] = array(
				'name'  => (string) $attribute->attribute_label,
				'slug'  => $slug,
				'terms' => is_array( $terms ) ? array_values( array_map( 'strval', $terms ) ) : array(),
			);
		}
		return $out;
	}

	/**
	 * Product category names.
	 *
	 * @return list<string>
	 */
	private static function category_names(): array {
		$names = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => self::MAX_CATEGORIES,
				'fields'     => 'names',
			)
		);
		return is_array( $names ) ? array_values( array_map( 'strval', $names ) ) : array();
	}

	/**
	 * Current values of an existing product, as short text.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<string,string|list<string>>
	 */
	private static function current_values( \WC_Product $product ): array {
		$category_names = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
		$tag_names      = wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) );
		return array(
			'name'              => $product->get_name(),
			'short_description' => self::excerpt( $product->get_short_description() ),
			'description'       => self::excerpt( $product->get_description() ),
			'regular_price'     => (string) $product->get_regular_price(),
			'sale_price'        => (string) $product->get_sale_price(),
			'sku'               => $product->get_sku(),
			'weight'            => (string) $product->get_weight(),
			'categories'        => is_array( $category_names ) ? array_values( array_map( 'strval', $category_names ) ) : array(),
			'tags'              => is_array( $tag_names ) ? array_values( array_map( 'strval', $tag_names ) ) : array(),
		);
	}

	/**
	 * Plain text excerpt of stored HTML, limited in length.
	 *
	 * @param string $html Stored HTML.
	 */
	private static function excerpt( string $html ): string {
		$text = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( $html ) ) );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 1500 ) : substr( $text, 0, 1500 );
	}

	/**
	 * Featured and gallery images with file names and titles.
	 *
	 * @param \WC_Product $product Product.
	 * @return list<array{id:int,file:string,title:string}>
	 */
	private static function images( \WC_Product $product ): array {
		$ids = array_merge( array( $product->get_image_id() ), $product->get_gallery_image_ids() );
		$out = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 || isset( $out[ $id ] ) ) {
				continue;
			}
			$file       = get_attached_file( $id );
			$out[ $id ] = array(
				'id'    => $id,
				'file'  => is_string( $file ) ? basename( $file ) : '',
				'title' => (string) get_the_title( $id ),
			);
		}
		return array_values( $out );
	}
}
