<?php

declare(strict_types=1);

namespace OCA\Social\Tests\Atproto;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Repository\Repository;
use OCA\Social\Command\Atproto\CrawlCommand;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

class RelayRegistrationTest extends TestCase {
	private function command(string $relays, IClientService $clients): CommandTester {
		$config = $this->createStub(IConfig::class);
		$config->method('getAppValue')->willReturn($relays);
		$identities = $this->createStub(IdentityService::class);
		$identities->method('isEnabled')->willReturn(true);
		$identities->method('getPdsEndpoint')->willReturn('https://pds.example:8443');
		return new CommandTester(new CrawlCommand($this->createStub(PlcClient::class), $identities, $this->createStub(Repository::class), $this->createStub(IDBConnection::class), $config, new NullLogger(), $clients));
	}
	public function testEmptyRelayListCannotClaimRegistration(): void {
		$clients = $this->createMock(IClientService::class);
		$clients->expects(self::never())->method('newClient');
		$command = $this->command('[]', $clients);
		self::assertSame(1, $command->execute([]));
		self::assertStringContainsString('no crawl has been requested', $command->getDisplay());
	}
	public function testRegistrationPreservesTheConfiguredPdsPort(): void {
		$response = $this->createStub(IResponse::class);
		$response->method('getStatusCode')->willReturn(200);
		$client = $this->createMock(IClient::class);
		$client->expects(self::once())->method('post')->with('https://relay.example/xrpc/com.atproto.sync.requestCrawl', self::callback(static fn (array $options): bool => json_decode($options['body'], true) === ['hostname' => 'pds.example:8443']))->willReturn($response);
		$clients = $this->createMock(IClientService::class);
		$clients->expects(self::once())->method('newClient')->willReturn($client);
		$command = $this->command('["https://relay.example"]', $clients);
		self::assertSame(0, $command->execute([]));
		self::assertStringContainsString('Accepted', $command->getDisplay());
	}
}
