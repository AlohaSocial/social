<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Federation;

use OCA\Social\AP;
use OCA\Social\Db\ActionsRequest;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\ActionDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\FollowNotFoundException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Interfaces\Activity\ActivityObjectResolver;
use OCA\Social\Interfaces\Activity\AddInterface;
use OCA\Social\Interfaces\Activity\FeaturedCollection;
use OCA\Social\Interfaces\Activity\RemoveInterface;
use OCA\Social\Interfaces\Actor\ApplicationInterface;
use OCA\Social\Interfaces\Actor\GroupInterface;
use OCA\Social\Interfaces\Actor\OrganizationInterface;
use OCA\Social\Interfaces\Object\FollowInterface;
use OCA\Social\Interfaces\Object\NoteInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Activity\Accept;
use OCA\Social\Model\ActivityPub\Activity\Add;
use OCA\Social\Model\ActivityPub\Activity\Create;
use OCA\Social\Model\ActivityPub\Activity\Remove;
use OCA\Social\Model\ActivityPub\Actor\Application;
use OCA\Social\Model\ActivityPub\Actor\Group;
use OCA\Social\Model\ActivityPub\Actor\Organization;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\PinService;
use OCA\Social\Service\SignatureService;
use OCA\Social\Tests\Model\TActivityPubMocks;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once __DIR__ . '/../Model/TActivityPubMocks.php';

/**
 * Wire-level regressions for the interoperability gaps this app used to have:
 * actor types that could never be cached, object types that were silently
 * discarded, and wrapping activities whose `object` arrives as a bare URI.
 *
 * Every fixture is a document as the implementation named in its filename
 * actually sends it.
 */
#[AllowMockObjectsWithoutExpectations]
class InteropRegressionTest extends TestCase {
	use TActivityPubMocks;

	protected function setUp(): void {
		$this->installDispatcher();
		\OC::$server->register(\OCP\IURLGenerator::class, $this->createStub(\OCP\IURLGenerator::class));
	}

	/**
	 * The dispatcher with no actor known locally, so an import never reaches
	 * for the network.
	 *
	 * @param list<class-string> $mocked the interfaces the test expects calls on
	 */
	private function installDispatcher(array $mocked = []): void {
		$this->installActivityPub(mocked: $mocked);
		$this->apInterface(\OCA\Social\Interfaces\Actor\PersonInterface::class)
			->method('getItemById')
			->willThrowException(new \OCA\Social\Exceptions\ItemNotFoundException());
	}

	protected function tearDown(): void {
		AP::set(null);
		\OC::$server->reset();
	}

	private function fixture(string $name): array {
		$json = file_get_contents(__DIR__ . '/fixtures/' . $name . '.json');
		$this->assertIsString($json, $name . ' fixture exists');

		return json_decode($json, true, 128, JSON_THROW_ON_ERROR);
	}

	// actor types other than Person

	/** @return iterable<string, array{string, string, string}> */
	public static function nonPersonActors(): iterable {
		yield 'a Lemmy community' => [
			'lemmy-group-actor', Group::class, GroupInterface::class,
		];
		yield 'a Mastodon instance actor' => [
			'mastodon-instance-actor', Application::class, ApplicationInterface::class,
		];
		yield 'an a.gup.pe group' => [
			'guppe-group-actor', Organization::class, OrganizationInterface::class,
		];
	}

	/**
	 * These were parsed into the right model all along, but the registry had no
	 * interface for them: they raised ItemUnknownException, which
	 * CacheActorService::save() swallows, so the actor was never written to
	 * social_cache_actors. One signature check passed on the in-memory copy and
	 * every later request re-fetched over HTTP — a Lemmy community, a Friendica
	 * or a.gup.pe group and a Mastodon instance or relay actor could not be
	 * followed or resolved at all.
	 */
	#[DataProvider('nonPersonActors')]
	public function testANonPersonActorHasAnInterfaceThatCanCacheIt(
		string $fixture, string $model, string $interface,
	): void {
		$actor = AP::instance()->getItemFromData($this->fixture($fixture));

		$this->assertInstanceOf($model, $actor);
		$this->assertTrue(AP::instance()->isActor($actor));
		$this->assertSame(
			$this->apInterface($interface),
			AP::instance()->getInterfaceForItem($actor),
			'without this the actor is never stored'
		);
	}

	public function testALemmyCommunityKeepsTheFieldsNeededToDeliverToIt(): void {
		/** @var Person $actor */
		$actor = AP::instance()->getItemFromData($this->fixture('lemmy-group-actor'));

		$this->assertSame('https://lemmy.world/c/technology', $actor->getId());
		$this->assertSame('technology', $actor->getPreferredUsername());
		$this->assertSame('https://lemmy.world/c/technology/inbox', $actor->getInbox());
		$this->assertSame('https://lemmy.world/inbox', $actor->getSharedInbox());
		$this->assertStringContainsString('BEGIN PUBLIC KEY', $actor->getPublicKey());
	}

