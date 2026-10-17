<?php
/**
 * Refresher double for the API client tests.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\OAuth\TokenRefresher;

/**
 * Refresher double that records calls and runs a callback instead of HTTP.
 */
class Api_Test_Refresher extends TokenRefresher {

	/**
	 * Whether is_due() reports true.
	 *
	 * @var bool
	 */
	public $due = false;

	/**
	 * Whether is_expired() reports true.
	 *
	 * @var bool
	 */
	public $expired = false;

	/**
	 * Refresh tokens passed to refresh(), in call order.
	 *
	 * @var list<string|null>
	 */
	public $calls = array();

	/**
	 * Runs on refresh(); may change the store or throw.
	 *
	 * @var callable|null
	 */
	public $on_refresh;

	/**
	 * {@inheritDoc}
	 */
	public function is_due(): bool {
		return $this->due;
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_expired(): bool {
		return $this->expired;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string|null $expected_refresh_token Refresh token the caller read.
	 * @return string Always REFRESHED; the authentication layer ignores it.
	 */
	public function refresh( ?string $expected_refresh_token = null ): string {
		$this->calls[] = $expected_refresh_token;
		if ( null !== $this->on_refresh ) {
			call_user_func( $this->on_refresh );
		}
		return self::REFRESHED;
	}
}
