<?php
/**
 * OAuthException class file.
 *
 * @package Halawa\ChatGptAiProvider
 */

declare(strict_types=1);

namespace Halawa\ChatGptAiProvider\OAuth;

/**
 * Sign-in, token or verification failure with a machine-readable code.
 *
 * @since 0.2.0
 */
final class OAuthException extends \RuntimeException {

	/**
	 * Machine-readable error code.
	 *
	 * @var string
	 */
	private $error_code;

	/**
	 * HTTP status of the failed response, 0 when none.
	 *
	 * @var int
	 */
	private $http_status;

	/**
	 * Value of the x-oai-request-id header, when present.
	 *
	 * @var string|null
	 */
	private $request_id;

	/**
	 * Constructor.
	 *
	 * @since 0.2.0
	 *
	 * @param string      $code        OpenAI error value or a local code.
	 * @param string      $message     Message safe to show an admin.
	 * @param int         $http_status HTTP status, 0 when none.
	 * @param string|null $request_id  OpenAI request id.
	 */
	public function __construct( string $code, string $message, int $http_status = 0, ?string $request_id = null ) {
		parent::__construct( $message );
		$this->error_code  = $code;
		$this->http_status = $http_status;
		$this->request_id  = $request_id;
	}

	/**
	 * Returns the machine-readable error code.
	 *
	 * @since 0.2.0
	 *
	 * @return string The code.
	 */
	public function getErrorCode(): string {
		return $this->error_code;
	}

	/**
	 * Returns the HTTP status, 0 when the failure was not an HTTP response.
	 *
	 * @since 0.2.0
	 *
	 * @return int The status.
	 */
	public function getHttpStatus(): int {
		return $this->http_status;
	}

	/**
	 * Returns the OpenAI request id, when present.
	 *
	 * @since 0.2.0
	 *
	 * @return string|null The request id.
	 */
	public function getRequestId(): ?string {
		return $this->request_id;
	}
}
