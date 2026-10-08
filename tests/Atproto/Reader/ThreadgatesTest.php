<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\AppView\AppViewClient;
use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Reader\PostMapper;
use OCA\Social\Atproto\Reader\Threadgates;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class ThreadgatesTest extends TestCase {
	private const AUTHOR = 'did:plc:bob';
	private const ME = 'did:plc:alice';
	private const ROOT = 'at://did:plc:bob/app.bsky.feed.post/3kroot';
	private const GATE = 'app.bsky.feed.threadgate';

	private ?array $allow = null;
	private array $mentions = [];
	private array $relationship = [];
	private array $listMembers = [];

	private function gates(): Threadgates {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$appView = $this->createMock(AppViewClient::class);
		$appView->method('query')->willReturnCallback(function (string $method, array $params): array {
			return match ($method) {
				'app.bsky.feed.getPosts' => ['posts' => [[
					'uri' => self::ROOT,
					'author' => ['did' => self::AUTHOR],
					'record' => ['facets' => array_map(static fn (string $did): array => ['features' => [['$type' => 'app.bsky.richtext.facet#mention', 'did' => $did]]], $this->mentions)],
				] + ($this->allow === null ? [] : ['threadgate' => ['record' => ['allow' => $this->allow]]])]],
				'app.bsky.graph.getRelationships' => ['relationships' => [['did' => self::ME] + $this->relationship]],
				'app.bsky.graph.getList' => ['items' => array_map(static fn (string $did): array => ['subject' => ['did' => $did]], $this->listMembers)],
			};
		});
		$identities = $this->createMock(IdentityService::class);
		$identities->method('forActor')->willReturn(new Identity(1, 'https://social.test/@alice', self::ME, 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0));

		return new Threadgates($config, $appView, $identities, new NullLogger());
	}

	/** A reply deep in a thread, its root stored with it. */
	private function parent(string $id = 'https://bsky.app/profile/did:plc:carol/post/3kreply'): Note {
		$note = new Note();
		$note->setId($id);
		$note->setDetailArray(PostMapper::DETAIL, ['uri' => 'at://did:plc:carol/app.bsky.feed.post/3kreply', 'reply_root' => ['uri' => self::ROOT, 'cid' => 'x']]);

		return $note;
	}

	private function refusal(): string {
		return $this->gates()->refusal(new Person(), $this->parent());
	}

	public function testAThreadWithoutAGateTakesAnyReply(): void {
		$this->assertSame('', $this->refusal());
		$this->assertSame('', $this->gates()->refusal(new Person(), $this->parent('https://social.test/@bob/1')), 'not a Bluesky post');
	}

	public function testAThreadNobodyMayReplyToRefusesTheReply(): void {
		$this->allow = [];

		$this->assertSame('The author of this thread on Bluesky allows no replies', $this->refusal());
	}

	public function testEachRuleLetsItsOwnThrough(): void {
		$this->allow = [['$type' => self::GATE . '#mentionRule'], ['$type' => self::GATE . '#followingRule']];
		$this->assertSame('The author of this thread on Bluesky allows replies only from the accounts it mentions or the accounts its author follows', $this->refusal());

		$this->mentions = [self::ME];
		$this->assertSame('', $this->refusal(), 'mentioned');

		$this->mentions = [];
		$this->relationship = ['following' => 'at://did:plc:bob/app.bsky.graph.follow/1'];
		$this->assertSame('', $this->refusal(), 'followed by the author');

		$this->allow = [['$type' => self::GATE . '#followerRule']];
		$this->assertNotSame('', $this->refusal(), 'the author following me does not make me a follower');
		$this->relationship = ['followedBy' => 'at://did:plc:alice/app.bsky.graph.follow/1'];
		$this->assertSame('', $this->refusal(), 'a follower');

		$this->allow = [['$type' => self::GATE . '#listRule', 'list' => 'at://did:plc:bob/app.bsky.graph.list/1']];
		$this->assertNotSame('', $this->refusal());
		$this->listMembers = [self::ME];
		$this->assertSame('', $this->refusal(), 'on the list');
	}
}
