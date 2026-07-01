<?php

declare(strict_types=1);

namespace OD_Product_Hub_Client;

final class SystemClock implements Clock {
	public function now(): int {
		return time();
	}
}
