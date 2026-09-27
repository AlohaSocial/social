<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests;

use OCA\Social\AP;
use OCA\Social\Exceptions\ItemUnknownException;
use OCA\Social\Exceptions\RedundancyLimitException;
use OCA\Social\Interfaces\Activity\AcceptInterface;
use OCA\Social\Interfaces\Activity\AddInterface;
use OCA\Social\Interfaces\Activity\BlockInterface;
use OCA\Social\Interfaces\Activity\CreateInterface;
use OCA\Social\Interfaces\Activity\DeleteInterface;
use OCA\Social\Interfaces\Activity\MoveInterface;
use OCA\Social\Interfaces\Activity\QuoteRequestInterface;
use OCA\Social\Interfaces\Activity\RejectInterface;
use OCA\Social\Interfaces\Activity\RemoveInterface;
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
use OCA\Social\Interfaces\Object\FlagInterface;
use OCA\Social\Interfaces\Object\FollowInterface;
use OCA\Social\Interfaces\Object\ImageInterface;
use OCA\Social\Interfaces\Object\LikeInterface;
use OCA\Social\Interfaces\Object\NoteInterface;
use OCA\Social\Model\ActivityPub\Activity\Accept;
use OCA\Social\Model\ActivityPub\Activity\Add;
use OCA\Social\Model\ActivityPub\Activity\Block;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Delete;
use OCA\Social\Model\ActivityPub\Activity\Move;
use OCA\Social\Model\ActivityPub\Activity\QuoteRequest;
use OCA\Social\Model\ActivityPub\Activity\Reject;
use OCA\Social\Model\ActivityPub\Activity\Remove;
use OCA\Social\Model\ActivityPub\Activity\Undo;
use OCA\Social\Model\ActivityPub\Activity\Update;
use OCA\Social\Model\ActivityPub\Actor\Application;
use OCA\Social\Model\ActivityPub\Actor\Group;
use OCA\Social\Model\ActivityPub\Actor\Organization;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Actor\Service;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Flag;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Object\Tombstone;
use OCA\Social\Model\ActivityPub\OrderedCollection;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Model/TActivityPubMocks.php';

class APTest extends TestCase {
	use TActivityPubMocks;

	private AP $ap;

	protected function setUp(): void {
		$this->ap = $this->installActivityPub();
		// Stream::import() always resolves the URL generator, even without attachments
		\OC::$server->register(IURLGenerator::class, $this->createStub(IURLGenerator::class));
	}

	protected function tearDown(): void {
		AP::set(null);
		\OC::$server->reset();
	}

	public static function knownTypeProvider(): array {
		return [
			'Accept' => ['Accept', Accept::class],
			'Add' => ['Add', Add::class],
			'Announce' => ['Announce', Announce::class],
			'Block' => ['Block', Block::class],
			'Move' => ['Move', Move::class],
			'Create' => ['Create', Create::class],
			'Delete' => ['Delete', Delete::class],
			'Document' => ['Document', Document::class],
			'Flag' => ['Flag', Flag::class],
			'Follow' => ['Follow', Follow::class],
			'Image' => ['Image', Image::class],
			'Like' => ['Like', Like::class],
			'Note' => ['Note', Note::class],
			'Question' => ['Question', Question::class],
			'QuoteRequest' => ['QuoteRequest', QuoteRequest::class],
			'OrderedCollection' => ['OrderedCollection', OrderedCollection::class],
			'SocialAppNotification' => ['SocialAppNotification', SocialAppNotification::class],
			'Stream' => ['Stream', Stream::class],
			'Person' => ['Person', Person::class],
			'Reject' => ['Reject', Reject::class],
			'Remove' => ['Remove', Remove::class],
			'Service' => ['Service', Service::class],
			'Group' => ['Group', Group::class],
			'Organization' => ['Organization', Organization::class],
			'Application' => ['Application', Application::class],
			'Tombstone' => ['Tombstone', Tombstone::class],
			'Undo' => ['Undo', Undo::class],
			'Update' => ['Update', Update::class],
		];
	}

	#[DataProvider('knownTypeProvider')]
	public function testGetItemFromTypeMapsEveryKnownType(string $type, string $class): void {
		$item = $this->ap->getItemFromType($type);

		$this->assertInstanceOf($class, $item);
		$this->assertSame(self::cloudUrl(), $item->getUrlCloud());
	}

