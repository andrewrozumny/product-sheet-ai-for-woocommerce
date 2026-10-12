<?php
/**
 * AnthropicProvider tests.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Tests\Unit\Providers;

use Brain\Monkey\Filters;
use ProductSheetAI\Providers\AnthropicProvider;
use ProductSheetAI\Providers\ProviderException;

/**
 * Anthropic request shape, parsing and error mapping.
 */
final class AnthropicProviderTest extends ProviderTestCase {

	/**
	 * A successful body.
	 *
	 * @return array<string,mixed>
	 */
	private function ok_body(): array {
		return array(
			'model'       => 'claude-sonnet-5-5',
			'stop_reason' => 'end_turn',
			'content'     => array(
				array(
					'type'     => 'thinking',
					'thinking' => 'ignore me',
				),
				array(
					'type' => 'text',
					'text' => '{"name":',
				),
				array(
					'type' => 'text',
					'text' => '"Mug"}',
				),
			),
			'usage'       => array(
				'input_tokens'  => 120,
				'output_tokens' => 45,
			),
		);
	}

	/**
	 * Build the provider with canned responses.
	 *
	 * @param array<int,mixed> $responses Responses.
	 */
	private function provider( array $responses ): AnthropicProvider {
		return new AnthropicProvider( self::KEY, 'claude-sonnet-5-5', $this->transport( $responses ) );
	}

	/**
	 * URL, headers and body match the contract.
	 */
	public function test_request_shape(): void {
		$result = $this->provider( array( $this->response( 200, $this->ok_body() ) ) )->complete( $this->request() );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://api.anthropic.com/v1/messages', $this->requests[0]['url'] );
		$this->assertSame( 60, $this->requests[0]['args']['timeout'] );

		$headers = $this->requests[0]['args']['headers'];
		$this->assertSame( self::KEY, $headers['x-api-key'] );
		$this->assertSame( '2023-06-01', $headers['anthropic-version'] );
		$this->assertSame( 'application/json', $headers['content-type'] );

		$body = $this->sent_body();
		$this->assertSame( 'claude-sonnet-5-5', $body['model'] );
		$this->assertSame( 16000, $body['max_tokens'] );
		$this->assertSame( 'System prompt', $body['system'] );
		$this->assertSame( array( array( 'role' => 'user', 'content' => 'User prompt' ) ), $body['messages'] ); // phpcs:ignore Universal.Arrays.MultiLineArrayItemTrailingComma,WordPress.Arrays.ArrayDeclarationSpacing
		$this->assertSame( 'low', $body['output_config']['effort'] );
		$this->assertSame( 'json_schema', $body['output_config']['format']['type'] );
		$this->assertSame( 'object', $body['output_config']['format']['schema']['type'] );
		$this->assertArrayNotHasKey( 'temperature', $body );
		$this->assertArrayNotHasKey( 'tool_choice', $body );
		$this->assertArrayNotHasKey( 'tools', $body );
		$this->assertArrayNotHasKey( 'thinking', $body );

		$this->assertSame( '{"name":"Mug"}', $result->text );
		$this->assertSame( 120, $result->input_tokens );
		$this->assertSame( 45, $result->output_tokens );
		$this->assertSame( 'claude-sonnet-5-5', $result->model );
		$this->assertSame( 'end_turn', $result->stop_reason );
		$this->assertGreaterThanOrEqual( 0, $result->duration_ms );
	}

	/**
	 * The effort filter changes the effort.
	 */
	public function test_effort_filter(): void {
		Filters\expectApplied( 'product_sheet_ai_anthropic_effort' )->once()->with( 'low' )->andReturn( 'medium' );
		$this->provider( array( $this->response( 200, $this->ok_body() ) ) )->complete( $this->request() );
		$this->assertSame( 'medium', $this->sent_body()['output_config']['effort'] );
	}

	/**
	 * An empty key fails before any request.
	 */
	public function test_no_key(): void {
		$provider = new AnthropicProvider( '', 'm', $this->transport( array() ) );
		$this->assertSame( 'no_key', $this->code_of( $provider ) );
		$this->assertCount( 0, $this->requests );
	}

	/**
	 * Error status mapping.
	 *
	 * @return array<string,array{0:int,1:string,2:string}>
	 */
	public function error_cases(): array {
		return array(
			'401' => array( 401, 'authentication_error', 'auth' ),
			'403' => array( 403, 'permission_error', 'permission' ),
			'429' => array( 429, 'rate_limit_error', 'rate_limit' ),
			'529' => array( 529, 'overloaded_error', 'overloaded' ),
			'400' => array( 400, 'invalid_request_error', 'bad_request' ),
			'413' => array( 413, 'request_too_large', 'too_large' ),
			'500' => array( 500, 'api_error', 'http' ),
		);
	}

	/**
	 * Each status maps to its code. Retryable statuses return the same error twice.
	 *
	 * @dataProvider error_cases
	 *
	 * @param int    $status HTTP status.
	 * @param string $type   Anthropic error type.
	 * @param string $code   Expected code.
	 */
	public function test_error_mapping( int $status, string $type, string $code ): void {
		$body = array(
			'type'  => 'error',
			'error' => array(
				'type'    => $type,
				'message' => 'Something happened',
			),
		);
		$this->assertSame( $code, $this->code_of( $this->provider( array( $this->response( $status, $body ) ) ) ) );
	}

