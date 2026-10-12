<?php
/**
 * Settings tests.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Tests\Unit;

use Brain\Monkey\Functions;
use ProductSheetAI\Schema;
use ProductSheetAI\Settings;

/**
 * Settings::sanitize() and getters.
 */
final class SettingsTest extends TestCase {

	/**
	 * Stub the stored option.
	 *
	 * @param mixed $stored Stored value.
	 */
	private function stored( $stored ): void {
		Functions\when( 'get_option' )->justReturn( $stored );
	}

	/**
	 * Defaults are valid and skip sku and prices for overwrite.
	 */
	public function test_defaults(): void {
		$this->stored( false );
		$settings = Settings::get();
		$this->assertSame( 'anthropic', $settings['provider'] );
		$this->assertSame( 'claude-sonnet-5-5', $settings['model'] );
		$this->assertSame( 'neutral', $settings['tone'] );
		$this->assertSame( 'English', $settings['store_language'] );
		$this->assertNotContains( 'sku', $settings['overwrite_fields'] );
		$this->assertNotContains( 'regular_price', $settings['overwrite_fields'] );
		$this->assertNotContains( 'sale_price', $settings['overwrite_fields'] );
		$this->assertContains( 'description', $settings['overwrite_fields'] );
		$this->assertFalse( Settings::has_key() );
		$this->assertSame( '', Settings::masked_key() );
	}

	/**
	 * Locale maps to a language name.
	 */
	public function test_language_from_locale(): void {
		$this->stored( false );
		Functions\when( 'get_locale' )->justReturn( 'uk' );
		$this->assertSame( 'Ukrainian', Settings::get()['store_language'] );
	}

	/**
	 * OpenAI-compatible falls back to its own default model when empty.
	 */
	public function test_openai_default_model(): void {
		$this->stored( array( 'provider' => 'openai_compatible' ) );
		$this->assertSame( 'gpt-4o-mini', Settings::get()['model'] );
	}

	/**
	 * Empty key input keeps the stored key.
	 */
	public function test_empty_key_keeps_old(): void {
		$this->stored( array( 'api_key' => 'sk-ant-old-1a2b' ) );
		$out = Settings::sanitize( array( 'api_key' => '' ) );
		$this->assertSame( 'sk-ant-old-1a2b', $out['api_key'] );
	}

	/**
	 * A new key replaces the old one, whitespace stripped.
	 */
	public function test_new_key_replaces(): void {
		$this->stored( array( 'api_key' => 'sk-ant-old-1a2b' ) );
		$out = Settings::sanitize( array( 'api_key' => "  sk-ant-new-9z9z \n" ) );
		$this->assertSame( 'sk-ant-new-9z9z', $out['api_key'] );
	}

	/**
	 * The remove_key flag clears the key, even when a new one is typed.
	 */
	public function test_remove_key_clears(): void {
		$this->stored( array( 'api_key' => 'sk-ant-old-1a2b' ) );
		$out = Settings::sanitize(
			array(
				'api_key'    => '',
				'remove_key' => '1',
			)
		);
		$this->assertSame( '', $out['api_key'] );
		$this->assertArrayNotHasKey( 'remove_key', $out );
	}

	/**
	 * The masked key shows only the last four characters.
	 */
	public function test_masked_key(): void {
		$this->stored( array( 'api_key' => 'sk-ant-secret-1a2b' ) );
		$this->assertSame( 'ending in 1a2b', Settings::masked_key() );
		$this->assertTrue( Settings::has_key() );
	}

	/**
	 * Invalid tone and provider fall back.
	 */
	public function test_invalid_tone_and_provider_fall_back(): void {
		$this->stored( array() );
		$out = Settings::sanitize(
			array(
				'tone'     => 'sarcastic',
				'provider' => 'evil',
			)
		);
		$this->assertSame( 'neutral', $out['tone'] );
		$this->assertSame( 'anthropic', $out['provider'] );
	}

	/**
	 * Valid tone and provider are kept.
	 */
	public function test_valid_tone_and_provider(): void {
		$this->stored( array() );
		$out = Settings::sanitize(
			array(
				'tone'     => 'premium',
				'provider' => 'openai_compatible',
				'model'    => '',
			)
		);
		$this->assertSame( 'premium', $out['tone'] );
		$this->assertSame( 'openai_compatible', $out['provider'] );
		$this->assertSame( 'gpt-4o-mini', $out['model'] );
	}

	/**
	 * Overwrite fields are limited to known keys, in canonical order.
	 */
	public function test_overwrite_fields_filtered(): void {
		$this->stored( array() );
		$out = Settings::sanitize( array( 'overwrite_fields' => array( '', 'description', 'bogus', 'name', 7 ) ) );
		$this->assertSame( array( 'name', 'description' ), $out['overwrite_fields'] );
	}

	/**
	 * An empty list is a valid choice, an absent field keeps the stored list.
	 */
	public function test_overwrite_fields_empty_and_absent(): void {
		$this->stored( array( 'overwrite_fields' => array( 'tags' ) ) );
		$this->assertSame( array(), Settings::sanitize( array( 'overwrite_fields' => array( '' ) ) )['overwrite_fields'] );
		$this->assertSame( array( 'tags' ), Settings::sanitize( array() )['overwrite_fields'] );
	}

	/**
	 * Endpoint must be https.
	 */
	public function test_endpoint_must_be_https(): void {
		$this->stored( array() );
		$this->assertSame( 'https://api.example.com/v1', Settings::sanitize( array( 'endpoint' => 'https://api.example.com/v1/' ) )['endpoint'] );
		$this->assertSame( 'https://api.openai.com/v1', Settings::sanitize( array( 'endpoint' => 'http://api.example.com/v1' ) )['endpoint'] );
		$this->assertSame( 'https://api.openai.com/v1', Settings::sanitize( array( 'endpoint' => 'ftp://x' ) )['endpoint'] );
		$this->assertSame( 'https://api.openai.com/v1', Settings::sanitize( array( 'endpoint' => '' ) )['endpoint'] );
	}

	/**
	 * Language is trimmed to 40 characters and defaults when empty.
	 */
	public function test_language_limits(): void {
		$this->stored( array() );
		$this->assertSame( 40, strlen( Settings::sanitize( array( 'store_language' => str_repeat( 'a', 80 ) ) )['store_language'] ) );
		$this->assertSame( 'English', Settings::sanitize( array( 'store_language' => '   ' ) )['store_language'] );
	}

	/**
	 * Non-array input gives valid defaults.
	 */
	public function test_non_array_input(): void {
		$this->stored( array() );
		$out = Settings::sanitize( 'garbage' );
		$this->assertSame( 'anthropic', $out['provider'] );
		$this->assertSame( '', $out['api_key'] );
	}

	/**
	 * Every default overwrite key is a known field key.
	 */
	public function test_default_overwrite_keys_known(): void {
		foreach ( Settings::defaults()['overwrite_fields'] as $key ) {
			$this->assertContains( $key, Schema::FIELD_KEYS );
		}
		$this->assertSame( Schema::FIELD_KEYS, array_keys( Schema::labels() ) );
	}
}
