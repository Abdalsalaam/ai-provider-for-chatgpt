<?php
/**
 * Base test case.
 *
 * @package Halawa\ChatGptAiProvider
 */

/**
 * Resets the HTTP mock around every test and reads fixtures.
 */
abstract class Test_Case extends WP_UnitTestCase {

	/**
	 * Starts each test with an empty mock.
	 */
	public function set_up() {
		parent::set_up();
		Http_Mock::reset();
	}

	/**
	 * Leaves no queued response for the next test.
	 */
	public function tear_down() {
		Http_Mock::reset();
		parent::tear_down();
	}

	/**
	 * Returns the contents of a fixture file.
	 *
	 * @param string $name File name under tests/phpunit/fixtures.
	 * @return string
	 * @throws RuntimeException When the fixture does not exist.
	 */
	protected static function fixture( string $name ): string {
		$path = __DIR__ . '/../fixtures/' . $name;
		if ( ! is_readable( $path ) ) {
			throw new RuntimeException( 'Missing fixture ' . $name ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test failure message.
		}
		return (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local fixture file.
	}
}
