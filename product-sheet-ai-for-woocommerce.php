<?php
/**
 * Plugin Name:       Product Sheet AI for WooCommerce
 * Plugin URI:        https://helpmybiz.org
 * Description:       Turn a supplier sheet (pasted text, CSV or PDF) into a complete WooCommerce product draft with AI. You review a preview, then choose what gets saved.
 * Version:           0.1.0
 * Requires at least: 6.6
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * WC requires at least: 9.0
 * WC tested up to:   11.2
 * Author:            Andrew Rozumny
 * Author URI:        https://helpmybiz.org
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       product-sheet-ai-for-woocommerce
 * Domain Path:       /languages
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PRODUCT_SHEET_AI_VERSION', '0.1.0' );
define( 'PRODUCT_SHEET_AI_FILE', __FILE__ );
define( 'PRODUCT_SHEET_AI_PATH', plugin_dir_path( __FILE__ ) );
define( 'PRODUCT_SHEET_AI_URL', plugin_dir_url( __FILE__ ) );

require_once PRODUCT_SHEET_AI_PATH . 'includes/autoload.php';

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

\ProductSheetAI\Plugin::instance()->boot();
