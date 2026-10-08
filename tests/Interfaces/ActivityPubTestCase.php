<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interfaces;

use OCA\Social\AP;
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
use OCA\Social\Interfaces\IActivityPubInterface;
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
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\PeerTubeService;
use OCA\Social\Service\SignatureService;
use OCP\IURLGenerator;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Shared fixture for the incoming-federation handlers.
 *
 * The handlers reach each other through the static `AP` registry,
 * so every test gets a registry built from one mock per interface; the handler
 * under test is then constructed for real with mocked collaborators. Nothing in
 * here touches a database, the network or the filesystem.
 */
abstract class ActivityPubTestCase extends TestCase {
	public const LOCAL_HOST = 'local.example';
	public const LOCAL_URL = 'https://local.example';
	public const REMOTE_HOST = 'remote.example';
	public const REMOTE_URL = 'https://remote.example';

	protected AP $ap;
	/** @var ConfigService&MockObject */
	protected $configService;

	/** @var AcceptInterface&MockObject */
	protected $acceptInterface;
	/** @var AddInterface&MockObject */
	protected $addInterface;
	/** @var AnnounceInterface&MockObject */
	protected $announceInterface;
	/** @var BlockInterface&MockObject */
	protected $blockInterface;
	/** @var CreateInterface&MockObject */
	protected $createInterface;
	/** @var DeleteInterface&MockObject */
	protected $deleteInterface;
	/** @var DocumentInterface&MockObject */
	protected $documentInterface;
	/** @var FlagInterface&MockObject */
	protected $flagInterface;
	/** @var FollowInterface&MockObject */
	protected $followInterface;
	/** @var ImageInterface&MockObject */
	protected $imageInterface;
	/** @var LikeInterface&MockObject */
	protected $likeInterface;
	/** @var EmojiReactInterface&MockObject */
	protected $emojiReactInterface;
	/** @var StoryInterface&MockObject */
	protected $storyInterface;
	/** @var MoveInterface&MockObject */
	protected $moveInterface;
	/** @var NoteInterface&MockObject */
	protected $noteInterface;
	/** @var SocialAppNotificationInterface&MockObject */
	protected $notificationInterface;
	/** @var PersonInterface&MockObject */
	protected $personInterface;
	/** @var ServiceInterface&MockObject */
	protected $serviceInterface;
	/** @var GroupInterface&MockObject */
	protected $groupInterface;
	/** @var OrganizationInterface&MockObject */
	protected $organizationInterface;
	/** @var ApplicationInterface&MockObject */
	protected $applicationInterface;
	/** @var RejectInterface&MockObject */
	protected $rejectInterface;
	/** @var RemoveInterface&MockObject */
	protected $removeInterface;
	/** @var UndoInterface&MockObject */
	protected $undoInterface;
	/** @var UpdateInterface&MockObject */
	protected $updateInterface;
	/** @var QuoteRequestInterface&MockObject */
	protected $quoteRequestInterface;
	protected $storyAnswerInterface;

	protected function setUp(): void {
		parent::setUp();

		$this->acceptInterface = $this->createMock(AcceptInterface::class);
		$this->addInterface = $this->createMock(AddInterface::class);
		$this->storyAnswerInterface = $this->createMock(StoryAnswerInterface::class);
		$this->announceInterface = $this->createMock(AnnounceInterface::class);
		$this->blockInterface = $this->createMock(BlockInterface::class);
		$this->createInterface = $this->createMock(CreateInterface::class);
		$this->deleteInterface = $this->createMock(DeleteInterface::class);
		$this->documentInterface = $this->createMock(DocumentInterface::class);
		$this->flagInterface = $this->createMock(FlagInterface::class);
		$this->followInterface = $this->createMock(FollowInterface::class);
		$this->imageInterface = $this->createMock(ImageInterface::class);
		$this->likeInterface = $this->createMock(LikeInterface::class);
		$this->emojiReactInterface = $this->createMock(EmojiReactInterface::class);
		$this->storyInterface = $this->createMock(StoryInterface::class);
		$this->moveInterface = $this->createMock(MoveInterface::class);
		$this->noteInterface = $this->createMock(NoteInterface::class);
		$this->notificationInterface = $this->createMock(SocialAppNotificationInterface::class);
		$this->personInterface = $this->createMock(PersonInterface::class);
		$this->serviceInterface = $this->createMock(ServiceInterface::class);
		$this->groupInterface = $this->createMock(GroupInterface::class);
		$this->organizationInterface = $this->createMock(OrganizationInterface::class);
		$this->applicationInterface = $this->createMock(ApplicationInterface::class);
		$this->rejectInterface = $this->createMock(RejectInterface::class);
		$this->removeInterface = $this->createMock(RemoveInterface::class);
		$this->undoInterface = $this->createMock(UndoInterface::class);
		$this->updateInterface = $this->createMock(UpdateInterface::class);
		$this->quoteRequestInterface = $this->createMock(QuoteRequestInterface::class);

		// Every model created through the registry gets the cloud URL; ids generated
		// locally (the Accept answering a Follow, for instance) are built from it.
		$this->configService = $this->createMock(ConfigService::class);
		$this->configService->method('getCloudUrl')->willReturn(self::LOCAL_URL);
		$this->configService->method('getCloudHost')->willReturn('local.example');

		$this->ap = new AP(
			$this->acceptInterface,
			$this->addInterface,
			$this->storyAnswerInterface,
			$this->announceInterface,
			$this->blockInterface,
			$this->createInterface,
			$this->deleteInterface,
			$this->documentInterface,
			$this->flagInterface,
			$this->followInterface,
			$this->imageInterface,
			$this->likeInterface,
			$this->emojiReactInterface,
			$this->storyInterface,
			$this->moveInterface,
			$this->noteInterface,
			$this->notificationInterface,
			$this->personInterface,
			$this->serviceInterface,
			$this->groupInterface,
			$this->organizationInterface,
			$this->applicationInterface,
			$this->rejectInterface,
			$this->removeInterface,
			$this->undoInterface,
			$this->updateInterface,
			$this->quoteRequestInterface,
			$this->configService,
			$this->createMock(\OCA\Social\Interfaces\Activity\ApproveReplyInterface::class),
			$this->createMock(\OCA\Social\Interfaces\Object\DislikeInterface::class),
			$this->createMock(\OCA\Social\Interfaces\Object\PlaylistInterface::class),
			// the real one: reading a PeerTube `Video` is parsing, and only
			// the two things it writes through are doubles
			new PeerTubeService(
				$this->documentInterface,
				$this->createMock(IURLGenerator::class),
				$this->createMock(LoggerInterface::class),
			),
		);
		AP::set($this->ap);

		// Stream::import() resolves the URL generator statically while importing attachments
		\OC::$server->register(IURLGenerator::class, $this->createMock(IURLGenerator::class));
	}

