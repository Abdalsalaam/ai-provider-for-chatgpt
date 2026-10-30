<?php
/**
 * ModelSettings class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\Settings;

use Throwable;
use WordPress\AiClient\AiClient;
use Halawa\ChatGptAiProvider\Metadata\ChatGptModelMetadataDirectory;
use Halawa\ChatGptAiProvider\Provider\ChatGptProvider;

/**
 * Site defaults for the model and reasoning effort used when a caller sets neither.
 *
 * @since 1.1.0
 */
final class ModelSettings {

	/**
	 * Option holding the model listed first, '' for the server order.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	public const DEFAULT_MODEL_OPTION = 'halawa_chatgpt_default_model';

	/**
	 * Option holding the reasoning effort sent with each request, '' for the model default.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	public const EFFORT_OPTION = 'halawa_chatgpt_reasoning_effort';

	/**
	 * Option holding the reasoning efforts each listed model accepts.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	public const MODEL_EFFORTS_OPTION = 'halawa_chatgpt_model_efforts';

	/**
	 * Transient prefix marking a model whose route rejected the reasoning parameter.
	 *
	 * @since 1.1.0
	 *
	 * @var string
	 */
	public const REJECTED_PREFIX = 'halawa_chatgpt_reasoning_rejected_';

	/**
	 * Efforts per model read in this request.
	 *
	 * @var array<string, array{efforts: list<string>, default: string|null}>|null
	 */
	private static $model_efforts = null;

	/**
	 * Hooks the cache invalidation to changes of the default model.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'add_option_' . self::DEFAULT_MODEL_OPTION, array( self::class, 'invalidate_model_list' ) );
		add_action( 'update_option_' . self::DEFAULT_MODEL_OPTION, array( self::class, 'invalidate_model_list' ) );
		add_action( 'delete_option_' . self::DEFAULT_MODEL_OPTION, array( self::class, 'invalidate_model_list' ) );
	}

	/**
	 * Drops the AI Client's cached model list so the new order applies at once.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function invalidate_model_list(): void {
		if ( ! class_exists( AiClient::class ) ) {
			return;
		}
		try {
			$directory = ChatGptProvider::modelMetadataDirectory();
			if ( $directory instanceof ChatGptModelMetadataDirectory ) {
				$directory->invalidateCaches();
			}
		} catch ( Throwable $e ) {
			unset( $e );
		}
	}

	/**
	 * Returns the saved default model id, '' when none is set.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public static function default_model(): string {
		$value = get_option( self::DEFAULT_MODEL_OPTION, '' );
		return is_string( $value ) ? self::sanitize_model( $value ) : '';
	}

	/**
	 * Returns the saved reasoning effort, '' when the model default applies.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public static function reasoning_effort(): string {
		$value = get_option( self::EFFORT_OPTION, '' );
		return is_string( $value ) ? self::sanitize_effort( $value ) : '';
	}

	/**
	 * Saves both settings, deleting an option set back to its default.
	 *
	 * @since 1.1.0
	 *
	 * @param string $model  Model id, '' for the server order.
	 * @param string $effort Effort, '' for the model default.
	 * @return void
	 */
	public static function save( string $model, string $effort ): void {
		$values = array(
			self::DEFAULT_MODEL_OPTION => self::sanitize_model( $model ),
			self::EFFORT_OPTION        => self::sanitize_effort( $effort ),
		);
		foreach ( $values as $option => $value ) {
			if ( '' === $value ) {
				delete_option( $option );
			} else {
				// Autoloaded because every prompt reads both.
				update_option( $option, $value, true );
			}
		}
	}

	/**
	 * Keeps a model id to the characters OpenAI model slugs use.
	 *
	 * @since 1.1.0
	 *
	 * @param string $value Raw value.
	 * @return string The id, or '' when it has other characters.
	 */
	public static function sanitize_model( string $value ): string {
		$value = trim( $value );
		return 1 === preg_match( '/^[A-Za-z0-9._:-]{1,100}$/', $value ) ? $value : '';
	}

	/**
	 * Keeps an effort to a lower-case word such as low or medium.
	 *
	 * @since 1.1.0
	 *
	 * @param string $value Raw value.
	 * @return string The effort, or '' when it is not a lower-case word.
	 */
	public static function sanitize_effort( string $value ): string {
		$value = trim( $value );
		return 1 === preg_match( '/^[a-z]{1,20}$/', $value ) ? $value : '';
	}

	/**
	 * Moves the default model to the front of a model map so the AI Client picks it first.
	 *
	 * @since 1.1.0
	 *
	 * @template T
	 * @param array<string, T> $models Models keyed by id, in server order.
	 * @return array<string, T>
	 */
	public static function prefer_default( array $models ): array {
		$id = self::default_model();
		if ( '' === $id || ! isset( $models[ $id ] ) ) {
			return $models;
		}
		return array( $id => $models[ $id ] ) + $models;
	}

	/**
	 * Returns the efforts each listed model accepts, keyed by model id.
	 *
	 * @since 1.1.0
	 *
	 * @return array<string, array{efforts: list<string>, default: string|null}>
	 */
	public static function model_efforts(): array {
		if ( null === self::$model_efforts ) {
			$stored              = get_option( self::MODEL_EFFORTS_OPTION, array() );
			self::$model_efforts = is_array( $stored ) ? $stored : array();
		}
		return self::$model_efforts;
	}

	/**
	 * Stores the efforts of the listed models that report them.
	 *
	 * @since 1.1.0
	 *
	 * @param list<array{id: string, efforts: list<string>|null, default_effort: string|null}> $entries Listed entries.
	 * @return void
	 */
	public static function record_efforts( array $entries ): void {
		$map = array();
		foreach ( $entries as $entry ) {
			if ( null !== $entry['efforts'] ) {
				$map[ $entry['id'] ] = array(
					'efforts' => $entry['efforts'],
					'default' => $entry['default_effort'],
				);
			}
		}
		if ( self::model_efforts() !== $map ) {
			update_option( self::MODEL_EFFORTS_OPTION, $map, false );
		}
		self::$model_efforts = $map;
	}

	/**
	 * Returns the effort to send for a model, or '' to send none.
	 *
	 * @since 1.1.0
	 *
	 * @param string $model Model id.
	 * @return string
	 */
	public static function effort_for( string $model ): string {
		$effort = self::reasoning_effort();
		if ( '' === $effort || false !== get_transient( self::rejected_key( $model ) ) ) {
			return '';
		}
		$known = self::model_efforts()[ $model ]['efforts'] ?? null;
		// A model whose efforts are unknown gets the effort; a rejection is remembered.
		if ( is_array( $known ) && ! in_array( $effort, $known, true ) ) {
			return '';
		}
		return $effort;
	}

	/**
	 * Remembers for a day that a model's route rejected the reasoning parameter.
	 *
	 * @since 1.1.0
	 *
	 * @param string $model Model id.
	 * @return void
	 */
	public static function mark_rejected( string $model ): void {
		set_transient( self::rejected_key( $model ), 1, DAY_IN_SECONDS );
	}

	/**
	 * Returns the rejection transient name for a model.
	 *
	 * @since 1.1.0
	 *
	 * @param string $model Model id.
	 * @return string
	 */
	private static function rejected_key( string $model ): string {
		return self::REJECTED_PREFIX . substr( md5( $model ), 0, 12 );
	}

	/**
	 * Forgets the efforts read in this request, for tests.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public static function reset(): void {
		self::$model_efforts = null;
	}
}
