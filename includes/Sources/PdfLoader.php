<?php
/**
 * Loads the bundled PDF text parser without requiring the Composer autoloader at runtime.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Sources;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers an autoloader for Smalot\PdfParser from vendor/smalot/pdfparser/src.
 */
final class PdfLoader {

	private const PREFIX = 'Smalot\\PdfParser\\';

	/**
	 * Whether the autoloader was added by this class.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Make Smalot\PdfParser\Parser loadable. Does nothing when it is already available.
	 */
	public static function register(): void {
		if ( self::$registered || class_exists( 'Smalot\\PdfParser\\Parser', false ) ) {
			return;
		}
		self::$registered = true;

		// The parser needs the mbstring functions. Use the bundled polyfill when the extension is missing.
		$polyfill = PRODUCT_SHEET_AI_PATH . 'vendor/symfony/polyfill-mbstring/bootstrap.php';
		if ( ! function_exists( 'mb_substr' ) && is_readable( $polyfill ) ) {
			require_once $polyfill;
		}

		$base = PRODUCT_SHEET_AI_PATH . 'vendor/smalot/pdfparser/src/Smalot/PdfParser/';
		spl_autoload_register(
			static function ( string $class_name ) use ( $base ): void {
				if ( 0 !== strncmp( $class_name, self::PREFIX, strlen( self::PREFIX ) ) ) {
					return;
				}
				$file = $base . str_replace( '\\', '/', substr( $class_name, strlen( self::PREFIX ) ) ) . '.php';
				if ( is_readable( $file ) ) {
					require_once $file;
				}
			}
		);
	}

	/**
	 * Whether the parser can be used.
	 */
	public static function is_available(): bool {
		self::register();
		return class_exists( 'Smalot\\PdfParser\\Parser' );
	}
}
