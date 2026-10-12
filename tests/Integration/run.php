<?php
/**
 * Integration scenarios, run inside WordPress with `wp eval-file`.
 *
 * The AI provider is mocked at the HTTP layer with fixture responses, the settings are short-circuited
 * with a fake key, and every product, term and attribute the run creates is removed again.
 *
 * @package ProductSheetAI
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL, WordPress.Security.EscapeOutput


use ProductSheetAI\Frontend\Seo;
use ProductSheetAI\Generator;
use ProductSheetAI\Mapping\Applier;
use ProductSheetAI\ParseException;
use ProductSheetAI\Providers\ProviderException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$psai_fake_key = 'sk-ant-INTEGRATION-FAKE-KEY';
$psai_fixtures = __DIR__ . '/../fixtures';
$psai_problems = array();
$psai_urls     = array();
$psai_reports  = array();
$psai_state    = array(
	'response' => 'simple',
	'key'      => $psai_fake_key,
);
$psai_products = array();

// --- Environment: settings, HTTP mock, action listener, user ------------------------------------

add_filter(
	'pre_option_product_sheet_ai_settings',
	static function () use ( &$psai_state ): array {
		return array(
			'provider'       => 'anthropic',
			'api_key'        => $psai_state['key'],
			'model'          => 'claude-sonnet-5-5',
			'store_language' => 'English',
			'tone'           => 'neutral',
		);
	}
);

add_filter(
	'pre_http_request',
	static function ( $preempt, $args, $url ) use ( &$psai_state, &$psai_problems, &$psai_urls, $psai_fake_key, $psai_fixtures ) {
		$psai_urls[] = (string) $url;
		if ( 0 !== strpos( (string) $url, 'https://api.anthropic.com/' ) ) {
			if ( false !== strpos( (string) wp_json_encode( $args ), $psai_fake_key ) ) {
				$psai_problems[] = 'The API key was sent to an unexpected URL: ' . $url;
			}
			return new WP_Error( 'psai_blocked', 'Blocked by the integration test.' );
		}

		$headers = array_change_key_case( (array) ( $args['headers'] ?? array() ), CASE_LOWER );
		if ( ( $headers['x-api-key'] ?? '' ) !== $psai_state['key'] ) {
			$psai_problems[] = 'The request to Anthropic has no x-api-key header.';
		}
		$body = json_decode( (string) ( $args['body'] ?? '' ), true );
		$body = is_array( $body ) ? $body : array();
		if ( 'json_schema' !== ( $body['output_config']['format']['type'] ?? '' ) || empty( $body['output_config']['format']['schema'] ) ) {
			$psai_problems[] = 'The request has no output_config json_schema.';
		}
		foreach ( array( 'temperature', 'tool_choice', 'thinking' ) as $forbidden ) {
			if ( array_key_exists( $forbidden, $body ) ) {
				$psai_problems[] = 'The request must not contain ' . $forbidden . '.';
			}
		}

		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => (string) file_get_contents( $psai_fixtures . '/responses/' . $psai_state['response'] . '.json' ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	10,
	3
);

add_action(
	'product_sheet_ai_request_complete',
	static function ( $report ) use ( &$psai_reports ): void {
		$psai_reports[] = is_array( $report ) ? $report : array();
	}
);

$psai_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
if ( array() === $psai_admins ) {
	WP_CLI::error( 'No administrator found.' );
}
wp_set_current_user( (int) $psai_admins[0] );

// --- Helpers ---------------------------------------------------------------------------------------

$psai_assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};

$psai_sheet = static function ( string $name ) use ( $psai_fixtures ): string {
	return (string) file_get_contents( $psai_fixtures . '/sheets/' . $name . '.txt' );
};

$psai_generate = static function ( string $sheet, string $response, int $product_id = 0 ) use ( &$psai_state, $psai_sheet ): array {
	$psai_state['response'] = $response;
	return Generator::generate( $psai_sheet( $sheet ), $product_id, 'paste' );
};

/**
 * Keys of the fields that are checked and not disabled in a preview.
 */
$psai_checked = static function ( array $preview ): array {
	$keys = array();
	foreach ( $preview['fields'] as $field ) {
		if ( ! empty( $field['checked'] ) && empty( $field['disabled'] ) ) {
			$keys[] = $field['key'];
		}
	}
	return $keys;
};

