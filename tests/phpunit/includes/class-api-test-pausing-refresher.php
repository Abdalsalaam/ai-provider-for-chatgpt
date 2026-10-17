<?php
/**
 * Real refresher whose lock wait does not sleep.
 *
 * @package Halawa\ChatGptAiProvider
 */

use Halawa\ChatGptAiProvider\OAuth\TokenRefresher;

/**
 * Runs a callback on each pause so a test can act as the lock holder.
 */
class Api_Test_Pausing_Refresher extends TokenRefresher {

	/**
	 * Number of pauses so far.
	 *
	 * @var int
	 */
	public $pauses = 0;

	/**
	 * Runs on each pause with the pause count.
	 *
	 * @var callable|null
	 */
	public $on_pause;

	/**
	 * {@inheritDoc}
	 *
	 * @param int $milliseconds Ignored.
	 */
	protected function pause( int $milliseconds ): void {
		++$this->pauses;
		if ( null !== $this->on_pause ) {
			call_user_func( $this->on_pause, $this->pauses );
		}
	}
}
