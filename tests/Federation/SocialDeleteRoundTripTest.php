<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Federation;

use OCA\Social\AP;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\HostBreakerRequest;
use OCA\Social\Db\RelayRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\NoHighPriorityRequestException;
use OCA\Social\Interfaces\Activity\DeleteInterface;
use OCA\Social\Interfaces\Object\NoteInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\InstancePath;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\ForwardService;
use OCA\Social\Service\ImportService;
use OCA\Social\Service\LinkPreviewService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\NotificationService;
use OCA\Social\Service\PollService;
use OCA\Social\Service\PushService;
use OCA\Social\Service\RelayService;
use OCA\Social\Service\RequestQueueService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Service\StatusRevisionService;
use OCA\Social\Service\StreamQueueService;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * A post deleted on one Nextcloud Social instance, removed on another.
 *
 * The retraction is built by the sending instance's own ActivityService, taken
 * off the wire the way the request queue stores it, and fed into the receiving
 * instance's inbox path — ImportService, DeleteInterface, NoteInterface — for a
 * post stored the way that instance stores one it received. Only the database
 * and the network are doubles.
 */
class SocialDeleteRoundTripTest extends TestCase {
	private const SENDER_HOST = 'social.b.example';
	private const SENDER = 'https://social.b.example/index.php/apps/social';
	private const BOB = self::SENDER . '/users/bob';
	private const NOTE_ID = self::SENDER . '/@bob/17896864450807038316';
	private const RECEIVER = 'https://social.a.example';
	private const RECEIVER_INBOX = self::RECEIVER . '/apps/social/inbox';

	protected function tearDown(): void {
		AP::set(null);
		\OC::$server->reset();
	}

	/**
	 * @param array<class-string, object> $interfaces
	 */
	private function boot(string $cloudUrl, array $interfaces = []): void {
		$args = [];
		foreach ((new ReflectionClass(AP::class))->getConstructor()->getParameters() as $parameter) {
			$class = $parameter->getType()->getName();
			if (isset($interfaces[$class])) {
				$args[] = $interfaces[$class];
				continue;
			}
			$mock = $this->createStub($class);
			if ($class === ConfigService::class) {
				$mock->method('getCloudUrl')->willReturn($cloudUrl);
			}
			$args[] = $mock;
		}
		AP::set(new AP(...$args));
		\OC::$server->register(IURLGenerator::class, $this->createStub(IURLGenerator::class));
	}

	/**
	 * What the sending instance puts in its request queue when bob deletes
	 * the post: `StreamService::deleteLocalItem()` names the author as the
	 * actor and hands the post to `ActivityService::deleteActivity()`.
	 */
	private function deleteAsSent(Stream $post): string {
		$this->boot(self::SENDER);

		$bob = new Person();
		$bob->setId(self::BOB);
		$bob->setPreferredUsername('bob');
		$bob->setLocal(true);

		$actorsRequest = $this->createMock(ActorsRequest::class);
		$actorsRequest->method('getFromId')->with(self::BOB)->willReturn($bob);

		$wire = '';
		$requestQueueService = $this->createStub(RequestQueueService::class);
		$requestQueueService->method('generateRequestQueue')->willReturnCallback(
			static function (array $paths, ACore $item) use (&$wire): string {
				// what RequestQueueService stores and later posts
				$wire = (string)json_encode($item, JSON_UNESCAPED_SLASHES);

				return 'a0b1c2d3-e4f5-4678-9abc-def012345678';
			}
		);
		$requestQueueService->method('getPriorityRequest')
			->willThrowException(new NoHighPriorityRequestException());
		$requestQueueService->method('getRequestFromToken')->willReturn([]);

		$configService = $this->createStub(ConfigService::class);
		$configService->method('getCloudHost')->willReturn(self::SENDER_HOST);

		$service = new ActivityService(
			$this->createStub(FollowsRequest::class),
			$this->createStub(CacheActorsRequest::class),
			$this->createStub(SignatureService::class),
			$requestQueueService,
			$this->createStub(CurlService::class),
			$configService,
			$actorsRequest,
			$this->createStub(RelayRequest::class),
			$this->createStub(HostBreakerRequest::class),
			$this->createStub(LoggerInterface::class),
			$this->createStub(\OCA\Social\Db\StreamRequest::class),
		);

		$post->setActorId($post->getAttributedTo());
		$post->addInstancePath(
			new InstancePath(self::RECEIVER_INBOX, InstancePath::TYPE_INBOX, InstancePath::PRIORITY_MEDIUM)
		);
		$service->deleteActivity($post);
		$this->assertNotSame('', $wire, 'the Delete was never queued');

		return $wire;
	}

