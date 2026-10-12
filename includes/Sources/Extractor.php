<?php
/**
 * Turns pasted text or an uploaded .txt, .csv or .pdf file into plain sheet text.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Sources;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sheet text extraction. Failures are \InvalidArgumentException with a translated message.
 */
final class Extractor {

	public const MAX_BYTES = 5242880;

	public const MAX_CHARS = 60000;

	private const MIMES = array(
		'txt' => 'text/plain',
		'csv' => 'text/csv',
		'pdf' => 'application/pdf',
	);

	/**
	 * Normalize pasted text. The caller handles wp_unslash.
	 *
	 * @param string $text Pasted text.
	 * @throws \InvalidArgumentException When empty or too long.
	 */
	public static function from_paste( string $text ): string {
		return self::finish( self::clean( $text ) );
	}

	/**
	 * Extract text from an uploaded file ($_FILES entry).
	 *
	 * @param array<string,mixed> $file        Upload array with name, tmp_name, size and error.
	 * @param callable|null       $is_uploaded Upload check, defaults to is_uploaded_file. Injectable for tests.
	 * @return array{text:string,source:string} Source is txt, csv or pdf.
	 * @throws \InvalidArgumentException When the upload is not usable.
	 */
	public static function from_upload( array $file, ?callable $is_uploaded = null ): array {
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $error ) {
			throw new \InvalidArgumentException( esc_html__( 'The file could not be uploaded. Try again with a smaller file.', 'product-sheet-ai-for-woocommerce' ) );
		}

		$tmp   = isset( $file['tmp_name'] ) && is_string( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		$name  = isset( $file['name'] ) && is_string( $file['name'] ) ? $file['name'] : '';
		$check = $is_uploaded ?? 'is_uploaded_file';
		if ( '' === $tmp || ! $check( $tmp ) ) {
			throw new \InvalidArgumentException( esc_html__( 'The file was not uploaded correctly.', 'product-sheet-ai-for-woocommerce' ) );
		}

		$size = filesize( $tmp );
		if ( false === $size || $size <= 0 ) {
			throw new \InvalidArgumentException( esc_html__( 'The file is empty.', 'product-sheet-ai-for-woocommerce' ) );
		}
		if ( $size > self::MAX_BYTES ) {
			throw new \InvalidArgumentException( esc_html__( 'The file is larger than 5 MB.', 'product-sheet-ai-for-woocommerce' ) );
		}

		$source = self::detect_type( $tmp, $name );

		if ( 'pdf' === $source ) {
			$text = self::pdf_text( $tmp );
		} else {
			$raw = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local uploaded temp file.
			if ( false === $raw ) {
				throw new \InvalidArgumentException( esc_html__( 'The file could not be read.', 'product-sheet-ai-for-woocommerce' ) );
			}
			if ( false !== strpos( $raw, "\0" ) ) {
				throw new \InvalidArgumentException( esc_html__( 'The file is not a plain text file.', 'product-sheet-ai-for-woocommerce' ) );
			}
			$text = self::clean( self::to_utf8( $raw ) );
			if ( 'csv' === $source ) {
				$text = self::csv_to_text( $text );
			}
		}

		return array(
			'text'   => self::finish( $text ),
			'source' => $source,
		);
	}

