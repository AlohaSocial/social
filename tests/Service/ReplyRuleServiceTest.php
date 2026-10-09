<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\ListsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemNotFoundException;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MastodonList;
use OCA\Social\Service\ReplyRuleService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class ReplyRuleServiceTest extends TestCase {
	private const ALICE = 'https://social.test/@alice';
	private const BOB = 'https://remote.example/users/bob';
	private const CAROL = 'https://remote.example/users/carol';
	private const DAN = 'https://bsky.app/profile/did:plc:dan';

	/** @var array<string, bool> follows, `from>to` => accepted */
	private array $follows = [];
	private array $gatesUpdated = [];
	private Note $post;

	protected function setUp(): void {
		$this->post = new Note();
		$this->post->setId(self::ALICE . '/1');
		$this->post->setAttributedTo(self::ALICE);
		$this->post->setLocal(true);
		$this->post->setTo(ACore::CONTEXT_PUBLIC);
		$this->post->addCc(self::CAROL);
	}

	private function rules(): ReplyRuleService {
		$streams = $this->createMock(StreamRequest::class);
		$streams->method('getStreamById')->willReturnCallback(fn (string $id): Stream => $id === $this->post->getId() ? $this->post : throw new \OCA\Social\Exceptions\StreamNotFoundException());
		$follows = $this->createMock(FollowsRequest::class);
		$follows->method('getByPersons')->willReturnCallback(function (string $from, string $to): Follow {
			if (!array_key_exists($from . '>' . $to, $this->follows)) {
				throw new FollowNotFoundException();
			}
			$follow = new Follow();
			$follow->setAccepted($this->follows[$from . '>' . $to]);

			return $follow;
		});
		$publisher = $this->createMock(Publisher::class);
		$publisher->method('updateGates')->willReturnCallback(function (Stream $post): void {
			$this->gatesUpdated[] = $post->getReplyRule();
		});
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->with(Publisher::class)->willReturn($publisher);

		$lists = $this->createMock(ListsRequest::class);
		$lists->method('getOwnedById')->willReturnCallback(static fn (string $owner, int $id): MastodonList => $owner === self::ALICE && $id === 7 ? (new MastodonList())->setId(7) : throw new ItemNotFoundException());
		$lists->method('isMember')->willReturnCallback(static fn (MastodonList $list, string $member): bool => $member === self::DAN);
		$lists->method('getMemberIds')->willReturn([self::DAN]);

		return new ReplyRuleService($streams, $follows, $lists, new NullLogger(), $container);
	}

	public static function rules_(): iterable {
		yield 'everybody' => [Stream::REPLY_RULE_EVERYONE, [self::BOB => true, self::CAROL => true]];
		yield 'followers' => [Stream::REPLY_RULE_FOLLOWERS, [self::BOB => true, self::CAROL => false]];
		yield 'the accounts followed' => [Stream::REPLY_RULE_FOLLOWING, [self::BOB => false, self::CAROL => true]];
		yield 'the accounts mentioned' => [Stream::REPLY_RULE_MENTIONED, [self::BOB => false, self::CAROL => true]];
		yield 'nobody' => [Stream::REPLY_RULE_NOBODY, [self::BOB => false, self::CAROL => false]];
	}

	/** @param array<string, bool> $allowed */
	#[DataProvider('rules_')]
	public function testEachRuleLetsThroughWhomItNames(string $rule, array $allowed): void {
		$this->post->setReplyRule($rule);
		// bob follows alice; alice follows carol, whom the post mentions; a
		// follow still pending counts for nothing
		$this->follows = [self::BOB . '>' . self::ALICE => true, self::ALICE . '>' . self::CAROL => true, self::CAROL . '>' . self::ALICE => false];

		foreach ($allowed as $replier => $may) {
			$this->assertSame($may, $this->rules()->refusal($this->post, $replier) === '', $rule . ': ' . $replier);
		}
		$this->assertSame('', $this->rules()->refusal($this->post, self::ALICE), 'the author always');
	}

	public function testOnlyOurOwnPostsAreHeldToARule(): void {
		$this->post->setReplyRule(Stream::REPLY_RULE_NOBODY);
		$this->post->setLocal(false);

		$this->assertSame('', $this->rules()->refusal($this->post, self::BOB));
	}

	public function testTheAuthorSetsTheRuleAndBlueskyFollows(): void {
		$post = $this->rules()->setRule($this->post->getId(), (new Person())->setId(self::ALICE), Stream::REPLY_RULE_FOLLOWERS);

		$this->assertSame(Stream::REPLY_RULE_FOLLOWERS, $post->getReplyRule());
		$this->assertSame([Stream::REPLY_RULE_FOLLOWERS], $this->gatesUpdated);
		$this->expectException(InvalidResourceException::class);
		$this->rules()->setRule($this->post->getId(), (new Person())->setId(self::BOB), Stream::REPLY_RULE_NOBODY);
	}

	public function testACombinationLetsThroughWhomAnyPartNames(): void {
		$this->post->setReplyRule('followers,list:7');
		$this->follows = [self::BOB . '>' . self::ALICE => true];

		$this->assertSame('', $this->rules()->refusal($this->post, self::BOB), 'a follower');
		$this->assertSame('', $this->rules()->refusal($this->post, self::DAN), 'on the list');
		$this->assertSame('The author of this post allows replies only from their followers and the members of one of their lists', $this->rules()->refusal($this->post, self::CAROL));
	}

	public function testOnlyTheAuthorsOwnListsAreKeptAndNothingLeftIsNobody(): void {
		$this->assertSame('mentioned,list:7', $this->rules()->sanitize(self::ALICE, 'list:7,list:8,mentioned'));
		$this->assertSame(Stream::REPLY_RULE_NOBODY, $this->rules()->sanitize(self::ALICE, 'list:8'), 'a list not theirs is nobody, not everybody');
		$this->assertSame(Stream::REPLY_RULE_EVERYONE, $this->rules()->sanitize(self::ALICE, 'everyone'));
	}

	public function testTheListMembersAreWhomTheOtherServersAreTold(): void {
		$this->post->setReplyRule('list:7');
		$this->rules()->snapshotListMembers($this->post);

		$this->assertSame([self::DAN], $this->post->getReplyListMembers());
		$this->assertSame(['automaticApproval' => [self::DAN, self::ALICE]], $this->post->exportAsActivityPub()['interactionPolicy']['canReply']);
	}
}
