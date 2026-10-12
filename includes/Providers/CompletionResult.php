<?php
/**
 * One completion result.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider-neutral result.
 */
final class CompletionResult {

	/**
	 * Constructor.
	 *
	 * @param string $text          The JSON text returned by the model.
	 * @param int    $input_tokens  Input tokens used.
	 * @param int    $output_tokens Output tokens used.
	 * @param string $model         Model that answered.
	 * @param string $stop_reason   Provider stop reason.
	 * @param int    $duration_ms   Duration of the request in milliseconds.
	 */
	public function __construct(
		public readonly string $text,
		public readonly int $input_tokens,
		public readonly int $output_tokens,
		public readonly string $model,
		public readonly string $stop_reason,
		public readonly int $duration_ms
	) {
	}
}
