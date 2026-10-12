<?php
/**
 * Minimal WP_Error stand-in for unit tests.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

// phpcs:disable Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Tiny subset of WP_Error.
	 */
	class WP_Error { // phpcs:ignore Generic.Classes.OpeningBraceSameLine,PEAR.Classes.ClassDeclaration

		/**
		 * Error code.
		 *
		 * @var string
		 */
		private string $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		private string $message;

		/**
		 * Constructor.
		 *
		 * @param string $code    Code.
		 * @param string $message Message.
		 */
		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		/**
		 * Message.
		 */
		public function get_error_message(): string {
			return $this->message;
		}

		/**
		 * Code.
		 */
		public function get_error_code(): string {
			return $this->code;
		}
	}
}
