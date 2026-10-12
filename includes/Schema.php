<?php
/**
 * Product data schema: field keys, labels and the JSON schema of the model output.
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

	/**
	 * JSON schema of the model output, valid for Anthropic structured outputs and OpenAI strict mode.
	 *
	 * Every object has additionalProperties false and lists all of its properties as required.
	 * Optional values are expressed as anyOf string or null.
	 *
	 * @return array<string,mixed>
	 */
	public static function json_schema(): array {
		return self::object_of(
			array(
				'name'              => self::text( 'Product title as a shopper sees it. Plain text, no HTML, no SKU.' ),
				'short_description' => self::text( 'One or two sentences or a short bullet list. Simple HTML only: p, ul, li, strong, em, br.' ),
				'description'       => self::text( 'Full description. Simple HTML only: p, ul, li, strong, em, br, h3, h4, table, tr, th, td. Use only facts from the sheet.' ),
				'product_type'      => array(
					'type'        => 'string',
					'enum'        => array( 'simple', 'variable' ),
					'description' => 'Use variable only when the sheet lists real purchasable options such as sizes or colors.',
				),
				'regular_price'     => self::nullable_text( 'Regular price as a decimal string with a dot, no currency symbol, no thousands separator, for example 1299.00. Null when the sheet has no price.' ),
				'sale_price'        => self::nullable_text( 'Sale price in the same format as regular_price. Null when there is no sale.' ),
				'sku'               => self::nullable_text( 'SKU or article number exactly as written in the sheet. Null when absent.' ),
				'weight'            => self::nullable_text( 'Weight as a decimal string in the store weight unit, no unit text. Null when absent.' ),
				'dimensions'        => self::object_of(
					array(
						'length' => self::nullable_text( 'Length as a decimal string in the store dimension unit, no unit text.' ),
						'width'  => self::nullable_text( 'Width as a decimal string in the store dimension unit, no unit text.' ),
						'height' => self::nullable_text( 'Height as a decimal string in the store dimension unit, no unit text.' ),
					)
				),
				'categories'        => self::list_of( self::text( 'Category name.' ), 'Product categories. Reuse existing store category names when they mean the same.' ),
				'tags'              => self::list_of( self::text( 'Tag.' ), 'Short product tags.' ),
				'attributes'        => self::list_of(
					self::object_of(
						array(
							'name'                => self::text( 'Attribute name, for example color. Reuse the store attribute name when it means the same.' ),
							'values'              => self::list_of( self::text( 'Attribute value. Reuse the store value spelling when it means the same.' ), 'All values of this attribute offered or described in the sheet.' ),
							'used_for_variations' => array(
								'type'        => 'boolean',
								'description' => 'True when the customer chooses this attribute and it creates variations.',
							),
							'visible'             => array(
								'type'        => 'boolean',
								'description' => 'True when the attribute is shown on the product page.',
							),
						)
					),
					'Product attributes. Include specification attributes with used_for_variations false.'
				),
				'variations'        => self::list_of(
					self::object_of(
						array(
							'attributes'    => self::list_of(
								self::object_of(
									array(
										'name'  => self::text( 'Attribute name, same as in attributes.' ),
										'value' => self::text( 'One value of that attribute.' ),
									)
								),
								'One entry per variation attribute.'
							),
							'regular_price' => self::nullable_text( 'Price of this variation, decimal string with a dot. Null when it is the same as the product price.' ),
							'sale_price'    => self::nullable_text( 'Sale price of this variation. Null when there is none.' ),
							'sku'           => self::nullable_text( 'SKU of this variation. Null when absent.' ),
						)
					),
					'One variation per combination that the sheet really offers. Empty for simple products.'
				),
				'seo_title'         => self::text( 'SEO title, about 60 characters, plain text.' ),
				'seo_description'   => self::text( 'SEO meta description, about 155 characters, plain text.' ),
				'image_alts'        => self::list_of(
					self::object_of(
						array(
							'image_id' => array(
								'type'        => 'integer',
								'description' => 'Attachment id from the image list given in the request.',
							),
							'alt'      => self::text( 'Short factual alt text for the image.' ),
						)
					),
					'Alt texts, only for the image ids listed in the request. Empty when no images are listed.'
				),
				'warnings'          => self::list_of( self::text( 'One short warning.' ), 'Ambiguities and things the sheet did not say, written for the shop owner.' ),
			)
		);
	}

	/**
	 * Object schema with all properties required and no extra keys.
	 *
	 * @param array<string,array<string,mixed>> $properties Property schemas.
	 * @return array<string,mixed>
	 */
	private static function object_of( array $properties ): array {
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => array_keys( $properties ),
			'additionalProperties' => false,
		);
	}

	/**
	 * String schema.
	 *
	 * @param string $description Hint for the model.
	 * @return array<string,mixed>
	 */
	private static function text( string $description ): array {
		return array(
			'type'        => 'string',
			'description' => $description,
		);
	}

	/**
	 * String or null schema.
	 *
	 * @param string $description Hint for the model.
	 * @return array<string,mixed>
	 */
	private static function nullable_text( string $description ): array {
		return array(
			'anyOf'       => array(
				array( 'type' => 'string' ),
				array( 'type' => 'null' ),
			),
			'description' => $description,
		);
	}

	/**
	 * Array schema.
	 *
	 * @param array<string,mixed> $items       Item schema.
	 * @param string              $description Hint for the model.
	 * @return array<string,mixed>
	 */
	private static function list_of( array $items, string $description = '' ): array {
		$schema = array(
			'type'  => 'array',
			'items' => $items,
		);
		if ( '' !== $description ) {
			$schema['description'] = $description;
		}
		return $schema;
	}
}
