<?php
/**
 * The model answer could not be turned into valid product data.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thrown by the response parser. The detail holds only a JSON path and a reason, never model text.
 */
final class ParseException extends \RuntimeException {

	/**
	 * Short machine detail, for example "attributes[2].values (type)".
	 *
	 * @var string
	 */
	private string $detail;

	/**
	 * Constructor.
	 *
	 * @param string $detail Path and reason of the first problem.
	 */
	public function __construct( string $detail ) {
		$this->detail = $detail;
		parent::__construct(
			sprintf(
				/* translators: %s: short technical detail such as a field path. */
				__( 'The AI answer could not be read as a product (%s). Try again, or shorten the sheet.', 'product-sheet-ai-for-woocommerce' ),
				$detail
			)
		);
	}

	/**
	 * Build an exception for a problem at a JSON path.
	 *
	 * @param string $detail Path and reason, for example "attributes[2].values (type)".
	 */
	public static function because( string $detail ): self {
		return new self( $detail );
	}

	/**
	 * Machine readable code.
	 */
	public function error_code(): string {
		return 'schema';
	}

	/**
	 * Path and reason of the first problem.
	 */
	public function detail(): string {
		return $this->detail;
	}
}