	public function testAMastodonInstanceActorCarriesItsKey(): void {
		/** @var Person $actor */
		$actor = AP::instance()->getItemFromData($this->fixture('mastodon-instance-actor'));

		$this->assertSame('https://mastodon.social/actor', $actor->getId());
		$this->assertStringContainsString('BEGIN PUBLIC KEY', $actor->getPublicKey());
		$this->assertTrue($actor->isLocked());
	}

	// object types other than Note

	/** @return iterable<string, array{string, string, string, string}> */
	public static function noteLikeObjects(): iterable {
		yield 'a PeerTube video' => [
			'peertube-create-video', 'Video',
			'https://peertube.example/videos/watch/6f4c1e1a',
			'https://peertube.example/video-channels/news',
		];
		yield 'a WriteFreely article' => [
			'writefreely-create-article', 'Article',
			'https://blog.example/alice/abc',
			'https://blog.example/api/collections/alice',
		];
		yield 'a Mobilizon event' => [
			'mobilizon-create-event', 'Event',
			'https://mobilizon.example/events/9f',
			'https://mobilizon.example/@group',
		];
	}

	/**
	 * `getObjectFromData()` used to swallow the ItemUnknownException these
	 * raised and — because `object` had arrived as an array — set neither
	 * `object` nor `objectId`, so CreateInterface returned on `!hasObject()`.
	 * Following a PeerTube channel, a Plume or WriteFreely blog or a Mobilizon
	 * group produced a permanently empty timeline with no log line.
	 */
	#[DataProvider('noteLikeObjects')]
	public function testAnObjectTypeRealServersPostArrivesAsAStatus(
		string $fixture, string $wireType, string $objectId, string $author,
	): void {
		$item = AP::instance()->getItemFromData($this->fixture($fixture));

		$this->assertInstanceOf(Create::class, $item);
		$this->assertTrue($item->hasObject(), 'the object used to be dropped entirely');

		/** @var Note $object */
		$object = $item->getObject();
		$this->assertInstanceOf(Note::class, $object);
		$this->assertSame(Note::TYPE, $object->getType(), 'handled as a status, like Mastodon does');
		$this->assertSame($wireType, $object->getSubType(), 'the wire type is not lost');
		$this->assertSame($objectId, $object->getId());
		$this->assertSame($objectId, $item->getObjectId());
		$this->assertSame($author, $object->getAttributedTo());
		$this->assertSame(
			$this->apInterface(NoteInterface::class),
			AP::instance()->getInterfaceForItem($object)
		);
	}

	public function testAVideoKeepsItsDescriptionAndAnEventFallsBackToItsTitle(): void {
		/** @var Note $video */
		$video = AP::instance()->getItemFromData($this->fixture('peertube-create-video'))->getObject();
		// what a video is called lives in `name`, which a Note has no use for,
		// so a timeline that read `content` alone showed the description of a
		// video whose title it never mentioned. The description is markdown --
		// the object says so in its own `mediaType` -- so it is escaped rather
		// than passed through as html.
		$this->assertSame(
			'<p><a href="https://peertube.example/w/6f4c1e1a">The state of the Fediverse</a></p>'
			. '<p>A talk about federation.</p>',
			$video->getContent()
		);
		// a video's watch page is a different URL from its id, so it is linked
		$this->assertSame('https://peertube.example/w/6f4c1e1a', $video->getUrl());

		// Mobilizon sends no content at all: rendered as it arrived that is an
		// empty post, so the title becomes the content — the same substitution
		// Mastodon makes
		/** @var Note $event */
		$event = AP::instance()->getItemFromData($this->fixture('mobilizon-create-event'))->getObject();
		$this->assertSame('<p>Fediverse meetup</p>', $event->getContent());
	}

	/**
	 * PeerTube sends `url` as a list -- the watch page, one link per transcoded
	 * resolution, the HLS playlist, a torrent and a magnet URI -- and the video
	 * itself is in there rather than in `attachment`, where every other server
	 * puts its media. Without this the post arrived with nothing to play.
	 */
	public function testAVideoArrivesWithSomethingToPlay(): void {
		/** @var Note $video */
		$video = AP::instance()->getItemFromData($this->fixture('peertube-create-video'))->getObject();

		$attachments = $video->getAttachments();
		$this->assertCount(1, $attachments, 'the video itself is the attachment');
		$this->assertSame('video', $attachments[0]->getType());
		// the best resolution this app is willing to proxy, and never the
		// `rel: ["metadata"]` link beside it, which is json
		$this->assertSame(
			'https://peertube.example/static/web-videos/6f4c1e1a-720.mp4',
			$attachments[0]->getRemoteUrl()
		);
		$this->assertSame(3723.0, $attachments[0]->getMeta()?->getDuration());
	}

