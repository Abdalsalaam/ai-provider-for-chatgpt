<?php
/**
 * Stand-in for the AI plugin's Connector Approval request guard.
 *
 * @package Halawa\ChatGptAiProvider
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedNamespaceFound -- Mirrors the AI plugin's namespace.
namespace WordPress\AI\Connector_Approval;

/**
 * Hooked on pre_http_request while the experiment is on.
 */
class Http_Guard {

	/**
	 * Lets every request through.
	 *
	 * @param mixed $preempt Incoming value.
	 * @return mixed
	 */
	public function maybe_block_request( $preempt ) {
		return $preempt;
	}
}
