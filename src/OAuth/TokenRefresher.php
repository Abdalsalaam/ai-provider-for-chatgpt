<?php
/**
 * TokenRefresher class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\OAuth;

use Halawa\ChatGptAiProvider\Authentication\TokenStore;

/**
 * Rotates the stored tokens under a database lock and keeps them alive by cron.
 *
 * @since 0.1.0
 */
class TokenRefresher {

	public const LOCK_OPTION     = 'halawa_chatgpt_refresh_lock';
	public const LOCK_TTL        = 30;
	public const DUE_WINDOW      = 300;
	public const CRON_HOOK       = 'halawa_chatgpt_refresh_tokens';
	public const CRON_RECURRENCE = 'weekly';
	public const TERMINAL_CODES  = array( 'invalid_grant', 'invalid_refresh_token', 'token_expired', 'refresh_token_expired', 'refresh_token_invalidated', 'refresh_token_reused' );

	private const SESSION_ENDED        = 'ChatGPT session ended. Sign in again under Settings > ChatGPT.';
	private const CRON_INTERVAL_OFFSET = WEEK_IN_SECONDS;
	private const WAIT_POLLS           = 20;
	private const WAIT_POLL_MS         = 250;

	/**
	 * Token storage.
	 *
	 * @var TokenStore
	 */
	private $store;

	/**
	 * Token endpoint client.
	 *
	 * @var TokenEndpoint
	 */
	private $endpoint;

	/**
	 * Value of the lock row this process inserted, null when not holding it.
	 *
	 * @var string|null
	 */
	private $lock_value;

	/**
	 * Constructor.
	 *
	 * @since 0.1.0
	 *
	 * @param TokenStore         $store    Token storage.
	 * @param TokenEndpoint|null $endpoint Token endpoint client.
	 */
	public function __construct( TokenStore $store, ?TokenEndpoint $endpoint = null ) {
		$this->store    = $store;
		$this->endpoint = $endpoint ?? new TokenEndpoint();
	}

	/**
	 * Returns true when the access token expires within DUE_WINDOW or is unknown.
	 *
	 * @since 0.1.0
	 *
	 * @return bool True when a refresh is due.
	 */
	public function is_due(): bool {
		$expires_at = $this->store->get_expires_at();
		return 0 === $expires_at || $expires_at - time() < self::DUE_WINDOW;
	}

	/**
	 * Returns true when the access token has expired.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when expired.
	 */
	public function is_expired(): bool {
		return $this->store->get_expires_at() <= time();
	}

