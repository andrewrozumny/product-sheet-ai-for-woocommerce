<?php
/**
 * AJAX helper tests: request sanitizing, capability decisions and error mapping.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Tests\Unit;

use Brain\Monkey\Functions;
use ProductSheetAI\Ajax;
use ProductSheetAI\ParseException;
use ProductSheetAI\Providers\ProviderException;

/**
 * Ajax static helpers.
 */
final class AjaxTest extends TestCase {

	/**
	 * Stub the sanitizers the helpers use.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'sanitize_key' )->alias(
			static function ( $key ) {
				return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
			}
		);
		Functions\when( 'wp_check_invalid_utf8' )->returnArg();
	}

	/**
	 * Only known field keys survive, without duplicates.
	 */
	public function test_sanitize_fields_filters_to_schema_keys(): void {
		$result = Ajax::sanitize_fields( array( 'name', 'NAME', 'sku', 'bogus', '<script>', array( 'x' ), 'seo_title', 'name' ) );
		$this->assertSame( array( 'name', 'sku', 'seo_title' ), $result );
	}

	/**
	 * Non-array input gives an empty list.
	 */
	public function test_sanitize_fields_rejects_non_array(): void {
		$this->assertSame( array(), Ajax::sanitize_fields( 'name' ) );
		$this->assertSame( array(), Ajax::sanitize_fields( null ) );
	}

	/**
	 * Confirm ids are trimmed, de-duplicated and capped at 300.
	 */
	public function test_sanitize_confirm_limits_and_dedupes(): void {
		$raw = array( ' attribute:color ', 'attribute:color', '', array( 'x' ), str_repeat( 'a', 201 ) );
		for ( $i = 0; $i < 400; $i++ ) {
			$raw[] = 'term:pa_color:v' . $i;
		}
		$result = Ajax::sanitize_confirm( $raw );
		$this->assertCount( Ajax::MAX_CONFIRM, $result );
		$this->assertSame( 'attribute:color', $result[0] );
		$this->assertSame( 'term:pa_color:v0', $result[1] );
		$this->assertSame( array(), Ajax::sanitize_confirm( 'term:x' ) );
	}

	/**
	 * Tokens keep only token characters and a sane length.
	 */
	public function test_sanitize_token(): void {
		$this->assertSame( 'AbC123_-x', Ajax::sanitize_token( 'Ab<C>1 23_-x!' ) );
		$this->assertSame( 64, strlen( Ajax::sanitize_token( str_repeat( 'a', 100 ) ) ) );
		$this->assertSame( '', Ajax::sanitize_token( array( 'a' ) ) );
	}

	/**
	 * NUL bytes are removed and the text is trimmed.
	 */
	public function test_sanitize_sheet_text(): void {
		$this->assertSame( "a\nb", Ajax::sanitize_sheet_text( "  a\0\nb  " ) );
		$this->assertSame( '', Ajax::sanitize_sheet_text( null ) );
	}

	/**
	 * New products need edit_products, existing ones edit_product on that id.
	 */
	public function test_authorize_uses_the_right_capability(): void {
		Functions\expect( 'current_user_can' )->once()->with( 'edit_products' )->andReturn( true );
		$this->assertTrue( Ajax::authorize( 0 ) );

		Functions\expect( 'current_user_can' )->once()->with( 'edit_product', 42 )->andReturn( false );
		$this->assertFalse( Ajax::authorize( 42 ) );
	}

	/**
	 * Provider errors keep their code and user-facing message.
	 */
	public function test_error_from_provider_exception(): void {
		$error = Ajax::error_from( ProviderException::for_code( 'rate_limit' ) );
		$this->assertSame( 'rate_limit', $error['code'] );
		$this->assertSame( 502, $error['status'] );
		$this->assertNotSame( '', $error['message'] );

		$this->assertSame( 400, Ajax::error_from( ProviderException::for_code( 'no_key' ) )['status'] );
	}

	/**
	 * Parse errors map to the schema code.
	 */
	public function test_error_from_parse_exception(): void {
		$error = Ajax::error_from( new ParseException( 'name (missing)' ) );
		$this->assertSame( 'schema', $error['code'] );
		$this->assertSame( 422, $error['status'] );
	}

	/**
	 * Invalid input keeps its message.
	 */
	public function test_error_from_invalid_argument(): void {
		$error = Ajax::error_from( new \InvalidArgumentException( 'The sheet is too long.' ) );
		$this->assertSame( 'invalid', $error['code'] );
		$this->assertSame( 'The sheet is too long.', $error['message'] );
	}

	/**
	 * Unknown exceptions never leak their message.
	 */
	public function test_error_from_unexpected_hides_details(): void {
		$error = Ajax::error_from( new \RuntimeException( 'SQLSTATE secret trace' ) );
		$this->assertSame( 'unexpected', $error['code'] );
		$this->assertSame( 500, $error['status'] );
		$this->assertStringNotContainsString( 'secret', $error['message'] );
	}

	/**
	 * Upload errors give a message, a clean upload or no file gives none.
	 */
	public function test_upload_error_message(): void {
		$this->assertSame( '', Ajax::upload_error_message( array( 'error' => UPLOAD_ERR_OK ) ) );
		$this->assertSame( '', Ajax::upload_error_message( array( 'error' => UPLOAD_ERR_NO_FILE ) ) );
		$this->assertSame( '', Ajax::upload_error_message( array() ) );
		$this->assertStringContainsString( 'larger', Ajax::upload_error_message( array( 'error' => UPLOAD_ERR_INI_SIZE ) ) );
		$this->assertNotSame( '', Ajax::upload_error_message( array( 'error' => UPLOAD_ERR_PARTIAL ) ) );
	}

	/**
	 * Apply errors keep their user-facing message.
	 */
	public function test_apply_exception_message_is_shown(): void {
		$error = Ajax::error_from( new \ProductSheetAI\Mapping\ApplyException( 'The preview is missing or expired.' ) );
		$this->assertSame( 'The preview is missing or expired.', $error['message'] );
		$this->assertSame( 'apply', $error['code'] );
		$this->assertSame( 400, $error['status'] );
	}
}
