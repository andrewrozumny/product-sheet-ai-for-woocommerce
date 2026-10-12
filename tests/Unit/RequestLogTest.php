<?php
/**
 * Request log tests.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Tests\Unit;

use Brain\Monkey\Functions;
use ProductSheetAI\RequestLog;

/**
 * RequestLog storage rules.
 */
final class RequestLogTest extends TestCase {

	/**
	 * Option value held by the stubs.
	 *
	 * @var mixed
	 */
	private $stored = array();

	/**
	 * Autoload flag passed on the last update.
	 *
	 * @var mixed
	 */
	private $autoload = null;

	/**
	 * Stub the option functions.
	 */
	protected function set_up(): void {
		parent::set_up();
		$this->stored   = array();
		$this->autoload = null;
		Functions\when( 'get_option' )->alias(
			function () {
				return $this->stored;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value, $autoload = null ) {
				$this->stored   = $value;
				$this->autoload = $autoload;
				return true;
			}
		);
	}

	/**
	 * A sample payload.
	 *
	 * @param array<string,mixed> $over Overrides.
	 * @return array<string,mixed>
	 */
	private function payload( array $over = array() ): array {
		return array_merge(
			array(
				'user_id'       => 3,
				'product_id'    => 0,
				'provider'      => 'anthropic',
				'model'         => 'claude-sonnet-5-5',
				'input_tokens'  => 1000,
				'output_tokens' => 500,
				'status'        => 'ok',
				'duration_ms'   => 4200,
				'source'        => 'paste',
				'chars'         => 800,
			),
			$over
		);
	}

	/**
	 * Cost and time are added, autoload is off.
	 */
	public function test_entry_gets_time_and_cost(): void {
		( new RequestLog() )->handle( $this->payload() );
		$this->assertCount( 1, $this->stored );
		$this->assertEqualsWithDelta( 0.007, $this->stored[0]['cost_usd'], 0.0000001 );
		$this->assertEqualsWithDelta( time(), $this->stored[0]['time'], 5 );
		$this->assertFalse( $this->autoload );
	}

	/**
	 * Unknown keys are dropped, including anything that looks like a key or sheet text.
	 */
	public function test_whitelist_drops_unknown_keys(): void {
		( new RequestLog() )->handle(
			$this->payload(
				array(
					'api_key'    => 'sk-secret',
					'sheet_text' => 'Secret sheet',
					'extra'      => 1,
				)
			)
		);
		$keys = array_keys( $this->stored[0] );
		sort( $keys );
		$expected = array( 'chars', 'cost_usd', 'duration_ms', 'input_tokens', 'model', 'output_tokens', 'product_id', 'provider', 'source', 'status', 'time', 'user_id' );
		$this->assertSame( $expected, $keys );
		$this->assertStringNotContainsString( 'sk-secret', (string) wp_json_encode( $this->stored ) );
		$this->assertStringNotContainsString( 'Secret sheet', (string) wp_json_encode( $this->stored ) );
	}

	/**
	 * Newest first, capped at 20.
	 */
	public function test_cap_and_order(): void {
		$log = new RequestLog();
		for ( $i = 1; $i <= 25; $i++ ) {
			$log->handle( $this->payload( array( 'product_id' => $i ) ) );
		}
		$this->assertCount( 20, $this->stored );
		$this->assertSame( 25, $this->stored[0]['product_id'] );
		$this->assertSame( 6, $this->stored[19]['product_id'] );
	}

	/**
	 * Unknown model gives a null cost, errors keep their code.
	 */
	public function test_unknown_model_null_cost_and_error_status(): void {
		( new RequestLog() )->handle(
			$this->payload(
				array(
					'model'  => 'mystery',
					'status' => 'rate_limit',
				)
			)
		);
		$this->assertNull( $this->stored[0]['cost_usd'] );
		$this->assertSame( 'rate_limit', $this->stored[0]['status'] );
	}

	/**
	 * Bad values are coerced.
	 */
	public function test_values_are_sanitized(): void {
		( new RequestLog() )->handle(
			$this->payload(
				array(
					'input_tokens' => -5,
					'status'       => '',
					'user_id'      => 'abc',
				)
			)
		);
		$this->assertSame( 0, $this->stored[0]['input_tokens'] );
		$this->assertSame( 'internal', $this->stored[0]['status'] );
		$this->assertSame( 0, $this->stored[0]['user_id'] );
	}

	/**
	 * A non-array payload is ignored.
	 */
	public function test_non_array_payload_ignored(): void {
		( new RequestLog() )->handle( 'nope' );
		$this->assertSame( array(), $this->stored );
	}

	/**
	 * A corrupted option reads as an empty list.
	 */
	public function test_corrupt_option(): void {
		$this->stored = 'garbage';
		$this->assertSame( array(), RequestLog::entries() );
	}
}