	/**
	 * `attributedTo` is a list of two actors on a PeerTube video -- the channel
	 * and the account behind it -- where every other server sends one id as a
	 * string. `Stream::import()` asks for a string, so a federated video used
	 * to arrive attributed to nobody at all.
	 */
	public function testAVideoIsAttributedToItsChannel(): void {
		/** @var Note $video */
		$video = AP::instance()->getItemFromData($this->fixture('peertube-create-video'))->getObject();

		$this->assertSame('https://peertube.example/video-channels/news', $video->getAttributedTo());
	}

	public function testAVideosTitleIsLinkedWhenItCarriesNoDescription(): void {
		$data = $this->fixture('peertube-create-video');
		unset($data['object']['content']);

		/** @var Note $video */
		$video = AP::instance()->getItemFromData($data)->getObject();

		$this->assertStringContainsString('The state of the Fediverse', $video->getContent());
		$this->assertStringContainsString('https://peertube.example/w/6f4c1e1a', $video->getContent());
	}

	// a wrapping activity whose object is a bare URI

	/**
	 * Mastodon embeds the Follow in its Accept; GoToSocial and others send a
	 * link. The link used to be dropped, which left the follow pending forever:
	 * no posts arrived, and a later unfollow sent `Undo{Follow}` for a follow
	 * the peer believed it had granted.
	 */
	public function testAnAcceptCarryingOnlyTheFollowsUriStillConfirmsIt(): void {
		$this->installDispatcher([FollowInterface::class]);
		$accept = AP::instance()->getItemFromData($this->fixture('linked-accept-follow'));
		$accept->setOrigin('gotosocial.example', SignatureService::ORIGIN_HEADER, time());

		$this->assertInstanceOf(Accept::class, $accept);
		$this->assertFalse($accept->hasObject());
		$this->assertSame('https://cloud.example/apps/social/@alice#follows/4711', $accept->getObjectId());

		$stored = new Follow();
		$stored->setId($accept->getObjectId());
		$stored->setActorId('https://cloud.example/apps/social/@alice');
		$stored->setObjectId('https://gotosocial.example/users/bob');

		$follows = $this->createMock(FollowsRequest::class);
		$follows->method('getById')->with($accept->getObjectId())->willReturn($stored);
		$resolver = new ActivityObjectResolver(
			$this->createStub(StreamRequest::class),
			$follows,
			$this->createStub(ActionsRequest::class),
			$this->createStub(CacheActorsRequest::class)
		);

		$this->apInterface(FollowInterface::class)
			->expects($this->once())
			->method('activity')
			->with($this->identicalTo($accept), $this->identicalTo($stored));

		(new \OCA\Social\Interfaces\Activity\AcceptInterface($resolver, new NullLogger()))
			->processIncomingRequest($accept);
	}

	// pinned posts

	/**
	 * A pin is not federated as an activity of its own: what travels is
	 * `Add`/`Remove` naming the actor's `featured` collection as `target`, with
	 * `object` as a bare URI. Both used to return immediately, so a remote
	 * profile never showed a pinned post.
	 */
	public function testAMastodonAddPinsThePostToTheAuthorsProfile(): void {
		$add = AP::instance()->getItemFromData($this->fixture('mastodon-add-pinned'));
		$add->setOrigin('mastodon.social', SignatureService::ORIGIN_HEADER, time());

		$this->assertInstanceOf(Add::class, $add);
		$this->assertSame(
			'https://mastodon.social/users/alice/collections/featured',
			$add->getTarget(),
			'the target used never to be read'
		);

		$actions = $this->createMock(ActionsRequest::class);
		$actions->method('getAction')->willThrowException(new ActionDoesNotExistException());
		$actions->method('getActionsByActor')->willReturn([]);

		/** @var ACore|null $saved */
		$saved = null;
		$actions->expects($this->once())->method('save')
			->willReturnCallback(function (ACore $pin) use (&$saved): void {
				$saved = $pin;
			});

		(new AddInterface($this->featured($actions)))->processIncomingRequest($add);

		$this->assertNotNull($saved);
		$this->assertSame(PinService::TYPE, $saved->getType());
		$this->assertSame('https://mastodon.social/users/alice', $saved->getActorId());
		$this->assertSame('https://mastodon.social/users/alice/statuses/109876', $saved->getObjectId());
	}

