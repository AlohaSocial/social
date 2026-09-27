<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Exceptions\ActivityPubFormatException;
use OCA\Social\Exceptions\InvalidOriginException;
use OCA\Social\Exceptions\InvalidResourceException;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Interfaces\Activity\AcceptInterface;
use OCA\Social\Interfaces\Activity\AddInterface;
use OCA\Social\Interfaces\Activity\BlockInterface;
use OCA\Social\Interfaces\Activity\CreateInterface;
use OCA\Social\Interfaces\Activity\DeleteInterface;
use OCA\Social\Interfaces\Activity\MoveInterface;
use OCA\Social\Interfaces\Activity\QuoteRequestInterface;
use OCA\Social\Interfaces\Activity\RejectInterface;
use OCA\Social\Interfaces\Activity\RemoveInterface;
use OCA\Social\Interfaces\Activity\StoryAnswerInterface;
use OCA\Social\Interfaces\Activity\UndoInterface;
use OCA\Social\Interfaces\Activity\UpdateInterface;
use OCA\Social\Interfaces\Actor\ApplicationInterface;
use OCA\Social\Interfaces\Actor\GroupInterface;
use OCA\Social\Interfaces\Actor\OrganizationInterface;
use OCA\Social\Interfaces\Actor\PersonInterface;
use OCA\Social\Interfaces\Actor\ServiceInterface;
use OCA\Social\Interfaces\Internal\SocialAppNotificationInterface;
use OCA\Social\Interfaces\Object\AnnounceInterface;
use OCA\Social\Interfaces\Object\DocumentInterface;
use OCA\Social\Interfaces\Object\EmojiReactInterface;
use OCA\Social\Interfaces\Object\FlagInterface;
use OCA\Social\Interfaces\Object\FollowInterface;
use OCA\Social\Interfaces\Object\ImageInterface;
use OCA\Social\Interfaces\Object\LikeInterface;
use OCA\Social\Interfaces\Object\NoteInterface;
use OCA\Social\Interfaces\Object\StoryInterface;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\ImportService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\PeerTubeService;
use OCA\Social\Service\RelayService;
use OCA\Social\Service\SignatureService;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImportServiceTest extends TestCase {
	private const CLOUD_URL = 'https://cloud.example.com';

	private MiscService|MockObject $miscService;
	private ModerationService|MockObject $moderationService;
	private RelayService|MockObject $relayService;
	private ImportService $service;

	protected function setUp(): void {
		$this->miscService = $this->createMock(MiscService::class);
		$configService = $this->createMock(ConfigService::class);
		$configService->method('getCloudUrl')->willReturn(self::CLOUD_URL);
		$this->moderationService = $this->createMock(ModerationService::class);
		$this->relayService = $this->createMock(RelayService::class);
		$this->service = new ImportService(
			$this->miscService, $this->moderationService, $this->relayService
		);
	}

	protected function tearDown(): void {
		AP::set(null);
		\OC::$server->reset();
	}

	/** A real AP dispatcher over mocked persistence interfaces, so JSON is parsed into the real models. */
	private function useRealActivityPub(): AP {
		$configService = $this->createMock(ConfigService::class);
		$configService->method('getCloudUrl')->willReturn(self::CLOUD_URL);
		// Stream::import resolves the URL generator statically for attachment links
		\OC::$server->register(IURLGenerator::class, $this->createMock(IURLGenerator::class));

		$ap = new AP(
			$this->createMock(AcceptInterface::class),
			$this->createMock(AddInterface::class),
			$this->createMock(StoryAnswerInterface::class),
			$this->createMock(AnnounceInterface::class),
			$this->createMock(BlockInterface::class),
			$this->createMock(CreateInterface::class),
			$this->createMock(DeleteInterface::class),
			$this->createMock(DocumentInterface::class),
			$this->createMock(FlagInterface::class),
			$this->createMock(FollowInterface::class),
			$this->createMock(ImageInterface::class),
			$this->createMock(LikeInterface::class),
			$this->createMock(EmojiReactInterface::class),
			$this->createMock(StoryInterface::class),
			$this->createMock(MoveInterface::class),
			$this->createMock(NoteInterface::class),
			$this->createMock(SocialAppNotificationInterface::class),
			$this->createMock(PersonInterface::class),
			$this->createMock(ServiceInterface::class),
			$this->createMock(GroupInterface::class),
			$this->createMock(OrganizationInterface::class),
			$this->createMock(ApplicationInterface::class),
			$this->createMock(RejectInterface::class),
			$this->createMock(RemoveInterface::class),
			$this->createMock(UndoInterface::class),
			$this->createMock(UpdateInterface::class),
			$this->createMock(QuoteRequestInterface::class),
			$configService,
			$this->createMock(\OCA\Social\Interfaces\Activity\ApproveReplyInterface::class),
			$this->createMock(\OCA\Social\Interfaces\Object\DislikeInterface::class),
			$this->createMock(\OCA\Social\Interfaces\Object\PlaylistInterface::class),
			new PeerTubeService(
				$this->createMock(DocumentInterface::class),
				$this->createMock(IURLGenerator::class),
				$this->createMock(LoggerInterface::class),
			),
		);
		AP::set($ap);

		return $ap;
	}

	/** @return array<string, array{string}> */
	public static function notAnObjectProvider(): array {
		return [
			'garbage' => ['not json at all'],
			'empty' => [''],
			'scalar' => ['"Note"'],
			'number' => ['42'],
			'null' => ['null'],
		];
	}

	#[DataProvider('notAnObjectProvider')]
	public function testImportFromJsonRejectsAnythingButAnObject(string $json): void {
		$this->useRealActivityPub();

		$this->expectException(ActivityPubFormatException::class);
		$this->service->importFromJson($json);
	}

	public function testImportFromJsonBuildsTheTypedModel(): void {
		$this->useRealActivityPub();
		$data = [
			'@context' => 'https://www.w3.org/ns/activitystreams',
			'id' => 'https://remote.example/notes/1',
			'type' => 'Note',
			'attributedTo' => 'https://remote.example/users/bob',
			'content' => '<p>hello</p>',
			'to' => ['https://www.w3.org/ns/activitystreams#Public'],
			'published' => '2026-09-07T10:00:00Z',
		];

		$item = $this->service->importFromJson(json_encode($data));

		$this->assertInstanceOf(Note::class, $item);
		$this->assertSame('https://remote.example/notes/1', $item->getId());
		$this->assertSame('<p>hello</p>', $item->getContent());
		$this->assertSame('https://remote.example/users/bob', $item->getAttributedTo());
		$this->assertSame(['https://www.w3.org/ns/activitystreams#Public'], $item->getToArray());
		$this->assertSame(json_encode($data, JSON_UNESCAPED_SLASHES), $item->getSource());
		$this->assertSame(self::CLOUD_URL, $item->getUrlCloud());
	}

	public function testImportFromJsonNestsTheObjectAndActor(): void {
		$this->useRealActivityPub();
		$json = json_encode([
			'id' => 'https://remote.example/activities/1',
			'type' => 'Create',
			'actor' => 'https://remote.example/users/bob',
			'object' => [
				'id' => 'https://remote.example/notes/1',
				'type' => 'Note',
				'content' => 'nested',
			],
		]);

		$item = $this->service->importFromJson($json);

		$this->assertInstanceOf(Create::class, $item);
		$this->assertSame('https://remote.example/users/bob', $item->getActorId());
		$this->assertTrue($item->hasObject());
		$this->assertInstanceOf(Note::class, $item->getObject());
		$this->assertSame('nested', $item->getObject()->getContent());
		$this->assertSame($item, $item->getObject()->getParent());
		$this->assertSame('https://remote.example/notes/1', $item->getObjectId());
	}

	public function testImportFromJsonKeepsAnObjectReferenceAsId(): void {
		$this->useRealActivityPub();

		$item = $this->service->importFromJson(json_encode([
			'type' => 'Follow',
			'actor' => 'https://remote.example/users/bob',
			'object' => 'https://cloud.example.com/apps/social/@alice',
		]));

		$this->assertInstanceOf(Follow::class, $item);
		$this->assertFalse($item->hasObject());
		$this->assertSame('https://cloud.example.com/apps/social/@alice', $item->getObjectId());
	}

	public function testImportFromJsonRejectsUnknownTypes(): void {
		$this->useRealActivityPub();

		$this->expectException(ItemUnknownException::class);
		$this->service->importFromJson('{"type":"Teapot","id":"https://remote.example/1"}');
	}

	public function testImportFromJsonRejectsMissingType(): void {
		$this->useRealActivityPub();

		$this->expectException(ItemUnknownException::class);
		$this->service->importFromJson('{"id":"https://remote.example/1"}');
	}

	private function incomingNote(string $origin): Note {
		$note = new Note();
		$note->setId('https://remote.example/notes/1');
		$note->setOrigin($origin, SignatureService::ORIGIN_HEADER, time());

		return $note;
	}

	public function testParseIncomingRequestDispatchesToTheInterfaceWithARequestToken(): void {
		$ap = $this->createMock(AP::class);
		AP::set($ap);
		$note = $this->incomingNote('remote.example');
		$interface = $this->createMock(NoteInterface::class);
		$ap->expects($this->once())->method('getInterfaceForItem')->with($this->identicalTo($note))->willReturn($interface);
		$interface->expects($this->once())
			->method('processIncomingRequest')
			->with($this->callback(function (Note $item) use ($note) {
				$this->assertSame($note, $item);
				$this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $item->getRequestToken());

				return true;
			}));
		$this->miscService->expects($this->never())->method('log');

		$this->service->parseIncomingRequest($note);
	}

	public function testParseIncomingRequestRefusesASuspendedAccount(): void {
		$ap = $this->createMock(AP::class);
		AP::set($ap);
		$this->moderationService->method('isSuspended')->willReturn(true);

		// a suspension that let the account keep posting would undo itself
		$ap->expects($this->never())->method('getInterfaceForItem');

		$this->service->parseIncomingRequest($this->incomingNote('remote.example'));
	}

	public function testParseIncomingRequestAcceptsAnAccountUnderNoDecision(): void {
		$ap = $this->createMock(AP::class);
		AP::set($ap);
		$this->moderationService->method('isSuspended')->willReturn(false);
		$interface = $this->createMock(NoteInterface::class);
		$interface->expects($this->once())->method('processIncomingRequest');
		$ap->method('getInterfaceForItem')->willReturn($interface);

		$this->service->parseIncomingRequest($this->incomingNote('remote.example'));
	}

	public function testParseIncomingRequestPropagatesAnUnexpectedFailure(): void {
		$ap = $this->createMock(AP::class);
		AP::set($ap);
		$interface = $this->createMock(NoteInterface::class);
		// A database or other unexpected failure must not be swallowed behind a 200:
		// it propagates so the inbox answers 5xx and the sender retries.
		$interface->method('processIncomingRequest')->willThrowException(new Exception('boom'));
		$ap->method('getInterfaceForItem')->willReturn($interface);

		$this->expectException(Exception::class);
		$this->service->parseIncomingRequest($this->incomingNote('remote.example'));
	}

	public function testParseIncomingRequestToleratesAnUnprocessableActivity(): void {
		$ap = $this->createMock(AP::class);
		AP::set($ap);
		$interface = $this->createMock(NoteInterface::class);
		$interface->method('processIncomingRequest')
			->willThrowException(new InvalidResourceException('nothing to resolve'));
		$ap->method('getInterfaceForItem')->willReturn($interface);
		$this->miscService->expects($this->once())->method('log')
			->with($this->stringContains('Ignoring Note'));

		// No exception escapes: an understood-but-unprocessable activity is a no-op.
		$this->service->parseIncomingRequest($this->incomingNote('remote.example'));
	}

	public function testParseIncomingRequestRefusesAnIdFromAnotherOrigin(): void {
		$ap = $this->createMock(AP::class);
		AP::set($ap);
		$ap->expects($this->never())->method('getInterfaceForItem');

		$this->expectException(InvalidOriginException::class);
		$this->service->parseIncomingRequest($this->incomingNote('evil.example'));
	}

	public function testParseIncomingRequestRefusesAnItemWithoutOrigin(): void {
		AP::set($this->createMock(AP::class));
		$note = new Note();
		$note->setId('https://remote.example/notes/1');

		$this->expectException(InvalidOriginException::class);
		$this->service->parseIncomingRequest($note);
	}

	public function testParseIncomingRequestRejectsUnknownInterface(): void {
		$ap = $this->createMock(AP::class);
		AP::set($ap);
		$ap->method('getInterfaceForItem')->willThrowException(new ItemUnknownException());
		$person = new Person();
		$person->setId('https://remote.example/users/bob');
		$person->setOrigin('remote.example', SignatureService::ORIGIN_HEADER, time());

		$this->expectException(ItemUnknownException::class);
		$this->service->parseIncomingRequest($person);
	}
}
