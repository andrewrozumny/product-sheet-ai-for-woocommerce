<?php
/**
 * Shared script, style and panel markup for the product edit screen and the Add from sheet page.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Admin;

use ProductSheetAI\Ajax;
use ProductSheetAI\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueues admin.js and admin.css and prints the shared panel.
 */
final class Assets {

	public const HANDLE = 'product-sheet-ai-admin';

	/**
	 * Browser session storage key that carries the "applied" flag across the reload of the product screen.
	 */
	public const APPLIED_KEY = 'productSheetAiApplied';

	/**
	 * Enqueue script and style, with the inline configuration.
	 */
	public static function enqueue(): void {
		wp_enqueue_style( self::HANDLE, PRODUCT_SHEET_AI_URL . 'resources/admin.css', array(), PRODUCT_SHEET_AI_VERSION );
		wp_enqueue_script( self::HANDLE, PRODUCT_SHEET_AI_URL . 'resources/admin.js', array(), PRODUCT_SHEET_AI_VERSION, true );
		wp_add_inline_script(
			self::HANDLE,
			'window.productSheetAiConfig = ' . wp_json_encode( self::config() ) . ';',
			'before'
		);
	}

	/**
	 * Configuration passed to the script.
	 *
	 * @return array<string,mixed>
	 */
	public static function config(): array {
		return array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( Ajax::NONCE_ACTION ),
			'settingsUrl' => Ajax::settings_url(),
			'hasKey'      => Settings::has_key(),
			'appliedKey'  => self::APPLIED_KEY,
			'strings'     => self::strings(),
		);
	}

	/**
	 * Print the panel. The script fills it in.
	 *
	 * @param int    $product_id Product ID, 0 for a new product.
	 * @param string $mode       'metabox' (collapsed behind a button) or 'page' (always open).
	 */
	public static function render_panel( int $product_id, string $mode ): void {
		$is_page = 'page' === $mode;
		echo '<div class="product-sheet-ai-panel" data-product-id="' . esc_attr( (string) $product_id ) . '" data-mode="' . esc_attr( $mode ) . '">';

		if ( ! Settings::has_key() ) {
			printf(
				'<div class="notice notice-warning inline"><p>%1$s <a href="%2$s">%3$s</a></p></div>',
				esc_html__( 'Add your AI provider API key before you generate a preview.', 'product-sheet-ai-for-woocommerce' ),
				esc_url( Ajax::settings_url() ),
				esc_html__( 'Open WooCommerce > Product Sheet AI', 'product-sheet-ai-for-woocommerce' )
			);
		}

		if ( ! $is_page ) {
			echo '<p class="psai-open-wrap"><button type="button" class="button button-primary psai-open" aria-expanded="false">' . esc_html__( 'Fill from product sheet', 'product-sheet-ai-for-woocommerce' ) . '</button></p>';
		}

		echo '<div class="psai-form"' . ( $is_page ? '' : ' hidden' ) . '>';
		echo '<p><label for="psai-text"><strong>' . esc_html__( 'Product sheet text', 'product-sheet-ai-for-woocommerce' ) . '</strong></label></p>';
		printf(
			'<textarea id="psai-text" class="psai-text large-text" rows="8" placeholder="%s"></textarea>',
			esc_attr__( 'Paste a supplier spec, a table copied from a spreadsheet, or text copied from a PDF.', 'product-sheet-ai-for-woocommerce' )
		);
		echo '<p><label for="psai-file"><strong>' . esc_html__( 'Or upload a file', 'product-sheet-ai-for-woocommerce' ) . '</strong></label><br />';
		echo '<input type="file" id="psai-file" class="psai-file" accept=".txt,.csv,.pdf,text/plain,text/csv,application/pdf" aria-describedby="psai-file-hint" />';
		echo '<span id="psai-file-hint" class="description"> ' . esc_html__( '.txt, .csv or .pdf. If you also paste text, the file is used.', 'product-sheet-ai-for-woocommerce' ) . '</span></p>';
		echo '<p class="psai-actions"><button type="button" class="button button-primary psai-generate">' . esc_html__( 'Generate preview', 'product-sheet-ai-for-woocommerce' ) . '</button>';
		echo '<span class="spinner psai-spinner" aria-hidden="true"></span></p>';
		echo '<p class="psai-status description" role="status" aria-live="polite"></p>';
		echo '<div class="psai-error notice notice-error inline" role="alert" hidden></div>';
		echo '</div>';

		echo '<div class="psai-preview" hidden></div>';
		echo '<div class="psai-done" role="status" aria-live="polite" hidden></div>';
		echo '</div>';
	}

	/**
	 * Translatable strings for the script.
	 *
	 * @return array<string,string>
	 */
	public static function strings(): array {
		return array(
			'working'         => __( 'Reading the sheet... this can take up to a minute.', 'product-sheet-ai-for-woocommerce' ),
			'applying'        => __( 'Applying your choices...', 'product-sheet-ai-for-woocommerce' ),
			'networkError'    => __( 'The request failed. Check your connection and try again.', 'product-sheet-ai-for-woocommerce' ),
			'badResponse'     => __( 'The server sent an unexpected answer. Try again, and check the request log if it keeps happening.', 'product-sheet-ai-for-woocommerce' ),
			'openSettings'    => __( 'Open the settings page', 'product-sheet-ai-for-woocommerce' ),
			'emptyInput'      => __( 'Paste the product sheet text or choose a file first.', 'product-sheet-ai-for-woocommerce' ),
			'previewTitle'    => __( 'Preview', 'product-sheet-ai-for-woocommerce' ),
			'previewIntro'    => __( 'Nothing is saved yet. Tick what you want to apply.', 'product-sheet-ai-for-woocommerce' ),
			'colField'        => __( 'Field', 'product-sheet-ai-for-woocommerce' ),
			'colCurrent'      => __( 'Current', 'product-sheet-ai-for-woocommerce' ),
			'colProposed'     => __( 'Proposed', 'product-sheet-ai-for-woocommerce' ),
			'colApply'        => __( 'Apply', 'product-sheet-ai-for-woocommerce' ),
			/* translators: %s: field name. */
			'applyField'      => __( 'Apply: %s', 'product-sheet-ai-for-woocommerce' ),
			'productType'     => __( 'Product type', 'product-sheet-ai-for-woocommerce' ),
			'typeSimple'      => __( 'Simple product', 'product-sheet-ai-for-woocommerce' ),
			'typeVariable'    => __( 'Variable product', 'product-sheet-ai-for-woocommerce' ),
			'typeNone'        => __( 'None yet', 'product-sheet-ai-for-woocommerce' ),
			'empty'           => __( '(empty)', 'product-sheet-ai-for-woocommerce' ),
			'attributes'      => __( 'Attributes', 'product-sheet-ai-for-woocommerce' ),
			'matchGlobal'     => __( 'Existing attribute', 'product-sheet-ai-for-woocommerce' ),
			'matchNewGlobal'  => __( 'New attribute', 'product-sheet-ai-for-woocommerce' ),
			'matchLocal'      => __( 'Product-only attribute', 'product-sheet-ai-for-woocommerce' ),
			'newValue'        => __( 'new', 'product-sheet-ai-for-woocommerce' ),
			'forVariations'   => __( 'Used for variations', 'product-sheet-ai-for-woocommerce' ),
			'variations'      => __( 'Variations', 'product-sheet-ai-for-woocommerce' ),
			'colVariation'    => __( 'Variation', 'product-sheet-ai-for-woocommerce' ),
			'colPrice'        => __( 'Price', 'product-sheet-ai-for-woocommerce' ),
			'colSale'         => __( 'Sale price', 'product-sheet-ai-for-woocommerce' ),
			'colSku'          => __( 'SKU', 'product-sheet-ai-for-woocommerce' ),
			'newItems'        => __( 'New items to create', 'product-sheet-ai-for-woocommerce' ),
			'newItemsHint'    => __( 'Unchecked new attribute values are skipped. Unchecked new attributes are added as product-only attributes. Unchecked new categories are skipped.', 'product-sheet-ai-for-woocommerce' ),
			'warnings'        => __( 'Things to check', 'product-sheet-ai-for-woocommerce' ),
			/* translators: 1: model name, 2: input tokens, 3: output tokens. */
			'usage'           => __( 'Model: %1$s. Tokens used: %2$s in, %3$s out.', 'product-sheet-ai-for-woocommerce' ),
			'applySelected'   => __( 'Apply selected', 'product-sheet-ai-for-woocommerce' ),
			'cancel'          => __( 'Cancel', 'product-sheet-ai-for-woocommerce' ),
			'confirmApply'    => __( 'Applying will reload this screen, so any unsaved edits on it will be lost. Continue?', 'product-sheet-ai-for-woocommerce' ),
			'nothingSelected' => __( 'Select at least one field to apply.', 'product-sheet-ai-for-woocommerce' ),
			'draftCreated'    => __( 'Draft created.', 'product-sheet-ai-for-woocommerce' ),
			'editDraft'       => __( 'Edit the draft product', 'product-sheet-ai-for-woocommerce' ),
			'addAnother'      => __( 'Add another product', 'product-sheet-ai-for-woocommerce' ),
			'applied'         => __( 'Product Sheet AI: the product was updated.', 'product-sheet-ai-for-woocommerce' ),
			'notes'           => __( 'Notes', 'product-sheet-ai-for-woocommerce' ),
			'dismiss'         => __( 'Dismiss this notice.', 'product-sheet-ai-for-woocommerce' ),
		);
	}
}
