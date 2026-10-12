<?php
/**
 * OpenAICompatibleProvider tests.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Tests\Unit\Providers;

use Brain\Monkey\Filters;
use ProductSheetAI\Providers\AnthropicProvider;
use ProductSheetAI\Providers\OpenAICompatibleProvider;
use ProductSheetAI\Providers\ProviderException;
use ProductSheetAI\Providers\ProviderFactory;

/**
 * OpenAI-compatible request shape, parsing, errors and the factory.
 */
final class OpenAICompatibleProviderTest extends ProviderTestCase {

	/**
	 * A successful body.
	 *
	 * @return array<string,mixed>
	 */
	private function ok_body(): array {
		return array(
			'model'   => 'gpt-4o-mini',
			'choices' => array(
				array(
					'finish_reason' => 'stop',
					'message'       => array( 'content' => '{"name":"Mug"}' ),
				),
			),
			'usage'   => array(
				'prompt_tokens'     => 80,
				'completion_tokens' => 30,
			),
		);
	}

	/**
	 * Build the provider with canned responses.
	 *
	 * @param array<int,mixed> $responses Responses.
	 * @param string           $endpoint  Endpoint.
	 */
	private function provider( array $responses, string $endpoint = 'https://api.example.com/v1/' ): OpenAICompatibleProvider {
		return new OpenAICompatibleProvider( self::KEY, 'gpt-4o-mini', $endpoint, $this->transport( $responses ) );
	}

	/**
	 * Run complete() and return the error code.
	 *
	 * @param OpenAICompatibleProvider $provider Provider.
	 */
	private function code_of( OpenAICompatibleProvider $provider ): string {
		try {
			$provider->complete( $this->request() );
		} catch ( ProviderException $e ) {
			return $e->error_code();
		}
		return 'none';
	}

	/**
	 * URL, headers, body and parsing.
	 */
	public function test_request_and_parsing(): void {
		$result = $this->provider( array( $this->response( 200, $this->ok_body() ) ) )->complete( $this->request() );

		$this->assertSame( 'https://api.example.com/v1/chat/completions', $this->requests[0]['url'] );
		$this->assertSame( 'Bearer ' . self::KEY, $this->requests[0]['args']['headers']['Authorization'] );

		$body = $this->sent_body();
		$this->assertSame( 'gpt-4o-mini', $body['model'] );
		$this->assertSame( 'system', $body['messages'][0]['role'] );
		$this->assertSame( 'System prompt', $body['messages'][0]['content'] );
		$this->assertSame( 'user', $body['messages'][1]['role'] );
		$this->assertSame( 'json_schema', $body['response_format']['type'] );
		$this->assertSame( 'product_sheet', $body['response_format']['json_schema']['name'] );
		$this->assertTrue( $body['response_format']['json_schema']['strict'] );
		$this->assertSame( 'object', $body['response_format']['json_schema']['schema']['type'] );
		$this->assertSame( 16000, $body['max_completion_tokens'] );

		$this->assertSame( '{"name":"Mug"}', $result->text );
		$this->assertSame( 80, $result->input_tokens );
		$this->assertSame( 30, $result->output_tokens );
		$this->assertSame( 'stop', $result->stop_reason );
	}

	/**
	 * Error mapping.
	 */
	public function test_error_mapping(): void {
		$body  = array( 'error' => array( 'message' => 'nope' ) );
		$cases = array(
			401 => 'auth',
			403 => 'permission',
			429 => 'rate_limit',
			503 => 'overloaded',
			413 => 'too_large',
			400 => 'bad_request',
			500 => 'http',
		);
		foreach ( $cases as $status => $code ) {
			$this->assertSame( $code, $this->code_of( $this->provider( array( $this->response( $status, $body ) ) ) ), (string) $status );
		}
	}

	/**
	 * Finish reasons and refusals.
	 */
	public function test_truncated_refusal_empty(): void {
		$cut                                = $this->ok_body();
		$cut['choices'][0]['finish_reason'] = 'length';
		$this->assertSame( 'truncated', $this->code_of( $this->provider( array( $this->response( 200, $cut ) ) ) ) );

		$refused                                     = $this->ok_body();
		$refused['choices'][0]['message']['refusal'] = 'I cannot help';
		$this->assertSame( 'refusal', $this->code_of( $this->provider( array( $this->response( 200, $refused ) ) ) ) );

		$empty                                     = $this->ok_body();
		$empty['choices'][0]['message']['content'] = '';
		$this->assertSame( 'empty', $this->code_of( $this->provider( array( $this->response( 200, $empty ) ) ) ) );
	}

	/**
	 * Plain http endpoints are rejected before any request, and the message never has the key.
	 */
	public function test_http_endpoint_rejected(): void {
		$provider = $this->provider( array( $this->response( 200, $this->ok_body() ) ), 'http://api.example.com/v1' );
		$this->assertSame( 'bad_request', $this->code_of( $provider ) );
		$this->assertCount( 0, $this->requests );
	}

	/**
	 * Retry on 429 once, with the key never leaking into messages.
	 */
	public function test_retry_and_no_key_in_messages(): void {
		$limited = $this->response( 429, array( 'error' => array( 'message' => 'slow down ' . self::KEY ) ) );
		try {
			$this->provider( array( $limited, $limited ) )->complete( $this->request() );
			$this->fail( 'Expected exception' );
		} catch ( ProviderException $e ) {
			$this->assertSame( 'rate_limit', $e->error_code() );
			$this->assertStringNotContainsString( self::KEY, $e->getMessage() );
		}
		$this->assertCount( 2, $this->requests );

		$bad = $this->response( 400, array( 'error' => array( 'message' => 'wrong ' . self::KEY ) ) );
		try {
			$this->provider( array( $bad ) )->complete( $this->request() );
			$this->fail( 'Expected exception' );
		} catch ( ProviderException $e ) {
			$this->assertStringNotContainsString( self::KEY, $e->getMessage() );
		}
	}

	/**
	 * The factory builds the right provider and honours the filter.
	 */
	public function test_factory(): void {
		$anthropic = ProviderFactory::make(
			array(
				'provider' => 'anthropic',
				'api_key'  => self::KEY,
				'model'    => 'claude-sonnet-5-5',
			)
		);
		$this->assertInstanceOf( AnthropicProvider::class, $anthropic );
		$this->assertSame( 'anthropic', $anthropic->id() );

		$openai = ProviderFactory::make(
			array(
				'provider' => 'openai_compatible',
				'api_key'  => self::KEY,
				'model'    => '',
				'endpoint' => 'https://api.example.com/v1',
			)
		);
		$this->assertSame( 'openai_compatible', $openai->id() );
	}

	/**
	 * No key throws no_key, unless the filter supplies a provider.
	 */
	public function test_factory_no_key_and_filter(): void {
		try {
			ProviderFactory::make( array( 'provider' => 'anthropic' ) );
			$this->fail( 'Expected exception' );
		} catch ( ProviderException $e ) {
			$this->assertSame( 'no_key', $e->error_code() );
		}

		$mock = $this->provider( array() );
		Filters\expectApplied( 'product_sheet_ai_provider' )->once()->andReturn( $mock );
		$this->assertSame( $mock, ProviderFactory::make( array( 'provider' => 'anthropic' ) ) );
	}
}
