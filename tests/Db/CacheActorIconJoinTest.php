<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Db;

use InvalidArgumentException;
use OCA\Social\AP;
use OCA\Social\Db\SocialQueryBuilder;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

/**
 * The avatar of an actor read with its cached icon joined in.
 *
 * The address is built per row from the joined document, and a document that
 * is still being fetched has no copy to name: the route was built with an
 * empty uuid, the router threw, and every row that carried such an actor
 * answered 500.
 */
#[AllowMockObjectsWithoutExpectations]
class CacheActorIconJoinTest extends TestCase {
	use TActivityPubMocks;

	protected function setUp(): void {
		$this->installActivityPub();
	}

	protected function tearDown(): void {
		AP::set(null);
	}

	private function builder(): SocialQueryBuilder {
		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			function (string $route, array $parameters): string {
				if (($parameters['uuid'] ?? null) === '') {
					throw new InvalidArgumentException('Parameter "uuid" for route "' . $route . '" must match');
				}

				return 'https://cloud.example.org/media/' . $parameters['uuid'];
			}
		);

		return new SocialQueryBuilder($this->createStub(IQueryBuilder::class), $urlGenerator);
	}

	/** @return array<string, string> an actor row with its icon joined as `cd_` */
	private function row(string $localCopy, string $resizedCopy): array {
		return [
			'id' => 'https://mastodon.social/users/bob',
			'type' => 'Person',
			'preferred_username' => 'bob',
			'cd_id' => 'https://mastodon.social/avatars/bob.png',
			'cd_type' => 'Image',
			'cd_url' => 'https://files.mastodon.social/avatars/bob.png',
			'cd_local_copy' => $localCopy,
			'cd_resized_copy' => $resizedCopy,
		];
	}

	/** What the join wrote as the avatar address; `getAvatar()` answers the icon's origin instead. */
	private function storedAvatar(Person $actor): string {
		return (fn (): string => $this->avatar)->call($actor);
	}

	public function testAnIconStillBeingFetchedLeavesTheAvatarUnset(): void {
		$actor = $this->builder()->parseLeftJoinCacheActors($this->row('', ''));

		$this->assertTrue($actor->hasIcon());
		$this->assertSame('', $this->storedAvatar($actor));
	}

	public function testTheThumbnailIsPreferredOnceThereIsOne(): void {
		$actor = $this->builder()->parseLeftJoinCacheActors($this->row('full', 'thumb'));

		$this->assertSame('https://cloud.example.org/media/thumb', $this->storedAvatar($actor));
	}

	public function testTheFullCopyServesUntilTheThumbnailIsMade(): void {
		$actor = $this->builder()->parseLeftJoinCacheActors($this->row('full', ''));

		$this->assertSame('https://cloud.example.org/media/full', $this->storedAvatar($actor));
	}
}
