<?php
/**
 * Removes plugin data on uninstall.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'product_sheet_ai_settings' );
delete_option( 'product_sheet_ai_log' );