	/**
	 * Rotates the tokens unless another process holds the lock or already did.
	 *
	 * @since 0.1.0
	 *
	 * @param string|null $expected_refresh_token Refresh token the caller read, null to use the stored one.
	 * @return void
	 * @throws OAuthException When not connected, signed out, or the refresh fails.
	 */
	public function refresh( ?string $expected_refresh_token = null ): void {
		if ( null === $this->store->get() ) {
			throw new OAuthException( 'not_connected', 'The ChatGPT provider is not connected. Visit Settings > ChatGPT to sign in.' );
		}
		if ( $this->store->needs_signin() ) {
			throw new OAuthException( 'needs_signin', self::SESSION_ENDED ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed message; REST and UI escape on output.
		}
		$expected = $expected_refresh_token ?? $this->store->get_refresh_token();

		if ( ! $this->acquire_lock() ) {
			$this->wait_for_other_refresh();
			return;
		}
		try {
			// Another process may have rotated the record while this one waited.
			wp_cache_delete( TokenStore::OPTION_NAME, 'options' );
			$refresh_token = $this->store->get_refresh_token();
			$client_id     = $this->store->get_client_id();
			if ( null === $refresh_token || null === $client_id || $refresh_token !== $expected ) {
				return;
			}

			try {
				$tokens = $this->endpoint->refresh( $client_id, $refresh_token );
			} catch ( OAuthException $e ) {
				if ( in_array( $e->getErrorCode(), self::TERMINAL_CODES, true ) ) {
					$this->store->mark_needs_signin();
					throw new OAuthException( 'needs_signin', self::SESSION_ENDED, $e->getHttpStatus(), $e->getRequestId() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Fixed message; REST and UI escape on output.
				}
				throw $e;
			}

			$scopes = array_values( array_filter( explode( ' ', $tokens['scope'] ) ) );
			sort( $scopes );
			$fields = array(
				'access_token'        => $tokens['access_token'],
				'refresh_token'       => $tokens['refresh_token'],
				'id_token'            => $tokens['id_token'],
				'expires_at'          => time() + $tokens['expires_in'],
				'earliest_refresh_at' => $tokens['earliest_refresh_at'] ?? 0,
			);
			if ( array() !== $scopes ) {
				$fields['scopes'] = $scopes;
			}
			$this->store->update( $fields );
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Schedules the weekly refresh when it is not scheduled.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function schedule(): void {
		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + self::CRON_INTERVAL_OFFSET, self::CRON_RECURRENCE, self::CRON_HOOK );
		}
	}

	/**
	 * Removes the weekly refresh.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Cron callback: refreshes a live record so the refresh token stays valid.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	public static function run_cron(): void {
		$store = new TokenStore();
		if ( null === $store->get() || $store->needs_signin() ) {
			return;
		}
		try {
			( new self( $store ) )->refresh();
		} catch ( \Throwable $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Diagnostic under WP_DEBUG only.
				error_log( 'ai-provider-for-chatgpt: scheduled token refresh failed: ' . $e->getMessage() );
			}
		}
	}

	/**
	 * Inserts the lock row; one affected row means this process holds it.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when the lock was acquired.
	 */
	private function acquire_lock(): bool {
		global $wpdb;
		for ( $attempt = 0; $attempt < 2; $attempt++ ) {
			// add_option() is not atomic, so insert the way core's upgrader locks do.
			$value = (string) time();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock insert.
			$inserted = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", self::LOCK_OPTION, $value ) );
			if ( 1 === $inserted ) {
				$this->lock_value = $value;
				return true;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the lock row written above.
			$locked_at = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );
			if ( null !== $locked_at && (int) $locked_at > time() - self::LOCK_TTL ) {
				return false;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Removes only the stale row that was read.
			$wpdb->delete(
				$wpdb->options,
				array(
					'option_name'  => self::LOCK_OPTION,
					'option_value' => (string) $locked_at,
				)
			);
		}
		return false;
	}

	/**
	 * Waits for the lock holder when the current access token is already expired.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 * @throws OAuthException When the token is still expired after the wait.
	 */
	private function wait_for_other_refresh(): void {
		if ( ! $this->is_expired() ) {
			return;
		}
		$before = $this->store->get_access_token();
		for ( $i = 0; $i < self::WAIT_POLLS; $i++ ) {
			$this->pause( self::WAIT_POLL_MS );
			wp_cache_delete( TokenStore::OPTION_NAME, 'options' );
			if ( ! $this->lock_exists() || $this->store->get_access_token() !== $before ) {
				break;
			}
		}
		wp_cache_delete( TokenStore::OPTION_NAME, 'options' );
		if ( $this->is_expired() ) {
			throw new OAuthException( 'refresh_in_progress', 'ChatGPT tokens are being refreshed by another request. Try again in a moment.' );
		}
	}

	/**
	 * Sleeps between lock polls; tests override it to simulate the other process.
	 *
	 * @since 0.2.0
	 *
	 * @param int $milliseconds Pause length.
	 * @return void
	 */
	protected function pause( int $milliseconds ): void {
		usleep( $milliseconds * 1000 );
	}

	/**
	 * Returns true while a lock row exists.
	 *
	 * @since 0.2.0
	 *
	 * @return bool True when locked.
	 */
	private function lock_exists(): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock row is never read through the options API.
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK_OPTION ) );
	}

	/**
	 * Deletes the lock row.
	 *
	 * @since 0.2.0
	 *
	 * @return void
	 */
	private function release_lock(): void {
		global $wpdb;
		if ( null === $this->lock_value ) {
			return;
		}
		// A row with another value belongs to a process that replaced a stale lock.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Lock row is never read through the options API.
		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => self::LOCK_OPTION,
				'option_value' => $this->lock_value,
			)
		);
		$this->lock_value = null;
	}
}
