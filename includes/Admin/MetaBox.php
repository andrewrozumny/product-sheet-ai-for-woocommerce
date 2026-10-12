<?php
/**
 * "Product Sheet AI" meta box on the product edit screen.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the meta box.
 */
final class MetaBox {

	public const ID = 'product_sheet_ai';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the box to the product screen.
	 */
	public function add(): void {
		add_meta_box(
			self::ID,
			__( 'Product Sheet AI', 'product-sheet-ai-for-woocommerce' ),
			array( $this, 'render' ),
			'product',
			'normal',
			'high'
		);
	}

	/**
	 * Enqueue assets on the product edit screen only.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( string $hook ): void {
		if ( 'post.php' !== $hook && 'post-new.php' !== $hook ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || 'product' !== $screen->post_type ) {
			return;
		}
		Assets::enqueue();
	}

	/**
	 * Render the box.
	 *
	 * @param \WP_Post $post Current product post.
	 */
	public function render( $post ): void {
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Save the product first (Save draft), then come back here to fill it from a product sheet. To create a new product straight from a sheet, use Products > Add from sheet.', 'product-sheet-ai-for-woocommerce' ) . '</p>';
			printf(
				'<p><a class="button" href="%1$s">%2$s</a></p>',
				esc_url( admin_url( AddFromSheetPage::URL ) ),
				esc_html__( 'Add from sheet', 'product-sheet-ai-for-woocommerce' )
			);
			return;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}
		Assets::render_panel( (int) $post->ID, 'metabox' );
	}
}
