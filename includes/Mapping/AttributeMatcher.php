<?php
/**
 * Maps attribute names and values from the sheet to the store's global attributes and terms.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Mapping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds existing global attributes (pa_*) and their terms.
 */
final class AttributeMatcher {

	/**
	 * Global attributes as list of array{slug:string,label:string}. Slug has no pa_ prefix.
	 *
	 * @var list<array{slug:string,label:string}>|null
	 */
	private ?array $attributes;

	/**
	 * Alias map: variant name => canonical name, both lower case.
	 *
	 * @var array<string,string>|null
	 */
	private ?array $aliases;

	/**
	 * Terms cache by taxonomy.
	 *
	 * @var array<string,list<array{term_id:int,name:string,slug:string}>>
	 */
	private array $terms = array();

	/**
	 * Constructor. Pass lists in tests; in production both are read lazily from WooCommerce.
	 *
	 * @param list<array{slug:string,label:string}>|null $attributes Global attributes.
	 * @param array<string,string>|null                  $aliases    Alias map.
	 */
	public function __construct( ?array $attributes = null, ?array $aliases = null ) {
		$this->attributes = $attributes;
		$this->aliases    = $aliases;
	}

	/**
	 * Find the global attribute that matches a name from the sheet.
	 *
	 * @param string $name Attribute name as written by the model.
	 * @return array{taxonomy:string,slug:string,label:string}|null
	 */
	public function match_attribute( string $name ): ?array {
		return self::find_attribute( $name, $this->attributes(), $this->aliases() );
	}

	/**
	 * Find an existing term of a taxonomy by name or slug, case-insensitive.
	 *
	 * @param string $taxonomy Taxonomy name, for example pa_color.
	 * @param string $value    Term name from the sheet.
	 * @return array{term_id:int,name:string,slug:string}|null
	 */
	public function match_term( string $taxonomy, string $value ): ?array {
		return self::find_term( $value, $this->terms_of( $taxonomy ) );
	}

	/**
	 * Comparison keys of a name using the configured aliases.
	 *
	 * @param string $name Name from the sheet.
	 * @return list<string>
	 */
	public function keys( string $name ): array {
		return self::keys_for( $name, $this->aliases() );
	}

	/**
	 * Whether a name from the sheet can become a new global attribute.
	 *
	 * @param string $name Attribute name.
	 */
	public static function can_be_global( string $name ): bool {
		$slug = sanitize_title( trim( $name ) );
		if ( '' === $slug || strlen( $slug ) > 28 || false !== strpos( $slug, '%' ) ) {
			return false;
		}
		if ( function_exists( 'wc_check_if_attribute_name_is_reserved' ) && wc_check_if_attribute_name_is_reserved( $slug ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Forget cached attributes and terms, for example after creating new ones.
	 */
	public function reset(): void {
		$this->attributes = null;
		$this->terms      = array();
	}

	/**
	 * Pure matching of an attribute name against a list of global attributes.
	 *
	 * @param string                                $name       Name from the sheet.
	 * @param list<array{slug:string,label:string}> $attributes Global attributes.
	 * @param array<string,string>                  $aliases    Alias map, lower case.
	 * @return array{taxonomy:string,slug:string,label:string}|null
	 */
	public static function find_attribute( string $name, array $attributes, array $aliases ): ?array {
		$wanted = self::keys_for( $name, $aliases );
		if ( array() === $wanted ) {
			return null;
		}
		foreach ( $attributes as $attribute ) {
			$have = array_merge(
				self::keys_for( $attribute['label'], array() ),
				self::keys_for( $attribute['slug'], array() )
			);
			if ( array() !== array_intersect( $wanted, $have ) ) {
				return array(
					'taxonomy' => 'pa_' . $attribute['slug'],
					'slug'     => $attribute['slug'],
					'label'    => $attribute['label'],
				);
			}
		}
		return null;
	}

	/**
	 * Pure matching of a value against a list of terms by name or slug, case-insensitive.
	 *
	 * @param string                                           $value Value from the sheet.
	 * @param list<array{term_id:int,name:string,slug:string}> $terms Existing terms.
	 * @return array{term_id:int,name:string,slug:string}|null
	 */
	public static function find_term( string $value, array $terms ): ?array {
		$wanted = self::keys_for( $value, array() );
		if ( array() === $wanted ) {
			return null;
		}
		foreach ( $terms as $term ) {
			$have = array_merge( self::keys_for( $term['name'], array() ), self::keys_for( $term['slug'], array() ) );
			if ( array() !== array_intersect( $wanted, $have ) ) {
				return $term;
			}
		}
		return null;
	}

	/**
	 * Comparison keys of a name: lower case text, its sanitized slug, and the alias target of both.
	 *
	 * @param string               $text    Text.
	 * @param array<string,string> $aliases Alias map.
	 * @return list<string>
	 */
	public static function keys_for( string $text, array $aliases ): array {
		$lower = self::lower( trim( $text ) );
		if ( '' === $lower ) {
			return array();
		}
		$keys = array( $lower, sanitize_title( $lower ) );
		foreach ( $keys as $key ) {
			if ( isset( $aliases[ $key ] ) ) {
				$keys[] = $aliases[ $key ];
				$keys[] = sanitize_title( $aliases[ $key ] );
			}
		}
		return array_values( array_unique( array_filter( $keys, static fn( string $key ): bool => '' !== $key ) ) );
	}

	/**
	 * Global attributes from WooCommerce.
	 *
	 * @return list<array{slug:string,label:string}>
	 */
	private function attributes(): array {
		if ( null === $this->attributes ) {
			$this->attributes = array();
			foreach ( wc_get_attribute_taxonomies() as $taxonomy ) {
				$this->attributes[] = array(
					'slug'  => (string) $taxonomy->attribute_name,
					'label' => (string) $taxonomy->attribute_label,
				);
			}
		}
		return $this->attributes;
	}

	/**
	 * Alias map, filterable.
	 *
	 * @return array<string,string>
	 */
	private function aliases(): array {
		if ( null === $this->aliases ) {
			$default = array(
				'colour'  => 'color',
				'colours' => 'color',
				'sizes'   => 'size',
			);
			/**
			 * Filters the attribute alias map (variant name => canonical name).
			 *
			 * @param array<string,string> $aliases Aliases, lower case.
			 */
			$aliases       = apply_filters( 'product_sheet_ai_attribute_aliases', $default );
			$this->aliases = array();
			foreach ( is_array( $aliases ) ? $aliases : array() as $variant => $canonical ) {
				if ( is_string( $variant ) && is_string( $canonical ) ) {
					$this->aliases[ self::lower( trim( $variant ) ) ] = self::lower( trim( $canonical ) );
				}
			}
		}
		return $this->aliases;
	}

	/**
	 * Terms of a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return list<array{term_id:int,name:string,slug:string}>
	 */
	private function terms_of( string $taxonomy ): array {
		if ( ! isset( $this->terms[ $taxonomy ] ) ) {
			$this->terms[ $taxonomy ] = array();
			$found                    = get_terms(
				array(
					'taxonomy'   => $taxonomy,
					'hide_empty' => false,
				)
			);
			if ( is_array( $found ) ) {
				foreach ( $found as $term ) {
					if ( $term instanceof \WP_Term ) {
						$this->terms[ $taxonomy ][] = array(
							'term_id' => $term->term_id,
							'name'    => $term->name,
							'slug'    => $term->slug,
						);
					}
				}
			}
		}
		return $this->terms[ $taxonomy ];
	}

	/**
	 * Multibyte aware lower case.
	 *
	 * @param string $text Text.
	 */
	private static function lower( string $text ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
	}
}
