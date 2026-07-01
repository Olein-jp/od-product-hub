<?php

declare(strict_types=1);

namespace OD_Product_Hub_Client\Tests;

use OD_Product_Hub_Client\Client;
use OD_Product_Hub_Client\Clock;
use OD_Product_Hub_Client\Config;
use OD_Product_Hub_Client\StateStore;
use OD_Product_Hub_Client\Transport;
use OD_Product_Hub_Client\TransportException;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase {
	public function test_activate_and_cached_verify_return_active(): void {
		$transport = new FakeTransport( array( $this->active_response() ) );
		$client    = $this->client( $transport );
		$activated = $client->activate( 'ODPH-ABCD-EFGH-JKLM-NPQR' );
		$verified  = $client->verify( 'ODPH-ABCD-EFGH-JKLM-NPQR' );

		self::assertTrue( $activated->is_active() );
		self::assertSame( 'cache', $verified->source );
		self::assertCount( 1, $transport->requests );
	}

	public function test_business_inactive_is_immediate_and_never_uses_grace(): void {
		$clock     = new FakeClock( 1000 );
		$transport = new FakeTransport(
			array(
				$this->active_response(),
				array( 'success' => false, 'status' => 'suspended', 'error_code' => 'license_suspended', 'message' => 'Suspended.' ),
			)
		);
		$client = $this->client( $transport, $clock );
		$client->activate( 'ODPH-ABCD-EFGH-JKLM-NPQR' );
		$clock->now = 90000;
		$result     = $client->verify( 'ODPH-ABCD-EFGH-JKLM-NPQR', true );

		self::assertFalse( $result->is_active() );
		self::assertSame( 'suspended', $result->status );
		self::assertFalse( $result->is_grace_period() );
	}

	public function test_transport_failure_uses_only_a_72_hour_active_grace(): void {
		$clock     = new FakeClock( 1000 );
		$transport = new FakeTransport( array( $this->active_response(), new TransportException( 'offline' ), new TransportException( 'offline' ) ) );
		$client    = $this->client( $transport, $clock );
		$client->activate( 'ODPH-ABCD-EFGH-JKLM-NPQR' );
		$clock->now = 200000;
		$grace      = $client->verify( 'ODPH-ABCD-EFGH-JKLM-NPQR', true );
		$clock->now = 270201;
		$expired    = $client->verify( 'ODPH-ABCD-EFGH-JKLM-NPQR', true );

		self::assertTrue( $grace->is_active() );
		self::assertTrue( $grace->is_grace_period() );
		self::assertFalse( $expired->is_active() );
		self::assertSame( 'unavailable', $expired->status );
	}

	public function test_deactivate_clears_local_state(): void {
		$transport = new FakeTransport( array( $this->active_response(), array( 'success' => true, 'status' => 'active' ) ) );
		$client    = $this->client( $transport );
		$client->activate( 'ODPH-ABCD-EFGH-JKLM-NPQR' );
		$result = $client->deactivate( 'ODPH-ABCD-EFGH-JKLM-NPQR' );

		self::assertSame( 'deactivated', $result->status );
		self::assertSame( 'unverified', $client->current()->status );
	}

	public function test_a_different_license_key_never_reuses_active_cache(): void {
		$transport = new FakeTransport( array( $this->active_response(), array( 'success' => false, 'status' => 'inactive', 'error_code' => 'invalid_license' ) ) );
		$client    = $this->client( $transport );
		$client->activate( 'ODPH-ABCD-EFGH-JKLM-NPQR' );
		$result = $client->verify( 'ODPH-QRST-UVWX-YZAB-CDEF' );

		self::assertFalse( $result->is_active() );
		self::assertCount( 2, $transport->requests );
	}

	private function client( FakeTransport $transport, ?FakeClock $clock = null ): Client {
		return new Client(
			new Config( 'https://hub.example.test', 'example-plugin', '1.0.0', 'https://site.example.test' ),
			$transport,
			new MemoryStore(),
			$clock ?? new FakeClock( 1000 )
		);
	}

	/** @return array<string, mixed> */
	private function active_response(): array {
		return array( 'success' => true, 'status' => 'active', 'message' => 'Active.' );
	}
}

final class FakeClock implements Clock {
	public function __construct( public int $now ) {}
	public function now(): int { return $this->now; }
}

final class MemoryStore implements StateStore {
	/** @var array<string, mixed>|null */
	private ?array $value = null;
	public function get(): ?array { return $this->value; }
	public function set( array $state ): void { $this->value = $state; }
	public function delete(): void { $this->value = null; }
}

final class FakeTransport implements Transport {
	/** @var list<array<string, mixed>|TransportException> */
	private array $responses;
	/** @var list<array{url: string, payload: array<string, scalar>}> */
	public array $requests = array();
	/** @param list<array<string, mixed>|TransportException> $responses */
	public function __construct( array $responses ) { $this->responses = $responses; }
	public function post( string $url, array $payload ): array {
		$this->requests[] = array( 'url' => $url, 'payload' => $payload );
		$response = array_shift( $this->responses );
		if ( $response instanceof TransportException ) { throw $response; }
		return $response ?? array();
	}
}