$psai_field = static function ( array $preview, string $key ): array {
	foreach ( $preview['fields'] as $field ) {
		if ( $key === $field['key'] ) {
			return $field;
		}
	}
	return array();
};

$psai_apply = static function ( array $generated, array $fields, array $confirm ) use ( &$psai_products ): array {
	$result          = Applier::apply( $generated['token'], $fields, $confirm );
	$psai_products[] = $result['product_id'];
	return $result;
};

$psai_term_names = static function ( int $product_id, string $taxonomy ): array {
	$names = wp_get_object_terms( $product_id, $taxonomy, array( 'fields' => 'names' ) );
	return is_array( $names ) ? $names : array();
};

$psai_attribute_names = static function (): array {
	$ids = array();
	foreach ( wc_get_attribute_taxonomies() as $taxonomy ) {
		$ids[ (int) $taxonomy->attribute_id ] = (string) $taxonomy->attribute_name;
	}
	return $ids;
};

$psai_delete_product = static function ( int $product_id ): void {
	$product = wc_get_product( $product_id );
	if ( $product instanceof WC_Product ) {
		$product->delete( true );
	}
};

// Snapshots, so the lab is left as it was found.
$psai_taxonomies = array( 'pa_color', 'pa_size', 'product_cat', 'product_tag' );
$psai_before     = array();
foreach ( $psai_taxonomies as $psai_taxonomy ) {
	$psai_before[ $psai_taxonomy ] = get_terms(
		array(
			'taxonomy'   => $psai_taxonomy,
			'hide_empty' => false,
			'fields'     => 'ids',
		)
	);
}
$psai_before_attributes = $psai_attribute_names();

$psai_sweep_products = static function () use ( $psai_delete_product ): int {
	$found = get_posts(
		array(
			'post_type'   => 'product',
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
			's'           => 'PSAI',
		)
	);
	$count = 0;
	foreach ( $found as $id ) {
		if ( 0 === strpos( (string) get_the_title( (int) $id ), 'PSAI ' ) ) {
			$psai_delete_product( (int) $id );
			++$count;
		}
	}
	$variations = get_posts(
		array(
			'post_type'   => 'product_variation',
			'post_status' => 'any',
			'numberposts' => -1,
			'fields'      => 'ids',
			's'           => 'PSAI',
		)
	);
	foreach ( $variations as $id ) {
		wp_delete_post( (int) $id, true );
		++$count;
	}
	return $count;
};

$psai_sweep_products();

// --- Scenarios -------------------------------------------------------------------------------------

$psai_scenarios = array();
$psai_ids       = array();

