<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub\Object;

use OCA\Social\AP;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../TActivityPubMocks.php';

class AnnounceTest extends TestCase {
	use TActivityPubMocks;

	protected function setUp(): void {
		$this->installActivityPub();
		\OC::$server->register(IURLGenerator::class, $this->createStub(IURLGenerator::class));
	}

	protected function tearDown(): void {
		AP::set(null);
		\OC::$server->reset();
	}

	public function testIsAStreamOfTypeAnnounce(): void {
		$announce = new Announce();

		$this->assertInstanceOf(Stream::class, $announce);
		$this->assertSame('Announce', $announce->getType());
	}

	public function testImportReadsAMastodonBoost(): void {
		$announce = new Announce();

		$announce->import([
			'id' => 'https://mastodon.social/users/alice/statuses/2/activity',
			'type' => 'Announce',
			'actor' => 'https://mastodon.social/users/alice',
			'published' => '2024-05-01T12:00:00Z',
			'to' => ['https://mastodon.social/users/alice/followers'],
			'cc' => ['https://remote.example/users/bob', ACore::CONTEXT_PUBLIC],
			'object' => 'https://remote.example/users/bob/statuses/1',
		]);

		$this->assertSame('https://mastodon.social/users/alice', $announce->getActorId());
		$this->assertSame('https://remote.example/users/bob/statuses/1', $announce->getObjectId());
		$this->assertTrue($announce->isPublic());
		$this->assertSame(1714564800, $announce->getPublishedTime());
	}

	public function testExportAsLocalEmbedsTheBoostedStatusAsReblog(): void {
		$boosted = new Note();
		$boosted->setId('https://remote.example/users/bob/statuses/1')
			->setNid(5)
			->setContent('<p>original</p>');
		$announce = new Announce();
		$announce->setId('https://mastodon.social/users/alice/statuses/2/activity');

		$this->assertNull($announce->exportAsLocal()['reblog']);

		$announce->setObject($boosted);
		$reblog = $announce->exportAsLocal()['reblog'];

		$this->assertSame('5', $reblog['id']);
		$this->assertSame('<p>original</p>', $reblog['content']);
		$this->assertSame('https://remote.example/users/bob/statuses/1', $reblog['uri']);
	}

	/**
	 * Only a Stream exports the shape of a status, so an `Announce` of anything
	 * else has no `reblog` rather than one a client cannot decode.
	 */
	public function testABoostOfSomethingThatIsNotAPostHasNoReblog(): void {
		$announce = new Announce();
		$announce->setId('https://mastodon.social/users/alice/statuses/2/activity');
		$announce->setObject(new Person());

		$this->assertNull($announce->exportAsLocal()['reblog']);
	}
}