	/**
	 * Validate extension and type, return txt, csv or pdf.
	 *
	 * @param string $tmp  Temp path.
	 * @param string $name Original file name.
	 * @throws \InvalidArgumentException For other file types.
	 */
	private static function detect_type( string $tmp, string $name ): string {
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		$checked   = wp_check_filetype_and_ext( $tmp, $name, self::MIMES );
		$checked_x = isset( $checked['ext'] ) && is_string( $checked['ext'] ) ? strtolower( $checked['ext'] ) : '';
		if ( ! isset( self::MIMES[ $extension ] ) || $checked_x !== $extension ) {
			throw new \InvalidArgumentException( esc_html__( 'Only .txt, .csv and .pdf files are supported.', 'product-sheet-ai-for-woocommerce' ) );
		}
		if ( 'pdf' === $extension ) {
			$head = file_get_contents( $tmp, false, null, 0, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local uploaded temp file.
			if ( false === $head || 0 !== strncmp( $head, '%PDF-', 5 ) ) {
				throw new \InvalidArgumentException( esc_html__( 'The file is not a valid PDF.', 'product-sheet-ai-for-woocommerce' ) );
			}
		}
		return $extension;
	}

	/**
	 * Read the text layer of a PDF.
	 *
	 * @param string $path PDF path.
	 * @throws \InvalidArgumentException When unreadable or without text.
	 */
	private static function pdf_text( string $path ): string {
		if ( ! PdfLoader::is_available() ) {
			throw new \InvalidArgumentException( esc_html__( 'PDF reading is not available on this server. Paste the text or upload a .txt or .csv file.', 'product-sheet-ai-for-woocommerce' ) );
		}
		try {
			$parser = new \Smalot\PdfParser\Parser();
			$text   = $parser->parseFile( $path )->getText();
		} catch ( \Throwable $e ) {
			unset( $e );
			throw new \InvalidArgumentException( esc_html__( 'The PDF could not be read. It may be damaged or password protected.', 'product-sheet-ai-for-woocommerce' ) );
		}
		$text = self::clean( self::to_utf8( $text ) );
		if ( '' === $text ) {
			throw new \InvalidArgumentException( esc_html__( 'This PDF has no text layer. Scanned PDFs without text are not supported: paste the text or upload a text PDF.', 'product-sheet-ai-for-woocommerce' ) );
		}
		return $text;
	}

	/**
	 * Final empty and length checks.
	 *
	 * @param string $text Cleaned text.
	 * @throws \InvalidArgumentException When empty or too long.
	 */
	private static function finish( string $text ): string {
		if ( '' === $text ) {
			throw new \InvalidArgumentException( esc_html__( 'The sheet is empty.', 'product-sheet-ai-for-woocommerce' ) );
		}
		$length = function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
		if ( $length > self::MAX_CHARS ) {
			throw new \InvalidArgumentException(
				esc_html(
					sprintf(
						/* translators: %s: maximum number of characters. */
						__( 'The sheet is too long (limit %s characters). Shorten it or split it into several products.', 'product-sheet-ai-for-woocommerce' ),
						number_format_i18n( self::MAX_CHARS )
					)
				)
			);
		}
		return $text;
	}

	/**
	 * Normalize newlines, remove null bytes and trim.
	 *
	 * @param string $text Raw text.
	 */
	private static function clean( string $text ): string {
		$text = str_replace( "\0", '', $text );
		$text = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $text );
		$text = str_replace( array( "\r\n", "\r" ), "\n", $text );
		return trim( $text );
	}

	/**
	 * Convert to UTF-8 when the bytes are not valid UTF-8 (common for spreadsheet exports).
	 *
	 * @param string $text Raw bytes.
	 */
	private static function to_utf8( string $text ): string {
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $text, 'UTF-8' ) && function_exists( 'mb_convert_encoding' ) ) {
			return mb_convert_encoding( $text, 'UTF-8', 'Windows-1252' );
		}
		return $text;
	}

	/**
	 * Render CSV rows as readable "Header: value" blocks.
	 *
	 * @param string $csv CSV text.
	 */
	private static function csv_to_text( string $csv ): string {
		$rows = self::parse_csv( $csv );
		if ( count( $rows ) < 2 ) {
			return $csv;
		}
		$header = array_shift( $rows );
		$blocks = array();
		foreach ( $rows as $row ) {
			$lines = array();
			foreach ( $row as $index => $cell ) {
				$cell = trim( $cell );
				if ( '' === $cell ) {
					continue;
				}
				$label = isset( $header[ $index ] ) && '' !== trim( $header[ $index ] )
					? trim( $header[ $index ] )
					/* translators: %d: column number in a CSV file. */
					: sprintf( __( 'Column %d', 'product-sheet-ai-for-woocommerce' ), $index + 1 );
				$lines[] = $label . ': ' . $cell;
			}
			if ( array() !== $lines ) {
				$blocks[] = implode( "\n", $lines );
			}
		}
		return implode( "\n\n", $blocks );
	}

	/**
	 * Small CSV parser: quotes, doubled quotes, newlines inside quotes, comma, semicolon or tab separated.
	 *
	 * @param string $csv CSV text with normalized newlines.
	 * @return list<list<string>>
	 */
	private static function parse_csv( string $csv ): array {
		$first = strstr( $csv, "\n", true );
		$first = false === $first ? $csv : $first;
		$delim = ',';
		$best  = 0;
		foreach ( array( ',', ';', "\t" ) as $candidate ) {
			$count = substr_count( $first, $candidate );
			if ( $count > $best ) {
				$best  = $count;
				$delim = $candidate;
			}
		}

		$rows   = array();
		$row    = array();
		$cell   = '';
		$quoted = false;
		$length = strlen( $csv );
		for ( $i = 0; $i < $length; $i++ ) {
			$char = $csv[ $i ];
			if ( $quoted ) {
				if ( '"' === $char ) {
					if ( $i + 1 < $length && '"' === $csv[ $i + 1 ] ) {
						$cell .= '"';
						++$i;
					} else {
						$quoted = false;
					}
				} else {
					$cell .= $char;
				}
				continue;
			}
			if ( '"' === $char && '' === $cell ) {
				$quoted = true;
			} elseif ( $delim === $char ) {
				$row[] = $cell;
				$cell  = '';
			} elseif ( "\n" === $char ) {
				$row[]  = $cell;
				$cell   = '';
				$rows[] = $row;
				$row    = array();
			} else {
				$cell .= $char;
			}
		}
		$row[] = $cell;
		if ( array( '' ) !== $row ) {
			$rows[] = $row;
		}
		return $rows;
	}
}
