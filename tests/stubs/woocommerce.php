<?php
/**
 * Minimal in-memory stand-ins for the WooCommerce classes the webhook handler uses.
 *
 * The order records what the handler does to it (status, notes, meta, saves) so a test can
 * assert that an ignored event left it alone.
 *
 * @package Straumur\Payments
 */

declare(strict_types=1);

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound

class WC_Order {

	/** @var int */
	private $id;

	/** @var string */
	private $payment_method;

	/** @var string */
	private $status = 'pending';

	/** @var array<string, mixed> */
	private $meta = array();

	/** @var string[] */
	public $notes = array();

	/** @var int */
	public $saves = 0;

	/** @var bool */
	public $paid = false;

	/** @var \Throwable|null Thrown from save(), to simulate a database failure. */
	public $fail_on_save;

	public function __construct( int $id, string $payment_method = 'straumur' ) {
		$this->id             = $id;
		$this->payment_method = $payment_method;
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_payment_method(): string {
		return $this->payment_method;
	}

	public function get_order_key(): string {
		return 'wc_order_key' . $this->id;
	}

	public function get_status(): string {
		return $this->status;
	}

	public function get_user_id(): int {
		return 0;
	}

	public function get_meta( string $key, bool $single = true ) {
		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( string $key, $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function delete_meta_data( string $key ): void {
		unset( $this->meta[ $key ] );
	}

	public function add_order_note( string $note ): int {
		$this->notes[] = $note;
		return count( $this->notes );
	}

	public function payment_complete(): bool {
		$this->paid   = true;
		$this->status = 'processing';
		return true;
	}

	public function needs_processing(): bool {
		return true;
	}

	public function update_status( string $status, string $note = '' ): bool {
		$this->status = $status;
		if ( '' !== $note ) {
			$this->notes[] = $note;
		}
		return true;
	}

	public function get_refunds(): array {
		return array();
	}

	public function save(): int {
		if ( null !== $this->fail_on_save ) {
			throw $this->fail_on_save;
		}
		++$this->saves;
		return $this->id;
	}

	/**
	 * Whether anything was written to the order.
	 */
	public function is_untouched(): bool {
		return 'pending' === $this->status && array() === $this->notes && array() === $this->meta && 0 === $this->saves && ! $this->paid;
	}
}

/**
 * Collects log lines instead of writing them, so tests can check what reaches the log.
 */
class Test_Logger {

	/** @var array<int, array{level: string, message: string}> */
	public $entries = array();

	public function info( string $message, array $context = array() ): void {
		$this->entries[] = array( 'level' => 'info', 'message' => $message );
	}

	public function warning( string $message, array $context = array() ): void {
		$this->entries[] = array( 'level' => 'warning', 'message' => $message );
	}

	public function error( string $message, array $context = array() ): void {
		$this->entries[] = array( 'level' => 'error', 'message' => $message );
	}

	public function text(): string {
		return implode( "\n", array_column( $this->entries, 'message' ) );
	}
}
