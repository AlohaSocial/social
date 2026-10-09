<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Move;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\DnsLookup;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Move\BridgyTwin;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Post;
use OCA\Social\Service\PostService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BridgyTwinTest extends TestCase {
	private const TWIN = 'did:plc:3guzzweuqraryl3rdkimjamk';

	private array $endpoints = [self::TWIN => 'https://atproto.brid.gy'];
	private array $aka = [];
	private array $txt = [];
	private array $searchedFor = [];
	private array $found = [];

	private function actor(string $account): Person {
		$actor = new Person();
		$actor->setAccount($account);

		return $actor;
	}

	private function twins(?PostService $posts = null): BridgyTwin {
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('query')->willReturnCallback(function (string $method, array $params): array {
			if ($method === 'app.bsky.actor.searchActors') {
				$this->searchedFor[] = $params['q'];

				return ['actors' => array_map(static fn (string $did): array => ['did' => $did], $this->found)];
			}

			return match ($params['handle']) {
				'alice.social.example.com.ap.brid.gy' => ['did' => self::TWIN],
				'bob.social.example.com.ap.brid.gy' => ['did' => 'did:plc:notbridgyatall000000000'],
				default => throw new AtprotoException('Unable to resolve handle'),
			};
		});
		$plc = $this->createMock(PlcClient::class);
		$plc->method('data')->willReturnCallback(fn (string $did): array => [
			'alsoKnownAs' => $this->aka[$did] ?? [],
			'services' => ['atproto_pds' => ['endpoint' => $this->endpoints[$did] ?? 'https://bsky.social']],
		]);
		$dns = $this->createMock(DnsLookup::class);
		$dns->method('txt')->willReturnCallback(fn (string $name): array => $this->txt[$name] ?? []);

		return new BridgyTwin($appView, $plc, $posts ?? $this->createMock(PostService::class), $dns, new NullLogger());
	}

	public function testTheTwinsHandleIsTheFediverseAddressFlattened(): void {
		$this->assertSame('alice.social.example.com.ap.brid.gy', BridgyTwin::handleOf($this->actor('Alice@Social.Example.com')));
		$this->assertSame('al-ice.social.example.com.ap.brid.gy', BridgyTwin::handleOf($this->actor('al_ice@social.example.com')));
	}

	public function testATwinIsOneBridgyKeeps(): void {
		$this->assertSame(['handle' => 'alice.social.example.com.ap.brid.gy', 'did' => self::TWIN], $this->twins()->find($this->actor('alice@social.example.com')));
		$this->assertNull($this->twins()->find($this->actor('bob@social.example.com')), 'the handle names an account Bridgy does not keep');
		$this->assertNull($this->twins()->find($this->actor('carol@social.example.com')), 'not bridged');
		$this->assertTrue(BridgyTwin::hosts('https://atproto.brid.gy/'));
		$this->assertFalse(BridgyTwin::hosts('https://atproto.brid.gy.example.org'));
	}

	public function testARenamedTwinIsFoundByTheRecordBridgyKeepsForItsHandle(): void {
		$renamed = 'did:plc:renamedtwin0000000000000';
		$this->endpoints[$renamed] = 'https://atproto.brid.gy';
		$this->aka[$renamed] = ['at://carol.example.org', 'https://social.example.com/users/carol'];
		$this->txt['_atproto.carol.social.example.com.ap.brid.gy'] = ['did=' . $renamed];

		$this->assertSame(['handle' => 'carol.example.org', 'did' => $renamed], $this->twins()->find($this->actor('carol@social.example.com')), 'under the handle it has now');
		$this->assertSame([], $this->searchedFor, 'nothing was searched for');
	}

	public function testBlueskysSearchIsAskedOnlyWhenAskedAndTakenOnlyWhenTheTwinNamesTheAccount(): void {
		$carol = $this->actor('carol@social.example.com');
		$carol->setId('https://social.example.com/users/carol');
		$impostor = 'did:plc:impostor00000000000000000';
		$renamed = 'did:plc:renamedtwin0000000000000';
		$this->endpoints += [$impostor => 'https://atproto.brid.gy', $renamed => 'https://atproto.brid.gy'];
		$this->aka[$impostor] = ['at://carol.example.net', 'https://elsewhere.example/users/carol'];
		$this->aka[$renamed] = ['at://carol.example.org', 'https://social.example.com/users/carol'];
		$this->found = [$impostor, $renamed];

		$this->assertNull($this->twins()->find($carol));
		$this->assertSame([], $this->searchedFor);
		$this->assertSame(['handle' => 'carol.example.org', 'did' => $renamed], $this->twins()->find($carol, true));
		$this->assertSame(['carol@social.example.com'], $this->searchedFor);
	}

	public function testBridgyIsAskedWithItsOwnCommandInADirectMessage(): void {
		$posts = $this->createMock(PostService::class);
		$posts->expects($this->once())->method('createPost')->with($this->callback(function (Post $post): bool {
			$this->assertSame('@bsky.brid.gy@bsky.brid.gy migrate-to social.example.com alice@social.example.com alice.social.example.com code-1 code-1', $post->getContent());
			$this->assertSame(Stream::TYPE_DIRECT, $post->getType());

			return true;
		}));

		$this->twins($posts)->ask($this->actor('alice@social.example.com'), BridgyTwin::command('social.example.com', 'alice@social.example.com', 'alice.social.example.com', 'code-1'));
	}
}
