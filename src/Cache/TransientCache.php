<?php
/**
 * TransientCache class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\Cache;

use DateInterval;
use DateTimeImmutable;
use Psr\SimpleCache\CacheInterface;

/**
 * PSR-16 cache adapter backed by WordPress transients.
 *
 * The PHP AI Client SDK caches the `/models` response through `AiClient::setCache()`,
 * but defaults to a per-request in-memory array when no PSR-16 cache is wired in —
 * so without this adapter the Codex `/models` endpoint is hit on every request that
 * touches the provider (admin dashboard checks, AI picker renders, etc.).
 *
 * Keys are sanitized to WordPress's transient name constraints (≤ 172 chars,
 * alnum + `_` and `-`). The cleanup logic in CacheController already targets
 * `_transient_ai_client_*`, so this adapter is transparent to existing flows.
 *
 * @since 0.1.0
 */
final class TransientCache implements CacheInterface {

	/**
	 * Default TTL when the SDK passes null.
	 *
	 * @since 0.1.0
	 *
	 * @var int
	 */
	private const DEFAULT_TTL = DAY_IN_SECONDS;

	/**
	 * Normalizes a PSR-16 key to a WordPress-safe transient name.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key The PSR-16 key.
	 * @return string The transient name.
	 */
	private function normalize_key( string $key ): string {
		// Hash anything that wouldn't fit in a transient name; keep a readable
		// prefix so the SDK-emitted keys (already starting with `ai_client_`)
		// remain inspectable in wp_options.
		if ( preg_match( '/^[A-Za-z0-9_-]{1,172}$/', $key ) === 1 ) {
			return $key;
		}
		return 'ai_client_' . md5( $key );
	}

	/**
	 * Converts the PSR-16 TTL union to integer seconds.
	 *
	 * @since 0.1.0
	 *
	 * @param int|DateInterval|null $ttl The TTL value.
	 * @return int Seconds.
	 */
	private function ttl_seconds( $ttl ): int {
		if ( null === $ttl ) {
			return self::DEFAULT_TTL;
		}
		if ( is_int( $ttl ) ) {
			return $ttl > 0 ? $ttl : 0;
		}
		if ( $ttl instanceof DateInterval ) {
			$now = new DateTimeImmutable();
			return $now->add( $ttl )->getTimestamp() - $now->getTimestamp();
		}
		return self::DEFAULT_TTL;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param string $key     Cache key.
	 * @param mixed  $default Default value when missing.
	 * @return mixed
	 */
	public function get( $key, $default = null ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- Inherited from PSR-16 CacheInterface::get(); cannot rename.
		$value = get_transient( $this->normalize_key( (string) $key ) );
		return false === $value ? $default : $value;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param string                $key   Cache key.
	 * @param mixed                 $value Value to cache.
	 * @param int|DateInterval|null $ttl   TTL.
	 * @return bool
	 */
	public function set( $key, $value, $ttl = null ): bool {
		return (bool) set_transient(
			$this->normalize_key( (string) $key ),
			$value,
			$this->ttl_seconds( $ttl )
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Cache key.
	 * @return bool
	 */
	public function delete( $key ): bool {
		return (bool) delete_transient( $this->normalize_key( (string) $key ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function clear(): bool {
		global $wpdb;
		// PSR-16 clear() must wipe every cache entry we own. WP has no public
		// API for prefix-matched transient deletion, so a direct prepared LIKE
		// on wp_options is the established pattern.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Prefix-matched bulk delete; no caching equivalent.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_ai_client_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_ai_client_' ) . '%'
			)
		);
		if ( wp_using_ext_object_cache() ) {
			wp_cache_flush_group( 'transient' );
		}
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param iterable $keys    Keys to fetch.
	 * @param mixed    $default Default value for missing keys.
	 * @return iterable
	 */
	public function getMultiple( $keys, $default = null ): iterable { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.defaultFound -- Inherited from PSR-16 CacheInterface::getMultiple(); cannot rename.
		$out = array();
		foreach ( $keys as $key ) {
			$out[ (string) $key ] = $this->get( (string) $key, $default );
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param iterable              $values Key/value pairs.
	 * @param int|DateInterval|null $ttl    TTL.
	 * @return bool
	 */
	public function setMultiple( $values, $ttl = null ): bool {
		$ok = true;
		foreach ( $values as $key => $value ) {
			$ok = $this->set( (string) $key, $value, $ttl ) && $ok;
		}
		return $ok;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param iterable $keys Keys to delete.
	 * @return bool
	 */
	public function deleteMultiple( $keys ): bool {
		$ok = true;
		foreach ( $keys as $key ) {
			$ok = $this->delete( (string) $key ) && $ok;
		}
		return $ok;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Cache key.
	 * @return bool
	 */
	public function has( $key ): bool {
		return false !== get_transient( $this->normalize_key( (string) $key ) );
	}
}