	/**
	 * The receiving instance, holding $stored the way it holds a post it
	 * received.
	 *
	 * @return StreamRequest&MockObject
	 */
	private function receiver(Stream $stored): StreamRequest {
		$streamRequest = $this->createMock(StreamRequest::class);
		$streamRequest->method('getStreamById')->willReturnCallback(
			static function (string $id) use ($stored): Stream {
				if ($id !== $stored->getId()) {
					throw new \OCA\Social\Exceptions\StreamNotFoundException();
				}

				return $stored;
			}
		);

		$noteInterface = new NoteInterface(
			$streamRequest,
			$this->createStub(CacheActorsRequest::class),
			$this->createStub(PollService::class),
			$this->createStub(PushService::class),
			$this->createStub(StreamQueueService::class),
			$this->createStub(LinkPreviewService::class),
			$this->createStub(ForwardService::class),
			$this->createStub(NotificationService::class),
			$this->createStub(StatusRevisionService::class),
		);
		$this->boot(self::RECEIVER, [
			DeleteInterface::class => new DeleteInterface(),
			NoteInterface::class => $noteInterface,
		]);

		return $streamRequest;
	}

	private function storedAsReceived(Stream $post): Stream {
		$post->setId(self::NOTE_ID);
		// NoteInterface::activity() stores a Create under its actor
		$post->setAttributedTo(self::BOB);
		$post->setLocal(false);

		return $post;
	}

	public function testADeletedPostIsRemovedOnTheOtherInstance(): void {
		$sent = new Note();
		$sent->setId(self::NOTE_ID);
		$sent->setAttributedTo(self::BOB);
		$sent->setTo(ACore::CONTEXT_PUBLIC);
		$sent->setLocal(true);
		$wire = $this->deleteAsSent($sent);

		$decoded = json_decode($wire, true);
		$this->assertSame('Delete', $decoded['type']);
		$this->assertSame(self::BOB, $decoded['actor']);
		$this->assertSame('Tombstone', $decoded['object']['type']);
		$this->assertSame(self::NOTE_ID, $decoded['object']['id']);

		$streamRequest = $this->receiver($this->storedAsReceived(new Note()));
		$streamRequest->expects($this->once())->method('deleteById')->with(self::NOTE_ID, Note::TYPE);

		$this->parse($wire);
	}

	/**
	 * A poll is stored as the `Question` it is on the wire, and a Delete of
	 * it has to remove that row: the guard on the delete is there so that a
	 * note's id never takes a row of some other kind with it, and a poll is
	 * not some other kind.
	 */
	public function testADeletedPollIsRemovedOnTheOtherInstance(): void {
		$sent = new Question();
		$sent->setId(self::NOTE_ID);
		$sent->setAttributedTo(self::BOB);
		$sent->setTo(ACore::CONTEXT_PUBLIC);
		$sent->setLocal(true);
		$wire = $this->deleteAsSent($sent);

		$streamRequest = $this->receiver($this->storedAsReceived(new Question()));
		$streamRequest->expects($this->once())->method('deleteById')->with(self::NOTE_ID, Question::TYPE);

		$this->parse($wire);
	}

	/**
	 * The receiving inbox: the body imported, vouched for by the HTTP
	 * signature of bob's key (the LD signature is not checked here), and
	 * handed to ImportService as `ActivityPubController::sharedInbox()` does.
	 */
	private function parse(string $wire): void {
		$import = new ImportService(
			$this->createStub(MiscService::class),
			$this->createStub(ModerationService::class),
			$this->createStub(RelayService::class),
		);
		$activity = $import->importFromJson($wire);
		$activity->setOrigin(self::SENDER_HOST, SignatureService::ORIGIN_HEADER, time());
		$import->parseIncomingRequest($activity);
	}
}
