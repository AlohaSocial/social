<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces\Activity;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Interfaces\Activity\ReplyRequestInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Reject;
use OCA\Social\Model\ActivityPub\Activity\ReplyRequest;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ReplyRuleService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Tests\Interfaces\ActivityPubTestCase;
use OCP\IConfig;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../ActivityPubTestCase.php';

#[AllowMockObjectsWithoutExpectations]
class ReplyRequestInterfaceTest extends ActivityPubTestCase {
	private const ALICE = self::LOCAL_URL . '/users/alice';
	private const BOB = self::REMOTE_URL . '/users/bob';
	private const LOCAL_POST = self::LOCAL_URL . '/@alice/1';
	private const REMOTE_REPLY = self::REMOTE_URL . '/@bob/2';

	private StreamRequest|MockObject $streams;
	private ReplyRuleService|MockObject $rules;
	private CacheActorService|MockObject $actors;
	private ActivityService|MockObject $activities;
	private ReplyRequestInterface $handler;

	protected function setUp(): void {
		parent::setUp();
		$this->streams = $this->createMock(StreamRequest::class);
		$this->rules = $this->createMock(ReplyRuleService::class);
		$this->actors = $this->createMock(CacheActorService::class);
		$this->activities = $this->createMock(ActivityService::class);
		$container = $this->createStub(ContainerInterface::class);
		$container->method('get')->with(ActivityService::class)->willReturn($this->activities);
		$config = $this->createStub(IConfig::class);
		$config->method('getSystemValueString')->with('secret', '')->willReturn('test-secret');
		$this->handler = new ReplyRequestInterface($this->streams, $this->rules, $this->actors, $container, $config, new NullLogger());
	}

	private function target(string $visibility): Note {
		$post = new Note();
		$post->setId(self::LOCAL_POST);
		$post->setAttributedTo(self::ALICE);
		$post->setVisibility($visibility);
		$post->setLocal(true);
		if ($visibility === Stream::TYPE_PUBLIC) {
			$post->setToArray([ACore::CONTEXT_PUBLIC]);
		}

		return $post;
	}

	private function request(): ReplyRequest {
		$request = new ReplyRequest();
		$request->import([
			'id' => self::REMOTE_URL . '/reply_requests/1',
			'type' => ReplyRequest::TYPE,
			'actor' => self::BOB,
			'to' => [self::ALICE],
			'object' => self::LOCAL_POST,
			'instrument' => [
				'id' => self::REMOTE_REPLY,
				'type' => 'Note',
				'attributedTo' => self::BOB,
				'inReplyTo' => self::LOCAL_POST,
			],
		]);
		$request->setOrigin('remote.example', SignatureService::ORIGIN_HEADER, time());

		return $request;
	}

	public function testAReplyAuthorizationIsRejectedForFollowersOnlyPosts(): void {
		$this->streams->method('getStreamById')->with(self::LOCAL_POST)->willReturn($this->target(Stream::TYPE_FOLLOWERS));
		$this->rules->expects($this->never())->method('refusal');
		$this->actors->method('getFromId')->with(self::BOB)->willReturn($this->person(self::BOB));
		$sent = null;
		$this->activities->expects($this->once())->method('request')->willReturnCallback(
			function (ACore $activity) use (&$sent): string {
				$sent = $activity;

				return 'token';
			}
		);

		$this->handler->processIncomingRequest($this->request());

		$this->assertInstanceOf(Reject::class, $sent);
		$this->assertArrayNotHasKey('result', $sent->exportAsActivityPub());
	}

	public function testAReplyAuthorizationCanBeIssuedForAnUnlistedPost(): void {
		$post = $this->target(Stream::TYPE_UNLISTED);
		$post->setToArray([self::LOCAL_URL . '/users/alice/followers']);
		$this->streams->method('getStreamById')->with(self::LOCAL_POST)->willReturn($post);
		$this->rules->method('refusal')->willReturn('');
		$this->actors->method('getFromId')->with(self::BOB)->willReturn($this->person(self::BOB));
		$sent = null;
		$this->activities->expects($this->once())->method('request')->willReturnCallback(
			function (ACore $activity) use (&$sent): string {
				$sent = $activity;

				return 'token';
			}
		);

		$this->handler->processIncomingRequest($this->request());

		$this->assertInstanceOf(\OCA\Social\Model\ActivityPub\Activity\Accept::class, $sent);
		$this->assertNotEmpty($sent->exportAsActivityPub()['result']);
	}
}
