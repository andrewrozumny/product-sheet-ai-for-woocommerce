<?php
/**
 * Keeps the last generation attempts in an option.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Request log: last 20 entries, newest first. Never stores the key or the sheet text.
 */
final class RequestLog {

	public const OPTION = 'product_sheet_ai_log';

	public const MAX_ENTRIES = 20;

	public const CLEAR_ACTION = 'product_sheet_ai_clear_log';

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'product_sheet_ai_request_complete', array( $this, 'handle' ) );
	}

	/**
	 * Store one entry.
	 *
	 * @param mixed $entry Payload of the completion action.
	 */
	public function handle( $entry ): void {
		if ( ! is_array( $entry ) ) {
			return;
		}
		$log = self::entries();
		array_unshift( $log, self::normalize( $entry ) );
		$log = array_slice( $log, 0, self::MAX_ENTRIES );
		update_option( self::OPTION, $log, false );
	}

	/**
	 * Keep whitelisted keys only, sanitized, and add time and cost.
	 *
	 * @param array<mixed,mixed> $entry Raw payload.
	 * @return array<string,mixed>
	 */
	public static function normalize( array $entry ): array {
		$model  = isset( $entry['model'] ) && is_scalar( $entry['model'] ) ? sanitize_text_field( (string) $entry['model'] ) : '';
		$in     = isset( $entry['input_tokens'] ) && is_numeric( $entry['input_tokens'] ) ? max( 0, (int) $entry['input_tokens'] ) : 0;
		$out    = isset( $entry['output_tokens'] ) && is_numeric( $entry['output_tokens'] ) ? max( 0, (int) $entry['output_tokens'] ) : 0;
		$status = isset( $entry['status'] ) && is_scalar( $entry['status'] ) ? preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $entry['status'] ) ) : '';

		return array(
			'time'          => time(),
			'user_id'       => isset( $entry['user_id'] ) && is_numeric( $entry['user_id'] ) ? max( 0, (int) $entry['user_id'] ) : 0,
			'product_id'    => isset( $entry['product_id'] ) && is_numeric( $entry['product_id'] ) ? max( 0, (int) $entry['product_id'] ) : 0,
			'provider'      => isset( $entry['provider'] ) && is_scalar( $entry['provider'] ) ? sanitize_text_field( (string) $entry['provider'] ) : '',
			'model'         => $model,
			'input_tokens'  => $in,
			'output_tokens' => $out,
			'status'        => null === $status || '' === $status ? 'internal' : $status,
			'duration_ms'   => isset( $entry['duration_ms'] ) && is_numeric( $entry['duration_ms'] ) ? max( 0, (int) $entry['duration_ms'] ) : 0,
			'source'        => isset( $entry['source'] ) && is_scalar( $entry['source'] ) ? sanitize_text_field( (string) $entry['source'] ) : '',
			'chars'         => isset( $entry['chars'] ) && is_numeric( $entry['chars'] ) ? max( 0, (int) $entry['chars'] ) : 0,
			'cost_usd'      => Pricing::estimate( $model, $in, $out ),
		);
	}

	/**
	 * Stored entries, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function entries(): array {
		$log = get_option( self::OPTION, array() );
		if ( ! is_array( $log ) ) {
			return array();
		}
		$entries = array();
		foreach ( $log as $row ) {
			if ( is_array( $row ) ) {
				$entries[] = $row;
			}
		}
		return array_slice( $entries, 0, self::MAX_ENTRIES );
	}

	/**
	 * Delete every entry.
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}
}
