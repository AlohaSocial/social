<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Integration\Atproto;

use OCA\Social\Atproto\Identity\HandleVerifier;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Publisher\PictureService;
use OCA\Social\Atproto\Publisher\VideoBlobService;
use OCA\Social\Atproto\Repository\RepositoryService;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Atproto\Xrpc\XrpcService;
use OCA\Social\Db\AtprotoBlobRequest;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Db\ModerationRequest;
use OCA\Social\Model\Moderation;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Tests\Integration\QueryCounter;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * `com.atproto.sync.listRepos` against the real database: a page asks for
 * its identities and for which of them are suspended at once, so a page
 * of fifty repositories costs the queries a page of five does.
 */
class ListReposQueryBudgetTest extends TestCase {
	private const PAGE = 50;

	private AtprotoIdentityRequest $identityRequest;
	private AtprotoRepoRequest $repoRequest;
	private ModerationRequest $moderationRequest;
	private XrpcService $xrpc;
	/** @var array<string, string> did => actor id */
	private array $seeded = [];

	protected function setUp(): void {
		$this->identityRequest = Server::get(AtprotoIdentityRequest::class);
		$this->repoRequest = Server::get(AtprotoRepoRequest::class);
		$this->moderationRequest = Server::get(ModerationRequest::class);
		$config = $this->createStub(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$this->xrpc = new XrpcService(
			$config, Server::get(IdentityService::class), Server::get(RepositoryService::class), $this->repoRequest,
			Server::get(AtprotoBlobRequest::class), Server::get(PictureService::class), Server::get(VideoBlobService::class),
			Server::get(ConfigService::class), Server::get(ModerationService::class), $this->identityRequest, Server::get(HandleVerifier::class), Server::get(PlcClient::class),
		);
	}

	protected function tearDown(): void {
		foreach ($this->seeded as $did => $actorId) {
			$this->repoRequest->deleteRepository($did);
			$this->identityRequest->deleteByDid($did);
			$this->moderationRequest->delete($actorId);
		}
	}

	public function testAPageOfFiftyCostsWhatAPageOfFiveCosts(): void {
		$cursor = (string)$this->repoRequest->countHeads();
		$page = fn (): array => $this->xrpc->query('com.atproto.sync.listRepos', ['limit' => (string)self::PAGE, 'cursor' => $cursor]);

		$this->seed(5);
		$suspended = array_key_first($this->seeded);
		$this->moderationRequest->save(new Moderation($this->seeded[$suspended], Moderation::SUSPEND, '', time()));
		$small = QueryCounter::record($page);
		$this->assertCount(5, $small->result()['repos']);

		$this->seed(self::PAGE - 5);
		$large = QueryCounter::record($page);
		$repos = $large->result()['repos'];
		$this->assertCount(self::PAGE, $repos, 'the larger seed fills a whole page, or the comparison proves nothing');

		$this->assertSame(
			$small->count(), $large->count(),
			'a page of ' . self::PAGE . ' costs more queries than a page of 5' . "\nwith 5, " . $small->summary() . "\nwith " . self::PAGE . ', ' . $large->summary(),
		);
		$this->assertSame(['takendown'], array_values(array_filter(array_map(static fn (array $repo): string => $repo['status'] ?? '', $repos))));
		$this->assertSame($suspended, $repos[0]['did']);
	}

	private function seed(int $count): void {
		for ($i = 0; $i < $count; $i++) {
			$did = 'did:plc:' . substr(str_shuffle(str_repeat('abcdefghijklmnopqrstuvwxyz234567', 2)), 0, 24);
			$name = 'lr' . bin2hex(random_bytes(5));
			$actorId = 'https://social.example.com/users/' . $name;
			$this->identityRequest->create($actorId, $did, $name . '.social.example.com', '', 'did:key:z', '');
			$this->repoRequest->setHead($did, Cid::forRaw($did), '3kznmn7xqxl22', 1);
			$this->seeded[$did] = $actorId;
		}
	}
}
