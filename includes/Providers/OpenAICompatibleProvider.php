<?php
/**
 * OpenAI-compatible chat completions provider.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Talks to any OpenAI-compatible /chat/completions endpoint with structured outputs.
 */
final class OpenAICompatibleProvider implements ProviderInterface {

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
	 * Base URL, https only.
	 *
	 * @var string
	 */
	private string $endpoint;

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
	 * @param string             $endpoint  Base URL, for example https://api.openai.com/v1.
	 * @param HttpTransport|null $transport Transport.
	 */
	public function __construct( string $api_key, string $model, string $endpoint, ?HttpTransport $transport = null ) {
		$this->api_key   = $api_key;
		$this->model     = $model;
		$this->endpoint  = rtrim( trim( $endpoint ), '/' );
		$this->transport = $transport ?? new HttpTransport();
	}

	/**
	 * Provider id.
	 */
	public function id(): string {
		return 'openai_compatible';
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
		if ( 0 !== stripos( $this->endpoint, 'https://' ) ) {
			throw ProviderException::for_code( 'bad_request', 'The endpoint must start with https://' );
		}

		$body = wp_json_encode(
			array(
				'model'                 => $this->model,
				'messages'              => array(
					array(
						'role'    => 'system',
						'content' => $request->system,
					),
					array(
						'role'    => 'user',
						'content' => $request->user,
					),
				),
				'response_format'       => array(
					'type'        => 'json_schema',
					'json_schema' => array(
						'name'   => $request->schema_name,
						'strict' => true,
						'schema' => $request->schema,
					),
				),
				'max_completion_tokens' => $request->max_tokens,
			)
		);
		if ( ! is_string( $body ) ) {
			throw ProviderException::for_code( 'http' );
		}

		$started  = microtime( true );
		$response = $this->transport->post(
			$this->endpoint . '/chat/completions',
			array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
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

		$choice = isset( $data['choices'][0] ) && is_array( $data['choices'][0] ) ? $data['choices'][0] : array();
		$finish = isset( $choice['finish_reason'] ) && is_string( $choice['finish_reason'] ) ? $choice['finish_reason'] : '';
		if ( 'length' === $finish ) {
			throw ProviderException::for_code( 'truncated' );
		}
		if ( 'content_filter' === $finish ) {
			throw ProviderException::for_code( 'refusal' );
		}

		$message = isset( $choice['message'] ) && is_array( $choice['message'] ) ? $choice['message'] : array();
		if ( isset( $message['refusal'] ) && is_string( $message['refusal'] ) && '' !== trim( $message['refusal'] ) ) {
			throw ProviderException::for_code( 'refusal' );
		}
		$text = isset( $message['content'] ) && is_string( $message['content'] ) ? $message['content'] : '';
		if ( '' === trim( $text ) ) {
			throw ProviderException::for_code( 'empty' );
		}

		$usage = isset( $data['usage'] ) && is_array( $data['usage'] ) ? $data['usage'] : array();

		return new CompletionResult(
			$text,
			(int) ( $usage['prompt_tokens'] ?? 0 ),
			(int) ( $usage['completion_tokens'] ?? 0 ),
			isset( $data['model'] ) && is_string( $data['model'] ) ? $data['model'] : $this->model,
			$finish,
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
		$message = isset( $error['message'] ) && is_string( $error['message'] ) ? $error['message'] : '';

		switch ( $status ) {
			case 401:
				return ProviderException::for_code( 'auth' );
			case 403:
				return ProviderException::for_code( 'permission' );
			case 429:
				return ProviderException::for_code( 'rate_limit' );
			case 503:
			case 529:
				return ProviderException::for_code( 'overloaded' );
			case 413:
				return ProviderException::for_code( 'too_large' );
			case 400:
			case 404:
			case 422:
				return ProviderException::for_code( 'bad_request', $message, $this->api_key );
			default:
				return ProviderException::for_code( 'http' );
		}
	}
}