	public static function unknownTypeProvider(): array {
		return [
			'unsupported AS2 type' => ['Profile'],
			'empty' => [''],
			'wrong case' => ['note'],
		];
	}

	#[DataProvider('unknownTypeProvider')]
	public function testGetItemFromTypeRejectsUnknownTypes(string $type): void {
		$this->expectException(ItemUnknownException::class);

		$this->ap->getItemFromType($type);
	}

	public function testOnlyAnnouncesAreMarkedToFilterDuplicates(): void {
		/** @var Announce $announce */
		$announce = $this->ap->getItemFromType('Announce');
		/** @var Note $note */
		$note = $this->ap->getItemFromType('Note');

		$this->assertTrue($announce->isFilterDuplicate());
		$this->assertFalse($note->isFilterDuplicate());
	}

	public static function interfaceProvider(): array {
		return [
			'Accept' => ['Accept', AcceptInterface::class],
			'Add' => ['Add', AddInterface::class],
			'Announce' => ['Announce', AnnounceInterface::class],
			'Block' => ['Block', BlockInterface::class],
			'Create' => ['Create', CreateInterface::class],
			'Delete' => ['Delete', DeleteInterface::class],
			'Document' => ['Document', DocumentInterface::class],
			'Flag' => ['Flag', FlagInterface::class],
			'Follow' => ['Follow', FollowInterface::class],
			'Image' => ['Image', ImageInterface::class],
			'Like' => ['Like', LikeInterface::class],
			'Move' => ['Move', MoveInterface::class],
			'Note' => ['Note', NoteInterface::class],
			'Question' => ['Question', NoteInterface::class],
			'QuoteRequest' => ['QuoteRequest', QuoteRequestInterface::class],
			'SocialAppNotification' => ['SocialAppNotification', SocialAppNotificationInterface::class],
			'Person' => ['Person', PersonInterface::class],
			'Reject' => ['Reject', RejectInterface::class],
			'Remove' => ['Remove', RemoveInterface::class],
			'Service' => ['Service', ServiceInterface::class],
			'Group' => ['Group', GroupInterface::class],
			'Organization' => ['Organization', OrganizationInterface::class],
			'Application' => ['Application', ApplicationInterface::class],
			'Undo' => ['Undo', UndoInterface::class],
			'Update' => ['Update', UpdateInterface::class],
		];
	}

	#[DataProvider('interfaceProvider')]
	public function testGetInterfaceFromTypeReturnsTheInjectedInterface(string $type, string $interfaceClass): void {
		$this->assertSame($this->apInterface($interfaceClass), $this->ap->getInterfaceFromType($type));
	}

	public function testGetInterfaceFromTypeRejectsUnknownTypes(): void {
		$this->expectException(ItemUnknownException::class);

		$this->ap->getInterfaceFromType('Profile');
	}

	public static function noteLikeTypeProvider(): array {
		return array_map(static fn (string $type): array => [$type], AP::NOTE_LIKE_TYPES);
	}

	/**
	 * PeerTube, Plume, Mobilizon, Lemmy and Funkwhale post these; they are
	 * handled as statuses, which is also how Mastodon shows them.
	 */
	#[DataProvider('noteLikeTypeProvider')]
	public function testNoteLikeTypesAreModelledAsNotes(string $type): void {
		$this->assertInstanceOf(Note::class, $this->ap->getItemFromType($type));
		$this->assertSame($this->apInterface(NoteInterface::class), $this->ap->getInterfaceFromType($type));
	}

	#[DataProvider('noteLikeTypeProvider')]
	public function testNoteLikeTypeKeepsTheWireTypeInTheSubtype(string $type): void {
		$item = $this->ap->getSimpleItemFromData([
			'id' => 'https://peertube.example/videos/watch/1',
			'type' => $type,
			'attributedTo' => 'https://peertube.example/accounts/alice',
			'content' => '<p>a description</p>',
		]);

		$this->assertInstanceOf(Note::class, $item);
		$this->assertSame(Note::TYPE, $item->getType());
		$this->assertSame($type, $item->getSubType());
		$this->assertSame('<p>a description</p>', $item->getContent());
	}

	public function testNoteLikeTypeWithoutContentFallsBackToItsTitleAndLink(): void {
		/** @var Note $item */
		$item = $this->ap->getSimpleItemFromData([
			'id' => 'https://peertube.example/videos/watch/1',
			'type' => 'Video',
			'attributedTo' => 'https://peertube.example/accounts/alice',
			'name' => 'Cats & dogs',
			'url' => 'https://peertube.example/w/1',
		]);

		$this->assertStringContainsString('Cats &amp; dogs', $item->getContent());
		$this->assertStringContainsString('https://peertube.example/w/1', $item->getContent());
		// `name` on a Note means the option a poll vote chose; a title must not
		// land there or the post could be counted as a vote
		$this->assertSame('', $item->getName());
	}

