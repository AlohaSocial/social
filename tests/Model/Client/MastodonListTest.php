<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\Client;

use OCA\Social\Model\Client\MastodonList;
use PHPUnit\Framework\TestCase;

/**
 * The List entity a client is handed.
 *
 * Its shape is a contract: Elk, Phanpy and every other client read exactly
 * these four keys off it, compare `id` as a string, and draw the list's
 * settings from `replies_policy` and `exclusive`.
 */
class MastodonListTest extends TestCase {
	public function testTheEntityIsMastodonsFourKeysAndTheGroupItFollows(): void {
		$list = (new MastodonList())
			->setId(12249)
			->setOwnerId('https://cloud.example/users/alice')
			->setTitle('Friends')
			->setRepliesPolicy(MastodonList::REPLIES_FOLLOWED)
			->setExclusive(true);

		$this->assertSame(
			[
				'id' => '12249',
				'title' => 'Friends',
				'replies_policy' => 'followed',
				'exclusive' => true,
				// null for a list made by hand; a Mastodon client ignores it
				'nextcloud_group' => null,
				// this app's own too: whether anyone may see the list
				'public' => false,
			],
			$list->jsonSerialize()
		);
	}

	public function testAGroupListSaysWhichGroupItFollows(): void {
		$list = (new MastodonList())->setId(3)->setTitle('Design')->setGroupId('design');

		$this->assertSame('design', $list->jsonSerialize()['nextcloud_group']);
		$this->assertSame('design', (new MastodonList())->importFromDatabase(['id' => 3, 'group_id' => 'design', 'creation' => ''])->getGroupId());
		// rows from before the column existed
		$this->assertSame('', (new MastodonList())->importFromDatabase(['id' => 3, 'creation' => ''])->getGroupId());
	}

	public function testAListIsPrivateUnlessMadePublic(): void {
		$this->assertFalse((new MastodonList())->isPublic());
		$this->assertTrue((new MastodonList())->setPublic(true)->jsonSerialize()['public']);
		$this->assertSame('private', (new MastodonList())->setVisibility('everybody')->getVisibility(), 'anything but public is private');
		$this->assertTrue((new MastodonList())->importFromDatabase(['id' => 3, 'visibility' => 'public', 'creation' => ''])->isPublic());
		// rows from before the column existed
		$this->assertFalse((new MastodonList())->importFromDatabase(['id' => 3, 'creation' => ''])->isPublic());
	}

	public function testTheIdIsAStringAsEveryIdAClientIsHandedIs(): void {
		// a client compares it as an opaque string; a JSON number would not
		// equal the id it was given back
		$this->assertSame('7', (new MastodonList())->setId(7)->jsonSerialize()['id']);
	}

	public function testTheOwnerIsNotPartOfWhatAClientSees(): void {
		$list = (new MastodonList())->setOwnerId('https://cloud.example/users/alice');

		$this->assertArrayNotHasKey('actor_id', $list->jsonSerialize());
		$this->assertNotContains(
			'https://cloud.example/users/alice',
			array_values($list->jsonSerialize())
		);
	}

	public function testANewListDefaultsToWhatMastodonsColumnDefaultsTo(): void {
		// a client that never sends replies_policy must not end up with a
		// different list here than it would get there
		$this->assertSame('list', MastodonList::DEFAULT_REPLIES_POLICY);
		$this->assertSame('list', (new MastodonList())->getRepliesPolicy());
	}

	public function testTheThreePoliciesAreMastodonsThree(): void {
		$this->assertSame(['followed', 'list', 'none'], MastodonList::REPLIES_POLICIES);

		foreach (MastodonList::REPLIES_POLICIES as $policy) {
			$this->assertTrue(MastodonList::isRepliesPolicy($policy));
			$this->assertSame($policy, (new MastodonList())->setRepliesPolicy($policy)->getRepliesPolicy());
		}
	}

	public function testAPolicyThatIsNotOneIsNeverStoredAsItself(): void {
		// the column would otherwise hold a value no client and no query here
		// knows how to read; the write routes refuse it outright, and this is
		// the backstop for every other path
		$this->assertFalse(MastodonList::isRepliesPolicy('everything'));
		$this->assertFalse(MastodonList::isRepliesPolicy(''));
		$this->assertFalse(MastodonList::isRepliesPolicy('List'), 'the enum is case-sensitive');

		$this->assertSame(
			MastodonList::DEFAULT_REPLIES_POLICY,
			(new MastodonList())->setRepliesPolicy('everything')->getRepliesPolicy()
		);
	}

	public function testARowBecomesTheEntityWholeRatherThanHalfFilled(): void {
		$list = (new MastodonList())->importFromDatabase([
			'id' => '42',
			'actor_id' => 'https://cloud.example/users/alice',
			'actor_id_prim' => md5('https://cloud.example/users/alice'),
			'title' => 'Friends',
			'replies_policy' => 'none',
			'exclusive' => '1',
			'creation' => '2026-09-11 10:00:00',
		]);

		$this->assertSame(42, $list->getId());
		$this->assertSame('https://cloud.example/users/alice', $list->getOwnerId());
		$this->assertSame('Friends', $list->getTitle());
		$this->assertSame('none', $list->getRepliesPolicy());
		$this->assertTrue($list->isExclusive());
		$this->assertSame(strtotime('2026-09-11 10:00:00'), $list->getCreation());
	}

	public function testARowWithNoCreationIsNotDatedToTheEpoch(): void {
		$list = (new MastodonList())->importFromDatabase(['id' => '1', 'title' => 'x']);

		$this->assertSame(0, $list->getCreation());
	}
}