$psai_scenarios['(a) simple product, new draft'] = static function () use ( $psai_generate, $psai_checked, $psai_field, $psai_apply, $psai_assert, $psai_term_names, &$psai_ids ): string {
	$generated = $psai_generate( 'simple', 'simple' );
	$preview   = $generated['preview'];
	$psai_assert( true === $preview['is_new'] && 0 === $preview['product_id'], 'The preview should be for a new product.' );
	$psai_assert( 14 === count( $preview['fields'] ), 'The preview should list every field.' );
	$psai_assert( true === $psai_field( $preview, 'name' )['checked'], 'Name should be checked for a new product.' );
	$psai_assert( true === $psai_field( $preview, 'sku' )['checked'], 'SKU should be checked for a new product.' );
	$psai_assert( '0.4 ' . get_option( 'woocommerce_weight_unit' ) === $psai_field( $preview, 'weight' )['proposed'], 'Weight should show its unit.' );
	$psai_assert( '9 x 9 x 10 ' . get_option( 'woocommerce_dimension_unit' ) === $psai_field( $preview, 'dimensions' )['proposed'], 'Dimensions should read L x W x H unit.' );
	$ids = array_column( $preview['new_items'], 'id' );
	$psai_assert( in_array( 'category:PSAI Gift Mugs', $ids, true ), 'The new category should need a confirmation.' );
	$psai_assert( false === strpos( $psai_field( $preview, 'description' )['proposed'], '<' ), 'Display strings must be plain text.' );

	$result = $psai_apply( $generated, $psai_checked( $preview ), array( 'category:PSAI Gift Mugs' ) );
	$psai_assert( true === $result['created'], 'A new product should be reported as created.' );
	$product = wc_get_product( $result['product_id'] );
	$psai_assert( $product instanceof WC_Product_Simple, 'A simple product should be created.' );
	$psai_assert( 'draft' === $product->get_status(), 'A new product must be a draft.' );
	$psai_assert( 'PSAI Ceramic Mug 350 ml' === $product->get_name(), 'Name mismatch.' );
	$psai_assert( '12.50' === $product->get_regular_price() && '9.90' === $product->get_sale_price(), 'Prices mismatch.' );
	$psai_assert( 'PSAI-MUG-001' === $product->get_sku(), 'SKU mismatch.' );
	$psai_assert( '0.4' === $product->get_weight() && '9' === $product->get_length() && '10' === $product->get_height(), 'Weight or dimensions mismatch.' );
	$categories = $psai_term_names( $product->get_id(), 'product_cat' );
	$psai_assert( in_array( 'Decor', $categories, true ) && in_array( 'PSAI Gift Mugs', $categories, true ), 'Categories mismatch.' );
	$psai_assert( 2 === count( $psai_term_names( $product->get_id(), 'product_tag' ) ), 'Tags mismatch.' );
	$keys = Seo::meta_keys();
	$psai_assert( 'Ceramic Mug 350 ml, hand glazed stoneware' === $product->get_meta( $keys['title'] ), 'SEO title was not stored.' );
	$psai_assert( '' !== (string) $product->get_meta( $keys['description'] ), 'SEO description was not stored.' );
	$psai_assert( false !== strpos( $product->get_description(), '<li>Dishwasher safe</li>' ), 'Description HTML mismatch.' );

	$psai_ids['simple'] = $product->get_id();

	try {
		Applier::apply( $generated['token'], array( 'name' ), array() );
		throw new RuntimeException( 'A used token should not apply twice.' );
	} catch ( \RuntimeException $e ) {
		$psai_assert( 'A used token should not apply twice.' !== $e->getMessage(), $e->getMessage() );
	}
	return 'draft #' . $product->get_id() . ', 14 fields, token single use';
};

$psai_scenarios['(b1) variable product, new terms not confirmed'] = static function () use ( $psai_generate, $psai_checked, $psai_apply, $psai_assert, $psai_term_names, $psai_delete_product ): string {
	$generated = $psai_generate( 'variable', 'variable' );
	$preview   = $generated['preview'];
	$psai_assert( 'variable' === $preview['product_type']['proposed'], 'Proposed type should be variable.' );
	$matches = array();
	foreach ( $preview['attributes'] as $attribute ) {
		$matches[ $attribute['name'] ] = $attribute['taxonomy'] . ':' . $attribute['match'];
	}
	$psai_assert( 'pa_color:global' === ( $matches['Colour'] ?? '' ), 'Colour should map to pa_color through the alias.' );
	$psai_assert( 'pa_size:global' === ( $matches['Size'] ?? '' ), 'Size should map to pa_size.' );
	$ids = array_column( $preview['new_items'], 'id' );
	foreach ( array( 'term:pa_color:Purple', 'term:pa_size:XL', 'category:PSAI Organic Basics' ) as $expected ) {
		$psai_assert( in_array( $expected, $ids, true ), 'Missing new item ' . $expected );
	}
	$psai_assert( 6 === count( $preview['variations'] ), 'The preview should list six variations.' );

	$result = $psai_apply( $generated, $psai_checked( $preview ), array() );
	$parent = wc_get_product( $result['product_id'] );
	$psai_assert( $parent instanceof WC_Product_Variable, 'A variable product should be created.' );
	$psai_assert( 'draft' === $parent->get_status(), 'A new product must be a draft.' );
	$colors = $psai_term_names( $parent->get_id(), 'pa_color' );
	$sizes  = $psai_term_names( $parent->get_id(), 'pa_size' );
	$psai_assert( array( 'Blue', 'Red' ) === array_values( array_intersect( array( 'Blue', 'Red' ), $colors ) ) && 2 === count( $colors ), 'Only existing colours should be assigned: ' . implode( ',', $colors ) );
	$psai_assert( 3 === count( $sizes ) && ! in_array( 'XL', $sizes, true ), 'Only existing sizes should be assigned.' );
	$psai_assert( null === term_exists( 'Purple', 'pa_color' ) && null === term_exists( 'XL', 'pa_size' ), 'Unconfirmed terms must not be created.' );
	$psai_assert( null === term_exists( 'PSAI Organic Basics', 'product_cat' ), 'Unconfirmed category must not be created.' );
	$psai_assert( 4 === count( $parent->get_children() ), 'Four variations should remain, got ' . count( $parent->get_children() ) );
	$psai_assert( count( $result['notes'] ) >= 3, 'Dropped items should be explained in the notes.' );

	$prices = array();
	foreach ( $parent->get_children() as $child_id ) {
		$child = wc_get_product( $child_id );
		$psai_assert( $child instanceof WC_Product_Variation, 'Child is not a variation.' );
		$prices[ $child->get_sku() ] = $child->get_regular_price() . '/' . $child->get_sale_price();
	}
	$psai_assert( '24.00/' === ( $prices['PSAI-TEE-BLUE-S'] ?? '' ), 'A variation without a price should take the product price.' );
	$psai_assert( '25.00/20.00' === ( $prices['PSAI-TEE-BLUE-M'] ?? '' ), 'Variation sale price mismatch.' );
	$notes = count( $result['notes'] );
	$psai_delete_product( $parent->get_id() );
	return '4 of 6 variations kept, ' . $notes . ' notes';
};

