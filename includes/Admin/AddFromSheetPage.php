<?php
/**
 * Products > Add from sheet.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders the page that creates a draft product from a sheet.
 */
final class AddFromSheetPage {

	public const SLUG = 'product-sheet-ai-add';

	public const URL = 'edit.php?post_type=product&page=product-sheet-ai-add';

	/**
	 * Page hook suffix returned by add_submenu_page().
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Add the submenu under Products.
	 */
	public function add_menu(): void {
		$hook = add_submenu_page(
			'edit.php?post_type=product',
			__( 'Add product from sheet', 'product-sheet-ai-for-woocommerce' ),
			__( 'Add from sheet', 'product-sheet-ai-for-woocommerce' ),
			'edit_products',
			self::SLUG,
			array( $this, 'render' )
		);
		if ( is_string( $hook ) ) {
			$this->hook_suffix = $hook;
		}
	}

	/**
	 * Enqueue assets, only on this page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( string $hook ): void {
		if ( '' === $this->hook_suffix || $hook !== $this->hook_suffix ) {
			return;
		}
		Assets::enqueue();
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'edit_products' ) ) {
			return;
		}
		echo '<div class="wrap product-sheet-ai">';
		echo '<h1>' . esc_html__( 'Add product from sheet', 'product-sheet-ai-for-woocommerce' ) . '</h1>';
		echo '<p>' . esc_html__( 'Paste a supplier sheet or upload a file. You review a preview first, then a new draft product is created. Nothing is published.', 'product-sheet-ai-for-woocommerce' ) . '</p>';
		Assets::render_panel( 0, 'page' );
		echo '</div>';
	}
}