	protected function tearDown(): void {
		AP::set(null);
		\OC::$server->reset();

		parent::tearDown();
	}

	/**
	 * @return array<int, IActivityPubInterface&MockObject>
	 */
	protected function allInterfaces(): array {
		return [
			$this->acceptInterface,
			$this->addInterface,
			$this->announceInterface,
			$this->blockInterface,
			$this->createInterface,
			$this->deleteInterface,
			$this->documentInterface,
			$this->followInterface,
			$this->imageInterface,
			$this->likeInterface,
			$this->moveInterface,
			$this->noteInterface,
			$this->notificationInterface,
			$this->personInterface,
			$this->serviceInterface,
			$this->groupInterface,
			$this->organizationInterface,
			$this->applicationInterface,
			$this->rejectInterface,
			$this->removeInterface,
			$this->undoInterface,
			$this->updateInterface,
			$this->quoteRequestInterface,
		];
	}

	/** Nothing in the registry may be handed an activity. */
	protected function expectNoInterfaceReceivesActivity(): void {
		foreach ($this->allInterfaces() as $interface) {
			$interface->expects($this->never())->method('activity');
		}
	}

	/**
	 * Records the first argument of the next call to a mocked method; use it to
	 * assert on the object a handler passes on instead of matching it inline.
	 *
	 * @param mixed $into receives the argument
	 * @param mixed $return what the mocked method should return (void methods: null)
	 */
	protected function capture(MockObject $mock, string $method, &$into, $return = null): void {
		$mock->expects($this->once())
			->method($method)
			->willReturnCallback(function ($argument) use (&$into, $return) {
				$into = $argument;

				return $return;
			});
	}

	/** An actor whose collection URLs hang off its id, the way Mastodon lays them out. */
	protected function person(string $id, bool $local = false, string $account = ''): Person {
		$person = new Person();
		$person->setAccount($account !== '' ? $account : basename($id) . '@' . parse_url($id, PHP_URL_HOST))
			->setInbox($id . '/inbox')
			->setFollowers($id . '/followers')
			->setFollowing($id . '/following');
		$person->setId($id);
		$person->setLocal($local);

		return $person;
	}

	/** A Note as it arrives inside an activity: id plus author, nothing stored yet. */
	protected function note(string $id, string $attributedTo, bool $local = false): Note {
		$note = new Note();
		$note->setId($id);
		$note->setAttributedTo($attributedTo);
		$note->setLocal($local);

		return $note;
	}

	/**
	 * An item the way the inbox hands it to a handler: built through the registry,
	 * carrying the origin the request signature was verified against (the host of
	 * its own id unless told otherwise) and, optionally, the wrapped object.
	 */
	protected function incoming(
		string $type,
		string $id,
		string $actorId,
		?ACore $object = null,
		?string $origin = null,
	): ACore {
		$item = $this->ap->getItemFromType($type);
		$item->setId($id);
		$item->setActorId($actorId);
		if ($object !== null) {
			$item->setObject($object);
		}
		$item->setOrigin(
			$origin ?? (string)parse_url($id, PHP_URL_HOST),
			SignatureService::ORIGIN_HEADER,
			time()
		);

		return $item;
	}
}
