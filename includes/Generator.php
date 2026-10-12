<?php
/**
 * Orchestrates one generation: prompt, provider, parser, preview, stored result.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI;

use ProductSheetAI\Mapping\PreviewBuilder;
use ProductSheetAI\Providers\CompletionRequest;
use ProductSheetAI\Providers\ProviderException;
use ProductSheetAI\Providers\ProviderFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs a generation and stores the parsed result for the apply step.
 */
final class Generator {

	public const TRANSIENT_PREFIX = 'product_sheet_ai_preview_';

	public const MAX_SHEET_CHARS = 60000;

	/**
	 * Generate product data from a sheet.
	 *
	 * @param string $sheet_text Text of the sheet.
	 * @param int    $product_id Product to update, 0 for a new product.
	 * @param string $source     Where the text came from: paste, txt, csv or pdf.
	 * @return array{token:string,preview:array<string,mixed>}
	 * @throws \InvalidArgumentException When the sheet is empty or too long, or the product does not exist.
	 * @throws ProviderException         When the provider fails.
	 * @throws ParseException            When the answer cannot be read.
	 * @throws \Throwable                Any other failure, after it was reported.
	 */
	public static function generate( string $sheet_text, int $product_id = 0, string $source = 'paste' ): array {
		$sheet_text = trim( $sheet_text );
		$chars      = function_exists( 'mb_strlen' ) ? mb_strlen( $sheet_text ) : strlen( $sheet_text );
		if ( '' === $sheet_text ) {
			throw new \InvalidArgumentException( esc_html__( 'The sheet is empty. Paste or upload some product information first.', 'product-sheet-ai-for-woocommerce' ) );
		}
		if ( $chars > self::MAX_SHEET_CHARS ) {
			throw new \InvalidArgumentException(
				esc_html(
					sprintf(
						/* translators: %s: maximum number of characters. */
						__( 'The sheet is too long (the limit is %s characters). Shorten it or split it into several products.', 'product-sheet-ai-for-woocommerce' ),
						number_format_i18n( self::MAX_SHEET_CHARS )
					)
				)
			);
		}
		if ( $product_id > 0 && ! wc_get_product( $product_id ) instanceof \WC_Product ) {
			throw new \InvalidArgumentException( esc_html__( 'The product to update could not be found.', 'product-sheet-ai-for-woocommerce' ) );
		}

		if ( function_exists( 'wc_set_time_limit' ) ) {
			wc_set_time_limit( 180 );
		}

		$settings = Settings::get();
		$started  = microtime( true );
		$report   = array(
			'user_id'       => get_current_user_id(),
			'product_id'    => $product_id,
			'provider'      => $settings['provider'],
			'model'         => $settings['model'],
			'input_tokens'  => 0,
			'output_tokens' => 0,
			'status'        => 'ok',
			'duration_ms'   => 0,
			'source'        => $source,
			'chars'         => $chars,
		);

		try {
			$prompt   = PromptBuilder::build( $sheet_text, $product_id, $settings );
			$provider = ProviderFactory::make( $settings );
			$result   = $provider->complete(
				new CompletionRequest( $prompt['system'], $prompt['user'], Schema::json_schema(), 'product_sheet', 16000 )
			);

			$report['model']         = '' !== $result->model ? $result->model : $report['model'];
			$report['input_tokens']  = $result->input_tokens;
			$report['output_tokens'] = $result->output_tokens;
			$report['duration_ms']   = $result->duration_ms;

			$data    = ResponseParser::parse( $result->text );
			$preview = PreviewBuilder::build(
				$data,
				$product_id,
				array(
					'input_tokens'  => $result->input_tokens,
					'output_tokens' => $result->output_tokens,
					'model'         => $report['model'],
				)
			);

			$token = wp_generate_password( 32, false, false );
			set_transient(
				self::TRANSIENT_PREFIX . $token,
				array(
					'user_id'    => get_current_user_id(),
					'product_id' => $product_id,
					'data'       => $data,
					'created'    => time(),
				),
				HOUR_IN_SECONDS
			);
		} catch ( ProviderException $e ) {
			self::report( $report, $e->error_code(), $started );
			throw $e;
		} catch ( ParseException $e ) {
			self::report( $report, $e->error_code(), $started );
			throw $e;
		} catch ( \Throwable $e ) {
			self::report( $report, 'internal', $started );
			throw $e;
		}

		self::report( $report, 'ok', $started );
		return array(
			'token'   => $token,
			'preview' => $preview,
		);
	}

	/**
	 * Fire the completion action. The payload never contains the key or the sheet text.
	 *
	 * @param array<string,mixed> $report  Report so far.
	 * @param string              $status  'ok' or an error code.
	 * @param float               $started Start time from microtime(true).
	 */
	private static function report( array $report, string $status, float $started ): void {
		$report['status'] = $status;
		if ( 0 === (int) $report['duration_ms'] ) {
			$report['duration_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );
		}
		/**
		 * Fires after every generation attempt, successful or not.
		 *
		 * @param array<string,mixed> $report user_id, product_id, provider, model, input_tokens, output_tokens, status, duration_ms, source, chars.
		 */
		do_action( 'product_sheet_ai_request_complete', $report );
	}
}
