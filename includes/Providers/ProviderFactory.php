<?php
/**
 * Builds the provider from settings.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chooses and builds the provider.
 */
final class ProviderFactory {

	/**
	 * Build the provider for the given settings.
	 *
	 * The filter product_sheet_ai_provider may replace the provider, for example with a mock in tests.
	 *
	 * @param array<string,mixed> $settings Settings from Settings::get().
	 * @throws ProviderException When no provider can be built (no_key).
	 */
	public static function make( array $settings ): ProviderInterface {
		$key      = isset( $settings['api_key'] ) && is_string( $settings['api_key'] ) ? $settings['api_key'] : '';
		$provider = null;

		if ( '' !== $key ) {
			$model = isset( $settings['model'] ) && is_string( $settings['model'] ) ? trim( $settings['model'] ) : '';
			if ( isset( $settings['provider'] ) && 'openai_compatible' === $settings['provider'] ) {
				$endpoint = isset( $settings['endpoint'] ) && is_string( $settings['endpoint'] ) ? $settings['endpoint'] : '';
				$provider = new OpenAICompatibleProvider( $key, '' !== $model ? $model : 'gpt-4o-mini', $endpoint );
			} else {
				$provider = new AnthropicProvider( $key, '' !== $model ? $model : 'claude-sonnet-5-5' );
			}
		}

		/**
		 * Filters the provider. Return a ProviderInterface to replace it.
		 *
		 * @param ProviderInterface|null $provider Built provider, or null when no key is saved.
		 * @param array<string,mixed>    $settings Settings.
		 */
		$provider = apply_filters( 'product_sheet_ai_provider', $provider, $settings );

		if ( ! $provider instanceof ProviderInterface ) {
			throw ProviderException::for_code( 'no_key' );
		}
		return $provider;
	}
}
