<?php
/**
 * ConnectorApproval class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\Admin;

use Throwable;

/**
 * Reads the AI plugin's Connector Approval experiment, which blocks unapproved plugins.
 *
 * @since 1.1.0
 */
final class ConnectorApproval {

	/**
	 * Connector id the AI plugin records for this provider.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	public const CONNECTOR_ID = 'chatgpt';

	/**
	 * Class of the AI plugin's request guard.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	private const GUARD_CLASS = 'WordPress\\AI\\Connector_Approval\\Http_Guard';

	/**
	 * Class of the AI plugin's approvals store.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	private const STORE_CLASS = 'WordPress\\AI\\Connector_Approval\\Approvals_Store';

	/**
	 * Class of the AI plugin's approvals admin page.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	private const PAGE_CLASS = 'WordPress\\AI\\Experiments\\Connector_Approval\\Admin_Page';

	/**
	 * Returns the blocked plugins and the page that approves them, or null when nothing is blocked.
	 *
	 * @since 1.1.0
	 *
	 * @return array{callers: list<string>, url: string}|null
	 */
	public static function pending(): ?array {
		if ( ! self::guard_active() || ! class_exists( self::STORE_CLASS ) ) {
			return null;
		}
		try {
			$class   = self::STORE_CLASS;
			$store   = new $class();
			$callers = array();
			foreach ( (array) $store->get_pending() as $entry ) {
				if ( ! is_array( $entry ) || self::CONNECTOR_ID !== ( $entry['connector_id'] ?? '' ) ) {
					continue;
				}
				$basename = (string) ( $entry['caller_basename'] ?? '' );
				if ( '' === $basename || $store->is_approved( $basename, self::CONNECTOR_ID ) ) {
					continue;
				}
				$name      = (string) ( $entry['caller_name'] ?? '' );
				$callers[] = '' !== $name ? $name : $basename;
			}
		} catch ( Throwable $e ) {
			return null;
		}
		if ( array() === $callers ) {
			return null;
		}
		return array(
			'callers' => array_values( array_unique( $callers ) ),
			'url'     => self::review_url(),
		);
	}

	/**
	 * Whether the experiment's guard is hooked, so unapproved requests are blocked now.
	 *
	 * @since 1.1.0
	 *
	 * @return bool
	 */
	private static function guard_active(): bool {
		global $wp_filter;
		if ( ! isset( $wp_filter['pre_http_request'] ) ) {
			return false;
		}
		foreach ( $wp_filter['pre_http_request']->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				$function = $callback['function'] ?? null;
				if ( is_array( $function ) && is_object( $function[0] ?? null ) && is_a( $function[0], self::GUARD_CLASS ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Returns the Connector Approvals page URL.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	private static function review_url(): string {
		if ( is_callable( array( self::PAGE_CLASS, 'url' ) ) ) {
			$url = call_user_func( array( self::PAGE_CLASS, 'url' ) );
			if ( is_string( $url ) && '' !== $url ) {
				return $url;
			}
		}
		return admin_url( 'tools.php?page=ai-connector-approval' );
	}
}
