<?php
/**
 * Contract every AI provider implements.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An AI provider that can return a JSON completion.
 */
interface ProviderInterface {

	/**
	 * Provider id, for example 'anthropic'.
	 */
	public function id(): string;

	/**
	 * Run one completion.
	 *
	 * @param CompletionRequest $request Request.
	 * @throws ProviderException On any provider failure.
	 */
	public function complete( CompletionRequest $request ): CompletionResult;
}
