<?php
/**
 * Bootstrap file for PHPStan.
 *
 * @package Halawa\ChatGptAiProvider
 */

// The WordPress extension defines ABSPATH; the plugin files also guard on these.
if ( ! defined( 'WPINC' ) ) {
	define( 'WPINC', 'wp-includes' );
}
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	define( 'WP_UNINSTALL_PLUGIN', true );
}
