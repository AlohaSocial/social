<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Item;
use OCA\Social\Model\InstancePath;
use PHPUnit\Framework\TestCase;

class ItemTest extends TestCase {
	public function testAddToArrayIgnoresDuplicatesAndToAllIncludesTheSingleTo(): void {
		$item = new Item();
		$item->setTo('https://a.example/users/bob');

		$item->addToArray('https://a.example/users/alice')
			->addToArray('https://a.example/users/alice')
			->addToArray('https://a.example/users/carol');

		$this->assertSame(['https://a.example/users/alice', 'https://a.example/users/carol'], $item->getToArray());
		$this->assertSame(
			['https://a.example/users/alice', 'https://a.example/users/carol', 'https://a.example/users/bob'],
			$item->getToAll()
		);
	}

	public function testCcCanBeAddedOnceAndRemoved(): void {
		$item = new Item();

		$item->addCc('https://a.example/users/alice')
			->addCc('https://a.example/users/alice')
			->addCc('https://a.example/users/bob');

		$this->assertCount(2, $item->getCcArray());
		$this->assertTrue($item->hasCc('https://a.example/users/alice'));

		$item->removeCc('https://a.example/users/bob');
		$item->removeCc('https://nobody.example/');

		$this->assertFalse($item->hasCc('https://a.example/users/bob'));
		$this->assertSame(['https://a.example/users/alice'], $item->getCcArray());
	}

	public function testGetTagsCanFilterByType(): void {
		$item = new Item();
		$mention = ['type' => 'Mention', 'href' => 'https://a.example/users/bob', 'name' => '@bob'];
		$hashtag = ['type' => 'Hashtag', 'href' => 'https://a.example/tags/cats', 'name' => '#cats'];

		$item->addTag($mention)->addTag($hashtag);

		$this->assertSame([$mention, $hashtag], $item->getTags());
		$this->assertSame([$mention], $item->getTags('Mention'));
		$this->assertSame([$hashtag], $item->getTags('Hashtag'));
		$this->assertSame([], $item->getTags('Emoji'));
	}

	public function testActorObjectWinsOverTheActorId(): void {
		$item = new Item();
		$item->setActorId('https://a.example/users/old');

		$this->assertFalse($item->hasActor());
		$this->assertSame('https://a.example/users/old', $item->getActorId());

		$actor = new Person();
		$actor->setId('https://a.example/users/alice');
		$item->setActor($actor);

		$this->assertTrue($item->hasActor());
		$this->assertSame($actor, $item->getActor());
		$this->assertSame('https://a.example/users/alice', $item->getActorId());
	}

	public function testInstancePathsSkipEmptyUrisAndMerge(): void {
		$item = new Item();
		$inbox = new InstancePath('https://a.example/inbox', InstancePath::TYPE_INBOX);
		$shared = new InstancePath('https://b.example/inbox', InstancePath::TYPE_GLOBAL);

		$item->addInstancePath(new InstancePath(''));
		$item->addInstancePath($inbox);
		$item->addInstancePaths([$shared]);

		$this->assertSame([$inbox, $shared], $item->getInstancePaths());

		$item->setInstancePaths([$shared]);
		$this->assertSame([$shared], $item->getInstancePaths());
	}

	public function testOriginStoresHostSourceAndCreationTime(): void {
		$item = new Item();

		$item->setOrigin('mastodon.social', 2, 1714564800);

		$this->assertSame('mastodon.social', $item->getOrigin());
		$this->assertSame(2, $item->getOriginSource());
		$this->assertSame(1714564800, $item->getOriginCreationTime());
	}

	public function testAnOriginWithAPortKeepsItApartFromTheHost(): void {
		$item = new Item();

		$item->setOrigin('mastodon.social:8443', 2, 0);
		$this->assertSame('mastodon.social', $item->getOrigin());
		$this->assertSame('mastodon.social:8443', $item->getOriginAuthority());

		$item->setOrigin('[2001:db8::1]:8443', 2, 0);
		$this->assertSame('[2001:db8::1]', $item->getOrigin());

		$item->setOrigin('[2001:db8::1]', 2, 0);
		$this->assertSame('[2001:db8::1]', $item->getOrigin());
	}

	public function testScalarAccessorsRoundTrip(): void {
		$item = new Item();

		$item->setId('https://a.example/n/1')
			->setNid(5)
			->setType('Note')
			->setSubType('Mention')
			->setUrl('https://a.example/@alice/1')
			->setSummary('cw')
			->setPublished('2024-05-01T12:00:00Z')
			->setObjectId('https://a.example/n/0')
			->setTarget('https://b.example/users/alice')
			->setIconId('https://a.example/icon.png')
			->setSource('{}')
			->setUrlSocial('https://cloud.example.org/apps/social/')
			->setUrlCloud('https://cloud.example.org')
			->setAddress('cloud.example.org')
			->setLocal(true)
			->setCompleteDetails(true)
			->setBccArray(['https://a.example/users/bob']);

		$this->assertSame('https://a.example/n/1', $item->getId());
		$this->assertSame(5, $item->getNid());
		$this->assertSame('Note', $item->getType());
		$this->assertSame('Mention', $item->getSubType());
		$this->assertSame('https://a.example/@alice/1', $item->getUrl());
		$this->assertSame('cw', $item->getSummary());
		$this->assertSame('2024-05-01T12:00:00Z', $item->getPublished());
		$this->assertSame('https://a.example/n/0', $item->getObjectId());
		$this->assertSame('https://b.example/users/alice', $item->getTarget());
		$this->assertSame('https://a.example/icon.png', $item->getIconId());
		$this->assertSame('{}', $item->getSource());
		$this->assertSame('https://cloud.example.org/apps/social/', $item->getUrlSocial());
		$this->assertSame('https://cloud.example.org', $item->getUrlCloud());
		$this->assertSame('cloud.example.org', $item->getAddress());
		$this->assertTrue($item->isLocal());
		$this->assertTrue($item->isCompleteDetails());
		$this->assertSame(['https://a.example/users/bob'], $item->getBccArray());
	}
}
