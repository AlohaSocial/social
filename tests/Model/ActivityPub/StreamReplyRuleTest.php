<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub;

use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StreamReplyRuleTest extends TestCase {
	private const ALICE = 'https://cloud.example.org/apps/social/@alice';
	private const CAROL = 'https://remote.example/users/carol';

	private function post(string $rule): Note {
		$note = new Note();
		$note->setId(self::ALICE . '/1');
		$note->setLocal(true);
		$note->setVisibility(Stream::TYPE_PUBLIC);
		$note->setAttributedTo(self::ALICE);
		$note->setTo(ACore::CONTEXT_PUBLIC);
		$note->addCc(self::ALICE . '/followers');
		$note->addCc(self::CAROL);
		$note->setReplyRule($rule);

		return $note;
	}

	public static function rules(): iterable {
		yield 'followers' => [Stream::REPLY_RULE_FOLLOWERS, [self::ALICE . '/followers', self::ALICE]];
		yield 'the accounts followed' => [Stream::REPLY_RULE_FOLLOWING, [self::ALICE . '/following', self::ALICE]];
		yield 'the accounts mentioned' => [Stream::REPLY_RULE_MENTIONED, [self::CAROL, self::ALICE]];
		yield 'nobody' => [Stream::REPLY_RULE_NOBODY, [self::ALICE]];
	}

	/** @param string[] $approved */
	#[DataProvider('rules')]
	public function testTheRuleIsSaidAsCanReplyWithTheAuthorAlways(string $rule, array $approved): void {
		$post = $this->post($rule);

		$this->assertSame(['automaticApproval' => $approved], $post->exportAsActivityPub()['interactionPolicy']['canReply'] ?? null);
		$this->assertSame($rule, $post->exportAsLocal()['reply_policy']);
	}

	public function testEverybodyIsWhatSayingNothingMeans(): void {
		$post = $this->post('');

		$this->assertSame(Stream::REPLY_RULE_EVERYONE, $post->getReplyRule());
		$this->assertArrayNotHasKey('canReply', $post->exportAsActivityPub()['interactionPolicy']);
		$this->assertSame(Stream::REPLY_RULE_EVERYONE, $post->exportAsLocal()['reply_policy']);
	}

	public function testSomebodyElsesPostHasNoRuleOfOurs(): void {
		$post = $this->post(Stream::REPLY_RULE_NOBODY);
		$post->setLocal(false);

		$this->assertNull($post->exportAsLocal()['reply_policy']);
	}
}