$psai_scenarios['(b2) variable product, new terms confirmed'] = static function () use ( $psai_generate, $psai_checked, $psai_apply, $psai_assert, $psai_term_names, &$psai_ids ): string {
	$generated = $psai_generate( 'variable', 'variable' );
	$confirm   = array( 'term:pa_color:Purple', 'term:pa_size:XL', 'category:PSAI Organic Basics' );
	$result    = $psai_apply( $generated, $psai_checked( $generated['preview'] ), $confirm );
	$parent    = wc_get_product( $result['product_id'] );
	$psai_assert( $parent instanceof WC_Product_Variable, 'A variable product should be created.' );
	$psai_assert( 'draft' === $parent->get_status(), 'A new product must be a draft.' );
	$psai_assert( 3 === count( $psai_term_names( $parent->get_id(), 'pa_color' ) ), 'Three colours expected.' );
	$psai_assert( 4 === count( $psai_term_names( $parent->get_id(), 'pa_size' ) ), 'Four sizes expected.' );
	$psai_assert( in_array( 'PSAI Organic Basics', $psai_term_names( $parent->get_id(), 'product_cat' ), true ), 'The confirmed category should be assigned.' );
	$psai_assert( 6 === count( $parent->get_children() ), 'Six variations expected, got ' . count( $parent->get_children() ) );

	$found_purple = false;
	foreach ( $parent->get_children() as $child_id ) {
		$child = wc_get_product( $child_id );
		$psai_assert( $child instanceof WC_Product_Variation, 'Child is not a variation.' );
		$psai_assert( 'publish' === $child->get_status(), 'Variations should be enabled.' );
		$attributes = $child->get_attributes();
		if ( 'purple' === ( $attributes['pa_color'] ?? '' ) && 'medium' === ( $attributes['pa_size'] ?? '' ) ) {
			$found_purple = true;
			$psai_assert( '27.00' === $child->get_regular_price() && '' === $child->get_sale_price(), 'A sale price above the regular price should be dropped.' );
		}
	}
	$psai_assert( $found_purple, 'The Purple / Medium variation is missing.' );
	$psai_assert( '24.00' === (string) $parent->get_variation_regular_price( 'min' ) && '28.00' === (string) $parent->get_variation_regular_price( 'max' ), 'Variation price range mismatch.' );
	$joined = implode( ' ', $result['notes'] );
	$psai_assert( false !== strpos( $joined, 'sale price' ), 'The dropped sale price should be in the notes.' );
	$psai_ids['variable'] = $parent->get_id();
	return '6 variations, range 24.00 to 28.00, new terms created';
};

