<?php
/**
 * Error raised while applying a preview to a product.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Mapping;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carries a translated message that is safe to show to the user.
 */
final class ApplyException extends \RuntimeException {
}