	public function testNoteLikeTypeArrivingInsideACreateIsNotDropped(): void {
		$item = $this->ap->getItemFromData([
			'id' => 'https://peertube.example/videos/watch/1/activity',
			'type' => 'Create',
			'actor' => 'https://peertube.example/accounts/alice',
			'object' => [
				'id' => 'https://peertube.example/videos/watch/1',
				'type' => 'Video',
				'name' => 'a video',
				'attributedTo' => 'https://peertube.example/accounts/alice',
			],
		]);

		$this->assertTrue($item->hasObject());
		$this->assertInstanceOf(Note::class, $item->getObject());
		$this->assertSame('https://peertube.example/videos/watch/1', $item->getObjectId());
	}

	public function testGetInterfaceForItemDispatchesOnTheItemType(): void {
		$this->assertSame($this->apInterface(LikeInterface::class), $this->ap->getInterfaceForItem(new Like()));
		$this->assertSame($this->apInterface(NoteInterface::class), $this->ap->getInterfaceForItem(new Note()));
	}

	public static function actorProvider(): array {
		return [
			'Person' => [new Person(), true],
			'Service' => [new Service(), true],
			'Group' => [new Group(), true],
			'Organization' => [new Organization(), true],
			'Application' => [new Application(), true],
			'Note' => [new Note(), false],
			'Create' => [new Create(), false],
			'Document' => [new Document(), false],
		];
	}

	#[DataProvider('actorProvider')]
	public function testIsActorRecognisesTheFiveActorTypes(object $item, bool $expected): void {
		$this->assertSame($expected, $this->ap->isActor($item));
	}

	public function testGetSimpleItemFromDataImportsAndKeepsTheRawSource(): void {
		$data = [
			'id' => 'https://mastodon.social/users/alice#likes/1',
			'type' => 'Like',
			'actor' => 'https://mastodon.social/users/alice',
			'object' => 'https://cloud.example.org/@bob/status/2',
		];

		$item = $this->ap->getSimpleItemFromData($data);

		$this->assertInstanceOf(Like::class, $item);
		$this->assertSame('https://mastodon.social/users/alice#likes/1', $item->getId());
		$this->assertSame('https://mastodon.social/users/alice', $item->getActorId());
		$this->assertSame('https://cloud.example.org/@bob/status/2', $item->getObjectId());
		$this->assertSame(json_encode($data, JSON_UNESCAPED_SLASHES), $item->getSource());
	}

	public function testNestedObjectIsParsedRecursivelyAndLinkedToItsParent(): void {
		$item = $this->ap->getItemFromData([
			'id' => 'https://mastodon.social/users/alice/statuses/1/activity',
			'type' => 'Create',
			'actor' => 'https://mastodon.social/users/alice',
			'object' => [
				'id' => 'https://mastodon.social/users/alice/statuses/1',
				'type' => 'Note',
				'content' => '<p>hello</p>',
				'attributedTo' => 'https://mastodon.social/users/alice',
			],
		]);

		$this->assertInstanceOf(Create::class, $item);
		$this->assertTrue($item->hasObject());
		$note = $item->getObject();
		$this->assertInstanceOf(Note::class, $note);
		$this->assertSame('<p>hello</p>', $note->getContent());
		$this->assertSame($item, $note->getParent());
		$this->assertSame($item, $note->getRoot());
		$this->assertSame('https://mastodon.social/users/alice/statuses/1', $item->getObjectId());
		$this->assertTrue($item->isRoot());
	}

	public function testObjectGivenAsAnIdOnlySetsTheObjectId(): void {
		$item = $this->ap->getItemFromData([
			'id' => 'https://mastodon.social/users/alice#follows/1',
			'type' => 'Follow',
			'actor' => 'https://mastodon.social/users/alice',
			'object' => 'https://cloud.example.org/@bob',
		]);

		$this->assertFalse($item->hasObject());
		$this->assertSame('https://cloud.example.org/@bob', $item->getObjectId());
	}