$psai_scenarios['(c) russian sheet, english values mapped'] = static function () use ( $psai_generate, $psai_checked, $psai_apply, $psai_assert, $psai_term_names, $psai_delete_product, $psai_attribute_names ): string {
	$generated = $psai_generate( 'russian', 'russian' );
	$preview   = $generated['preview'];
	$by_name   = array();
	foreach ( $preview['attributes'] as $attribute ) {
		$by_name[ $attribute['name'] ] = $attribute;
	}
	$psai_assert( 'global' === $by_name['Color']['match'] && 'pa_color' === $by_name['Color']['taxonomy'], 'Color should map to pa_color.' );
	$psai_assert( true === $by_name['Color']['values'][0]['exists'], 'Blue should be an existing term.' );
	$psai_assert( 'new_global' === $by_name['PSAI Material']['match'], 'An unknown attribute should be offered as a new global attribute.' );
	$psai_assert( in_array( 'attribute:psai-material', array_column( $preview['new_items'], 'id' ), true ), 'The new attribute should need a confirmation.' );

	// Not confirmed: becomes a local attribute, nothing is lost.
	$result  = $psai_apply( $generated, $psai_checked( $preview ), array() );
	$product = wc_get_product( $result['product_id'] );
	$psai_assert( $product instanceof WC_Product_Simple && 'draft' === $product->get_status(), 'A draft simple product expected.' );
	$psai_assert( array( 'Blue', 'Green' ) === $psai_term_names( $product->get_id(), 'pa_color' ), 'Colours mismatch.' );
	$local = $product->get_attributes()['psai-material'] ?? null;
	$psai_assert( $local instanceof WC_Product_Attribute && ! $local->is_taxonomy() && array( 'Ceramic' ) === $local->get_options(), 'The unconfirmed attribute should be kept as a local attribute.' );
	$psai_assert( '0.9' === $product->get_weight(), 'Weight mismatch.' );
	$psai_delete_product( $product->get_id() );

	// Confirmed: a new global attribute with a registered taxonomy and a term.
	$generated = $psai_generate( 'russian', 'russian' );
	$result    = $psai_apply( $generated, $psai_checked( $generated['preview'] ), array( 'attribute:psai-material' ) );
	$product   = wc_get_product( $result['product_id'] );
	$psai_assert( $product instanceof WC_Product, 'The product should exist.' );
	$psai_assert( in_array( 'psai-material', $psai_attribute_names(), true ), 'The global attribute should be created.' );
	$psai_assert( taxonomy_exists( 'pa_psai-material' ), 'The taxonomy should be registered.' );
	$psai_assert( array( 'Ceramic' ) === $psai_term_names( $product->get_id(), 'pa_psai-material' ), 'The new term should be assigned.' );
	$psai_delete_product( $product->get_id() );
	return 'pa_color mapped, local and new global attribute paths';
};

