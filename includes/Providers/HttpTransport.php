<?php
/**
 * HTTP layer with one retry.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends JSON POST requests with a single retry on transient failures.
 *
 * The sender and the sleeper are injectable so tests need no network and no waiting.
 */
final class HttpTransport {

	public const TIMEOUT = 60;

	private const RETRY_STATUSES = array( 429, 500, 502, 503, 529 );

	/**
	 * Sends a request: fn( string $url, array $args ): array|\WP_Error.
	 *
	 * @var callable
	 */
	private $sender;

	/**
	 * Waits: fn( int $seconds ): void.
	 *
	 * @var callable
	 */
	private $sleeper;

	/**
	 * Constructor.
	 *
	 * @param callable|null $sender  Request sender. Defaults to wp_remote_post.
	 * @param callable|null $sleeper Sleep function. Defaults to sleep.
	 */
	public function __construct( ?callable $sender = null, ?callable $sleeper = null ) {
		$this->sender  = $sender ?? 'wp_remote_post';
		$this->sleeper = $sleeper ?? static function ( int $seconds ): void {
			sleep( $seconds );
		};
	}

	/**
	 * POST a JSON body.
	 *
	 * @param string               $url     Absolute URL.
	 * @param array<string,string> $headers Request headers.
	 * @param string               $body    JSON body.
	 * @return array{status:int,body:string}
	 * @throws ProviderException On timeout or a failed connection.
	 */
	public function post( string $url, array $headers, string $body ): array {
		$args = array(
			'headers'     => $headers,
			'body'        => $body,
			'timeout'     => self::TIMEOUT,
			'redirection' => 0,
		);

		$retried = false;
		while ( true ) {
			$response = ( $this->sender )( $url, $args );

			if ( $response instanceof \WP_Error ) {
				if ( ! $retried && $this->is_timeout( $response ) ) {
					$retried = true;
					( $this->sleeper )( 2 );
					continue;
				}
				throw ProviderException::for_code( $this->is_timeout( $response ) ? 'timeout' : 'http' );
			}

			$status = (int) ( is_array( $response ) && isset( $response['response']['code'] ) ? $response['response']['code'] : 0 );
			if ( ! $retried && in_array( $status, self::RETRY_STATUSES, true ) ) {
				$retried = true;
				( $this->sleeper )( $this->wait_seconds( $response ) );
				continue;
			}

			$raw_body = is_array( $response ) && isset( $response['body'] ) && is_string( $response['body'] ) ? $response['body'] : '';
			return array(
				'status' => $status,
				'body'   => $raw_body,
			);
		}
	}

	/**
	 * Whether a transport error is a timeout.
	 *
	 * @param \WP_Error $error Error.
	 */
	private function is_timeout( \WP_Error $error ): bool {
		$message = strtolower( $error->get_error_message() );
		return str_contains( $message, 'timed out' ) || str_contains( $message, 'timeout' );
	}

	/**
	 * Seconds to wait before the retry: Retry-After capped at 5, default 2.
	 *
	 * @param mixed $response Response array.
	 */
	private function wait_seconds( $response ): int {
		$headers = is_array( $response ) && isset( $response['headers'] ) ? $response['headers'] : array();
		$value   = null;
		if ( is_array( $headers ) || $headers instanceof \ArrayAccess ) {
			$value = $headers['retry-after'] ?? null;
		}
		if ( is_numeric( $value ) && (float) $value >= 0 ) {
			return (int) min( (float) $value, 5 );
		}
		return 2;
	}
}
