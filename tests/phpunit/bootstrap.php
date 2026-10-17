<?php
/**
 * PHPUnit bootstrap for the plugin tests.
 *
 * @package Halawa\ChatGptAiProvider
 */

$halawa_chatgpt_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $halawa_chatgpt_tests_dir ) {
	$halawa_chatgpt_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

if ( ! file_exists( $halawa_chatgpt_tests_dir . '/includes/functions.php' ) ) {
	echo 'WordPress test suite not found; run the tests through composer test.' . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI message before WordPress loads.
	exit( 1 );
}

/*
 * PHPUnit loads the Composer autoloader before WordPress, so the dev copy of the
 * AI Client SDK would shadow the one core bundles; the shipped zip has no dev copy.
 */
$halawa_chatgpt_loader = require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
$halawa_chatgpt_loader->unregister();
spl_autoload_register(
	static function ( $class_name ) use ( $halawa_chatgpt_loader ) {
		$core_has_sdk = defined( 'ABSPATH' ) && defined( 'WPINC' ) && file_exists( ABSPATH . WPINC . '/php-ai-client/autoload.php' );
		if ( $core_has_sdk && 0 === strpos( $class_name, 'WordPress\\AiClient\\' ) ) {
			return;
		}
		$halawa_chatgpt_loader->loadClass( $class_name );
	},
	true,
	true
);

require_once dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
require_once $halawa_chatgpt_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__, 2 ) . '/ai-provider-for-chatgpt.php';
	}
);

require $halawa_chatgpt_tests_dir . '/includes/bootstrap.php';

require_once __DIR__ . '/includes/class-http-mock.php';
require_once __DIR__ . '/includes/class-jwks-fixture.php';
require_once __DIR__ . '/includes/class-test-case.php';

// Installed before any test so WP_UnitTestCase restores it after every test.
Http_Mock::install();