$psai_scenarios['(d) edit an existing product'] = static function () use ( $psai_generate, $psai_checked, $psai_field, $psai_apply, $psai_assert, $psai_delete_product, &$psai_products, &$psai_ids ): string {
	// The variable product from (b2) owns the t-shirt SKUs; remove it so they can be reused.
	if ( ! empty( $psai_ids['variable'] ) ) {
		$psai_delete_product( (int) $psai_ids['variable'] );
	}

	$clothing = get_term_by( 'name', 'Clothing', 'product_cat' );
	$existing = new WC_Product_Simple();
	$existing->set_name( 'PSAI Existing' );
	$existing->set_status( 'publish' );
	$existing->set_description( '<p>Old description</p>' );
	$existing->set_regular_price( '10.00' );
	$existing->set_sku( 'PSAI-EXIST-1' );
	if ( $clothing instanceof WP_Term ) {
		$existing->set_category_ids( array( $clothing->term_id ) );
	}
	$existing_id     = $existing->save();
	$psai_products[] = $existing_id;

	// The mug from (a) still owns PSAI-MUG-001, so the SKU of this edit must be blocked.
	$generated = $psai_generate( 'simple', 'simple', $existing_id );
	$preview   = $generated['preview'];
	$psai_assert( false === $preview['is_new'] && $existing_id === $preview['product_id'], 'The preview should be for the existing product.' );
	$name = $psai_field( $preview, 'name' );
	$psai_assert( 'PSAI Existing' === $name['current'] && 'PSAI Ceramic Mug 350 ml' === $name['proposed'] && true === $name['checked'], 'Name current/proposed mismatch.' );
	$price = $psai_field( $preview, 'regular_price' );
	$psai_assert( '10.00' === $price['current'] && false === $price['checked'], 'Price should show the current value and stay unchecked.' );
	$desc = $psai_field( $preview, 'description' );
	$psai_assert( 'Old description' === $desc['current'], 'The current description should be plain text.' );
	$sku = $psai_field( $preview, 'sku' );
	$psai_assert( true === $sku['disabled'] && false === $sku['checked'] && '' !== $sku['note'], 'A SKU used by another product should be disabled with a note.' );

	$result  = $psai_apply( $generated, array( 'name', 'short_description' ), array() );
	$product = wc_get_product( $existing_id );
	$psai_assert( $product instanceof WC_Product_Simple && false === $result['created'], 'The existing product should be updated.' );
	$psai_assert( 'PSAI Ceramic Mug 350 ml' === $product->get_name() && '' !== $product->get_short_description(), 'Checked fields should change.' );
	$psai_assert( '<p>Old description</p>' === $product->get_description(), 'Unchecked description must stay.' );
	$psai_assert( '10.00' === $product->get_regular_price() && 'PSAI-EXIST-1' === $product->get_sku(), 'Unchecked price and SKU must stay.' );
	$psai_assert( 'publish' === $product->get_status(), 'The status of an existing product must not change.' );
	if ( $clothing instanceof WP_Term ) {
		$psai_assert( array( $clothing->term_id ) === array_map( 'intval', $product->get_category_ids() ), 'Unchecked categories must stay.' );
	}

	// A forced SKU is still refused at apply time; prices follow the sale price rule.
	$generated = $psai_generate( 'simple', 'simple', $existing_id );
	$result    = $psai_apply( $generated, array( 'sku', 'regular_price', 'sale_price' ), array() );
	$product   = wc_get_product( $existing_id );
	$psai_assert( 'PSAI-EXIST-1' === $product->get_sku(), 'A duplicate SKU must not be set.' );
	$psai_assert( '12.50' === $product->get_regular_price() && '9.90' === $product->get_sale_price(), 'Checked prices should change.' );
	$psai_assert( array() !== $result['notes'], 'The refused SKU should be explained.' );

	// A simple product becomes variable; applying twice updates the variations instead of duplicating them.
	$confirm = array( 'term:pa_color:Purple', 'term:pa_size:XL' );
	foreach ( array( 1, 2 ) as $round ) {
		$generated = $psai_generate( 'variable', 'variable', $existing_id );
		$psai_apply( $generated, array( 'attributes' ), $confirm );
		$product = wc_get_product( $existing_id );
		$psai_assert( $product instanceof WC_Product_Variable, 'The product should be converted to variable (round ' . $round . ').' );
		$psai_assert( 6 === count( $product->get_children() ), 'Six variations expected in round ' . $round . ', got ' . count( $product->get_children() ) );
		$psai_assert( 'publish' === $product->get_status(), 'The status must stay published.' );
	}
	return 'unchecked fields kept, SKU guarded, converted to variable, no duplicate variations';
};

$psai_scenarios['(e) broken answer, no key'] = static function () use ( $psai_generate, $psai_assert, &$psai_reports, &$psai_state, &$psai_urls ): string {
	try {
		$psai_generate( 'simple', 'broken' );
		throw new RuntimeException( 'A broken answer should raise ParseException.' );
	} catch ( ParseException $e ) {
		$psai_assert( 'schema' === $e->error_code(), 'The parse error code should be schema.' );
	}
	$last = end( $psai_reports );
	$psai_assert( is_array( $last ) && 'schema' === $last['status'] && 1480 === $last['input_tokens'], 'The request_complete action should report status schema with the token usage.' );
	$psai_assert( ! isset( $last['api_key'] ) && false === strpos( (string) wp_json_encode( $last ), 'sheet' ), 'The report must not contain the key or the sheet text.' );

	$requests          = count( $psai_urls );
	$psai_state['key'] = '';
	try {
		$psai_generate( 'simple', 'simple' );
		throw new RuntimeException( 'A missing key should raise ProviderException.' );
	} catch ( ProviderException $e ) {
		$psai_assert( 'no_key' === $e->error_code(), 'The error code should be no_key.' );
	} finally {
		$psai_state['key'] = 'sk-ant-INTEGRATION-FAKE-KEY';
	}
	$psai_assert( count( $psai_urls ) === $requests, 'No HTTP request should be made without a key.' );
	$last = end( $psai_reports );
	$psai_assert( is_array( $last ) && 'no_key' === $last['status'], 'The failure should be reported with status no_key.' );

	foreach ( array( '', str_repeat( 'x', 60001 ) ) as $bad ) {
		try {
			Generator::generate( $bad );
			throw new RuntimeException( 'An empty or oversized sheet should be refused.' );
		} catch ( InvalidArgumentException $e ) {
			$psai_assert( '' !== $e->getMessage(), 'The refusal needs a message.' );
		}
	}
	return 'schema and no_key reported, sheet limits enforced';
};

