<?php
/**
 * Turns the model answer into validated, normalized product data.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validation is driven by Schema::json_schema(), so the schema and the checks cannot drift apart.
 */
final class ResponseParser {

	public const MAX_ATTRIBUTES = 10;

	public const MAX_VALUES = 50;

	public const MAX_VARIATIONS = 100;

	public const MAX_CATEGORIES = 20;

	public const MAX_TAGS = 20;

	public const MAX_WARNINGS = 20;

	/**
	 * Parse the model answer.
	 *
	 * @param string $text Raw text returned by the provider.
	 * @return array<string,mixed> Product data in the shape of Schema::json_schema() plus 'dropped_keys'.
	 * @throws ParseException When the answer is not valid product data.
	 */
	public static function parse( string $text ): array {
		$decoded = self::decode( $text );
		$dropped = 0;
		$data    = self::validate( $decoded, Schema::json_schema(), '', $dropped );
		if ( ! is_array( $data ) ) {
			throw ParseException::because( '(root) (type)' );
		}
		/**
		 * Validated by the schema.
		 *
		 * @var array<string,mixed> $data
		 */
		$data                 = self::normalize( $data );
		$data['dropped_keys'] = $dropped;
		return $data;
	}

	/**
	 * Normalize a price-like string to a plain decimal string with a dot.
	 *
	 * @param string $raw Text such as "1,299.00", "$19.99" or "19,99".
	 * @return string|null Normalized decimal, or null when it is not a usable non-negative number.
	 */
	public static function normalize_price( string $raw ): ?string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}
		// Ranges, exponents and negative numbers are not a single price.
		if ( preg_match( '/\d\s*(?:-|\x{2013}|\x{2014})\s*\d|\d\s*[eE]\s*[+-]?\d|^\s*-/u', $raw ) ) {
			return null;
		}
		// Keep digit grouping written with spaces ("1 299,00"), drop currency text and symbols.
		$raw = (string) preg_replace( '/(?<=\d)[\s\x{00A0}\x{202F}](?=\d{3}(?!\d))/u', '', $raw );
		$raw = (string) preg_replace( '/[^\d.,\s]/u', '', $raw );
		$raw = trim( $raw );
		if ( '' === $raw || preg_match( '/\d\s+\d/', $raw ) ) {
			return null;
		}
		$raw = (string) preg_replace( '/\s+/', '', $raw );
		if ( ! preg_match( '/\d/', $raw ) ) {
			return null;
		}

		$last_comma = strrpos( $raw, ',' );
		$last_dot   = strrpos( $raw, '.' );
		if ( false !== $last_comma && false !== $last_dot ) {
			$decimal = $last_comma > $last_dot ? ',' : '.';
		} elseif ( false !== $last_comma ) {
			$decimal = self::is_decimal_separator( $raw, ',', $last_comma ) ? ',' : '';
		} elseif ( false !== $last_dot ) {
			$decimal = self::is_decimal_separator( $raw, '.', $last_dot ) ? '.' : '';
		} else {
			$decimal = '';
		}

		$integer  = $raw;
		$fraction = '';
		if ( '' !== $decimal ) {
			$position = (int) strrpos( $raw, $decimal );
			$integer  = substr( $raw, 0, $position );
			$fraction = substr( $raw, $position + 1 );
		}
		$integer = (string) preg_replace( '/\D/', '', $integer );
		if ( preg_match( '/\D/', $fraction ) ) {
			return null;
		}
		$integer = ltrim( $integer, '0' );
		if ( '' === $integer ) {
			$integer = '0';
		}
		if ( '' === $fraction && '' !== $decimal ) {
			return null;
		}
		if ( strlen( $integer ) > 12 ) {
			return null;
		}
		return '' === $fraction ? $integer : $integer . '.' . $fraction;
	}

	/**
	 * Decide whether the last separator in a number is the decimal mark.
	 *
	 * @param string $raw      Digits and separators.
	 * @param string $char     The separator, comma or dot.
	 * @param int    $position Position of its last occurrence.
	 */
	private static function is_decimal_separator( string $raw, string $char, int $position ): bool {
		$after = strlen( $raw ) - $position - 1;
		if ( substr_count( $raw, $char ) > 1 ) {
			// Repeated separator: thousands, unless the last group is not three digits long.
			return 3 !== $after;
		}
		if ( 3 !== $after ) {
			return true;
		}
		// Exactly three digits after a single separator: thousands ("1,299"), unless the integer part is zero.
		return '0' === substr( $raw, 0, $position );
	}

	/**
	 * Extract and decode the JSON object from the answer.
	 *
	 * @param string $text Raw text.
	 * @return mixed Decoded value, objects as stdClass.
	 * @throws ParseException When no JSON object can be decoded.
	 */
	private static function decode( string $text ): mixed {
		$text = trim( $text );
		if ( '' === $text ) {
			throw ParseException::because( '(root) (empty)' );
		}
		foreach ( self::candidates( $text ) as $candidate ) {
			$value = json_decode( $candidate, false, 64 );
			if ( JSON_ERROR_NONE === json_last_error() && $value instanceof \stdClass ) {
				return $value;
			}
		}
		throw ParseException::because( '(root) (json)' );
	}

	/**
	 * Possible JSON strings inside the answer, most likely first.
	 *
	 * @param string $text Trimmed answer.
	 * @return list<string>
	 */
	private static function candidates( string $text ): array {
		$candidates = array( $text );
		if ( preg_match( '/```(?:json)?\s*(\{.*\})\s*```/is', $text, $match ) ) {
			$candidates[] = $match[1];
		}
		$balanced = self::first_object( $text );
		if ( '' !== $balanced ) {
			$candidates[] = $balanced;
		}
		$start = strpos( $text, '{' );
		$end   = strrpos( $text, '}' );
		if ( false !== $start && false !== $end && $end > $start ) {
			$candidates[] = substr( $text, $start, $end - $start + 1 );
		}
		return array_values( array_unique( $candidates ) );
	}

	/**
	 * The first balanced {...} block, honoring JSON strings.
	 *
	 * @param string $text Text to scan.
	 */
	private static function first_object( string $text ): string {
		$start = strpos( $text, '{' );
		if ( false === $start ) {
			return '';
		}
		$depth  = 0;
		$quoted = false;
		$escape = false;
		$length = strlen( $text );
		for ( $i = $start; $i < $length; $i++ ) {
			$char = $text[ $i ];
			if ( $quoted ) {
				if ( $escape ) {
					$escape = false;
				} elseif ( '\\' === $char ) {
					$escape = true;
				} elseif ( '"' === $char ) {
					$quoted = false;
				}
				continue;
			}
			if ( '"' === $char ) {
				$quoted = true;
			} elseif ( '{' === $char ) {
				++$depth;
			} elseif ( '}' === $char ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $text, $start, $i - $start + 1 );
				}
			}
		}
		return '';
	}

	/**
	 * Validate a decoded value against the JSON schema subset used here and drop unknown keys.
	 *
	 * Supported: type (string, integer, boolean, null, array, object), properties, required,
	 * additionalProperties false, items, enum, anyOf.
	 *
	 * @param mixed               $value   Decoded value (objects are stdClass).
	 * @param array<string,mixed> $schema  Schema node.
	 * @param string              $path    JSON path of the value, for error details.
	 * @param int                 $dropped Counter of dropped unknown keys, by reference.
	 * @return mixed The value with objects turned into arrays and unknown keys removed.
	 * @throws ParseException On the first mismatch.
	 */
	private static function validate( mixed $value, array $schema, string $path, int &$dropped ): mixed {
		$label = '' === $path ? '(root)' : $path;

		if ( isset( $schema['anyOf'] ) && is_array( $schema['anyOf'] ) ) {
			foreach ( $schema['anyOf'] as $branch ) {
				$branch_dropped = 0;
				try {
					$clean    = self::validate( $value, (array) $branch, $path, $branch_dropped );
					$dropped += $branch_dropped;
					return $clean;
				} catch ( ParseException $e ) {
					unset( $e );
				}
			}
			$error = ParseException::because( $label . ' (type)' );
			throw $error;
		}

		$type = isset( $schema['type'] ) && is_string( $schema['type'] ) ? $schema['type'] : '';
		switch ( $type ) {
			case 'string':
				$ok = is_string( $value );
				break;
			case 'integer':
				$ok = is_int( $value );
				break;
			case 'boolean':
				$ok = is_bool( $value );
				break;
			case 'null':
				$ok = null === $value;
				break;
			case 'array':
				$ok = is_array( $value ) && array_is_list( $value );
				break;
			case 'object':
				$ok = $value instanceof \stdClass;
				break;
			default:
				$ok = false;
		}
		if ( ! $ok ) {
			$error = ParseException::because( $label . ' (type)' );
			throw $error;
		}

		if ( isset( $schema['enum'] ) && is_array( $schema['enum'] ) && ! in_array( $value, $schema['enum'], true ) ) {
			$error = ParseException::because( $label . ' (enum)' );
			throw $error;
		}

		if ( 'array' === $type && is_array( $value ) ) {
			$items = isset( $schema['items'] ) && is_array( $schema['items'] ) ? $schema['items'] : array();
			$clean = array();
			foreach ( $value as $index => $item ) {
				$clean[] = self::validate( $item, $items, $path . '[' . $index . ']', $dropped );
			}
			return $clean;
		}

		if ( 'object' === $type && $value instanceof \stdClass ) {
			$properties = isset( $schema['properties'] ) && is_array( $schema['properties'] ) ? $schema['properties'] : array();
			$required   = isset( $schema['required'] ) && is_array( $schema['required'] ) ? $schema['required'] : array();
			$input      = get_object_vars( $value );
			$clean      = array();
			foreach ( $required as $key ) {
				$key = (string) $key;
				if ( ! array_key_exists( $key, $input ) ) {
					$error = ParseException::because( ( '' === $path ? '' : $path . '.' ) . $key . ' (missing)' );
					throw $error;
				}
			}
			foreach ( $properties as $key => $property_schema ) {
				$key = (string) $key;
				if ( array_key_exists( $key, $input ) ) {
					$clean[ $key ] = self::validate( $input[ $key ], (array) $property_schema, ( '' === $path ? '' : $path . '.' ) . $key, $dropped );
				}
			}
			$dropped += count( array_diff_key( $input, $properties ) );
			return $clean;
		}

		return $value;
	}

	/**
	 * Sanitize, normalize prices and apply limits on validated data.
	 *
	 * @param array<string,mixed> $data Validated product data.
	 * @return array<string,mixed>
	 * @throws ParseException When the name is empty.
	 */
	private static function normalize( array $data ): array {
		$warnings = array();
		foreach ( (array) $data['warnings'] as $warning ) {
			$warning = self::plain( (string) $warning );
			if ( '' !== $warning ) {
				$warnings[] = $warning;
			}
		}

		$name = self::plain( (string) $data['name'] );
		if ( '' === $name ) {
			throw ParseException::because( 'name (empty)' );
		}

		$out = array(
			'name'              => $name,
			'short_description' => self::html( (string) $data['short_description'], self::short_html() ),
			'description'       => self::html( (string) $data['description'], self::long_html() ),
			'product_type'      => (string) $data['product_type'],
			'regular_price'     => self::price_field( $data['regular_price'], 'regular_price', $warnings ),
			'sale_price'        => self::price_field( $data['sale_price'], 'sale_price', $warnings ),
			'sku'               => self::nullable_plain( $data['sku'], 100 ),
			'weight'            => self::price_field( $data['weight'], 'weight', $warnings ),
		);

		$dimensions        = (array) $data['dimensions'];
		$out['dimensions'] = array(
			'length' => self::price_field( $dimensions['length'], 'length', $warnings ),
			'width'  => self::price_field( $dimensions['width'], 'width', $warnings ),
			'height' => self::price_field( $dimensions['height'], 'height', $warnings ),
		);

		$out['categories'] = self::name_list( (array) $data['categories'], self::MAX_CATEGORIES, __( 'categories', 'product-sheet-ai-for-woocommerce' ), $warnings );
		$out['tags']       = self::name_list( (array) $data['tags'], self::MAX_TAGS, __( 'tags', 'product-sheet-ai-for-woocommerce' ), $warnings );

		$out['attributes'] = self::attributes( (array) $data['attributes'], $warnings );
		$out['variations'] = self::variations( (array) $data['variations'], $warnings );

		if ( 'variable' === $out['product_type'] && array() === $out['variations'] ) {
			$out['product_type'] = 'simple';
			$warnings[]          = __( 'The sheet was read as a variable product but no variations were found, so it is proposed as a simple product.', 'product-sheet-ai-for-woocommerce' );
		}

		$out['seo_title']       = self::plain( (string) $data['seo_title'] );
		$out['seo_description'] = self::plain( (string) $data['seo_description'] );
		$out['image_alts']      = self::image_alts( (array) $data['image_alts'] );

		$out['warnings'] = array_slice( array_values( array_unique( $warnings ) ), 0, self::MAX_WARNINGS );
		return $out;
	}

	/**
	 * Normalize a nullable number-like field.
	 *
	 * @param mixed         $value    String or null.
	 * @param string        $field    Field name for the warning.
	 * @param array<string> $warnings Warnings, by reference.
	 */
	private static function price_field( mixed $value, string $field, array &$warnings ): ?string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return null;
		}
		$normalized = self::normalize_price( $value );
		if ( null === $normalized ) {
			$warnings[] = sprintf(
				/* translators: 1: field name, 2: the unreadable value. */
				__( 'The value "%2$s" for %1$s is not a usable number and was left empty.', 'product-sheet-ai-for-woocommerce' ),
				$field,
				self::plain( $value )
			);
		}
		return $normalized;
	}

	/**
	 * Sanitized nullable text with a length cap.
	 *
	 * @param mixed $value  String or null.
	 * @param int   $length Maximum length.
	 */
	private static function nullable_plain( mixed $value, int $length ): ?string {
		if ( ! is_string( $value ) ) {
			return null;
		}
		$value = self::plain( $value );
		if ( '' === $value ) {
			return null;
		}
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}

	/**
	 * Plain text: tags removed, whitespace collapsed, trimmed.
	 *
	 * @param string $value Raw text.
	 */
	private static function plain( string $value ): string {
		return trim( sanitize_text_field( $value ) );
	}

	/**
	 * Limited HTML.
	 *
	 * @param string                           $value   Raw HTML.
	 * @param array<string,array<string,bool>> $allowed Allowed tags.
	 */
	private static function html( string $value, array $allowed ): string {
		// wp_kses removes the tags but keeps their content, so drop script and style blocks first.
		$value = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1\s*>#is', '', $value );
		return trim( wp_kses( $value, $allowed ) );
	}

	/**
	 * Tags allowed in the short description.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private static function short_html(): array {
		return array(
			'p'      => array(),
			'ul'     => array(),
			'ol'     => array(),
			'li'     => array(),
			'strong' => array(),
			'em'     => array(),
			'br'     => array(),
		);
	}

	/**
	 * Tags allowed in the full description.
	 *
	 * @return array<string,array<string,bool>>
	 */
	private static function long_html(): array {
		return self::short_html() + array(
			'h3'    => array(),
			'h4'    => array(),
			'table' => array(),
			'thead' => array(),
			'tbody' => array(),
			'tr'    => array(),
			'th'    => array(),
			'td'    => array(),
		);
	}

	/**
	 * Clean, unique, capped list of names.
	 *
	 * @param array<mixed>  $items    Raw items.
	 * @param int           $max      Maximum count.
	 * @param string        $label    Translated list name for the warning.
	 * @param array<string> $warnings Warnings, by reference.
	 * @return list<string>
	 */
	private static function name_list( array $items, int $max, string $label, array &$warnings ): array {
		$out  = array();
		$seen = array();
		foreach ( $items as $item ) {
			$item = self::plain( (string) $item );
			$key  = function_exists( 'mb_strtolower' ) ? mb_strtolower( $item ) : strtolower( $item );
			if ( '' === $item || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $item;
		}
		if ( count( $out ) > $max ) {
			$out        = array_slice( $out, 0, $max );
			$warnings[] = sprintf(
				/* translators: 1: list name, 2: kept count. */
				__( 'Too many %1$s in the answer, only the first %2$d were kept.', 'product-sheet-ai-for-woocommerce' ),
				$label,
				$max
			);
		}
		return $out;
	}

	/**
	 * Clean attributes with limits.
	 *
	 * @param array<mixed>  $items    Raw attributes.
	 * @param array<string> $warnings Warnings, by reference.
	 * @return list<array{name:string,values:list<string>,used_for_variations:bool,visible:bool}>
	 */
	private static function attributes( array $items, array &$warnings ): array {
		$out = array();
		foreach ( $items as $item ) {
			$item   = (array) $item;
			$name   = self::plain( (string) $item['name'] );
			$values = self::name_list( (array) $item['values'], self::MAX_VALUES, sprintf( /* translators: %s: attribute name. */ __( 'values of "%s"', 'product-sheet-ai-for-woocommerce' ), $name ), $warnings );
			if ( '' === $name || array() === $values ) {
				$warnings[] = __( 'An attribute without a name or without values was ignored.', 'product-sheet-ai-for-woocommerce' );
				continue;
			}
			$out[] = array(
				'name'                => $name,
				'values'              => $values,
				'used_for_variations' => (bool) $item['used_for_variations'],
				'visible'             => (bool) $item['visible'],
			);
		}
		if ( count( $out ) > self::MAX_ATTRIBUTES ) {
			$out        = array_slice( $out, 0, self::MAX_ATTRIBUTES );
			$warnings[] = sprintf(
				/* translators: %d: kept count. */
				__( 'Too many attributes in the answer, only the first %d were kept.', 'product-sheet-ai-for-woocommerce' ),
				self::MAX_ATTRIBUTES
			);
		}
		return $out;
	}

	/**
	 * Clean variations with limits.
	 *
	 * @param array<mixed>  $items    Raw variations.
	 * @param array<string> $warnings Warnings, by reference.
	 * @return list<array{attributes:list<array{name:string,value:string}>,regular_price:?string,sale_price:?string,sku:?string}>
	 */
	private static function variations( array $items, array &$warnings ): array {
		$out = array();
		foreach ( $items as $item ) {
			$item  = (array) $item;
			$pairs = array();
			foreach ( (array) $item['attributes'] as $pair ) {
				$pair  = (array) $pair;
				$name  = self::plain( (string) $pair['name'] );
				$value = self::plain( (string) $pair['value'] );
				if ( '' !== $name && '' !== $value ) {
					$pairs[] = array(
						'name'  => $name,
						'value' => $value,
					);
				}
			}
			if ( array() === $pairs ) {
				$warnings[] = __( 'A variation without attributes was ignored.', 'product-sheet-ai-for-woocommerce' );
				continue;
			}
			$out[] = array(
				'attributes'    => $pairs,
				'regular_price' => self::price_field( $item['regular_price'], 'variation regular_price', $warnings ),
				'sale_price'    => self::price_field( $item['sale_price'], 'variation sale_price', $warnings ),
				'sku'           => self::nullable_plain( $item['sku'], 100 ),
			);
		}
		if ( count( $out ) > self::MAX_VARIATIONS ) {
			$out        = array_slice( $out, 0, self::MAX_VARIATIONS );
			$warnings[] = sprintf(
				/* translators: %d: kept count. */
				__( 'Too many variations in the answer, only the first %d were kept.', 'product-sheet-ai-for-woocommerce' ),
				self::MAX_VARIATIONS
			);
		}
		return $out;
	}

	/**
	 * Clean image alt texts, unique by image id.
	 *
	 * @param array<mixed> $items Raw entries.
	 * @return list<array{image_id:int,alt:string}>
	 */
	private static function image_alts( array $items ): array {
		$out  = array();
		$seen = array();
		foreach ( $items as $item ) {
			$item = (array) $item;
			$id   = (int) $item['image_id'];
			$alt  = self::plain( (string) $item['alt'] );
			if ( $id <= 0 || '' === $alt || isset( $seen[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$out[]       = array(
				'image_id' => $id,
				'alt'      => $alt,
			);
		}
		return $out;
	}
}
