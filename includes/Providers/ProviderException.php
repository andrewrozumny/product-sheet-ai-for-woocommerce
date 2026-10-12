<?php
/**
 * Provider failure with a stable error code.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown for every provider failure. Messages never contain the API key.
 */
final class ProviderException extends \RuntimeException {

	public const CODES = array(
		'no_key',
		'auth',
		'permission',
		'rate_limit',
		'overloaded',
		'timeout',
		'bad_request',
		'too_large',
		'refusal',
		'truncated',
		'http',
		'empty',
	);

	/**
	 * Machine readable code.
	 *
	 * @var string
	 */
	private string $error_code;

	/**
	 * Constructor.
	 *
	 * @param string $error_code One of self::CODES.
	 * @param string $message    User-facing message.
	 */
	private function __construct( string $error_code, string $message ) {
		parent::__construct( $message );
		$this->error_code = $error_code;
	}

	/**
	 * Build an exception for a code.
	 *
	 * @param string $code   One of self::CODES. Unknown codes become 'http'.
	 * @param string $detail Optional detail from the provider, shown for bad_request only.
	 * @param string $secret A secret that must be removed from the detail.
	 */
	public static function for_code( string $code, string $detail = '', string $secret = '' ): self {
		if ( ! in_array( $code, self::CODES, true ) ) {
			$code = 'http';
		}
		return new self( $code, self::message_for( $code, self::clean_detail( $detail, $secret ) ) );
	}

	/**
	 * The machine readable code.
	 */
	public function error_code(): string {
		return $this->error_code;
	}

	/**
	 * Remove the secret, tags and excess length from a provider detail.
	 *
	 * @param string $detail Provider detail.
	 * @param string $secret Secret to remove.
	 */
	private static function clean_detail( string $detail, string $secret ): string {
		if ( '' !== $secret ) {
			$detail = str_replace( $secret, '[removed]', $detail );
		}
		$detail = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $detail ) ) ?? '' );
		return function_exists( 'mb_substr' ) ? mb_substr( $detail, 0, 300 ) : substr( $detail, 0, 300 );
	}

	/**
	 * Translated user message for a code.
	 *
	 * @param string $code   Error code.
	 * @param string $detail Cleaned detail.
	 */
	private static function message_for( string $code, string $detail ): string {
		switch ( $code ) {
			case 'no_key':
				return __( 'No API key is saved yet. Add one in WooCommerce > Product Sheet AI.', 'product-sheet-ai-for-woocommerce' );
			case 'auth':
				return __( 'The provider did not accept the API key. Check that the key is correct and still active.', 'product-sheet-ai-for-woocommerce' );
			case 'permission':
				return __( 'The API key is not allowed to use this model or feature. Check the key permissions and the model name.', 'product-sheet-ai-for-woocommerce' );
			case 'rate_limit':
				return __( 'The provider says there were too many requests or the usage limit is reached. Wait a minute and try again.', 'product-sheet-ai-for-woocommerce' );
			case 'overloaded':
				return __( 'The provider is overloaded right now. Try again in a few minutes.', 'product-sheet-ai-for-woocommerce' );
			case 'timeout':
				return __( 'The provider took too long to answer. Try again, or use a shorter sheet.', 'product-sheet-ai-for-woocommerce' );
			case 'bad_request':
				return '' === $detail
					? __( 'The provider rejected the request. Check the model name and the endpoint.', 'product-sheet-ai-for-woocommerce' )
					/* translators: %s: error text from the provider. */
					: sprintf( __( 'The provider rejected the request: %s', 'product-sheet-ai-for-woocommerce' ), $detail );
			case 'too_large':
				return __( 'The sheet is too large for the provider. Use a shorter sheet.', 'product-sheet-ai-for-woocommerce' );
			case 'refusal':
				return __( 'The model declined to process this sheet.', 'product-sheet-ai-for-woocommerce' );
			case 'truncated':
				return __( 'The answer was cut off because it was too long. Use a shorter sheet or split it into several products.', 'product-sheet-ai-for-woocommerce' );
			case 'empty':
				return __( 'The provider returned an empty answer. Try again.', 'product-sheet-ai-for-woocommerce' );
			default:
				return __( 'The provider could not be reached or returned an unexpected error. Try again later.', 'product-sheet-ai-for-woocommerce' );
		}
	}
}
