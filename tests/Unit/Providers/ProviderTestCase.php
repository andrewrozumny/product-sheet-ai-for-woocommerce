<?php
/**
 * Shared helpers for provider tests.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Tests\Unit\Providers;

use ProductSheetAI\Providers\CompletionRequest;
use ProductSheetAI\Providers\HttpTransport;
use ProductSheetAI\Tests\Unit\TestCase;

/**
 * Builds a transport that replays canned responses and records requests.
 */
abstract class ProviderTestCase extends TestCase {

	public const KEY = 'sk-ant-test-SECRET123';

	/**
	 * Requests seen by the fake sender.
	 *
	 * @var list<array{url:string,args:array<string,mixed>}>
	 */
	protected array $requests = array();

	/**
	 * Seconds the fake sleeper was asked to wait.
	 *
	 * @var list<int>
	 */
	protected array $sleeps = array();

	/**
	 * Build a transport that returns the given responses in order (the last repeats).
	 *
	 * @param array<int,mixed> $responses Responses or WP_Error objects.
	 */
	protected function transport( array $responses ): HttpTransport {
		$this->requests = array();
		$this->sleeps   = array();
		$index          = 0;
		$sender         = function ( string $url, array $args ) use ( &$index, $responses ) {
			$this->requests[] = array(
				'url'  => $url,
				'args' => $args,
			);
			$response         = $responses[ min( $index, count( $responses ) - 1 ) ];
			++$index;
			return $response;
		};
		$sleeper        = function ( int $seconds ): void {
			$this->sleeps[] = $seconds;
		};
		return new HttpTransport( $sender, $sleeper );
	}

	/**
	 * Build a response array like wp_remote_post returns.
	 *
	 * @param int                  $status  HTTP status.
	 * @param array<string,mixed>  $body    Body to JSON encode.
	 * @param array<string,string> $headers Response headers.
	 * @return array<string,mixed>
	 */
	protected function response( int $status, array $body, array $headers = array() ): array {
		return array(
			'response' => array( 'code' => $status ),
			'headers'  => $headers,
			'body'     => (string) wp_json_encode( $body ),
		);
	}

	/**
	 * A small request.
	 */
	protected function request(): CompletionRequest {
		return new CompletionRequest(
			'System prompt',
			'User prompt',
			array(
				'type'       => 'object',
				'properties' => array( 'name' => array( 'type' => 'string' ) ),
			)
		);
	}

	/**
	 * Decoded JSON body of request number $i.
	 *
	 * @param int $i Index.
	 * @return array<string,mixed>
	 */
	protected function sent_body( int $i = 0 ): array {
		$decoded = json_decode( (string) $this->requests[ $i ]['args']['body'], true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
