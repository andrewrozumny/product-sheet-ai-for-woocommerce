<?php
/**
 * Plugin settings stored in one option.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and sanitizes the plugin settings.
 *
 * The API key lives only in this option. It is never printed, logged or put into messages.
 */
final class Settings {

	public const OPTION = 'product_sheet_ai_settings';

	public const PROVIDERS = array( 'anthropic', 'openai_compatible' );

	public const TONES = array( 'neutral', 'friendly', 'premium' );

	public const DEFAULT_ANTHROPIC_MODEL = 'claude-sonnet-5-5';

	public const DEFAULT_OPENAI_MODEL = 'gpt-4o-mini';

	public const DEFAULT_ENDPOINT = 'https://api.openai.com/v1';

	/**
	 * Language names by locale prefix, used for the default store language.
	 */
	private const LANGUAGES = array(
		'en' => 'English',
		'uk' => 'Ukrainian',
		'ru' => 'Russian',
		'de' => 'German',
		'fr' => 'French',
		'es' => 'Spanish',
		'it' => 'Italian',
		'pt' => 'Portuguese',
		'nl' => 'Dutch',
		'pl' => 'Polish',
		'cs' => 'Czech',
		'sv' => 'Swedish',
		'da' => 'Danish',
		'nb' => 'Norwegian',
		'fi' => 'Finnish',
		'tr' => 'Turkish',
		'ro' => 'Romanian',
		'hu' => 'Hungarian',
		'el' => 'Greek',
		'he' => 'Hebrew',
		'ar' => 'Arabic',
		'ja' => 'Japanese',
		'ko' => 'Korean',
		'zh' => 'Chinese',
	);

	/**
	 * Default values for every setting.
	 *
	 * @return array{provider:string,api_key:string,model:string,endpoint:string,store_language:string,tone:string,overwrite_fields:list<string>}
	 */
	public static function defaults(): array {
		return array(
			'provider'         => 'anthropic',
			'api_key'          => '',
			'model'            => self::DEFAULT_ANTHROPIC_MODEL,
			'endpoint'         => self::DEFAULT_ENDPOINT,
			'store_language'   => self::default_language(),
			'tone'             => 'neutral',
			'overwrite_fields' => self::default_overwrite_fields(),
		);
	}

	/**
	 * Settings merged with defaults. Always valid.
	 *
	 * @return array{provider:string,api_key:string,model:string,endpoint:string,store_language:string,tone:string,overwrite_fields:list<string>}
	 */
	public static function get(): array {
		$defaults = self::defaults();
		$stored   = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$provider = isset( $stored['provider'] ) && is_string( $stored['provider'] ) && in_array( $stored['provider'], self::PROVIDERS, true )
			? $stored['provider']
			: $defaults['provider'];

		$model = isset( $stored['model'] ) && is_string( $stored['model'] ) ? trim( $stored['model'] ) : '';
		if ( '' === $model ) {
			$model = self::default_model( $provider );
		}

		$endpoint = isset( $stored['endpoint'] ) && is_string( $stored['endpoint'] ) && self::is_https_url( $stored['endpoint'] )
			? $stored['endpoint']
			: $defaults['endpoint'];

		$language = isset( $stored['store_language'] ) && is_string( $stored['store_language'] ) ? trim( $stored['store_language'] ) : '';
		if ( '' === $language ) {
			$language = $defaults['store_language'];
		}

		$tone = isset( $stored['tone'] ) && is_string( $stored['tone'] ) && in_array( $stored['tone'], self::TONES, true )
			? $stored['tone']
			: $defaults['tone'];

		$overwrite = $defaults['overwrite_fields'];
		if ( isset( $stored['overwrite_fields'] ) && is_array( $stored['overwrite_fields'] ) ) {
			$overwrite = self::known_fields( $stored['overwrite_fields'] );
		}

		return array(
			'provider'         => $provider,
			'api_key'          => isset( $stored['api_key'] ) && is_string( $stored['api_key'] ) ? $stored['api_key'] : '',
			'model'            => $model,
			'endpoint'         => $endpoint,
			'store_language'   => $language,
			'tone'             => $tone,
			'overwrite_fields' => $overwrite,
		);
	}

	/**
	 * The stored API key. Callers must never print or log it.
	 */
	public static function api_key(): string {
		return self::get()['api_key'];
	}

	/**
	 * Whether a key is saved.
	 */
	public static function has_key(): bool {
		return '' !== self::api_key();
	}

	/**
	 * Safe hint about the saved key: '' or 'ending in 1a2b'.
	 */
	public static function masked_key(): string {
		$key = self::api_key();
		if ( '' === $key ) {
			return '';
		}
		$tail = strlen( $key ) > 4 ? substr( $key, -4 ) : '';
		return '' === $tail ? '' : 'ending in ' . $tail;
	}

