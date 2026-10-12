<?php
/**
 * Writes the confirmed preview into WooCommerce, using the CRUD classes only.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Mapping;

use ProductSheetAI\Frontend\Seo;
use ProductSheetAI\Generator;
use ProductSheetAI\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies the stored result of a generation to a new or an existing product.
 *
 * Throws ApplyException with a translated, user-facing message on every failure.
 */
final class Applier {

	/**
	 * Parsed product data.
	 *
	 * @var array<string,mixed>
	 */
	private array $data;

	/**
	 * Selected field keys.
	 *
	 * @var array<string,true>
	 */
	private array $fields;

	/**
	 * Confirmed new item ids.
	 *
	 * @var array<string,true>
	 */
	private array $confirm;

	/**
	 * Notes about everything that was skipped or changed on the way.
	 *
	 * @var list<string>
	 */
	private array $notes = array();

	/**
	 * Matcher.
	 *
	 * @var AttributeMatcher
	 */
	private AttributeMatcher $matcher;

	/**
	 * How variations refer to the attributes that were applied.
	 *
	 * @var list<array{keys:list<string>,attr_key:string,values:array<string,string>}>
	 */
	private array $variation_map = array();

	/**
	 * Slug (taxonomy name) of the attribute created in this run.
	 *
	 * @var string
	 */
	private string $created_attribute = '';

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $data    Parsed data.
	 * @param array<string,true>  $fields  Selected fields.
	 * @param array<string,true>  $confirm Confirmed new items.
	 */
	private function __construct( array $data, array $fields, array $confirm ) {
		$this->data    = $data;
		$this->fields  = $fields;
		$this->confirm = $confirm;
		$this->matcher = new AttributeMatcher();
	}

	/**
	 * Apply a stored generation.
	 *
	 * @param string       $token   Preview token from Generator::generate().
	 * @param array<mixed> $fields  Field keys the user kept checked.
	 * @param array<mixed> $confirm Ids of new items the user confirmed (see the preview new_items).
	 * @return array{product_id:int,edit_url:string,created:bool,notes:list<string>}
	 * @throws ApplyException When the token is invalid, the user may not do this, or WooCommerce refuses to save.
	 */
	public static function apply( string $token, array $fields, array $confirm ): array {
		$payload    = self::load( $token );
		$product_id = $payload['product_id'];
		if ( $product_id > 0 ) {
			if ( ! current_user_can( 'edit_product', $product_id ) ) {
				throw new ApplyException( esc_html__( 'You are not allowed to edit this product.', 'product-sheet-ai-for-woocommerce' ) );
			}
		} elseif ( ! current_user_can( 'edit_products' ) ) {
			throw new ApplyException( esc_html__( 'You are not allowed to create products.', 'product-sheet-ai-for-woocommerce' ) );
		}

		$selected = array();
		foreach ( $fields as $key ) {
			if ( is_string( $key ) && in_array( $key, Schema::FIELD_KEYS, true ) ) {
				$selected[ $key ] = true;
			}
		}
		$confirmed = array();
		foreach ( $confirm as $id ) {
			if ( is_string( $id ) && '' !== $id ) {
				$confirmed[ $id ] = true;
			}
		}

		$run    = new self( $payload['data'], $selected, $confirmed );
		$result = $run->execute( $product_id );
		delete_transient( Generator::TRANSIENT_PREFIX . $token );
		return $result;
	}

