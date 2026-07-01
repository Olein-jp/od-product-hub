<?php

declare(strict_types=1);

namespace OD_Product_Hub_Client;

interface StateStore {
	/** @return array<string, mixed>|null */
	public function get(): ?array;

	/** @param array<string, mixed> $state */
	public function set( array $state ): void;

	public function delete(): void;
}