	/**
	 * Sanitize raw form input into a valid settings array.
	 *
	 * An empty api_key input keeps the stored key. The checkbox remove_key clears it.
	 *
	 * @param mixed $input Raw input.
	 * @return array{provider:string,api_key:string,model:string,endpoint:string,store_language:string,tone:string,overwrite_fields:list<string>}
	 */
	public static function sanitize( $input ): array {
		$input   = is_array( $input ) ? $input : array();
		$current = self::get();

		$provider = self::string_value( $input, 'provider' );
		if ( ! in_array( $provider, self::PROVIDERS, true ) ) {
			$provider = 'anthropic';
		}

		if ( ! empty( $input['remove_key'] ) ) {
			$api_key = '';
		} else {
			$new_key = preg_replace( '/\s+/', '', self::string_value( $input, 'api_key' ) );
			$new_key = is_string( $new_key ) ? $new_key : '';
			$api_key = '' !== $new_key ? $new_key : $current['api_key'];
		}

		$model = trim( sanitize_text_field( self::string_value( $input, 'model' ) ) );
		if ( '' === $model ) {
			$model = self::default_model( $provider );
		}

		$endpoint = rtrim( trim( esc_url_raw( trim( self::string_value( $input, 'endpoint' ) ) ) ), '/' );
		if ( ! self::is_https_url( $endpoint ) ) {
			$endpoint = self::DEFAULT_ENDPOINT;
		}

		$language = trim( sanitize_text_field( self::string_value( $input, 'store_language' ) ) );
		$language = function_exists( 'mb_substr' ) ? mb_substr( $language, 0, 40 ) : substr( $language, 0, 40 );
		if ( '' === $language ) {
			$language = self::default_language();
		}

		$tone = self::string_value( $input, 'tone' );
		if ( ! in_array( $tone, self::TONES, true ) ) {
			$tone = 'neutral';
		}

		$overwrite = $current['overwrite_fields'];
		if ( array_key_exists( 'overwrite_fields', $input ) ) {
			$overwrite = self::known_fields( is_array( $input['overwrite_fields'] ) ? $input['overwrite_fields'] : array() );
		}

		return array(
			'provider'         => $provider,
			'api_key'          => $api_key,
			'model'            => $model,
			'endpoint'         => $endpoint,
			'store_language'   => $language,
			'tone'             => $tone,
			'overwrite_fields' => $overwrite,
		);
	}

	/**
	 * Default model for a provider.
	 *
	 * @param string $provider Provider id.
	 */
	public static function default_model( string $provider ): string {
		return 'openai_compatible' === $provider ? self::DEFAULT_OPENAI_MODEL : self::DEFAULT_ANTHROPIC_MODEL;
	}

	/**
	 * Create the option once with autoload disabled, so the key is not loaded on every request.
	 */
	public static function ensure_option(): void {
		if ( null === get_option( self::OPTION, null ) ) {
			add_option( self::OPTION, self::defaults(), '', false );
		}
	}

	/**
	 * All fields except the ones that should rarely be overwritten.
	 *
	 * @return list<string>
	 */
	private static function default_overwrite_fields(): array {
		return array_values( array_diff( Schema::FIELD_KEYS, array( 'sku', 'regular_price', 'sale_price' ) ) );
	}

	/**
	 * Language name derived from the site locale.
	 */
	private static function default_language(): string {
		$locale = function_exists( 'get_locale' ) ? get_locale() : 'en_US';
		$prefix = strtolower( substr( $locale, 0, 2 ) );
		return self::LANGUAGES[ $prefix ] ?? 'English';
	}

	/**
	 * Keep only known field keys, in canonical order.
	 *
	 * @param array<mixed> $keys Raw keys.
	 * @return list<string>
	 */
	private static function known_fields( array $keys ): array {
		return array_values( array_intersect( Schema::FIELD_KEYS, array_filter( $keys, 'is_string' ) ) );
	}

	/**
	 * Whether the value is an https URL with a host.
	 *
	 * @param string $url URL.
	 */
	private static function is_https_url( string $url ): bool {
		return 1 === preg_match( '#^https://[^\s/]+#i', trim( $url ) );
	}

	/**
	 * Read a scalar input as a string.
	 *
	 * @param array<mixed> $input Input.
	 * @param string       $key   Key.
	 */
	private static function string_value( array $input, string $key ): string {
		return isset( $input[ $key ] ) && is_scalar( $input[ $key ] ) ? (string) $input[ $key ] : '';
	}
}
