<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\Reader\BlueskyIds;
use PHPUnit\Framework\TestCase;

class BlueskyIdsTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	public function testActorAndPostIdsAreHttpsUrlsOnBskyApp(): void {
		$this->assertSame('https://bsky.app/profile/' . self::DID, BlueskyIds::actorId(self::DID));
		$this->assertSame('https://bsky.app/profile/' . self::DID . '/post/3kznmn7xqxl22', BlueskyIds::postId(self::DID, '3kznmn7xqxl22'));
		$this->assertSame('https://bsky.app/profile/' . self::DID . '/followers', BlueskyIds::followersId(self::DID));
		$this->assertTrue(BlueskyIds::isActorId(BlueskyIds::actorId(self::DID)));
		$this->assertTrue(BlueskyIds::isActorId('https://bsky.app/profile/did:web:example.com'));
		$this->assertFalse(BlueskyIds::isActorId('https://bsky.app/profile/alice.bsky.social'), 'a handle is not an id');
		$this->assertFalse(BlueskyIds::isActorId(BlueskyIds::postId(self::DID, 'x')));
		$this->assertFalse(BlueskyIds::isActorId('https://social.test/@alice'));
		$this->assertTrue(BlueskyIds::isPostId(BlueskyIds::postId(self::DID, '3kznmn7xqxl22')));
		$this->assertFalse(BlueskyIds::isPostId(BlueskyIds::actorId(self::DID)));
	}

	public function testTheDidAndRkeyAreReadBack(): void {
		$this->assertSame(self::DID, BlueskyIds::didOf(BlueskyIds::actorId(self::DID)));
		$this->assertSame(self::DID, BlueskyIds::didOf(BlueskyIds::postId(self::DID, '3kznmn7xqxl22')));
		$this->assertSame('', BlueskyIds::didOf('https://bsky.app/profile/alice.bsky.social'));
		$this->assertSame(['did' => self::DID, 'rkey' => '3kznmn7xqxl22'], BlueskyIds::parsePostId(BlueskyIds::postId(self::DID, '3kznmn7xqxl22')));
		$this->assertNull(BlueskyIds::parsePostId('https://bsky.app/profile/' . self::DID . '/post/'));
	}

	public function testAtUrisRoundTrip(): void {
		$uri = BlueskyIds::atUri(self::DID, BlueskyIds::POST, '3kznmn7xqxl22');
		$this->assertSame('at://' . self::DID . '/app.bsky.feed.post/3kznmn7xqxl22', $uri);
		$this->assertSame(BlueskyIds::postId(self::DID, '3kznmn7xqxl22'), BlueskyIds::postIdOfUri($uri));
		$this->assertSame('', BlueskyIds::postIdOfUri('at://' . self::DID . '/app.bsky.feed.like/3kznmn7xqxl22'), 'only posts have a post id');
		$this->assertSame('', BlueskyIds::postIdOfUri('at://alice.bsky.social/app.bsky.feed.post/3kznmn7xqxl22'), 'a handle authority is not resolved here');
		$this->assertSame('', BlueskyIds::postIdOfUri('https://bsky.app/'));
	}

	public function testPagesAreByHandle(): void {
		$this->assertSame('https://bsky.app/profile/alice.bsky.social', BlueskyIds::profileUrl('alice.bsky.social'));
		$this->assertSame('https://bsky.app/profile/alice.bsky.social/post/3kznmn7xqxl22', BlueskyIds::postUrl('alice.bsky.social', '3kznmn7xqxl22'));
	}

	public function testAHandleIsADomainWithoutAnAt(): void {
		$this->assertTrue(BlueskyIds::isHandle('alice.bsky.social'));
		$this->assertTrue(BlueskyIds::isHandle('Alice.Example.ORG'));
		$this->assertFalse(BlueskyIds::isHandle('alice@bsky.social'), 'a Fediverse address');
		$this->assertFalse(BlueskyIds::isHandle('alice'), 'a local username');
		$this->assertFalse(BlueskyIds::isHandle('alice.local'), 'no top-level domain that resolves');
		$this->assertFalse(BlueskyIds::isHandle('https://bsky.app/profile/alice.bsky.social'));
	}
}
