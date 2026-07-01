<?php

declare(strict_types=1);

namespace OD_Product_Hub_Client;

final class ContractResult {
	/** @param array<string, mixed> $data */
	public function __construct(
		public readonly bool $active,
		public readonly string $status,
		public readonly ?string $error_code,
		public readonly string $message,
		public readonly string $source,
		public readonly int $checked_at,
		public readonly array $data = array()
	) {}

	public function is_active(): bool {
		return $this->active;
	}

	public function is_grace_period(): bool {
		return 'grace' === $this->status;
	}

	public function is_service_available(): bool {
		return $this->active;
	}

	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'active'     => $this->active,
			'status'     => $this->status,
			'error_code' => $this->error_code,
			'message'    => $this->message,
			'source'     => $this->source,
			'checked_at' => $this->checked_at,
			'data'       => $this->data,
		);
	}

	/** @param array<string, mixed> $value */
	public static function from_array( array $value ): self {
		return new self(
			(bool) ( $value['active'] ?? false ),
			(string) ( $value['status'] ?? 'unavailable' ),
			isset( $value['error_code'] ) ? (string) $value['error_code'] : null,
			(string) ( $value['message'] ?? '' ),
			(string) ( $value['source'] ?? 'cache' ),
			(int) ( $value['checked_at'] ?? 0 ),
			is_array( $value['data'] ?? null ) ? $value['data'] : array()
		);
	}
}
