<?php
/**
 * Prints the plugin's own SEO title and description on single product pages.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outputs SEO meta only when no dedicated SEO plugin (Yoast SEO, Rank Math) is active.
 */
final class Seo {

	public const META_TITLE = '_product_sheet_ai_seo_title';

	public const META_DESCRIPTION = '_product_sheet_ai_seo_description';

	/**
	 * Hook the frontend output.
	 */
	public function register(): void {
		add_action( 'wp_head', array( $this, 'print_description' ), 1 );
		add_filter( 'pre_get_document_title', array( $this, 'filter_title' ), 20 );
	}

	/**
	 * Whether Yoast SEO is active.
	 */
	public static function yoast_active(): bool {
		return defined( 'WPSEO_VERSION' );
	}

	/**
	 * Whether Rank Math is active.
	 */
	public static function rank_math_active(): bool {
		return class_exists( 'RankMath', false );
	}

	/**
	 * Whether this plugin prints its own SEO meta (no SEO plugin is active).
	 */
	public static function uses_own_meta(): bool {
		return ! self::yoast_active() && ! self::rank_math_active();
	}

	/**
	 * Meta keys where the SEO title and description of a product are stored.
	 *
	 * @return array{title:string,description:string}
	 */
	public static function meta_keys(): array {
		if ( self::yoast_active() ) {
			return array(
				'title'       => '_yoast_wpseo_title',
				'description' => '_yoast_wpseo_metadesc',
			);
		}
		if ( self::rank_math_active() ) {
			return array(
				'title'       => 'rank_math_title',
				'description' => 'rank_math_description',
			);
		}
		return array(
			'title'       => self::META_TITLE,
			'description' => self::META_DESCRIPTION,
		);
	}

	/**
	 * Print the meta description of the current product.
	 */
	public function print_description(): void {
		$description = $this->current_value( self::META_DESCRIPTION );
		if ( '' === $description ) {
			return;
		}
		printf( "<meta name=\"description\" content=\"%s\" />\n", esc_attr( $description ) );
	}

	/**
	 * Replace the document title of the current product.
	 *
	 * @param mixed $title Title decided so far (empty string by default).
	 * @return mixed
	 */
	public function filter_title( $title ) {
		$custom = $this->current_value( self::META_TITLE );
		return '' === $custom ? $title : $custom;
	}

	/**
	 * Read a stored value for the current single product, only when this plugin owns the output.
	 *
	 * @param string $meta_key Meta key.
	 */
	private function current_value( string $meta_key ): string {
		if ( ! self::uses_own_meta() || ! function_exists( 'is_product' ) || ! is_product() ) {
			return '';
		}
		$product_id = (int) get_queried_object_id();
		if ( $product_id <= 0 ) {
			return '';
		}
		$value = get_post_meta( $product_id, $meta_key, true );
		return is_string( $value ) ? trim( wp_strip_all_tags( $value ) ) : '';
	}
}
