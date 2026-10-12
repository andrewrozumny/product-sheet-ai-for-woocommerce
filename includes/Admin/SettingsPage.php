<?php
/**
 * Settings page under the WooCommerce menu.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Admin;

use ProductSheetAI\RequestLog;
use ProductSheetAI\Schema;
use ProductSheetAI\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders WooCommerce > Product Sheet AI.
 */
final class SettingsPage {

	public const SLUG = 'product-sheet-ai';

	public const GROUP = 'product_sheet_ai_settings_group';

	private const SECTION = 'product_sheet_ai_main';

	/**
	 * Page hook suffix returned by add_submenu_page().
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_' . RequestLog::CLEAR_ACTION, array( $this, 'handle_clear_log' ) );
	}

	/**
	 * Clear the request log (admin-post).
	 */
	public function handle_clear_log(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'product-sheet-ai-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( RequestLog::CLEAR_ACTION );
		RequestLog::clear();
		wp_safe_redirect(
			add_query_arg(
				array(
					'tab'     => 'log',
					'cleared' => '1',
				),
				admin_url( 'admin.php?page=' . self::SLUG )
			)
		);
		exit;
	}

	/**
	 * Add the submenu under WooCommerce.
	 */
	public function add_menu(): void {
		$hook = add_submenu_page(
			'woocommerce',
			__( 'Product Sheet AI', 'product-sheet-ai-for-woocommerce' ),
			__( 'Product Sheet AI', 'product-sheet-ai-for-woocommerce' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
		if ( is_string( $hook ) ) {
			$this->hook_suffix = $hook;
		}
	}

	/**
	 * Enqueue assets, only on our page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue( string $hook ): void {
		if ( '' === $this->hook_suffix || $hook !== $this->hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'product-sheet-ai-admin', PRODUCT_SHEET_AI_URL . 'resources/admin.css', array(), PRODUCT_SHEET_AI_VERSION );
	}

	/**
	 * Register the option, the section and the fields.
	 */
	public function register_settings(): void {
		Settings::ensure_option();

		register_setting(
			self::GROUP,
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( Settings::class, 'sanitize' ),
				'default'           => Settings::defaults(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section( self::SECTION, '', '__return_empty_string', self::SLUG );

		$fields = array(
			'provider'         => __( 'AI provider', 'product-sheet-ai-for-woocommerce' ),
			'api_key'          => __( 'API key', 'product-sheet-ai-for-woocommerce' ),
			'model'            => __( 'Model', 'product-sheet-ai-for-woocommerce' ),
			'endpoint'         => __( 'Endpoint', 'product-sheet-ai-for-woocommerce' ),
			'store_language'   => __( 'Store language', 'product-sheet-ai-for-woocommerce' ),
			'tone'             => __( 'Tone of voice', 'product-sheet-ai-for-woocommerce' ),
			'overwrite_fields' => __( 'Fields the AI may overwrite when they already have a value', 'product-sheet-ai-for-woocommerce' ),
		);
		foreach ( $fields as $key => $label ) {
			add_settings_field(
				'product_sheet_ai_' . $key,
				$label,
				array( $this, 'render_field_' . $key ),
				self::SLUG,
				self::SECTION,
				array( 'label_for' => 'product_sheet_ai_' . $key )
			);
		}
	}

	/**
	 * Current tab: 'settings' or 'log'.
	 */
	private function current_tab(): string {
		// Read-only navigation parameter, no state change.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return 'log' === $tab ? 'log' : 'settings';
	}

	/**
	 * Render the page.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$tab  = $this->current_tab();
		$base = admin_url( 'admin.php?page=' . self::SLUG );

		echo '<div class="wrap product-sheet-ai">';
		echo '<h1>' . esc_html__( 'Product Sheet AI', 'product-sheet-ai-for-woocommerce' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';
		printf(
			'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
			esc_url( $base ),
			'settings' === $tab ? ' nav-tab-active' : '',
			esc_html__( 'Settings', 'product-sheet-ai-for-woocommerce' )
		);
		printf(
			'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
			esc_url( add_query_arg( 'tab', 'log', $base ) ),
			'log' === $tab ? ' nav-tab-active' : '',
			esc_html__( 'Request log', 'product-sheet-ai-for-woocommerce' )
		);
		echo '</nav>';

		if ( 'log' === $tab ) {
			$this->render_log_tab();
		} else {
			$this->render_settings_tab();
		}
		echo '</div>';
	}

	/**
	 * Settings tab: a standard Settings API form.
	 */
	private function render_settings_tab(): void {
		echo '<form method="post" action="options.php">';
		settings_fields( self::GROUP );
		do_settings_sections( self::SLUG );
		submit_button();
		echo '</form>';
	}

	/**
	 * Request log tab.
	 */
	public function render_log_tab(): void {
		// Read-only flag set by our own redirect, no state change.
		if ( isset( $_GET['cleared'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'The request log was cleared.', 'product-sheet-ai-for-woocommerce' ) . '</p></div>';
		}
		$entries = RequestLog::entries();
		if ( array() === $entries ) {
			echo '<p>' . esc_html__( 'No requests yet.', 'product-sheet-ai-for-woocommerce' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped product-sheet-ai-log"><thead><tr>';
		$heads = array(
			__( 'When', 'product-sheet-ai-for-woocommerce' ),
			__( 'User', 'product-sheet-ai-for-woocommerce' ),
			__( 'Product', 'product-sheet-ai-for-woocommerce' ),
			__( 'Provider / model', 'product-sheet-ai-for-woocommerce' ),
			__( 'Tokens in / out', 'product-sheet-ai-for-woocommerce' ),
			__( 'Approx. cost', 'product-sheet-ai-for-woocommerce' ),
			__( 'Status', 'product-sheet-ai-for-woocommerce' ),
			__( 'Time', 'product-sheet-ai-for-woocommerce' ),
		);
		foreach ( $heads as $head ) {
			echo '<th scope="col">' . esc_html( $head ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $entries as $entry ) {
			$this->render_log_row( $entry );
		}
		echo '</tbody></table>';

		echo '<p class="description">' . esc_html__( "Cost is an estimate from the provider's list prices. Your provider's invoice is the source of truth.", 'product-sheet-ai-for-woocommerce' ) . '</p>';

		printf(
			'<form method="post" action="%s">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( RequestLog::CLEAR_ACTION ) );
		wp_nonce_field( RequestLog::CLEAR_ACTION );
		submit_button( __( 'Clear log', 'product-sheet-ai-for-woocommerce' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * One row of the log table.
	 *
	 * @param array<string,mixed> $entry Log entry.
	 */
	private function render_log_row( array $entry ): void {
		$time     = isset( $entry['time'] ) && is_numeric( $entry['time'] ) ? (int) $entry['time'] : 0;
		$user_id  = isset( $entry['user_id'] ) && is_numeric( $entry['user_id'] ) ? (int) $entry['user_id'] : 0;
		$product  = isset( $entry['product_id'] ) && is_numeric( $entry['product_id'] ) ? (int) $entry['product_id'] : 0;
		$user     = $user_id > 0 ? get_userdata( $user_id ) : false;
		$cost     = isset( $entry['cost_usd'] ) && is_numeric( $entry['cost_usd'] ) ? (float) $entry['cost_usd'] : null;
		$status   = isset( $entry['status'] ) && is_string( $entry['status'] ) ? $entry['status'] : 'internal';
		$ms       = isset( $entry['duration_ms'] ) && is_numeric( $entry['duration_ms'] ) ? (int) $entry['duration_ms'] : 0;
		$in       = isset( $entry['input_tokens'] ) && is_numeric( $entry['input_tokens'] ) ? (int) $entry['input_tokens'] : 0;
		$out      = isset( $entry['output_tokens'] ) && is_numeric( $entry['output_tokens'] ) ? (int) $entry['output_tokens'] : 0;
		$provider = isset( $entry['provider'] ) && is_string( $entry['provider'] ) ? $entry['provider'] : '';
		$model    = isset( $entry['model'] ) && is_string( $entry['model'] ) ? $entry['model'] : '';

		echo '<tr>';
		$when = $time > 0 ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time ) : '';
		echo '<td>' . esc_html( false === $when ? '' : $when ) . '</td>';
		echo '<td>' . ( $user instanceof \WP_User ? esc_html( $user->display_name ) : '-' ) . '</td>';

		echo '<td>';
		if ( 0 === $product ) {
			echo esc_html__( 'New draft', 'product-sheet-ai-for-woocommerce' );
		} elseif ( 'product' === get_post_type( $product ) ) {
			$title = get_the_title( $product );
			printf(
				'<a href="%1$s">%2$s</a>',
				esc_url( (string) get_edit_post_link( $product, 'raw' ) ),
				esc_html( '' !== $title ? $title : '#' . $product )
			);
		} else {
			/* translators: %d: product ID. */
			echo esc_html( sprintf( __( 'Product #%d (deleted)', 'product-sheet-ai-for-woocommerce' ), $product ) );
		}
		echo '</td>';

		echo '<td>' . esc_html( trim( $provider . ' / ' . $model, ' /' ) ) . '</td>';
		echo '<td>' . esc_html( number_format_i18n( $in ) . ' / ' . number_format_i18n( $out ) ) . '</td>';
		echo '<td>' . esc_html( null === $cost ? __( 'unknown', 'product-sheet-ai-for-woocommerce' ) : '$' . number_format_i18n( $cost, 4 ) ) . '</td>';
		echo '<td>' . esc_html( self::status_label( $status ) ) . '</td>';
		echo '<td>' . esc_html( number_format_i18n( $ms / 1000, 1 ) . ' s' ) . '</td>';
		echo '</tr>';
	}

	/**
	 * Readable label for a log status.
	 *
	 * @param string $status 'ok', a provider or parser error code, or 'internal'.
	 */
	public static function status_label( string $status ): string {
		$labels = array(
			'ok'          => __( 'OK', 'product-sheet-ai-for-woocommerce' ),
			'no_key'      => __( 'No API key', 'product-sheet-ai-for-woocommerce' ),
			'auth'        => __( 'Key not accepted', 'product-sheet-ai-for-woocommerce' ),
			'permission'  => __( 'Not permitted', 'product-sheet-ai-for-woocommerce' ),
			'rate_limit'  => __( 'Rate limited', 'product-sheet-ai-for-woocommerce' ),
			'overloaded'  => __( 'Provider overloaded', 'product-sheet-ai-for-woocommerce' ),
			'timeout'     => __( 'Timed out', 'product-sheet-ai-for-woocommerce' ),
			'bad_request' => __( 'Request rejected', 'product-sheet-ai-for-woocommerce' ),
			'too_large'   => __( 'Sheet too large', 'product-sheet-ai-for-woocommerce' ),
			'refusal'     => __( 'Model declined', 'product-sheet-ai-for-woocommerce' ),
			'truncated'   => __( 'Answer cut off', 'product-sheet-ai-for-woocommerce' ),
			'http'        => __( 'Connection error', 'product-sheet-ai-for-woocommerce' ),
			'empty'       => __( 'Empty answer', 'product-sheet-ai-for-woocommerce' ),
			'schema'      => __( 'Unreadable answer', 'product-sheet-ai-for-woocommerce' ),
			'internal'    => __( 'Internal error', 'product-sheet-ai-for-woocommerce' ),
		);
		return $labels[ $status ] ?? __( 'Error', 'product-sheet-ai-for-woocommerce' );
	}

	/**
	 * Field name attribute for the option array.
	 *
	 * @param string $key Setting key.
	 */
	private function name( string $key ): string {
		return Settings::OPTION . '[' . $key . ']';
	}

	/**
	 * Provider select.
	 */
	public function render_field_provider(): void {
		$current = Settings::get()['provider'];
		$options = array(
			'anthropic'         => __( 'Anthropic (Claude)', 'product-sheet-ai-for-woocommerce' ),
			'openai_compatible' => __( 'OpenAI-compatible', 'product-sheet-ai-for-woocommerce' ),
		);
		echo '<select id="product_sheet_ai_provider" name="' . esc_attr( $this->name( 'provider' ) ) . '">';
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	/**
	 * API key password input. The saved key is never printed.
	 */
	public function render_field_api_key(): void {
		$masked = Settings::masked_key();
		printf(
			'<input type="password" id="product_sheet_ai_api_key" class="regular-text" name="%s" value="" autocomplete="new-password" spellcheck="false" />',
			esc_attr( $this->name( 'api_key' ) )
		);
		if ( Settings::has_key() ) {
			$tail = '' !== $masked ? substr( $masked, -4 ) : '';
			echo '<p class="description">';
			if ( '' !== $tail ) {
				/* translators: %s: last four characters of the saved API key. */
				printf( esc_html__( 'A key is saved, ending in %s. Leave this field empty to keep it.', 'product-sheet-ai-for-woocommerce' ), '<code>' . esc_html( $tail ) . '</code>' );
			} else {
				esc_html_e( 'A key is saved. Leave this field empty to keep it.', 'product-sheet-ai-for-woocommerce' );
			}
			echo '</p>';
			printf(
				'<p><label><input type="checkbox" name="%1$s" value="1" /> %2$s</label></p>',
				esc_attr( $this->name( 'remove_key' ) ),
				esc_html__( 'Remove saved key', 'product-sheet-ai-for-woocommerce' )
			);
		}
		echo '<p class="description">' . esc_html__( 'The key is stored in your site database and is sent only to the provider you choose above.', 'product-sheet-ai-for-woocommerce' ) . '</p>';
	}

	/**
	 * Model text input.
	 */
	public function render_field_model(): void {
		printf(
			'<input type="text" id="product_sheet_ai_model" class="regular-text" name="%1$s" value="%2$s" />',
			esc_attr( $this->name( 'model' ) ),
			esc_attr( Settings::get()['model'] )
		);
		echo '<p class="description">' . esc_html(
			sprintf(
				/* translators: 1: default Anthropic model, 2: default OpenAI-compatible model. */
				__( 'Default: %1$s for Anthropic, %2$s for OpenAI-compatible providers.', 'product-sheet-ai-for-woocommerce' ),
				Settings::DEFAULT_ANTHROPIC_MODEL,
				Settings::DEFAULT_OPENAI_MODEL
			)
		) . '</p>';
	}

	/**
	 * Endpoint input, used by OpenAI-compatible providers.
	 */
	public function render_field_endpoint(): void {
		printf(
			'<input type="url" id="product_sheet_ai_endpoint" class="regular-text" name="%1$s" value="%2$s" />',
			esc_attr( $this->name( 'endpoint' ) ),
			esc_attr( Settings::get()['endpoint'] )
		);
		echo '<p class="description">' . esc_html__( 'Only used for OpenAI-compatible providers. Base URL without /chat/completions. It must start with https://.', 'product-sheet-ai-for-woocommerce' ) . '</p>';
	}

	/**
	 * Store language input.
	 */
	public function render_field_store_language(): void {
		printf(
			'<input type="text" id="product_sheet_ai_store_language" class="regular-text" maxlength="40" name="%1$s" value="%2$s" />',
			esc_attr( $this->name( 'store_language' ) ),
			esc_attr( Settings::get()['store_language'] )
		);
		echo '<p class="description">' . esc_html__( 'The language all generated product text is written in, for example English or Ukrainian.', 'product-sheet-ai-for-woocommerce' ) . '</p>';
	}

	/**
	 * Tone presets as radio buttons with a one-line explanation each.
	 */
	public function render_field_tone(): void {
		$current = Settings::get()['tone'];
		$tones   = array(
			'neutral'  => array( __( 'Neutral', 'product-sheet-ai-for-woocommerce' ), __( 'Plain, factual and clear. No hype.', 'product-sheet-ai-for-woocommerce' ) ),
			'friendly' => array( __( 'Friendly', 'product-sheet-ai-for-woocommerce' ), __( 'Warm and conversational, speaks to the buyer directly.', 'product-sheet-ai-for-woocommerce' ) ),
			'premium'  => array( __( 'Premium', 'product-sheet-ai-for-woocommerce' ), __( 'Refined and confident, focused on quality and craftsmanship.', 'product-sheet-ai-for-woocommerce' ) ),
		);
		echo '<fieldset id="product_sheet_ai_tone">';
		foreach ( $tones as $value => $texts ) {
			printf(
				'<p><label><input type="radio" name="%1$s" value="%2$s"%3$s /> <strong>%4$s</strong></label><br /><span class="description">%5$s</span></p>',
				esc_attr( $this->name( 'tone' ) ),
				esc_attr( $value ),
				checked( $current, $value, false ),
				esc_html( $texts[0] ),
				esc_html( $texts[1] )
			);
		}
		echo '</fieldset>';
	}

	/**
	 * Overwrite checkboxes over the schema field keys.
	 */
	public function render_field_overwrite_fields(): void {
		$selected = Settings::get()['overwrite_fields'];
		echo '<fieldset id="product_sheet_ai_overwrite_fields">';
		// Posted first so that unchecking every box is saved as an empty list.
		printf( '<input type="hidden" name="%s[]" value="" />', esc_attr( $this->name( 'overwrite_fields' ) ) );
		foreach ( Schema::labels() as $key => $label ) {
			printf(
				'<label class="product-sheet-ai-check"><input type="checkbox" name="%1$s[]" value="%2$s"%3$s /> %4$s</label>',
				esc_attr( $this->name( 'overwrite_fields' ) ),
				esc_attr( $key ),
				checked( in_array( $key, $selected, true ), true, false ),
				esc_html( $label )
			);
		}
		echo '<p class="description">' . esc_html__( 'Fields that are empty are always filled. Checked fields are also replaced when they already have a value. You still see a preview before anything is saved.', 'product-sheet-ai-for-woocommerce' ) . '</p>';
		echo '</fieldset>';
	}
}
