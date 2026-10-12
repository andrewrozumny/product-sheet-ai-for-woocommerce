<?php
/**
 * Product data schema: field keys and labels. The JSON schema of the model output is added next to these.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes the product fields the plugin can fill.
 */
final class Schema {

	/**
	 * Field keys used by the preview, the overwrite setting and the apply step.
	 */
	public const FIELD_KEYS = array(
		'name',
		'short_description',
		'description',
		'regular_price',
		'sale_price',
		'sku',
		'weight',
		'dimensions',
		'categories',
		'tags',
		'attributes',
		'seo_title',
		'seo_description',
		'image_alts',
	);

	/**
	 * Translated, human readable label for every field key.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			'name'              => __( 'Product name', 'product-sheet-ai-for-woocommerce' ),
			'short_description' => __( 'Short description', 'product-sheet-ai-for-woocommerce' ),
			'description'       => __( 'Description', 'product-sheet-ai-for-woocommerce' ),
			'regular_price'     => __( 'Regular price', 'product-sheet-ai-for-woocommerce' ),
			'sale_price'        => __( 'Sale price', 'product-sheet-ai-for-woocommerce' ),
			'sku'               => __( 'SKU', 'product-sheet-ai-for-woocommerce' ),
			'weight'            => __( 'Weight', 'product-sheet-ai-for-woocommerce' ),
			'dimensions'        => __( 'Dimensions', 'product-sheet-ai-for-woocommerce' ),
			'categories'        => __( 'Categories', 'product-sheet-ai-for-woocommerce' ),
			'tags'              => __( 'Tags', 'product-sheet-ai-for-woocommerce' ),
			'attributes'        => __( 'Attributes, product type and variations', 'product-sheet-ai-for-woocommerce' ),
			'seo_title'         => __( 'SEO title', 'product-sheet-ai-for-woocommerce' ),
			'seo_description'   => __( 'SEO description', 'product-sheet-ai-for-woocommerce' ),
			'image_alts'        => __( 'Image alt texts', 'product-sheet-ai-for-woocommerce' ),
		);
	}
}