	/**
	 * The API message is shown for bad_request, with the key removed if echoed.
	 */
	public function test_bad_request_message_is_redacted(): void {
		$body = array(
			'error' => array(
				'type'    => 'invalid_request_error',
				'message' => 'model not found for key ' . self::KEY,
			),
		);
		try {
			$this->provider( array( $this->response( 400, $body ) ) )->complete( $this->request() );
			$this->fail( 'Expected exception' );
		} catch ( ProviderException $e ) {
			$this->assertStringContainsString( 'model not found', $e->getMessage() );
			$this->assertStringNotContainsString( self::KEY, $e->getMessage() );
		}
	}

	/**
	 * Refusal and max_tokens stop reasons.
	 */
	public function test_refusal_and_truncated(): void {
		$refusal                = $this->ok_body();
		$refusal['stop_reason'] = 'refusal';
		$this->assertSame( 'refusal', $this->code_of( $this->provider( array( $this->response( 200, $refusal ) ) ) ) );

		$cut                = $this->ok_body();
		$cut['stop_reason'] = 'max_tokens';
		$this->assertSame( 'truncated', $this->code_of( $this->provider( array( $this->response( 200, $cut ) ) ) ) );
	}

	/**
	 * A body with no text blocks is empty.
	 */
	public function test_empty_answer(): void {
		$body            = $this->ok_body();
		$body['content'] = array(
			array(
				'type'     => 'thinking',
				'thinking' => 'only thoughts',
			),
		);
		$this->assertSame( 'empty', $this->code_of( $this->provider( array( $this->response( 200, $body ) ) ) ) );
	}

	/**
	 * One retry on 529, then success.
	 */
	public function test_retry_once_on_529_then_success(): void {
		$overloaded = $this->response(
			529,
			array(
				'error' => array(
					'type'    => 'overloaded_error',
					'message' => 'busy',
				),
			),
			array( 'retry-after' => '9' )
		); // phpcs:ignore Universal.Arrays.MultiLineArrayItemTrailingComma
		$result     = $this->provider( array( $overloaded, $this->response( 200, $this->ok_body() ) ) )->complete( $this->request() );
		$this->assertSame( '{"name":"Mug"}', $result->text );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( array( 5 ), $this->sleeps, 'Retry-After is capped at 5 seconds.' );
	}

	/**
	 * No second retry: two 529 answers give an overloaded error after two requests.
	 */
	public function test_no_second_retry(): void {
		$overloaded = $this->response(
			529,
			array(
				'error' => array(
					'type'    => 'overloaded_error',
					'message' => 'busy',
				),
			)
		); // phpcs:ignore Universal.Arrays.MultiLineArrayItemTrailingComma
		$this->assertSame( 'overloaded', $this->code_of( $this->provider( array( $overloaded, $overloaded, $this->response( 200, $this->ok_body() ) ) ) ) );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( array( 2 ), $this->sleeps, 'Default wait is 2 seconds.' );
	}

	/**
	 * A WP_Error timeout is retried once, then reported as timeout.
	 */
	public function test_timeout(): void {
		$timeout = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 60001 milliseconds' );
		$this->assertSame( 'timeout', $this->code_of( $this->provider( array( $timeout ) ) ) );
		$this->assertCount( 2, $this->requests );
	}

	/**
	 * A timeout followed by a good answer succeeds.
	 */
	public function test_timeout_then_success(): void {
		$timeout = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		$result  = $this->provider( array( $timeout, $this->response( 200, $this->ok_body() ) ) )->complete( $this->request() );
		$this->assertSame( 120, $result->input_tokens );
	}

	/**
	 * A non-timeout transport error is a plain http error and is not retried.
	 */
	public function test_connection_error(): void {
		$error = new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host' );
		$this->assertSame( 'http', $this->code_of( $this->provider( array( $error ) ) ) );
		$this->assertCount( 1, $this->requests );
	}

	/**
	 * No exception message ever contains the key.
	 */
	public function test_no_message_contains_the_key(): void {
		$leaky = array(
			'error' => array(
				'type'    => 'invalid_request_error',
				'message' => 'bad ' . self::KEY,
			),
		);
		$cases = array(
			$this->response( 401, $leaky ),
			$this->response( 403, $leaky ),
			$this->response( 429, $leaky ),
			$this->response( 529, $leaky ),
			$this->response( 413, $leaky ),
			$this->response( 400, $leaky ),
			$this->response( 500, $leaky ),
			new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' ),
			new \WP_Error( 'http_request_failed', 'cURL error 6: host' ),
		);
		foreach ( $cases as $case ) {
			try {
				$this->provider( array( $case ) )->complete( $this->request() );
				$this->fail( 'Expected exception' );
			} catch ( ProviderException $e ) {
				$this->assertStringNotContainsString( self::KEY, $e->getMessage() );
				$this->assertStringNotContainsString( 'SECRET123', (string) $e );
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
		foreach ( ProviderException::CODES as $code ) {
			$this->assertStringNotContainsString( self::KEY, ProviderException::for_code( $code, 'x ' . self::KEY, self::KEY )->getMessage() );
		}
	}

	/**
	 * Run complete() and return the error code.
	 *
	 * @param AnthropicProvider $provider Provider.
	 */
	private function code_of( AnthropicProvider $provider ): string {
		try {
			$provider->complete( $this->request() );
		} catch ( ProviderException $e ) {
			return $e->error_code();
		}
		return 'none';
	}
}
