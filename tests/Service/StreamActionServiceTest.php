<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\StreamActionsRequest;
use OCA\Social\Exceptions\StreamActionDoesNotExistException;
use OCA\Social\Model\StreamAction;
use OCA\Social\Service\StreamActionService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * What a like, boost, bookmark or poll vote does to the action row of one
 * actor on one post.
 *
 * The service used to decide between an UPDATE and an INSERT itself, from the
 * number of rows the update touched — which is a race (two concurrent likes
 * both update nothing and both insert) and, on MySQL, not even a reliable
 * signal, since rowCount() counts changed rows and setting a flag to the value
 * it already holds changes none. The decision now belongs to the Db layer,
 * which inserts and falls back to an update on the unique index.
 */
class StreamActionServiceTest extends TestCase {
	private const ACTOR = 'https://cloud.example.com/apps/social/@alice';
	private const STREAM = 'https://remote.example/notes/1';

	/** @var StreamActionsRequest&MockObject */
	private $streamActionsRequest;
	private StreamActionService $service;

	protected function setUp(): void {
		$this->streamActionsRequest = $this->createMock(StreamActionsRequest::class);
		$this->service = new StreamActionService($this->streamActionsRequest);
	}

	public function testSetActionStoresTheLoadedRow(): void {
		$existing = new StreamAction(self::ACTOR, self::STREAM);
		$existing->setId(12);
		$this->streamActionsRequest->method('getAction')
			->with(self::ACTOR, self::STREAM)
			->willReturn($existing);

		$this->streamActionsRequest->expects($this->once())
			->method('save')
			->with($this->identicalTo($existing));
		$this->streamActionsRequest->expects($this->never())->method('update');
		$this->streamActionsRequest->expects($this->never())->method('create');

		$this->service->setAction(self::ACTOR, self::STREAM, 'note', 'hello');

		$this->assertSame('hello', $existing->getValue('note'));
		$this->assertSame([], $existing->getAffected(), 'unknown keys are not tracked as affected');
	}

	public function testSetActionOnAPostWithNoRowYetStoresANewOne(): void {
		$this->streamActionsRequest->method('getAction')
			->willThrowException(new StreamActionDoesNotExistException());

		$saved = null;
		$this->streamActionsRequest->expects($this->once())
			->method('save')
			->willReturnCallback(function (StreamAction $action) use (&$saved): void {
				$saved = $action;
			});

		$this->service->setAction(self::ACTOR, self::STREAM, StreamAction::LIKED, '1');

		$this->assertSame(self::ACTOR, $saved->getActorId());
		$this->assertSame(self::STREAM, $saved->getStreamId());
		$this->assertSame('1', $saved->getValue(StreamAction::LIKED));
		$this->assertSame([StreamAction::LIKED], $saved->getAffected());
	}

	public function testSetActionIntStoresAnInteger(): void {
		$this->streamActionsRequest->method('getAction')
			->willThrowException(new StreamActionDoesNotExistException());
		$this->streamActionsRequest->expects($this->once())
			->method('save')
			->with($this->callback(function (StreamAction $action) {
				$this->assertSame(3, $action->getValueInt('count'));

				return true;
			}));

		$this->service->setActionInt(self::ACTOR, self::STREAM, 'count', 3);
	}

	public function testSetActionBoolTracksAcceptedKeysAsAffected(): void {
		$this->streamActionsRequest->method('getAction')
			->willThrowException(new StreamActionDoesNotExistException());
		$this->streamActionsRequest->expects($this->once())
			->method('save')
			->with($this->callback(function (StreamAction $action) {
				$this->assertTrue($action->getValueBool(StreamAction::BOOSTED));
				$this->assertSame([StreamAction::BOOSTED], $action->getAffected());

				return true;
			}));

		$this->service->setActionBool(self::ACTOR, self::STREAM, StreamAction::BOOSTED, true);
	}

	public function testSettingAFlagToTheValueItAlreadyHoldsIsStillStored(): void {
		// on MySQL this update reports zero rows, which is what used to send
		// the service into an INSERT that could only fail
		$existing = new StreamAction(self::ACTOR, self::STREAM);
		$existing->updateValueBool(StreamAction::LIKED, true);
		$this->streamActionsRequest->method('getAction')->willReturn($existing);

		$this->streamActionsRequest->expects($this->once())->method('save');

		$this->service->setActionBool(self::ACTOR, self::STREAM, StreamAction::LIKED, true);
	}
}
