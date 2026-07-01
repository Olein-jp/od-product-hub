<?php

declare(strict_types=1);

namespace OD_Product_Hub_Client\WordPress;

use OD_Product_Hub_Client\StateStore;

final class OptionStateStore implements StateStore {
	public function __construct( private readonly string $option_name ) {}

	public function get(): ?array {
		$value = get_option( $this->option_name, null );
		return is_array( $value ) ? $value : null;
	}

	public function set( array $state ): void {
		update_option( $this->option_name, $state, false );
	}

	public function delete(): void {
		delete_option( $this->option_name );
	}
}