	/**
	 * Find a product category by name, case-insensitive.
	 *
	 * @param string $name Category name.
	 */
	public static function find_category( string $name ): ?\WP_Term {
		$name = trim( $name );
		if ( '' === $name ) {
			return null;
		}
		$wanted      = self::lower( $name );
		$wanted_slug = sanitize_title( $name );
		$terms       = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			if ( $term instanceof \WP_Term && ( self::lower( $term->name ) === $wanted || $term->slug === $wanted_slug ) ) {
				return $term;
			}
		}
		return null;
	}

	/**
	 * Load and verify the stored generation.
	 *
	 * @param string $token Token.
	 * @return array{product_id:int,data:array<string,mixed>}
	 * @throws ApplyException When missing, expired, or owned by another user.
	 */
	private static function load( string $token ): array {
		$expired = esc_html__( 'This preview has expired. Generate it again.', 'product-sheet-ai-for-woocommerce' );
		if ( 1 !== preg_match( '/^[A-Za-z0-9]{16,64}$/', $token ) ) {
			throw new ApplyException( $expired ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped above.
		}
		$stored = get_transient( Generator::TRANSIENT_PREFIX . $token );
		if ( ! is_array( $stored ) || ! isset( $stored['data'], $stored['user_id'], $stored['product_id'] ) || ! is_array( $stored['data'] ) ) {
			throw new ApplyException( $expired ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped above.
		}
		if ( get_current_user_id() !== (int) $stored['user_id'] || get_current_user_id() <= 0 ) {
			throw new ApplyException( esc_html__( 'This preview belongs to another user.', 'product-sheet-ai-for-woocommerce' ) );
		}
		/**
		 * Product data stored by the generator.
		 *
		 * @var array<string,mixed> $data
		 */
		$data = $stored['data'];
		return array(
			'product_id' => (int) $stored['product_id'],
			'data'       => $data,
		);
	}

	/**
	 * Create or update the product.
	 *
	 * @param int $product_id Product to update, 0 to create.
	 * @return array{product_id:int,edit_url:string,created:bool,notes:list<string>}
	 * @throws ApplyException On failure.
	 */
	private function execute( int $product_id ): array {
		$is_new     = 0 === $product_id;
		$created_id = 0;
		$saved_id   = 0;
		try {
			$product = $this->prepare_product( $product_id );
			$this->apply_simple_fields( $product );
			$this->apply_taxonomies( $product );
			$this->apply_seo( $product );
			$plan = $this->apply_attributes( $product );
			$product->save();
			$saved_id = $product->get_id();
			if ( $is_new ) {
				$created_id = $saved_id;
			}
			if ( $product->is_type( 'variable' ) && $this->type_changed( $product_id, $product ) ) {
				wp_set_object_terms( $saved_id, 'variable', 'product_type' );
			}
			if ( $product->is_type( 'variable' ) && $this->selected( 'attributes' ) ) {
				$this->apply_variations( $saved_id, $plan );
			}
			$this->apply_image_alts( $saved_id );
		} catch ( ApplyException $e ) {
			$this->discard( $created_id );
			throw $e;
		} catch ( \Throwable $e ) {
			$this->discard( $created_id );
			throw new ApplyException(
				esc_html(
					sprintf(
						/* translators: %s: error text from WooCommerce. */
						__( 'WooCommerce could not save the product: %s', 'product-sheet-ai-for-woocommerce' ),
						wp_strip_all_tags( $e->getMessage() )
					)
				)
			);
		}

		$edit_url = get_edit_post_link( $saved_id, 'raw' );
		return array(
			'product_id' => $saved_id,
			'edit_url'   => is_string( $edit_url ) && '' !== $edit_url ? $edit_url : admin_url( 'post.php?post=' . $saved_id . '&action=edit' ),
			'created'    => $is_new,
			'notes'      => $this->notes,
		);
	}

	/**
	 * Remove a product that was created in this run, after a failure.
	 *
	 * @param int $product_id Created product id, 0 if none.
	 */
	private function discard( int $product_id ): void {
		if ( $product_id <= 0 ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( $product instanceof \WC_Product ) {
			$product->delete( true );
		}
	}

	/**
	 * Whether the product type was changed by this run.
	 *
	 * @param int         $original_id Original product id (0 if new).
	 * @param \WC_Product $product     Product object.
	 */
	private function type_changed( int $original_id, \WC_Product $product ): bool {
		if ( 0 === $original_id ) {
			return true;
		}
		$terms = wp_get_object_terms( $original_id, 'product_type', array( 'fields' => 'slugs' ) );
		return is_array( $terms ) && ! in_array( $product->get_type(), $terms, true );
	}

	/**
	 * Whether a field was selected.
	 *
	 * @param string $key Field key.
	 */
	private function selected( string $key ): bool {
		return isset( $this->fields[ $key ] );
	}

	/**
	 * Build the product object: new draft, or the existing product (converted to variable when asked).
	 *
	 * @param int $product_id Product id or 0.
	 * @throws ApplyException When the product cannot be found.
	 */
	private function prepare_product( int $product_id ): \WC_Product {
		$proposed = PreviewBuilder::proposed_type( $this->data );
		if ( 0 === $product_id ) {
			$product = 'variable' === $proposed && $this->selected( 'attributes' ) ? new \WC_Product_Variable() : new \WC_Product_Simple();
			$product->set_status( 'draft' );
			return $product;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			throw new ApplyException( esc_html__( 'The product to update could not be found.', 'product-sheet-ai-for-woocommerce' ) );
		}
		if ( ! $this->selected( 'attributes' ) || $product->get_type() === $proposed ) {
			return $product;
		}
		if ( 'variable' === $proposed && 'simple' === $product->get_type() ) {
			$class_name = \WC_Product_Factory::get_product_classname( $product_id, 'variable' );
			$converted  = new $class_name( $product_id );
			if ( $converted instanceof \WC_Product ) {
				$this->notes[] = __( 'The product was converted from a simple product to a variable product.', 'product-sheet-ai-for-woocommerce' );
				return $converted;
			}
		}
		$this->notes[] = sprintf(
			/* translators: 1: current product type, 2: proposed product type. */
			__( 'The product type stays %1$s (the sheet suggested %2$s).', 'product-sheet-ai-for-woocommerce' ),
			$product->get_type(),
			$proposed
		);
		return $product;
	}

	/**
	 * Names, descriptions, prices, SKU, weight and dimensions.
	 *
	 * @param \WC_Product $product Product.
	 */
	private function apply_simple_fields( \WC_Product $product ): void {
		if ( $this->selected( 'name' ) && '' !== $this->text( 'name' ) ) {
			$product->set_name( $this->text( 'name' ) );
		}
		if ( $this->selected( 'short_description' ) ) {
			$product->set_short_description( wp_kses_post( $this->text( 'short_description' ) ) );
		}
		if ( $this->selected( 'description' ) ) {
			$product->set_description( wp_kses_post( $this->text( 'description' ) ) );
		}

		$this->apply_prices( $product );

		if ( $this->selected( 'sku' ) && '' !== $this->text( 'sku' ) ) {
			$sku   = $this->text( 'sku' );
			$owner = (int) wc_get_product_id_by_sku( $sku );
			if ( $owner > 0 && $owner !== $product->get_id() ) {
				/* translators: %s: SKU. */
				$this->notes[] = sprintf( __( 'The SKU %s is already used by another product, so it was not set.', 'product-sheet-ai-for-woocommerce' ), $sku );
			} else {
				$product->set_sku( $sku );
			}
		}

		if ( $this->selected( 'weight' ) && '' !== $this->text( 'weight' ) ) {
			$weight = wc_format_decimal( $this->text( 'weight' ) );
			if ( is_numeric( $weight ) ) {
				$product->set_weight( $weight );
			} else {
				$this->notes[] = __( 'The weight is not a number, so it was not set.', 'product-sheet-ai-for-woocommerce' );
			}
		}

		if ( $this->selected( 'dimensions' ) && isset( $this->data['dimensions'] ) && is_array( $this->data['dimensions'] ) ) {
			foreach ( array( 'length', 'width', 'height' ) as $axis ) {
				$value = isset( $this->data['dimensions'][ $axis ] ) && is_scalar( $this->data['dimensions'][ $axis ] ) ? wc_format_decimal( (string) $this->data['dimensions'][ $axis ] ) : '';
				if ( ! is_numeric( $value ) ) {
					continue;
				}
				if ( 'length' === $axis ) {
					$product->set_length( $value );
				} elseif ( 'width' === $axis ) {
					$product->set_width( $value );
				} else {
					$product->set_height( $value );
				}
			}
		}
	}

	/**
	 * Prices of a simple product. Variable products get their prices on the variations.
	 *
	 * @param \WC_Product $product Product.
	 */
	private function apply_prices( \WC_Product $product ): void {
		$wants_price = $this->selected( 'regular_price' ) || $this->selected( 'sale_price' );
		if ( ! $wants_price ) {
			return;
		}
		if ( 'variable' === $product->get_type() ) {
			$this->notes[] = __( 'Prices of a variable product are set on its variations, so the product price fields were not used.', 'product-sheet-ai-for-woocommerce' );
			return;
		}
		if ( $this->selected( 'regular_price' ) && '' !== $this->text( 'regular_price' ) ) {
			$product->set_regular_price( wc_format_decimal( $this->text( 'regular_price' ) ) );
		}
		if ( $this->selected( 'sale_price' ) && '' !== $this->text( 'sale_price' ) ) {
			$sale = self::sale_or_null( $product->get_regular_price(), $this->text( 'sale_price' ) );
			if ( null === $sale ) {
				$this->notes[] = __( 'The sale price was dropped because it is not lower than the regular price.', 'product-sheet-ai-for-woocommerce' );
			} else {
				$product->set_sale_price( $sale );
			}
		}
	}

	/**
	 * The sale price when it is a number lower than the regular price, otherwise null.
	 *
	 * @param string $regular Regular price.
	 * @param string $sale    Proposed sale price.
	 */
	public static function sale_or_null( string $regular, string $sale ): ?string {
		$sale    = wc_format_decimal( $sale );
		$regular = wc_format_decimal( $regular );
		if ( ! is_numeric( $sale ) || ! is_numeric( $regular ) || (float) $sale >= (float) $regular ) {
			return null;
		}
		return $sale;
	}

	/**
	 * Categories and tags.
	 *
	 * @param \WC_Product $product Product.
	 */
	private function apply_taxonomies( \WC_Product $product ): void {
		if ( $this->selected( 'categories' ) ) {
			$ids = array();
			foreach ( $this->strings( 'categories' ) as $name ) {
				$term = self::find_category( $name );
				if ( null !== $term ) {
					$ids[] = $term->term_id;
				} elseif ( isset( $this->confirm[ 'category:' . $name ] ) ) {
					$id = $this->insert_term( $name, 'product_cat' );
					if ( $id > 0 ) {
						$ids[] = $id;
					}
				} else {
					/* translators: %s: category name. */
					$this->notes[] = sprintf( __( 'The category %s does not exist and was not confirmed, so it was skipped.', 'product-sheet-ai-for-woocommerce' ), $name );
				}
			}
			if ( array() !== $ids ) {
				$product->set_category_ids( array_values( array_unique( $ids ) ) );
			}
		}

		if ( $this->selected( 'tags' ) ) {
			$ids = array();
			foreach ( $this->strings( 'tags' ) as $name ) {
				$id = $this->insert_term( $name, 'product_tag' );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
			if ( array() !== $ids ) {
				$product->set_tag_ids( array_values( array_unique( $ids ) ) );
			}
		}
	}

	/**
	 * Id of an existing term by name, or a new term. Zero on failure (with a note).
	 *
	 * @param string $name     Term name.
	 * @param string $taxonomy Taxonomy.
	 */
	private function insert_term( string $name, string $taxonomy ): int {
		$existing = get_term_by( 'name', $name, $taxonomy );
		if ( $existing instanceof \WP_Term ) {
			return $existing->term_id;
		}
		$result = wp_insert_term( $name, $taxonomy );
		if ( is_wp_error( $result ) ) {
			$existing_id = $result->get_error_data( 'term_exists' );
			if ( is_numeric( $existing_id ) ) {
				return (int) $existing_id;
			}
			/* translators: %s: term name. */
			$this->notes[] = sprintf( __( 'The term %s could not be created and was skipped.', 'product-sheet-ai-for-woocommerce' ), $name );
			return 0;
		}
		return (int) $result['term_id'];
	}

	/**
	 * SEO title and description into the active SEO plugin's meta, or the plugin's own meta.
	 *
	 * @param \WC_Product $product Product.
	 */
	private function apply_seo( \WC_Product $product ): void {
		$keys = Seo::meta_keys();
		if ( $this->selected( 'seo_title' ) && '' !== $this->text( 'seo_title' ) ) {
			$product->update_meta_data( $keys['title'], sanitize_text_field( $this->text( 'seo_title' ) ) );
		}
		if ( $this->selected( 'seo_description' ) && '' !== $this->text( 'seo_description' ) ) {
			$product->update_meta_data( $keys['description'], sanitize_text_field( $this->text( 'seo_description' ) ) );
		}
	}

	/**
	 * Set the product attributes. Variations are created later through $this->variation_map.
	 *
	 * @param \WC_Product $product Product.
	 * @return bool Whether attributes were applied.
	 */
	private function apply_attributes( \WC_Product $product ): bool {
		if ( ! $this->selected( 'attributes' ) ) {
			return false;
		}
		$is_variable = $product->is_type( 'variable' );
		$used_in_var = $this->attribute_names_used_in_variations();
		$attributes  = $product->get_attributes();
		$position    = count( $attributes );

		foreach ( $this->list_of_arrays( 'attributes' ) as $row ) {
			$name = isset( $row['name'] ) && is_string( $row['name'] ) ? trim( $row['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$values    = self::string_list( $row['values'] ?? array() );
			$resolved  = $this->resolve_attribute( $name, $values );
			$keys      = $this->matcher->keys( $name );
			$variation = $is_variable && ( ! empty( $row['used_for_variations'] ) || array() !== array_intersect( $keys, $used_in_var ) );

			if ( array() === $resolved['options'] ) {
				/* translators: %s: attribute name. */
				$this->notes[] = sprintf( __( 'The attribute %s was skipped because none of its values could be used.', 'product-sheet-ai-for-woocommerce' ), $name );
				continue;
			}

			$attr_key = $resolved['key'];
			$existing = $attributes[ $attr_key ] ?? null;
			if ( $existing instanceof \WC_Product_Attribute ) {
				$attribute = $existing;
				$options   = $attribute->get_options();
				foreach ( $resolved['options'] as $option ) {
					if ( ! in_array( $option, $options, true ) ) {
						$options[] = $option;
					}
				}
				$attribute->set_options( $options );
				$attribute->set_variation( $attribute->get_variation() || $variation );
			} else {
				$attribute = new \WC_Product_Attribute();
				$attribute->set_id( $resolved['id'] );
				$attribute->set_name( $resolved['name'] );
				$attribute->set_options( $resolved['options'] );
				$attribute->set_position( $position++ );
				$attribute->set_visible( ! empty( $row['visible'] ) );
				$attribute->set_variation( $variation );
			}
			$attributes[ $attr_key ] = $attribute;

			$this->variation_map[] = array(
				'keys'     => $keys,
				'attr_key' => $attr_key,
				'values'   => $resolved['values'],
			);
		}
		$product->set_attributes( $attributes );
		return true;
	}

	/**
	 * Normalized names of attributes that variations refer to.
	 *
	 * @return list<string>
	 */
	private function attribute_names_used_in_variations(): array {
		$keys = array();
		foreach ( $this->list_of_arrays( 'variations' ) as $variation ) {
			$pairs = isset( $variation['attributes'] ) && is_array( $variation['attributes'] ) ? $variation['attributes'] : array();
			foreach ( $pairs as $pair ) {
				if ( is_array( $pair ) && isset( $pair['name'] ) && is_string( $pair['name'] ) ) {
					$keys = array_merge( $keys, $this->matcher->keys( $pair['name'] ) );
				}
			}
		}
		return array_values( array_unique( $keys ) );
	}

	/**
	 * Map an attribute from the sheet to a global attribute (existing or confirmed new) or a local one.
	 *
	 * @param string        $name   Attribute name from the sheet.
	 * @param array<string> $values Values from the sheet.
	 * @return array{key:string,id:int,name:string,options:list<int|string>,values:array<string,string>}
	 */
	private function resolve_attribute( string $name, array $values ): array {
		$matched = $this->matcher->match_attribute( $name );
		if ( null === $matched && AttributeMatcher::can_be_global( $name ) && isset( $this->confirm[ 'attribute:' . sanitize_title( $name ) ] ) ) {
			$matched = $this->create_global_attribute( $name );
		}

		if ( null === $matched ) {
			$map = array();
			foreach ( $values as $value ) {
				foreach ( AttributeMatcher::keys_for( $value, array() ) as $key ) {
					$map[ $key ] = $value;
				}
			}
			return array(
				'key'     => sanitize_title( $name ),
				'id'      => 0,
				'name'    => $name,
				'options' => array_values( $values ),
				'values'  => $map,
			);
		}

		$taxonomy = $matched['taxonomy'];
		$options  = array();
		$map      = array();
		foreach ( $values as $value ) {
			$term_id = 0;
			$slug    = '';
			$term    = $this->matcher->match_term( $taxonomy, $value );
			if ( null !== $term ) {
				$term_id = $term['term_id'];
				$slug    = $term['slug'];
			} elseif ( isset( $this->confirm[ 'term:' . $taxonomy . ':' . $value ] ) || $this->created_attribute === $taxonomy ) {
				$term_id = $this->insert_term( $value, $taxonomy );
				$created = $term_id > 0 ? get_term( $term_id, $taxonomy ) : null;
				$slug    = $created instanceof \WP_Term ? $created->slug : '';
				$this->matcher->reset();
			} else {
				$this->notes[] = sprintf(
					/* translators: 1: value, 2: attribute label. */
					__( 'The value %1$s of %2$s does not exist and was not confirmed, so it was skipped.', 'product-sheet-ai-for-woocommerce' ),
					$value,
					$matched['label']
				);
			}
			if ( $term_id > 0 && '' !== $slug ) {
				$options[] = $term_id;
				foreach ( AttributeMatcher::keys_for( $value, array() ) as $key ) {
					$map[ $key ] = $slug;
				}
			}
		}

		return array(
			'key'     => $taxonomy,
			'id'      => (int) wc_attribute_taxonomy_id_by_name( $taxonomy ),
			'name'    => $taxonomy,
			'options' => $options,
			'values'  => $map,
		);
	}

	/**
	 * Create a global attribute and register its taxonomy for this request.
	 *
	 * @param string $name Attribute name from the sheet.
	 * @return array{taxonomy:string,slug:string,label:string}|null Null when it could not be created (the attribute then stays local).
	 */
	private function create_global_attribute( string $name ): ?array {
		$slug = sanitize_title( $name );
		$id   = wc_create_attribute(
			array(
				'name'         => $name,
				'slug'         => $slug,
				'type'         => 'select',
				'order_by'     => 'menu_order',
				'has_archives' => false,
			)
		);
		if ( is_wp_error( $id ) ) {
			/* translators: %s: attribute name. */
			$this->notes[] = sprintf( __( 'The global attribute %s could not be created, so it was added to the product only.', 'product-sheet-ai-for-woocommerce' ), $name );
			return null;
		}

		$taxonomy = wc_attribute_taxonomy_name( $slug );
		if ( ! taxonomy_exists( $taxonomy ) ) {
			register_taxonomy(
				$taxonomy,
				array( 'product' ),
				array(
					'hierarchical' => false,
					'show_ui'      => false,
					'query_var'    => true,
					'rewrite'      => false,
					'public'       => false,
					'labels'       => array( 'name' => $name ),
				)
			);
		}
		$this->matcher->reset();
		$this->created_attribute = $taxonomy;
		return array(
			'taxonomy' => $taxonomy,
			'slug'     => $slug,
			'label'    => $name,
		);
	}

	/**
	 * Create or update the variations of a variable product.
	 *
	 * @param int  $parent_id Parent id.
	 * @param bool $applied   Whether attributes were applied.
	 */
	private function apply_variations( int $parent_id, bool $applied ): void {
		if ( ! $applied ) {
			return;
		}
		$parent = wc_get_product( $parent_id );
		if ( ! $parent instanceof \WC_Product_Variable ) {
			return;
		}

		$known = array();
		foreach ( $parent->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( $child instanceof \WC_Product_Variation ) {
				$known[ self::signature( $child->get_attributes() ) ] = $child_id;
			}
		}

		$fallback = $this->text( 'regular_price' );
		$count    = 0;
		foreach ( $this->list_of_arrays( 'variations' ) as $row ) {
			$label      = PreviewBuilder::variation_label( $row['attributes'] ?? array() );
			$attributes = $this->variation_attributes( $row, $label );
			if ( null === $attributes ) {
				continue;
			}
			$signature = self::signature( $attributes );
			if ( isset( $known[ $signature ] ) ) {
				$variation = wc_get_product( $known[ $signature ] );
			} else {
				$variation = new \WC_Product_Variation();
				$variation->set_parent_id( $parent_id );
				$variation->set_status( 'publish' );
			}
			if ( ! $variation instanceof \WC_Product_Variation ) {
				continue;
			}
			$variation->set_attributes( $attributes );
			$this->fill_variation( $variation, $row, $fallback, $label );
			$variation->save();
			$known[ $signature ] = $variation->get_id();
			++$count;
		}

		if ( 0 === $count ) {
			$this->notes[] = __( 'No variations were created. Add them on the product edit screen.', 'product-sheet-ai-for-woocommerce' );
		}
		\WC_Product_Variable::sync( $parent_id );
		wc_delete_product_transients( $parent_id );
	}

	/**
	 * Attribute map of a variation, or null (with a note) when it cannot be built.
	 *
	 * @param array<string,mixed> $row   Variation row.
	 * @param string              $label Label for notes.
	 * @return array<string,string>|null Keyed by attribute key, values are term slugs or text.
	 */
	private function variation_attributes( array $row, string $label ): ?array {
		$out   = array();
		$pairs = isset( $row['attributes'] ) && is_array( $row['attributes'] ) ? $row['attributes'] : array();
		foreach ( $pairs as $pair ) {
			if ( ! is_array( $pair ) || ! isset( $pair['name'], $pair['value'] ) || ! is_string( $pair['name'] ) || ! is_string( $pair['value'] ) ) {
				continue;
			}
			$wanted = $this->matcher->keys( $pair['name'] );
			$found  = null;
			foreach ( $this->variation_map as $entry ) {
				if ( array() !== array_intersect( $wanted, $entry['keys'] ) ) {
					$found = $entry;
					break;
				}
			}
			$value = null;
			if ( null !== $found ) {
				foreach ( AttributeMatcher::keys_for( $pair['value'], array() ) as $key ) {
					if ( isset( $found['values'][ $key ] ) ) {
						$value = $found['values'][ $key ];
						break;
					}
				}
			}
			if ( null === $found || null === $value ) {
				$this->notes[] = sprintf(
					/* translators: 1: variation label, 2: attribute value. */
					__( 'The variation %1$s was skipped because the value %2$s was not added to the product.', 'product-sheet-ai-for-woocommerce' ),
					$label,
					$pair['value']
				);
				return null;
			}
			$out[ $found['attr_key'] ] = $value;
		}
		if ( array() === $out ) {
			/* translators: %s: variation label. */
			$this->notes[] = sprintf( __( 'A variation without attributes was skipped (%s).', 'product-sheet-ai-for-woocommerce' ), $label );
			return null;
		}
		return $out;
	}

	/**
	 * Prices and SKU of a variation.
	 *
	 * @param \WC_Product_Variation $variation Variation.
	 * @param array<string,mixed>   $row       Variation row.
	 * @param string                $fallback  Product regular price used when the variation has none.
	 * @param string                $label     Label for notes.
	 */
	private function fill_variation( \WC_Product_Variation $variation, array $row, string $fallback, string $label ): void {
		$regular = isset( $row['regular_price'] ) && is_scalar( $row['regular_price'] ) ? trim( (string) $row['regular_price'] ) : '';
		if ( '' === $regular ) {
			$regular = $fallback;
		}
		if ( '' !== $regular ) {
			$variation->set_regular_price( wc_format_decimal( $regular ) );
		} elseif ( '' === $variation->get_regular_price() ) {
			/* translators: %s: variation label. */
			$this->notes[] = sprintf( __( 'The variation %s has no price. Set it on the product edit screen.', 'product-sheet-ai-for-woocommerce' ), $label );
		}

		$sale = isset( $row['sale_price'] ) && is_scalar( $row['sale_price'] ) ? trim( (string) $row['sale_price'] ) : '';
		if ( '' !== $sale ) {
			$valid = self::sale_or_null( $variation->get_regular_price(), $sale );
			if ( null === $valid ) {
				/* translators: %s: variation label. */
				$this->notes[] = sprintf( __( 'The sale price of the variation %s was dropped because it is not lower than the regular price.', 'product-sheet-ai-for-woocommerce' ), $label );
			} else {
				$variation->set_sale_price( $valid );
			}
		}

		$sku = isset( $row['sku'] ) && is_scalar( $row['sku'] ) ? trim( (string) $row['sku'] ) : '';
		if ( '' !== $sku ) {
			$owner = (int) wc_get_product_id_by_sku( $sku );
			if ( $owner > 0 && $owner !== $variation->get_id() ) {
				/* translators: %s: SKU. */
				$this->notes[] = sprintf( __( 'The SKU %s is already used by another product, so it was not set on the variation.', 'product-sheet-ai-for-woocommerce' ), $sku );
			} else {
				$variation->set_sku( $sku );
			}
		}
	}

	/**
	 * Stable text form of a variation's attribute set.
	 *
	 * @param array<mixed> $attributes Attributes keyed by attribute key.
	 */
	private static function signature( array $attributes ): string {
		$clean = array();
		foreach ( $attributes as $key => $value ) {
			$clean[ self::lower( (string) $key ) ] = self::lower( is_scalar( $value ) ? (string) $value : '' );
		}
		ksort( $clean );
		return (string) wp_json_encode( $clean );
	}

	/**
	 * Alt texts, only for the product's own images.
	 *
	 * @param int $product_id Product id.
	 */
	private function apply_image_alts( int $product_id ): void {
		if ( ! $this->selected( 'image_alts' ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$own = array_map( 'intval', $product->get_gallery_image_ids() );
		if ( $product->get_image_id() ) {
			$own[] = (int) $product->get_image_id();
		}
		foreach ( $this->list_of_arrays( 'image_alts' ) as $item ) {
			if ( ! isset( $item['image_id'], $item['alt'] ) || ! is_numeric( $item['image_id'] ) || ! is_string( $item['alt'] ) ) {
				continue;
			}
			$image_id = (int) $item['image_id'];
			if ( ! in_array( $image_id, $own, true ) ) {
				/* translators: %d: image id. */
				$this->notes[] = sprintf( __( 'Image %d does not belong to this product, so its alt text was skipped.', 'product-sheet-ai-for-woocommerce' ), $image_id );
				continue;
			}
			update_post_meta( $image_id, '_wp_attachment_image_alt', sanitize_text_field( $item['alt'] ) );
		}
	}

	/**
	 * A text value of the data.
	 *
	 * @param string $key Key.
	 */
	private function text( string $key ): string {
		return isset( $this->data[ $key ] ) && is_scalar( $this->data[ $key ] ) ? trim( (string) $this->data[ $key ] ) : '';
	}

	/**
	 * A list of strings of the data.
	 *
	 * @param string $key Key.
	 * @return list<string>
	 */
	private function strings( string $key ): array {
		return self::string_list( $this->data[ $key ] ?? array() );
	}

	/**
	 * A list of arrays of the data.
	 *
	 * @param string $key Key.
	 * @return list<array<string,mixed>>
	 */
	private function list_of_arrays( string $key ): array {
		$out = array();
		$raw = $this->data[ $key ] ?? array();
		foreach ( is_array( $raw ) ? $raw : array() as $item ) {
			if ( is_array( $item ) ) {
				$out[] = $item;
			}
		}
		return $out;
	}

	/**
	 * Non-empty trimmed strings.
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
	 * Multibyte aware lower case.
	 *
	 * @param string $text Text.
	 */
	private static function lower( string $text ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
	}
}
