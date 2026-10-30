<?php
/**
 * Stand-in for the AI plugin's Connector Approval store.
 *
 * @package Halawa\ChatGptAiProvider
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Mirrors the AI plugin's namespace.
namespace WordPress\AI\Connector_Approval;

/**
 * Pending and approved callers set by the test.
 */
class Approvals_Store {

	/**
	 * Pending requests keyed by caller and connector.
	 *
	 * @var array<string, array<string, string>>
	 */
	public static $pending = array();

	/**
	 * Approvals keyed by caller basename, then connector id.
	 *
	 * @var array<string, array<string, bool>>
	 */
	public static $approved = array();

	/**
	 * Returns the pending requests.
	 *
	 * @return array<string, array<string, string>>
	 */
	public function get_pending(): array {
		return self::$pending;
	}

	/**
	 * Whether a caller is approved for a connector.
	 *
	 * @param string $caller_basename Caller basename.
	 * @param string $connector_id    Connector id.
	 * @return bool
	 */
	public function is_approved( string $caller_basename, string $connector_id ): bool {
		return ! empty( self::$approved[ $caller_basename ][ $connector_id ] );
	}
}
