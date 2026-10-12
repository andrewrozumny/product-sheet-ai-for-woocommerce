<?php
/**
 * PHPUnit bootstrap.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
define( 'PRODUCT_SHEET_AI_VERSION', '0.1.0' );
define( 'PRODUCT_SHEET_AI_FILE', ABSPATH . 'product-sheet-ai-for-woocommerce.php' );
define( 'PRODUCT_SHEET_AI_PATH', ABSPATH );
define( 'PRODUCT_SHEET_AI_URL', 'https://example.test/wp-content/plugins/product-sheet-ai-for-woocommerce/' );

require_once ABSPATH . 'vendor/autoload.php';
require_once ABSPATH . 'includes/autoload.php';
require_once __DIR__ . '/stubs/WP_Error.php';
