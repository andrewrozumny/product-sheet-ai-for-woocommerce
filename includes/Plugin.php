<?php
/**
 * Plugin bootstrap.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Get the instance.
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor.
	 */
	private function __construct() {
	}

	/**
	 * Whether the Pro layer is active. Free build always returns false unless filtered.
	 */
	public static function is_pro(): bool {
		return (bool) apply_filters( 'product_sheet_ai_is_pro', false );
	}

	/**
	 * Hook everything up.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		if ( ! $this->woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'missing_woocommerce_notice' ) );
			return;
		}

		( new Admin\SettingsPage() )->register();

		// Later phases register here: Admin\MetaBox, Admin\AddFromSheetPage, Ajax, Frontend\Seo, RequestLog.
	}

	/**
	 * Whether WooCommerce is loaded or active.
	 */
	private function woocommerce_active(): bool {
		if ( class_exists( 'WooCommerce' ) ) {
			return true;
		}
		$active = (array) get_option( 'active_plugins', array() );
		return in_array( 'woocommerce/woocommerce.php', $active, true );
	}

	/**
	 * Admin notice when WooCommerce is missing.
	 */
	public function missing_woocommerce_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p>%s</p></div>',
			esc_html__( 'Product Sheet AI for WooCommerce needs WooCommerce to be installed and active.', 'product-sheet-ai-for-woocommerce' )
		);
	}
}