	public function testAMastodonRemoveUnpinsIt(): void {
		$remove = AP::instance()->getItemFromData($this->fixture('mastodon-remove-pinned'));
		$remove->setOrigin('mastodon.social', SignatureService::ORIGIN_HEADER, time());

		$this->assertInstanceOf(Remove::class, $remove);

		$actions = $this->createMock(ActionsRequest::class);
		$actions->expects($this->once())->method('deleteAction')->with(
			'https://mastodon.social/users/alice',
			'https://mastodon.social/users/alice/statuses/109876',
			PinService::TYPE
		);

		(new RemoveInterface($this->featured($actions)))->processIncomingRequest($remove);
	}

	private function featured(ActionsRequest $actions): FeaturedCollection {
		$actor = new Person();
		$actor->setId('https://mastodon.social/users/alice');
		$actor->setFeatured('https://mastodon.social/users/alice/collections/featured');

		$actors = $this->createStub(CacheActorsRequest::class);
		$actors->method('getFromId')->willReturn($actor);

		$note = new Note();
		$note->setId('https://mastodon.social/users/alice/statuses/109876');
		$note->setAttributedTo($actor->getId());
		$streams = $this->createStub(StreamRequest::class);
		$streams->method('getStreamById')->willReturn($note);

		return new FeaturedCollection($actors, $streams, $actions, new NullLogger());
	}

	// the @context of what we send

	/**
	 * The extension terms outgoing documents use are defined inline, so a
	 * consumer that actually compacts JSON-LD keeps them. They used to expand
	 * to blank nodes through the shipped context's `@vocab: "_:"` and were
	 * dropped.
	 */
	public function testOutgoingDocumentsDeclareTheExtensionTermsTheyUse(): void {
		$note = new Note();
		$note->setId('https://cloud.example/@alice/statuses/1');
		$export = $note->exportAsActivityPub();

		$context = $export['@context'];
		$this->assertSame(ACore::CONTEXT_ACTIVITYSTREAMS, $context[0]);

		$inline = end($context);
		$this->assertIsArray($inline);
		foreach ([
			'manuallyApprovesFollowers', 'featured', 'alsoKnownAs', 'movedTo',
			'sensitive', 'conversation', 'blurhash', 'Hashtag', 'Emoji',
			'PropertyValue', 'value', 'discoverable', 'votersCount', 'focalPoint',
		] as $term) {
			$this->assertArrayHasKey($term, $inline, $term . ' is emitted but was undefined');
		}
		// the prefixes the definitions above resolve through
		$this->assertSame('http://joinmastodon.org/ns#', $inline['toot']);
		$this->assertSame('http://schema.org#', $inline['schema']);
		$this->assertSame('http://ostatus.org#', $inline['ostatus']);
	}

	public function testTheExtensionContextIsNotRepeatedOnNestedObjects(): void {
		$create = new Create();
		$note = new Note($create);
		$note->setId('https://cloud.example/@alice/statuses/1');
		$create->setId('https://cloud.example/@alice/statuses/1/activity');
		$create->setObject($note);

		$this->assertArrayHasKey('@context', $create->exportAsActivityPub());
		$this->assertArrayNotHasKey('@context', $note->exportAsActivityPub());
	}

	/**
	 * Nothing in the resolver reaches the network: a wrapping activity that
	 * refers to something this instance has never seen has nothing to undo or
	 * accept, and must not become an outbound request an unauthenticated peer
	 * gets to choose.
	 */
	public function testAnUnknownBareObjectUriResolvesToNothing(): void {
		$resolver = new ActivityObjectResolver(
			$this->streamsWithout(),
			$this->followsWithout(),
			$this->actionsWithout(),
			$this->actorsWithout()
		);

		$accept = new Accept();
		$accept->setId('https://remote.example/accepts/1');
		$accept->setObjectId('https://remote.example/anything/1');

		$this->expectException(\OCA\Social\Exceptions\ItemNotFoundException::class);
		$resolver->resolve($accept);
	}

	private function streamsWithout(): StreamRequest {
		$mock = $this->createMock(StreamRequest::class);
		$mock->method('getStreamById')->willThrowException(new StreamNotFoundException());

		return $mock;
	}

	private function followsWithout(): FollowsRequest {
		$mock = $this->createMock(FollowsRequest::class);
		$mock->method('getById')->willThrowException(new FollowNotFoundException());

		return $mock;
	}

	private function actionsWithout(): ActionsRequest {
		$mock = $this->createMock(ActionsRequest::class);
		$mock->method('getById')->willThrowException(new ActionDoesNotExistException());

		return $mock;
	}

	private function actorsWithout(): CacheActorsRequest {
		$mock = $this->createMock(CacheActorsRequest::class);
		$mock->method('getFromId')->willThrowException(new CacheActorDoesNotExistException());

		return $mock;
	}
}
