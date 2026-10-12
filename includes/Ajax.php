<?php
/**
 * AJAX endpoints: generate a preview and apply it.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI;

use ProductSheetAI\Admin\SettingsPage;
use ProductSheetAI\Mapping\ApplyException;
use ProductSheetAI\Mapping\Applier;
use ProductSheetAI\Providers\ProviderException;
use ProductSheetAI\Sources\Extractor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles wp_ajax_product_sheet_ai_generate and wp_ajax_product_sheet_ai_apply.
 */
final class Ajax {

	public const NONCE_ACTION = 'product_sheet_ai';

	public const MAX_CONFIRM = 300;

	private const MAX_ID_LENGTH = 200;

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'wp_ajax_product_sheet_ai_generate', array( $this, 'handle_generate' ) );
		add_action( 'wp_ajax_product_sheet_ai_apply', array( $this, 'handle_apply' ) );
	}

	/**
	 * Whether the current user may use the plugin for a product (0 means a new product).
	 *
	 * @param int $product_id Product ID or 0.
	 */
	public static function authorize( int $product_id ): bool {
		if ( $product_id > 0 ) {
			return current_user_can( 'edit_product', $product_id );
		}
		return current_user_can( 'edit_products' );
	}

	/**
	 * Keep only known field keys, without duplicates.
	 *
	 * @param mixed $raw Raw (unslashed) request value.
	 * @return string[]
	 */
	public static function sanitize_fields( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$fields = array();
		foreach ( $raw as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$key = sanitize_key( (string) $value );
			if ( in_array( $key, Schema::FIELD_KEYS, true ) && ! in_array( $key, $fields, true ) ) {
				$fields[] = $key;
			}
		}
		return $fields;
	}

	/**
	 * Sanitize the ids of confirmed new items. At most MAX_CONFIRM, without duplicates.
	 *
	 * @param mixed $raw Raw (unslashed) request value.
	 * @return string[]
	 */
	public static function sanitize_confirm( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$ids = array();
		foreach ( $raw as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$id = trim( sanitize_text_field( (string) $value ) );
			if ( '' === $id || strlen( $id ) > self::MAX_ID_LENGTH || in_array( $id, $ids, true ) ) {
				continue;
			}
			$ids[] = $id;
			if ( count( $ids ) >= self::MAX_CONFIRM ) {
				break;
			}
		}
		return $ids;
	}

	/**
	 * Reduce a preview token to the characters tokens are made of.
	 *
	 * @param mixed $raw Raw (unslashed) request value.
	 */
	public static function sanitize_token( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		return substr( (string) preg_replace( '/[^A-Za-z0-9_-]/', '', $raw ), 0, 64 );
	}

	/**
	 * Strip NUL bytes and invalid UTF-8 from pasted sheet text. The text is only sent to the model, never printed.
	 *
	 * @param mixed $raw Raw (unslashed) request value.
	 */
	public static function sanitize_sheet_text( $raw ): string {
		if ( ! is_string( $raw ) ) {
			return '';
		}
		$text = (string) wp_check_invalid_utf8( str_replace( "\0", '', $raw ) );
		return trim( $text );
	}

	/**
	 * Turn an exception into a user-facing error payload. Stack traces and internals never leave the server.
	 *
	 * @param \Throwable $e Caught exception.
	 * @return array{message:string,code:string,status:int}
	 */
	public static function error_from( \Throwable $e ): array {
		if ( $e instanceof ProviderException ) {
			$code = $e->error_code();
			return array(
				'message' => $e->getMessage(),
				'code'    => $code,
				'status'  => 'no_key' === $code ? 400 : 502,
			);
		}
		if ( $e instanceof ParseException ) {
			return array(
				'message' => $e->getMessage(),
				'code'    => $e->error_code(),
				'status'  => 422,
			);
		}
		if ( $e instanceof ApplyException ) {
			return array(
				'message' => $e->getMessage(),
				'code'    => 'apply',
				'status'  => 400,
			);
		}
		if ( $e instanceof \InvalidArgumentException ) {
			return array(
				'message' => $e->getMessage(),
				'code'    => 'invalid',
				'status'  => 400,
			);
		}
		return array(
			'message' => __( 'Something went wrong. Please try again. If it keeps happening, check the request log on the settings page.', 'product-sheet-ai-for-woocommerce' ),
			'code'    => 'unexpected',
			'status'  => 500,
		);
	}

	/**
	 * Message for a failed upload, or an empty string when the upload is fine or absent.
	 *
	 * @param array<string,mixed> $file An entry of $_FILES.
	 */
	public static function upload_error_message( array $file ): string {
		$error = isset( $file['error'] ) && is_int( $file['error'] ) ? $file['error'] : UPLOAD_ERR_OK;
		if ( UPLOAD_ERR_OK === $error || UPLOAD_ERR_NO_FILE === $error ) {
			return '';
		}
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return __( 'The file is larger than this server allows. Use a smaller file or paste the text.', 'product-sheet-ai-for-woocommerce' );
		}
		return __( 'The file could not be uploaded. Please try again or paste the text.', 'product-sheet-ai-for-woocommerce' );
	}

	/**
	 * Send a JSON error and stop.
	 *
	 * @param string              $message User-facing message.
	 * @param string              $code    Machine readable code.
	 * @param int                 $status  HTTP status.
	 * @param array<string,mixed> $extra   Extra payload.
	 * @return never
	 */
	private function fail( string $message, string $code, int $status, array $extra = array() ): void {
		wp_send_json_error(
			array_merge(
				array(
					'message' => $message,
					'code'    => $code,
				),
				$extra
			),
			$status
		);
	}

	/**
	 * Verify the nonce without dying, so the browser gets a readable JSON error.
	 */
	private function verify_nonce(): void {
		if ( false === check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			$this->fail( __( 'Your session expired. Reload the page and try again.', 'product-sheet-ai-for-woocommerce' ), 'nonce', 403 );
		}
	}

	/**
	 * Settings page URL.
	 */
	public static function settings_url(): string {
		return admin_url( 'admin.php?page=' . SettingsPage::SLUG );
	}

	/**
	 * Generate a preview from pasted text or an uploaded file.
	 */
	public function handle_generate(): void {
		$this->verify_nonce();

		// Nonce is verified above. Every value is sanitized by the helpers below.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$text       = isset( $_POST['sheet_text'] ) ? self::sanitize_sheet_text( wp_unslash( $_POST['sheet_text'] ) ) : '';
		$file       = ( isset( $_FILES['sheet_file'] ) && is_array( $_FILES['sheet_file'] ) ) ? $_FILES['sheet_file'] : array();
		// phpcs:enable

		if ( ! self::authorize( $product_id ) ) {
			$this->fail( __( 'You are not allowed to edit this product.', 'product-sheet-ai-for-woocommerce' ), 'forbidden', 403 );
		}

		if ( ! Settings::has_key() ) {
			$this->fail(
				__( 'No API key is set. Add your key on the Product Sheet AI settings page first.', 'product-sheet-ai-for-woocommerce' ),
				'no_key',
				400,
				array( 'settings_url' => self::settings_url() )
			);
		}

		$upload_error = self::upload_error_message( $file );
		if ( '' !== $upload_error ) {
			$this->fail( $upload_error, 'upload', 400 );
		}

		$had_text = '' !== $text;
		$has_file = array() !== $file && isset( $file['error'] ) && UPLOAD_ERR_OK === (int) $file['error'];
		if ( ! $has_file && '' === $text ) {
			$this->fail( __( 'Paste the product sheet text or choose a file first.', 'product-sheet-ai-for-woocommerce' ), 'empty', 400 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 150 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- A model call can take longer than the default limit.
		}

		try {
			$source = 'paste';
			if ( $has_file ) {
				$extracted = Extractor::from_upload( $file );
				$text      = $extracted['text'];
				$source    = $extracted['source'];
			} else {
				$text = Extractor::from_paste( $text );
			}
			$result = Generator::generate( $text, $product_id, $source );
		} catch ( \Throwable $e ) {
			$error = self::error_from( $e );
			$this->fail( $error['message'], $error['code'], $error['status'] );
		}

		$notice = ( $has_file && $had_text )
			? __( 'You gave both text and a file. The file was used and the pasted text was ignored.', 'product-sheet-ai-for-woocommerce' )
			: '';

		wp_send_json_success(
			array(
				'token'   => $result['token'],
				'preview' => $result['preview'],
				'notice'  => $notice,
			)
		);
	}

	/**
	 * Apply the chosen part of a stored preview.
	 */
	public function handle_apply(): void {
		$this->verify_nonce();

		// Nonce is verified above. Every value is sanitized by the helpers below.
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$token      = isset( $_POST['token'] ) ? self::sanitize_token( wp_unslash( $_POST['token'] ) ) : '';
		$fields     = isset( $_POST['fields'] ) ? self::sanitize_fields( wp_unslash( $_POST['fields'] ) ) : array();
		$confirm    = isset( $_POST['confirm'] ) ? self::sanitize_confirm( wp_unslash( $_POST['confirm'] ) ) : array();
		// phpcs:enable

		if ( ! self::authorize( $product_id ) ) {
			$this->fail( __( 'You are not allowed to edit this product.', 'product-sheet-ai-for-woocommerce' ), 'forbidden', 403 );
		}
		if ( '' === $token ) {
			$this->fail( __( 'The preview is missing or expired. Generate it again.', 'product-sheet-ai-for-woocommerce' ), 'token', 400 );
		}
		if ( array() === $fields ) {
			$this->fail( __( 'Select at least one field to apply.', 'product-sheet-ai-for-woocommerce' ), 'no_fields', 400 );
		}

		try {
			$result = Applier::apply( $token, $fields, $confirm );
		} catch ( \Throwable $e ) {
			$error = self::error_from( $e );
			$this->fail( $error['message'], $error['code'], $error['status'] );
		}

		wp_send_json_success(
			array(
				'product_id' => (int) $result['product_id'],
				'edit_url'   => esc_url_raw( (string) $result['edit_url'] ),
				'created'    => (bool) $result['created'],
				'notes'      => array_values( array_map( 'strval', $result['notes'] ) ),
			)
		);
	}
}
