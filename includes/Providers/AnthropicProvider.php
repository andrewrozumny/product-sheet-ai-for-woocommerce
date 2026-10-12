<?php
/**
 * Anthropic Messages API provider.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Talks to the Anthropic Messages API with structured outputs.
 */
final class AnthropicProvider implements ProviderInterface {

	public const URL = 'https://api.anthropic.com/v1/messages';

	/**
	 * API key. Never printed, logged or put into messages.
	 *
	 * @var string
	 */
	private string $api_key;

	/**
	 * Model id.
	 *
	 * @var string
	 */
	private string $model;

	/**
	 * Transport.
	 *
	 * @var HttpTransport
	 */
	private HttpTransport $transport;

	/**
	 * Constructor.
	 *
	 * @param string             $api_key   API key.
	 * @param string             $model     Model id.
	 * @param HttpTransport|null $transport Transport.
	 */
	public function __construct( string $api_key, string $model, ?HttpTransport $transport = null ) {
		$this->api_key   = $api_key;
		$this->model     = $model;
		$this->transport = $transport ?? new HttpTransport();
	}

	/**
	 * Provider id.
	 */
	public function id(): string {
		return 'anthropic';
	}

	/**
	 * Run one completion.
	 *
	 * @param CompletionRequest $request Request.
	 * @throws ProviderException On any failure.
	 */
	public function complete( CompletionRequest $request ): CompletionResult {
		if ( '' === $this->api_key ) {
			throw ProviderException::for_code( 'no_key' );
		}

		/**
		 * Filters the effort level sent to Anthropic.
		 *
		 * @param string $effort One of low, medium, high.
		 */
		$effort = apply_filters( 'product_sheet_ai_anthropic_effort', 'low' );

		$body = wp_json_encode(
			array(
				'model'         => $this->model,
				'max_tokens'    => $request->max_tokens,
				'system'        => $request->system,
				'messages'      => array(
					array(
						'role'    => 'user',
						'content' => $request->user,
					),
				),
				'output_config' => array(
					'effort' => is_string( $effort ) && '' !== $effort ? $effort : 'low',
					'format' => array(
						'type'   => 'json_schema',
						'schema' => $request->schema,
					),
				),
			)
		);
		if ( ! is_string( $body ) ) {
			throw ProviderException::for_code( 'http' );
		}

		$started  = microtime( true );
		$response = $this->transport->post(
			self::URL,
			array(
				'x-api-key'         => $this->api_key,
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			),
			$body
		);
		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

		$data = json_decode( $response['body'], true );
		$data = is_array( $data ) ? $data : array();

		if ( $response['status'] >= 400 || 0 === $response['status'] ) {
			$failure = $this->error_for( $response['status'], $data );
			throw $failure;
		}

		$stop_reason = isset( $data['stop_reason'] ) && is_string( $data['stop_reason'] ) ? $data['stop_reason'] : '';
		if ( 'refusal' === $stop_reason ) {
			throw ProviderException::for_code( 'refusal' );
		}
		if ( 'max_tokens' === $stop_reason ) {
			throw ProviderException::for_code( 'truncated' );
		}

		$text = '';
		if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) && is_string( $block['text'] ?? null ) ) {
					$text .= $block['text'];
				}
			}
		}
		if ( '' === trim( $text ) ) {
			throw ProviderException::for_code( 'empty' );
		}

		$usage = isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array();

		return new CompletionResult(
			$text,
			(int) ( $usage['input_tokens'] ?? 0 ),
			(int) ( $usage['output_tokens'] ?? 0 ),
			isset( $data['model'] ) && is_string( $data['model'] ) ? $data['model'] : $this->model,
			$stop_reason,
			$duration
		);
	}

	/**
	 * Map an error response to an exception.
	 *
	 * @param int                $status HTTP status.
	 * @param array<mixed,mixed> $data   Decoded body.
	 */
	private function error_for( int $status, array $data ): ProviderException {
		$error   = isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array();
		$type    = isset( $error['type'] ) && is_string( $error['type'] ) ? $error['type'] : '';
		$message = isset( $error['message'] ) && is_string( $error['message'] ) ? $error['message'] : '';

		switch ( true ) {
			case 401 === $status || 'authentication_error' === $type:
				return ProviderException::for_code( 'auth' );
			case 403 === $status || 'permission_error' === $type:
				return ProviderException::for_code( 'permission' );
			case 429 === $status || 'rate_limit_error' === $type:
				return ProviderException::for_code( 'rate_limit' );
			case 529 === $status || 'overloaded_error' === $type:
				return ProviderException::for_code( 'overloaded' );
			case 413 === $status:
				return ProviderException::for_code( 'too_large' );
			case 400 === $status || 'invalid_request_error' === $type:
				return ProviderException::for_code( 'bad_request', $message, $this->api_key );
			default:
				return ProviderException::for_code( 'http' );
		}
	}
}
