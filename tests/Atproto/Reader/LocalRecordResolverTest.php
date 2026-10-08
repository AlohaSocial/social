<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Model\StoredRecord;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Publisher\RecordMapper;
use OCA\Social\Atproto\Reader\LocalRecordResolver;
use OCA\Social\Db\AtprotoIdentityRequest;
use OCA\Social\Db\AtprotoRepoRequest;
use OCA\Social\Exceptions\AtprotoIdentityNotFoundException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class LocalRecordResolverTest extends TestCase {
	private const LOCAL = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const REMOTE = 'did:plc:z72i7hdynmk6r22z27h6tvur';

	public function testOurOwnRecordsResolveToTheLocalObjects(): void {
		$identities = $this->createMock(AtprotoIdentityRequest::class);
		$identities->expects($this->exactly(2))->method('getByDid')->willReturnCallback(static fn (string $did): Identity => $did === self::LOCAL
			? new Identity(1, 'https://social.test/@alice', self::LOCAL, 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0)
			: throw new AtprotoIdentityNotFoundException());
		$records = $this->createMock(AtprotoRepoRequest::class);
		$records->method('getRecord')->willReturnCallback(static fn (string $did, string $collection, string $rkey): ?StoredRecord => $rkey === '3kknown'
			? new StoredRecord($did, $collection, $rkey, Cid::forRaw('r'), '', 'https://social.test/@alice/1', 0) : null);
		$resolver = new LocalRecordResolver($identities, $records);

		$this->assertSame('https://social.test/@alice/1', $resolver->postId('at://' . self::LOCAL . '/app.bsky.feed.post/3kknown'));
		$this->assertSame('https://bsky.app/profile/' . self::LOCAL . '/post/3kunknown', $resolver->postId('at://' . self::LOCAL . '/app.bsky.feed.post/3kunknown'), 'a record of ours we never wrote keeps the Bluesky id');
		$this->assertSame('https://bsky.app/profile/' . self::REMOTE . '/post/3kknown', $resolver->postId('at://' . self::REMOTE . '/' . RecordMapper::POST . '/3kknown'));
		$this->assertSame('', $resolver->postId('at://' . self::LOCAL . '/app.bsky.feed.like/3k'));
		$this->assertSame('https://social.test/@alice', $resolver->mentionTarget(self::LOCAL));
		$this->assertSame('https://bsky.app/profile/' . self::REMOTE, $resolver->mentionTarget(self::REMOTE));
		$this->assertSame('', $resolver->actorId('not-a-did'));
		$this->assertSame('https://social.test/@alice', $resolver->actorId(self::LOCAL), 'remembered, asked once');
	}
}
