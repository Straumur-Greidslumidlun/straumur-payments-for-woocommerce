<?php
/**
 * Minimal stand-ins for the WordPress classes the plugin uses.
 *
 * Only the methods the plugin calls are implemented, with WordPress's behaviour for them.
 *
 * @package Straumur\Payments
 */

declare(strict_types=1);

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

class WP_Error {

	/** @var string */
	private $code;

	/** @var string */
	private $message;

	/** @var mixed */
	private $data;

	public function __construct( $code = '', $message = '', $data = '' ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

class WP_REST_Request {

	/** @var string */
	private $body = '';

	public function __construct( string $method = '', string $route = '' ) {
	}

	public function set_body( string $body ): void {
		$this->body = $body;
	}

	public function get_body() {
		return $this->body;
	}
}

class WP_REST_Response {

	/** @var mixed */
	private $data;

	/** @var int */
	private $status;

	public function __construct( $data = null, int $status = 200 ) {
		$this->data   = $data;
		$this->status = $status;
	}

	public function get_data() {
		return $this->data;
	}

	public function get_status(): int {
		return $this->status;
	}
}

class WP_REST_Server {
	const CREATABLE = 'POST';
}
