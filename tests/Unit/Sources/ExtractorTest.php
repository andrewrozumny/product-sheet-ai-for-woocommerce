<?php
/**
 * Extractor tests.
 *
 * @package ProductSheetAI
 */

declare(strict_types=1);

namespace ProductSheetAI\Tests\Unit\Sources;

use Brain\Monkey\Functions;
use ProductSheetAI\Sources\Extractor;
use ProductSheetAI\Tests\Unit\TestCase;

/**
 * Extractor::from_paste() and from_upload().
 */
final class ExtractorTest extends TestCase {

	/**
	 * Temp files to remove.
	 *
	 * @var list<string>
	 */
	private array $temp = array();

	/**
	 * WordPress stubs.
	 */
	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'number_format_i18n' )->alias( static fn( $n ): string => number_format( (float) $n ) );
		Functions\when( 'wp_check_filetype_and_ext' )->alias(
			static function ( string $file, string $name, ?array $mimes = null ): array {
				$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
				$mime = $mimes[ $ext ] ?? false;
				return array(
					'ext'             => false === $mime ? false : $ext,
					'type'            => $mime,
					'proper_filename' => false,
				);
			}
		);
	}

	/**
	 * Remove temp files.
	 */
	protected function tear_down(): void {
		foreach ( $this->temp as $path ) {
			if ( is_file( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test cleanup.
			}
		}
		parent::tear_down();
	}

	/**
	 * Fixture path.
	 *
	 * @param string $name File name.
	 */
	private function fixture( string $name ): string {
		return dirname( __DIR__, 2 ) . '/fixtures/sheets/' . $name;
	}

	/**
	 * A temp file with content.
	 *
	 * @param string $content Bytes.
	 */
	private function temp_file( string $content ): string {
		$path = (string) tempnam( sys_get_temp_dir(), 'psai' );
		file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		$this->temp[] = $path;
		return $path;
	}

	/**
	 * Upload array for a file.
	 *
	 * @param string $path Real path.
	 * @param string $name Original name.
	 * @return array<string,mixed>
	 */
	private function upload( string $path, string $name ): array {
		return array(
			'name'     => $name,
			'tmp_name' => $path,
			'size'     => (int) filesize( $path ),
			'error'    => UPLOAD_ERR_OK,
		);
	}

	/**
	 * Accept every path as an upload.
	 */
	private function accept(): callable {
		return static fn( string $path ): bool => '' !== $path;
	}

	/**
	 * Paste is normalized.
	 */
	public function test_paste_normalized(): void {
		$this->assertSame( "a\nb\nc", Extractor::from_paste( "  a\r\nb\rc\0  " ) );
	}

	/**
	 * Empty paste fails.
	 */
	public function test_paste_empty(): void {
		$this->expectException( \InvalidArgumentException::class );
		Extractor::from_paste( " \n\0 " );
	}

	/**
	 * Over the limit fails and is never truncated.
	 */
	public function test_paste_too_long(): void {
		$this->assertSame( Extractor::MAX_CHARS, strlen( Extractor::from_paste( str_repeat( 'a', Extractor::MAX_CHARS ) ) ) );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'too long' );
		Extractor::from_paste( str_repeat( 'a', Extractor::MAX_CHARS + 1 ) );
	}

	/**
	 * Plain text upload.
	 */
	public function test_txt_upload(): void {
		$result = Extractor::from_upload( $this->upload( $this->fixture( 'sample-sheet.txt' ), 'sheet.txt' ), $this->accept() );
		$this->assertSame( 'txt', $result['source'] );
		$this->assertStringContainsString( 'Trail Runner Backpack 20L', $result['text'] );
		$this->assertStringContainsString( 'USD 79.90', $result['text'] );
	}

	/**
	 * CSV rows become header: value lines, semicolons and quotes handled.
	 */
	public function test_csv_upload(): void {
		$result = Extractor::from_upload( $this->upload( $this->fixture( 'sample.csv' ), 'products.csv' ), $this->accept() );
		$this->assertSame( 'csv', $result['source'] );
		$blocks = explode( "\n\n", $result['text'] );
		$this->assertCount( 3, $blocks );
		$this->assertSame( "Name: Trail Runner Backpack 20L\nSKU: TR-BP-20-BLK\nColor: Black\nSize: 20L\nPrice: 79,90", $blocks[0] );
		$this->assertSame( "Name: Rain cover, universal\nSKU: TR-RC-01\nPrice: 9.50", $blocks[2] );
	}

	/**
	 * CSV with a quoted newline and doubled quotes.
	 */
	public function test_csv_quoted_newline(): void {
		$path   = $this->temp_file( "name,note\n\"Pack\",\"line one\nline \"\"two\"\"\"\n" );
		$result = Extractor::from_upload( $this->upload( $path, 'x.csv' ), $this->accept() );
		$this->assertSame( "name: Pack\nnote: line one\nline \"two\"", $result['text'] );
	}

	/**
	 * Windows-1252 text is converted to UTF-8.
	 */
	public function test_legacy_encoding(): void {
		$path   = $this->temp_file( "Caf\xE9 table" );
		$result = Extractor::from_upload( $this->upload( $path, 'x.txt' ), $this->accept() );
		$this->assertSame( "Caf\u{E9} table", $result['text'] );
	}

	/**
	 * Real PDF with a text layer.
	 */
	public function test_pdf_upload(): void {
		$result = Extractor::from_upload( $this->upload( $this->fixture( 'sample-sheet.pdf' ), 'sheet.pdf' ), $this->accept() );
		$this->assertSame( 'pdf', $result['source'] );
		$this->assertStringContainsString( 'Trail Runner Backpack 20L', $result['text'] );
		$this->assertStringContainsString( '79.90', $result['text'] );
	}

	/**
	 * A PDF without a text layer is refused with a clear message.
	 */
	public function test_pdf_without_text_layer(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'text layer' );
		Extractor::from_upload( $this->upload( $this->fixture( 'scanned-no-text.pdf' ), 'scan.pdf' ), $this->accept() );
	}

	/**
	 * A file named .pdf that is not a PDF is refused.
	 */
	public function test_fake_pdf(): void {
		$path = $this->temp_file( 'just text' );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'not a valid PDF' );
		Extractor::from_upload( $this->upload( $path, 'fake.pdf' ), $this->accept() );
	}

	/**
	 * Other extensions are refused.
	 */
	public function test_bad_extension(): void {
		$path = $this->temp_file( 'MZ...' );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'Only .txt, .csv and .pdf' );
		Extractor::from_upload( $this->upload( $path, 'run.php' ), $this->accept() );
	}

	/**
	 * Binary data in a text file is refused.
	 */
	public function test_binary_in_txt(): void {
		$path = $this->temp_file( "abc\0def" );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'not a plain text' );
		Extractor::from_upload( $this->upload( $path, 'x.txt' ), $this->accept() );
	}

	/**
	 * Empty file.
	 */
	public function test_empty_file(): void {
		$path = $this->temp_file( '' );
		$this->expectException( \InvalidArgumentException::class );
		Extractor::from_upload( $this->upload( $path, 'x.txt' ), $this->accept() );
	}

	/**
	 * A file over 5 MB.
	 */
	public function test_file_too_large(): void {
		$path = $this->temp_file( str_repeat( 'a', Extractor::MAX_BYTES + 1 ) );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( '5 MB' );
		Extractor::from_upload( $this->upload( $path, 'x.txt' ), $this->accept() );
	}

	/**
	 * Text over the character limit.
	 */
	public function test_upload_text_too_long(): void {
		$path = $this->temp_file( str_repeat( 'a', Extractor::MAX_CHARS + 1 ) );
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'too long' );
		Extractor::from_upload( $this->upload( $path, 'x.txt' ), $this->accept() );
	}

	/**
	 * Files that were not really uploaded are refused.
	 */
	public function test_not_uploaded_file(): void {
		$this->expectException( \InvalidArgumentException::class );
		$this->expectExceptionMessage( 'not uploaded correctly' );
		Extractor::from_upload( $this->upload( $this->fixture( 'sample-sheet.txt' ), 'x.txt' ), static fn( string $path ): bool => '' === $path );
	}

	/**
	 * Upload errors are refused.
	 */
	public function test_upload_error(): void {
		$this->expectException( \InvalidArgumentException::class );
		Extractor::from_upload(
			array(
				'name'     => 'x.txt',
				'tmp_name' => '',
				'error'    => UPLOAD_ERR_INI_SIZE,
			),
			$this->accept()
		);
	}
}