	public function testNestedObjectOfUnknownTypeKeepsItsId(): void {
		$item = $this->ap->getItemFromData([
			'id' => 'https://mastodon.social/users/alice/statuses/1/activity',
			'type' => 'Create',
			'object' => [
				'id' => 'https://mastodon.social/users/alice/statuses/1',
				'type' => 'Profile',
			],
		]);

		$this->assertFalse($item->hasObject());
		// the id is what makes the activity loggable and resolvable later; it
		// used to be dropped along with the object
		$this->assertSame('https://mastodon.social/users/alice/statuses/1', $item->getObjectId());
	}

	public function testNestedObjectOfUnknownTypeWithoutAnIdIsDropped(): void {
		$item = $this->ap->getItemFromData([
			'id' => 'https://mastodon.social/users/alice/statuses/1/activity',
			'type' => 'Create',
			'object' => ['type' => 'Profile'],
		]);

		$this->assertFalse($item->hasObject());
		$this->assertSame('', $item->getObjectId());
	}

	/**
	 * `actor_info` is this app's own FORMAT_LOCAL field, holding a whole
	 * serialised actor. Read off a document a peer wrote, it *was* the acting
	 * account: `getActorId()` answers from it, so a crafted Announce was
	 * addressed to a stranger's followers collection and a crafted Like named a
	 * stranger in the notification it raised, none of it fetched or checked.
	 */
	public function testActorInfoFromTheWireIsIgnored(): void {
		$item = $this->ap->getItemFromData([
			'id' => 'https://mastodon.social/users/alice#likes/1',
			'type' => 'Like',
			'actor' => 'https://mastodon.social/users/alice',
			'object' => 'https://cloud.example.org/@bob/status/2',
			'actor_info' => [
				'id' => 'https://evil.example/users/mallory',
				'type' => 'Person',
				'preferredUsername' => 'mallory',
				'followers' => 'https://mastodon.social/users/gargron/followers',
			],
		]);

		$this->assertFalse($item->hasActor());
		$this->assertNull($item->getActor());
		$this->assertSame('https://mastodon.social/users/alice', $item->getActorId());
	}

	public function testGivenParentIsAttached(): void {
		$parent = new Create();

		$item = $this->ap->getItemFromData(['type' => 'Like', 'id' => 'https://a.example/l/1'], $parent);

		$this->assertSame($parent, $item->getParent());
	}

	private function nestedAccepts(int $depth): array {
		$data = ['type' => 'Accept', 'id' => 'https://a.example/accept/0'];
		for ($i = 1; $i <= $depth; $i++) {
			$data = ['type' => 'Accept', 'id' => 'https://a.example/accept/' . $i, 'object' => $data];
		}

		return $data;
	}

	public function testNestingUpToTheRedundancyLimitIsAccepted(): void {
		$item = $this->ap->getItemFromData($this->nestedAccepts(AP::REDUNDANCY_LIMIT - 1));

		$depth = 1;
		while ($item->hasObject()) {
			$item = $item->getObject();
			$depth++;
		}
		$this->assertSame(AP::REDUNDANCY_LIMIT, $depth);
	}

	public function testNestingBeyondTheRedundancyLimitIsRejected(): void {
		$this->expectException(RedundancyLimitException::class);

		$this->ap->getItemFromData($this->nestedAccepts(AP::REDUNDANCY_LIMIT));
	}

	public function testNothingRunsAtAutoloadTime(): void {
		// `AP::init();` used to sit at file scope at the bottom of this class,
		// so merely autoloading AP built all 24 interface services out of the
		// container -- on every request that touched ActivityPub, whether or not
		// any of them was wanted.
		$source = (string)file_get_contents(__DIR__ . '/../lib/AP.php');
		$afterClass = substr($source, (int)strrpos($source, "\n}"));

		$this->assertSame(
			'',
			trim(str_replace('}', '', $afterClass)),
			'lib/AP.php runs something at file scope again'
		);
	}

	public function testTheRegistryIsResolvedLazilyAndOnlyOnce(): void {
		AP::set(null);
		$double = $this->createStub(AP::class);
		AP::set($double);

		$this->assertSame($double, AP::instance());
		$this->assertSame($double, AP::instance(), 'the registry is rebuilt on every access');
	}

	public function testTheRegistryCannotBeReachedAroundTheAccessor(): void {
		// it was a public static, so any code anywhere could reassign it
		$this->assertFalse(
			(new \ReflectionClass(AP::class))->hasProperty('activityPub'),
			'the public mutable static is back'
		);
	}

}
