<?php
/**
 * One completion request.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider-neutral request.
 */
final class CompletionRequest {

	/**
	 * Constructor.
	 *
	 * @param string              $system      System prompt.
	 * @param string              $user        User prompt.
	 * @param array<string,mixed> $schema      JSON schema of the expected output.
	 * @param string              $schema_name Schema name.
	 * @param int                 $max_tokens  Output token limit.
	 */
	public function __construct(
		public readonly string $system,
		public readonly string $user,
		public readonly array $schema,
		public readonly string $schema_name = 'product_sheet',
		public readonly int $max_tokens = 16000
	) {
	}
}
