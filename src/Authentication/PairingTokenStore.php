<?php
/**
 * PairingTokenStore class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\Authentication;

use Exception;

/**
 * Issues short-lived, single-use pairing tokens that the companion CLI uses to
 * post a freshly-minted OAuth bundle back to this site without holding an admin
 * cookie.
 *
 * Security model:
 * - Tokens are 32 random bytes (256 bits), stored as a transient keyed by the
 *   SHA-256 of the token. Plaintext tokens never touch the database.
 * - Redemption is atomic and single-use: `consume()` relies on the boolean
 *   return value of `delete_transient()` rather than a non-atomic
 *   get-then-delete sequence, so two concurrent redemptions cannot both win.
 * - Only one pairing token is valid at a time. Issuing a new token revokes any
 *   prior outstanding token, shrinking the window where a leaked token is
 *   useful.
 * - TTL is short (10 minutes) so an unredeemed token cannot sit around
 *   waiting to be intercepted.
 *
 * @since 0.1.0
 */
final class PairingTokenStore {

	private const TRANSIENT_PREFIX = 'halawa_chatgpt_pair_';
	private const ACTIVE_OPTION    = 'halawa_chatgpt_pair_active';
	private const TTL_SECONDS      = 600;

	/**
	 * Generates a fresh pairing token, revoking any prior outstanding token.
	 *
	 * @since 0.1.0
	 *
	 * @return array{token: string, expires_at: int} The plaintext token and absolute expiry.
	 * @throws Exception When the system CSPRNG is unavailable (random_bytes).
	 */
	public function issue(): array {
		$prior = get_option( self::ACTIVE_OPTION, '' );
		if ( is_string( $prior ) && '' !== $prior ) {
			delete_transient( self::TRANSIENT_PREFIX . $prior );
		}

		$token       = bin2hex( random_bytes( 32 ) );
		$fingerprint = $this->fingerprint( $token );
		set_transient( self::TRANSIENT_PREFIX . $fingerprint, 1, self::TTL_SECONDS );
		update_option( self::ACTIVE_OPTION, $fingerprint, false );

		return array(
			'token'      => $token,
			'expires_at' => time() + self::TTL_SECONDS,
		);
	}

	/**
	 * Atomically consumes a pairing token, returning true on success.
	 *
	 * Uses `delete_transient()`'s atomic boolean return value so two
	 * concurrent redemptions cannot both observe the token as valid.
	 *
	 * @since 0.1.0
	 *
	 * @param string $token The plaintext token provided by the CLI.
	 * @return bool True when the token was valid and has now been spent.
	 */
	public function consume( string $token ): bool {
		if ( ! self::is_well_formed( $token ) ) {
			return false;
		}
		$fingerprint = $this->fingerprint( $token );
		$key         = self::TRANSIENT_PREFIX . $fingerprint;

		// delete_transient is atomic: the DB DELETE / wp_cache_delete returns
		// true only when the row/key actually existed, so this guards against
		// double-consume races.
		$deleted = delete_transient( $key );

		$active = get_option( self::ACTIVE_OPTION, '' );
		if ( is_string( $active ) && hash_equals( $active, $fingerprint ) ) {
			delete_option( self::ACTIVE_OPTION );
		}

		return $deleted;
	}

	/**
	 * Validates that a candidate token has the expected shape before any work.
	 *
	 * Mirrors the REST validate_callback so internal callers and tests get the
	 * same gate without having to re-derive the regex.
	 *
	 * @since 0.1.0
	 *
	 * @param string $token Candidate plaintext token.
	 * @return bool True when the token is a 64-char lowercase hex string.
	 */
	public static function is_well_formed( string $token ): bool {
		return 1 === preg_match( '/^[a-f0-9]{64}$/', $token );
	}

	/**
	 * Derives the transient key suffix from a plaintext token.
	 *
	 * @since 0.1.0
	 *
	 * @param string $token The plaintext token.
	 * @return string Hex SHA-256 digest.
	 */
	private function fingerprint( string $token ): string {
		return hash( 'sha256', $token );
	}
}
