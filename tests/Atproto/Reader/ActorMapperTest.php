<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\AP;
use OCA\Social\Atproto\Reader\ActorMapper;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\Details;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ActorMapperTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	protected function setUp(): void {
		// the unit suite has no server: the registry answers with real models
		$ap = $this->createMock(AP::class);
		$ap->method('getItemFromType')->willReturnCallback(static function (string $type) {
			$item = $type === Image::TYPE ? new Image() : new Person();
			$item->setUrlCloud('https://social.test');

			return $item;
		});
		$ap->method('getItemFromData')->willReturnCallback(static function (array $data): Person {
			$person = new Person();
			$person->setUrlCloud('https://social.test');
			$person->import($data);

			return $person;
		});
		AP::set($ap);
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	public function testAProfileBecomesACachedActorWithTheHandleAsAccount(): void {
		$person = (new ActorMapper())->person($this->profile(), 'https://morel.us-east.host.bsky.network');

		$this->assertSame('https://bsky.app/profile/' . self::DID, $person->getId());
		$this->assertSame(Person::TYPE, $person->getType());
		$this->assertFalse($person->isLocal());
		$this->assertSame('alice.bsky.social', $person->getAccount(), 'the bare handle, no @');
		$this->assertSame('alice.bsky.social', $person->getPreferredUsername());
		$this->assertSame('Alice <3', $person->getName());
		$this->assertStringContainsString('Likes &lt;b&gt;tags&lt;/b&gt;', $person->getSummary(), 'the bio is plain text, shown as it was written');
		$this->assertStringContainsString('<br', $person->getSummary(), 'line breaks survive');
		$this->assertSame('https://bsky.app/profile/alice.bsky.social', $person->getUrl());
		$this->assertSame('https://bsky.app/profile/' . self::DID . '/followers', $person->getFollowers());
		$this->assertSame('', $person->getInbox(), 'nothing is ever delivered to a Bluesky account');
		$this->assertSame(1700000000, $person->getCreation());
		$this->assertTrue($person->hasIcon());
		$this->assertSame('https://cdn.bsky.app/img/avatar/plain/' . self::DID . '/bafkreiavatar@jpeg', $person->getIcon()->getUrl());
		$this->assertSame('image/jpeg', $person->getIcon()->getMediaType());
		$this->assertSame(['followers' => 12, 'following' => 34, 'post' => 56], $person->getDetails(Details::COUNT));
		$this->assertSame([
			'did' => self::DID, 'handle' => 'alice.bsky.social', 'pds' => 'https://morel.us-east.host.bsky.network',
			'labels' => ['!no-unauthenticated'], 'limited' => false, 'indexed_at' => '2026-10-08T10:00:00.000Z',
		], $person->getDetails(ActorMapper::DETAIL));
		$this->assertSame(['handle' => 'alice.bsky.social', 'did' => self::DID, 'url' => 'https://bsky.app/profile/alice.bsky.social', 'native' => true], $person->getDetails(Details::BLUESKY));
	}

	public function testAHiddenAccountIsLimitedAndABareProfileStillMaps(): void {
		$hidden = (new ActorMapper())->person(['did' => self::DID, 'handle' => 'alice.bsky.social', 'labels' => [['src' => 'did:plc:ar7c4by46qjdydhdevvrndac', 'uri' => 'at://' . self::DID, 'val' => '!hide']]]);
		$this->assertTrue($hidden->getDetails(ActorMapper::DETAIL)['limited']);
		$this->assertSame('alice.bsky.social', $hidden->getName(), 'the handle stands in for a missing display name');
		$this->assertSame('', $hidden->getSummary());
		$this->assertFalse($hidden->hasIcon());
		$this->assertSame(0, $hidden->getCreation(), 'unknown, so the policy does not call it new');
	}

	public function testTheCdnNamesTheFormat(): void {
		$this->assertSame('image/png', ActorMapper::mediaTypeOf('https://cdn.bsky.app/img/avatar/plain/did/cid@png'));
		$this->assertSame('image/jpeg', ActorMapper::mediaTypeOf('https://example.com/picture'));
		$this->assertSame(['porn', 'spam'], ActorMapper::labelValues([['val' => 'porn'], ['val' => 'spam'], ['val' => 'porn'], 'junk', ['val' => '']]));
	}

	private function profile(): array {
		return [
			'did' => self::DID,
			'handle' => 'Alice.bsky.social',
			'displayName' => 'Alice <3',
			'description' => "Likes <b>tags</b>\nand breaks",
			'avatar' => 'https://cdn.bsky.app/img/avatar/plain/' . self::DID . '/bafkreiavatar@jpeg',
			'banner' => 'https://cdn.bsky.app/img/banner/plain/' . self::DID . '/bafkreibanner@jpeg',
			'followersCount' => 12,
			'followsCount' => 34,
			'postsCount' => 56,
			'indexedAt' => '2026-10-08T10:00:00.000Z',
			'createdAt' => '2023-11-14T22:13:20.000Z',
			'labels' => [['src' => self::DID, 'uri' => 'at://' . self::DID . '/app.bsky.actor.profile/self', 'val' => '!no-unauthenticated', 'cts' => '2023-11-14T22:13:20.000Z']],
		];
	}
}