// --- Run ---------------------------------------------------------------------------------------------

$psai_failed = 0;
foreach ( $psai_scenarios as $psai_name => $psai_run ) {
	try {
		$psai_summary = $psai_run();
		WP_CLI::log( 'PASS ' . $psai_name . ': ' . $psai_summary );
	} catch ( Throwable $psai_error ) {
		++$psai_failed;
		WP_CLI::log( 'FAIL ' . $psai_name . ': ' . $psai_error->getMessage() . ' (' . basename( $psai_error->getFile() ) . ':' . $psai_error->getLine() . ')' );
	}
}

// --- (f) leaks and requests ------------------------------------------------------------------------------

try {
	global $wpdb;
	foreach ( $psai_urls as $psai_url ) {
		if ( 0 !== strpos( $psai_url, 'https://api.anthropic.com/v1/messages' ) ) {
			throw new RuntimeException( 'Unexpected outgoing request: ' . $psai_url );
		}
	}
	if ( array() !== $psai_problems ) {
		throw new RuntimeException( implode( ' | ', array_unique( $psai_problems ) ) );
	}
	if ( array() === $psai_urls ) {
		throw new RuntimeException( 'No request reached the mock.' );
	}
	$leaks = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_value LIKE %s", '%' . $wpdb->esc_like( $psai_fake_key ) . '%' ) );
	if ( array() !== $leaks ) {
		throw new RuntimeException( 'The key was found in options or transients: ' . implode( ', ', $leaks ) );
	}
	if ( false !== strpos( (string) wp_json_encode( $psai_reports ), $psai_fake_key ) ) {
		throw new RuntimeException( 'The key was found in a request_complete payload.' );
	}
	$log = WP_CONTENT_DIR . '/debug.log';
	if ( is_readable( $log ) ) {
		$size = (int) filesize( $log );
		$tail = (string) file_get_contents( $log, false, null, max( 0, $size - 2000000 ) );
		if ( false !== strpos( $tail, $psai_fake_key ) ) {
			throw new RuntimeException( 'The key was found in debug.log.' );
		}
	}
	WP_CLI::log( 'PASS (f) leaks: ' . count( $psai_urls ) . ' requests, all to api.anthropic.com; key not in options, transients, reports or debug.log' );
} catch ( Throwable $psai_error ) {
	++$psai_failed;
	WP_CLI::log( 'FAIL (f) leaks: ' . $psai_error->getMessage() );
}

// --- Cleanup: products, terms, attributes ----------------------------------------------------------------

try {
	foreach ( array_unique( $psai_products ) as $psai_id ) {
		$psai_delete_product( (int) $psai_id );
	}
	$psai_sweep_products();

	foreach ( $psai_taxonomies as $psai_taxonomy ) {
		$now = get_terms(
			array(
				'taxonomy'   => $psai_taxonomy,
				'hide_empty' => false,
				'fields'     => 'ids',
			)
		);
		foreach ( is_array( $now ) ? $now : array() as $psai_term_id ) {
			if ( ! in_array( $psai_term_id, (array) $psai_before[ $psai_taxonomy ], true ) ) {
				wp_delete_term( (int) $psai_term_id, $psai_taxonomy );
			}
		}
	}
	foreach ( $psai_attribute_names() as $psai_attribute_id => $psai_attribute_slug ) {
		if ( ! isset( $psai_before_attributes[ $psai_attribute_id ] ) ) {
			wc_delete_attribute( $psai_attribute_id );
		}
	}
	delete_transient( 'wc_attribute_taxonomies' );

	$left = $psai_sweep_products();
	if ( 0 !== $left ) {
		throw new RuntimeException( 'Test products were left behind.' );
	}
	$psai_after_attributes = $psai_attribute_names();
	if ( $psai_before_attributes !== $psai_after_attributes ) {
		throw new RuntimeException( 'Test attributes were left behind.' );
	}
	WP_CLI::log( 'PASS cleanup: products, terms and attributes removed' );
} catch ( Throwable $psai_error ) {
	++$psai_failed;
	WP_CLI::log( 'FAIL cleanup: ' . $psai_error->getMessage() );
}

if ( $psai_failed > 0 ) {
	WP_CLI::error( $psai_failed . ' integration check(s) failed.' );
}
WP_CLI::success( 'All integration scenarios passed.' );
