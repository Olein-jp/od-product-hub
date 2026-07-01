<?php

declare(strict_types=1);

namespace OD_Product_Hub_Client;

interface Clock {
	public function now(): int;
}
