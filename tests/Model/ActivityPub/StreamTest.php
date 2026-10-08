<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\ActivityPub;

use OCA\Social\AP;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Interfaces\Object\DocumentInterface;
use OCA\Social\Interfaces\Object\ImageInterface;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Image;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Model\Details;
use OCA\Social\Model\StreamAction;
use OCA\Social\Service\AiContentService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Tests\Model\TActivityPubMocks;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/../TActivityPubMocks.php';

class StreamTest extends TestCase {
	use TActivityPubMocks;

	private string $timezone;

	protected function setUp(): void {
		$this->timezone = date_default_timezone_get();
		date_default_timezone_set('UTC');

		$this->installActivityPub();

		$urlGenerator = $this->createStub(IURLGenerator::class);
		$urlGenerator->method('linkToRouteAbsolute')->willReturnCallback(
			fn (string $route, array $args): string => 'https://cloud.example.org/' . $route . '/' . ($args['uuid'] ?? '')
		);
		\OC::$server->register(IURLGenerator::class, $urlGenerator);
	}

	protected function tearDown(): void {
		date_default_timezone_set($this->timezone);
		Stream::resetReplyParentCache();
		AP::set(null);
		\OC::$server->reset();
	}

	public function testImportReadsTheMastodonNoteFields(): void {
		$stream = new Stream();

		$stream->import([
			'id' => 'https://mastodon.social/users/alice/statuses/112000000000000001',
			'type' => 'Note',
			'summary' => 'CW: cats',
			'inReplyTo' => 'https://mastodon.social/users/bob/statuses/111999999999999999',
			'published' => '2024-05-01T12:00:00Z',
			'url' => 'https://mastodon.social/@alice/112000000000000001',
			'attributedTo' => 'https://mastodon.social/users/alice',
			'to' => [ACore::CONTEXT_PUBLIC],
			'cc' => ['https://mastodon.social/users/alice/followers'],
			'sensitive' => true,
			'conversation' => 'tag:mastodon.social,2024-05-01:objectId=1:objectType=Conversation',
			'content' => '<p>Look at <b>this</b></p>',
			'attachment' => [],
			'likes' => ['id' => 'https://mastodon.social/users/alice/statuses/112000000000000001/likes', 'type' => 'Collection', 'totalItems' => 5],
			'shares' => ['id' => 'https://mastodon.social/users/alice/statuses/112000000000000001/shares', 'type' => 'Collection', 'totalItems' => 2],
			'replies' => ['id' => 'https://mastodon.social/users/alice/statuses/112000000000000001/replies', 'type' => 'Collection', 'totalItems' => 1],
		]);

		$this->assertSame('Note', $stream->getType());
		$this->assertSame('CW: cats', $stream->getSummary());
		$this->assertSame('https://mastodon.social/users/bob/statuses/111999999999999999', $stream->getInReplyTo());
		$this->assertSame('https://mastodon.social/users/alice', $stream->getAttributedTo());
		$this->assertSame(1714564800, $stream->getPublishedTime());
		$this->assertTrue($stream->isSensitive());
		$this->assertSame('tag:mastodon.social,2024-05-01:objectId=1:objectType=Conversation', $stream->getConversation());
		$this->assertSame('<p>Look at <b>this</b></p>', $stream->getContent());
		$this->assertTrue($stream->isPublic());
		$this->assertSame([], $stream->getAttachments());
		$this->assertSame(5, $stream->getDetailInt('likes'));
		$this->assertSame(5, $stream->getDetailInt('remote_likes'));
		$this->assertSame(2, $stream->getDetailInt('boosts'));
		$this->assertSame(2, $stream->getDetailInt('remote_boosts'));
		$this->assertSame(1, $stream->getDetailInt('replies'));
	}

	public function testARemoteSummaryBecomesTheContentWarning(): void {
		$stream = new Stream();
		$stream->import([
			'id' => 'https://mastodon.social/users/alice/statuses/112000000000000001',
			'type' => 'Note',
			'summary' => 'CW: cats',
			'content' => '<p>cat</p>',
		]);

		$this->assertSame('CW: cats', $stream->getSpoilerText());
		$this->assertSame('CW: cats', $stream->exportAsLocal()['spoiler_text']);
	}

	public function testLocalStatusKeepsItsNidAsAStringForJavaScriptClients(): void {
		$nid = '1790166469240117859';
		$status = (new Stream())->setNid($nid)->exportAsLocal();

		$this->assertSame($nid, $status['id']);
		$this->assertSame($nid, $status['nid']);
	}

	public function testTheContentWarningIsStoredAsTheSummary(): void {
		$stream = new Stream();

		$stream->setSpoilerText('CW: dogs');

		$this->assertSame('CW: dogs', $stream->getSummary(), 'summary is the database column');
	}

	public function testCreatedAtIsUtcRegardlessOfTheServerTimezone(): void {
		$previous = date_default_timezone_get();
		date_default_timezone_set('America/New_York');

		try {
			$stream = new Stream();
			$stream->setPublishedTime(1714564800);

			$this->assertSame('2024-05-01T12:00:00.000Z', $stream->exportAsLocal()['created_at']);
		} finally {
			date_default_timezone_set($previous);
		}
	}

	public function testImportLeavesCountsAloneWhenTheCollectionsCarryNoTotals(): void {
		$stream = new Stream();

		$stream->import([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'type' => 'Note',
			'likes' => ['id' => 'https://mastodon.social/users/alice/statuses/1/likes', 'type' => 'Collection'],
		]);

		$this->assertSame([], $stream->getDetailsAll());
	}

	public function testImportBuildsMediaAttachmentsThroughTheDocumentAndImageInterfaces(): void {
		$this->installActivityPub(mocked: [DocumentInterface::class, ImageInterface::class]);
		$this->apInterface(DocumentInterface::class)->expects($this->once())->method('save')
			->with($this->isInstanceOf(Document::class));
		$this->apInterface(ImageInterface::class)->expects($this->once())->method('save')
			->with($this->isInstanceOf(Image::class));

		$stream = new Stream();
		$stream->import([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'type' => 'Note',
			'attachment' => [
				['type' => 'Document', 'mediaType' => 'image/jpeg', 'url' => 'https://files.mastodon.social/media/cat.jpg', 'name' => 'A cat'],
				['type' => 'Image', 'mediaType' => 'image/png', 'url' => 'https://files.mastodon.social/media/dog.png'],
				['type' => 'Link', 'href' => 'https://example.org/'],
				['type' => 'Document', 'mediaType' => 'video/mp4'],
			],
		]);

		$attachments = $stream->getAttachments();
		$this->assertCount(2, $attachments);
		$this->assertContainsOnlyInstancesOf(MediaAttachment::class, $attachments);
		$this->assertSame('image', $attachments[0]->getType());
		$this->assertSame('https://files.mastodon.social/media/cat.jpg', $attachments[0]->getRemoteUrl());
		$this->assertSame('https://files.mastodon.social/media/dog.png', $attachments[1]->getRemoteUrl());
		// Nothing has been fetched yet, so this instance holds no copy and
		// names no url of its own. It used to name `/media/.jpeg` — the route
		// with an empty uuid in it — which 404s for every client that tries.
		foreach ($attachments as $attachment) {
			$this->assertSame('', $attachment->getUrl());
			$this->assertSame('', $attachment->getPreviewUrl());
		}
	}

	/**
	 * Storing an attachment means fetching a file from another server, which
	 * fails in every way a request can. That used to come out of `import()`
	 * and take the whole `Create` with it: a Mastodon post with four pictures
	 * and one CDN object briefly answering 503 was never stored at all, text,
	 * thread and notification included.
	 */
	public function testAnAttachmentThatCannotBeStoredDoesNotTakeThePostWithIt(): void {
		$this->installActivityPub(mocked: [ImageInterface::class]);
		$this->apInterface(DocumentInterface::class)->method('save')
			->willThrowException(new \RuntimeException('the origin is down'));
		$this->apInterface(ImageInterface::class)->expects($this->once())->method('save');

		$stream = new Stream();
		$stream->import([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'type' => 'Note',
			'content' => 'four pictures',
			'attachment' => [
				['type' => 'Document', 'mediaType' => 'image/jpeg', 'url' => 'https://files.mastodon.social/media/cat.jpg'],
				['type' => 'Image', 'mediaType' => 'image/png', 'url' => 'https://files.mastodon.social/media/dog.png'],
			],
		]);

		$this->assertSame('four pictures', $stream->getContent());
		$attachments = $stream->getAttachments();
		$this->assertCount(1, $attachments, 'the one that could be stored');
		$this->assertSame('https://files.mastodon.social/media/dog.png', $attachments[0]->getRemoteUrl());
	}

	/**
	 * #2281: a reader reports that a post's pictures are missing and nobody
	 * can say why. Every way an attachment leaves the import is a `continue`,
	 * the post keeps its own frozen copy of whichever survived, and none of it
	 * was written down — so there was nothing to look at afterwards, on the
	 * instance that dropped them or anywhere else.
	 */
	public function testDroppedAttachmentsAreWrittenDownWithTheirReason(): void {
		$this->installActivityPub(mocked: [ImageInterface::class]);
		$logger = $this->createStub(LoggerInterface::class);
		$logged = [];
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);
		\OC::$server->register(LoggerInterface::class, $logger);

		$this->apInterface(DocumentInterface::class)->method('save')
			->willThrowException(new \RuntimeException('the origin is down'));
		$this->apInterface(ImageInterface::class)->expects($this->once())->method('save');

		$stream = new Stream();
		$stream->import([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'type' => 'Note',
			'attachment' => [
				['type' => 'Document', 'mediaType' => 'image/jpeg', 'url' => 'https://files.mastodon.social/media/cat.jpg'],
				['type' => 'Image', 'mediaType' => 'image/png', 'url' => 'https://files.mastodon.social/media/dog.png'],
				['type' => 'Link', 'href' => 'https://example.org/'],
			],
		]);

		$this->assertCount(1, $logged, 'one line for the post, not one per attachment');
		[$message, $context] = $logged[0];
		$this->assertSame('dropped attachments while importing a post', $message);
		$this->assertSame(3, $context['arrived']);
		$this->assertSame(1, $context['kept']);

		$reasons = array_column($context['dropped'], 'reason');
		$this->assertContains('could not be stored', $reasons, 'the one whose save threw');
		// the Link: its type has no interface, so it never becomes a Document
		// to have a type checked. The line says which type it was, because the
		// exception for an unknown one carries no message at all.
		$this->assertContains('unreadable', $reasons);
		$link = $context['dropped'][array_search('unreadable', $reasons, true)];
		$this->assertSame('Link', $link['type']);
		$this->assertSame('https://example.org/', $link['url']);
		// and the reason names the file, or the line says nothing useful
		$stored = $context['dropped'][array_search('could not be stored', $reasons, true)];
		$this->assertSame('https://files.mastodon.social/media/cat.jpg', $stored['url']);
		$this->assertStringContainsString('the origin is down', $stored['error']);
	}

	/** Nothing is logged when every attachment survives. */
	public function testNothingIsLoggedWhenEveryAttachmentSurvives(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('warning');
		\OC::$server->register(LoggerInterface::class, $logger);

		$stream = new Stream();
		$stream->import([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'type' => 'Note',
			'attachment' => [
				['type' => 'Document', 'mediaType' => 'image/jpeg', 'url' => 'https://files.mastodon.social/media/cat.jpg'],
			],
		]);

		$this->assertCount(1, $stream->getAttachments());
	}

	/**
	 * A post read back from the database has its attachments hydrated in the
	 * local format. Served to a peer, they went out as Mastodon's client
	 * entity under the ActivityPub key -- no `mediaType`, no Document -- so
	 * every re-served post lost its pictures on Pixelfed.
	 */
	public function testAHydratedPostServesItsAttachmentsAsDocuments(): void {
		$stream = new Stream();
		$stream->setId('https://cloud.example/apps/social/@alice/1');
		$attachment = (new MediaAttachment())->import([
			'id' => '7', 'type' => 'image', 'media_type' => 'image/png',
			'url' => 'https://cloud.example/apps/social/media/abc', 'preview_url' => null, 'remote_url' => null,
			'meta' => ['original' => ['width' => 640, 'height' => 480]], 'description' => 'a cat', 'blurhash' => 'LEHV6n',
		]);
		$stream->setAttachments([$attachment]);
		$this->assertSame(ACore::FORMAT_LOCAL, $attachment->getExportFormat(), 'hydrated in the local format, as importFromDatabase() leaves it');

		$stream->setExportFormat(ACore::FORMAT_ACTIVITYPUB);
		$served = $stream->jsonSerialize()['attachment'];

		$this->assertSame('Document', $served[0]['type']);
		$this->assertSame('image/png', $served[0]['mediaType']);
		$this->assertSame('a cat', $served[0]['name']);
		$this->assertArrayNotHasKey('preview_url', $served[0], 'not the client entity');

		$stream->setExportFormat(ACore::FORMAT_LOCAL);
		$this->assertArrayNotHasKey('attachment', $stream->jsonSerialize());
	}

	public function testAnAbsurdAttachmentListIsCappedRatherThanImported(): void {
		$this->installActivityPub(mocked: [DocumentInterface::class]);
		// a signed Create is authenticated, not trusted: each entry is a row
		// written and a file queued inside the inbox request
		$this->apInterface(DocumentInterface::class)
			->expects($this->exactly(Stream::MAX_ATTACHMENTS))->method('save');

		$attachments = [];
		for ($i = 0; $i < 5000; $i++) {
			$attachments[] = [
				'type' => 'Document',
				'mediaType' => 'image/jpeg',
				'url' => 'https://files.mastodon.social/media/' . $i . '.jpg',
			];
		}

		$stream = new Stream();
		$stream->import([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'type' => 'Note',
			'attachment' => $attachments,
		]);

		$this->assertCount(Stream::MAX_ATTACHMENTS, $stream->getAttachments());
	}

	public function testAnOrdinaryPostKeepsEveryAttachment(): void {
		$this->installActivityPub(mocked: [DocumentInterface::class]);
		$this->apInterface(DocumentInterface::class)->expects($this->exactly(4))->method('save');

		$attachments = [];
		for ($i = 0; $i < 4; $i++) {
			$attachments[] = [
				'type' => 'Document',
				'mediaType' => 'image/jpeg',
				'url' => 'https://files.mastodon.social/media/' . $i . '.jpg',
			];
		}

		$stream = new Stream();
		$stream->import([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'type' => 'Note',
			'attachment' => $attachments,
		]);

		$this->assertCount(4, $stream->getAttachments());
	}

	public function testConvertPublishedIgnoresUnparsableDates(): void {
		$stream = new Stream();
		$stream->setPublished('not a date');

		$stream->convertPublished();

		$this->assertSame(0, $stream->getPublishedTime());
	}

	public function testExportAsActivityPubAddsTheStreamFields(): void {
		$stream = new Note();
		$stream->setId('https://cloud.example.org/apps/social/@alice/1')
			->setContent('<p>hi</p>')
			->setInReplyTo('https://mastodon.social/users/bob/statuses/2')
			->setSensitive(true)
			->setConversation('https://cloud.example.org/conv/1')
			// a full actor URI, which is what every caller passes; it is
			// emitted verbatim rather than being prefixed with urlSocial
			->setAttributedTo('https://cloud.example.org/apps/social/@alice')
			->setUrlSocial('https://cloud.example.org/apps/social/');

		$export = $stream->exportAsActivityPub();

		$this->assertSame('<p>hi</p>', $export['content']);
		$this->assertSame('https://cloud.example.org/apps/social/@alice', $export['attributedTo']);
		$this->assertSame('https://mastodon.social/users/bob/statuses/2', $export['inReplyTo']);
		$this->assertTrue($export['sensitive']);
		$this->assertSame('https://cloud.example.org/conv/1', $export['conversation']);
		$this->assertArrayNotHasKey('details', $export);
		$this->assertArrayNotHasKey('publishedTime', $export);
	}

	public function testExportAsActivityPubAddsInternalsOnlyWithCompleteDetails(): void {
		$stream = new Note();
		$stream->setPublishedTime(1714564800)
			->setCompleteDetails(true);
		$stream->setDetailInt('likes', 3);

		$export = $stream->exportAsActivityPub();

		$this->assertSame(['likes' => 3], $export['details']);
		$this->assertSame(1714564800, $export['publishedTime']);
		$this->assertArrayNotHasKey('action', $export, 'an unset action is dropped');
		$this->assertArrayNotHasKey('cache', $export, 'an unset cache is dropped');
		$this->assertSame('', $export['attributedTo'] ?? '', 'empty attributedTo stays out');
	}

	public function testExportAsLocalProducesAMastodonStatus(): void {
		$actor = new Person();
		$actor->setNid(3)
			->setPreferredUsername('alice')
			->setName('Alice')
			->setLocal(true);

		$action = new StreamAction();
		$action->updateValueBool(StreamAction::LIKED, true);
		$action->updateValueBool(StreamAction::BOOSTED, false);

		$stream = new Note();
		$stream->setId('https://cloud.example.org/apps/social/@alice/1')
			->setNid(7)
			->setContent('<p>hi</p>')
			->setSensitive(true)
			->setSpoilerText('cw')
			->setVisibility(Stream::TYPE_PUBLIC)
			->setLanguage('de')
			->setPublishedTime(1714564800)
			->setMentions([['id' => '3', 'username' => 'bob', 'url' => 'https://b.example/@bob', 'acct' => 'bob@b.example']])
			->setLocal(true)
			->setAction($action)
			->setActor($actor);
		$stream->setDetailInt('replies', 1);
		$stream->setDetailInt('boosts', 2);
		$stream->setDetailInt('likes', 5);

		$status = $stream->exportAsLocal();

		$this->assertSame('7', $status['id']);
		$this->assertTrue($status['local']);
		$this->assertSame('<p>hi</p>', $status['content']);
		$this->assertTrue($status['sensitive']);
		$this->assertSame('cw', $status['spoiler_text']);
		$this->assertSame('public', $status['visibility']);
		$this->assertSame('de', $status['language']);
		$this->assertNull($status['in_reply_to_id']);
		$this->assertSame('bob@b.example', $status['mentions'][0]['acct']);
		$this->assertSame(1, $status['replies_count']);
		$this->assertSame(2, $status['reblogs_count']);
		$this->assertSame(5, $status['favourites_count']);
		$this->assertTrue($status['favourited']);
		$this->assertFalse($status['reblogged']);
		$this->assertSame('https://cloud.example.org/apps/social/@alice/1', $status['uri']);
		$this->assertSame('https://cloud.example.org/apps/social/@alice/1', $status['url']);
		$this->assertNull($status['reblog']);
		$this->assertSame([], $status['media_attachments']);
		$this->assertSame('2024-05-01T12:00:00.000Z', $status['created_at']);
		$this->assertSame('3', $status['account']['id']);
		$this->assertSame('alice', $status['account']['username']);
		$this->assertSame('alice', $status['account']['acct']);
		$this->assertSame('Alice', $status['account']['display_name']);
	}

	/**
	 * `delivery` is the one key of the entity that is absent rather than
	 * null: a transient state of the author's own post, not a property every
	 * status has. Set by whoever knows; a model exported outside a request
	 * — as here — has no reader to be the author of anything.
	 */
	public function testAHeldPostSaysSoToItsAuthorAndNobodyElseSeesTheKey(): void {
		$held = (new Note())->setDeliveryHeld(true)->exportAsLocal();
		$this->assertSame('held', $held['delivery']);

		$this->assertArrayNotHasKey('delivery', (new Note())->setDeliveryHeld(false)->exportAsLocal());
		$this->assertArrayNotHasKey('delivery', (new Note())->exportAsLocal());
	}

	// `ai_generated`: what the post says about itself, never a judgement

	public function testAnOrdinaryPostIsNotMarkedAsMadeWithAi(): void {
		$status = (new Note())->setHashtags(['cats'])->exportAsLocal();

		$this->assertArrayHasKey('ai_generated', $status);
		$this->assertFalse($status['ai_generated']);
		$this->assertFalse((new Note())->exportAsLocal()['ai_generated']);
	}

	public function testAPostTaggedAsMadeWithAiSaysSo(): void {
		foreach (['aigenerated', 'AIgenerated', '#MidJourney'] as $tag) {
			$this->assertTrue((new Note())->setHashtags(['cats', $tag])->exportAsLocal()['ai_generated'], $tag);
		}
	}

	public function testAPostWhosePictureStatesProvenanceSaysSo(): void {
		$note = new Note();
		$note->setAttachments([new MediaAttachment(), (new MediaAttachment())->setAiGenerated(true)]);

		$status = $note->exportAsLocal();

		$this->assertTrue($status['ai_generated']);
		$this->assertFalse($status['media_attachments'][0]->isAiGenerated());
		$this->assertTrue($status['media_attachments'][1]->isAiGenerated());
	}

	public function testABoostCarriesTheLabelOfThePostItBoostsOnBothLevels(): void {
		$boost = new Announce();
		$boost->setObject((new Note())->setHashtags(['stablediffusion']));

		$status = $boost->exportAsLocal();

		$this->assertTrue($status['reblog']['ai_generated'], 'the boosted post is labelled');
		$this->assertTrue($status['ai_generated'], 'and so is the boost, which has no tags of its own');

		$plain = new Announce();
		$plain->setObject((new Note())->setHashtags(['cats']));
		$this->assertFalse($plain->exportAsLocal()['ai_generated']);
	}

	/**
	 * Inside a request the container hands out the service, and with it the
	 * tags the administrator added; a model exported with no container to ask
	 * falls back to the built-in list, which is what every other test here
	 * exercises.
	 */
	public function testTheInstancesOwnTagsCountWhenTheServiceIsThere(): void {
		$configService = $this->createStub(ConfigService::class);
		$configService->method('getAppValue')->willReturn('synthetic');
		\OC::$server->register(AiContentService::class, new AiContentService($configService));

		$this->assertTrue((new Note())->setHashtags(['Synthetic'])->exportAsLocal()['ai_generated']);
		$this->assertTrue((new Note())->setHashtags(['aiart'])->exportAsLocal()['ai_generated']);
		$this->assertFalse((new Note())->setHashtags(['cats'])->exportAsLocal()['ai_generated']);
	}

	public function testExportAsLocalWithoutActorHasNoAccount(): void {
		$status = (new Note())->exportAsLocal();

		$this->assertArrayNotHasKey('account', $status);
		$this->assertFalse($status['favourited']);
		$this->assertFalse($status['reblogged']);
	}

	/**
	 * The reverse of the export map, used by the `types`/`exclude_types`
	 * notification filter. Both directions come from one table, so this also
	 * guards against the two drifting apart.
	 */
	public function testSubTypesOfNotificationTypesSelectsTheMatchingSubTypes(): void {
		$this->assertSame(['Mention'], Stream::subTypesOfNotificationTypes(['mention']));
		$this->assertSame(
			['Like', 'Announce'],
			Stream::subTypesOfNotificationTypes(['reblog', 'favourite']),
			'the order is the map\'s, which is all an IN clause needs'
		);
		$this->assertSame(
			['Mention'],
			Stream::subTypesOfNotificationTypes(['mention', 'mention']),
			'a repeated type is asked for once'
		);
	}

	public function testAnUnknownNotificationTypeSelectsNothing(): void {
		// `status` used to be one of these; it is a real type now, so the
		// unknown one here is a type Mastodon has and this app does not
		$this->assertSame([], Stream::subTypesOfNotificationTypes(['admin.sign_up']));
		$this->assertSame(
			['Mention'],
			Stream::subTypesOfNotificationTypes(['mention', 'admin.sign_up']),
			'an unknown type alongside a known one does not widen the filter'
		);
		$this->assertSame([], Stream::subTypesOfNotificationTypes([]));
	}

	public static function notificationTypeProvider(): array {
		return [
			'like' => ['Like', 'favourite'],
			'announce' => ['Announce', 'reblog'],
			'mention' => ['Mention', 'mention'],
			'follow' => ['Follow', 'follow'],
			'follow request' => ['FollowRequest', 'follow_request'],
			'other' => ['Create', ''],
		];
	}

	#[DataProvider('notificationTypeProvider')]
	public function testExportAsNotificationMapsTheSubTypeToMastodonTypes(string $subType, string $expected): void {
		$actor = new Person();
		$actor->setPreferredUsername('alice');
		$status = new Note();
		$stream = new Stream();
		$stream->setNid(9)
			->setSubType($subType)
			->setPublishedTime(1714564800)
			->setObject($status)
			->setActor($actor);

		$notification = $stream->exportAsNotification();

		$this->assertSame('9', $notification['id']);
		$this->assertSame($expected, $notification['type']);
		$this->assertSame('2024-05-01T12:00:00.000Z', $notification['created_at']);
		$this->assertSame($status, $notification['status']);
		$this->assertSame('alice', $notification['account']['username']);
	}

	public function testImportReadsCustomEmojiFromTheTagList(): void {
		$stream = new Stream();
		$stream->import([
			'id' => 'https://remote.example/notes/1',
			'type' => 'Note',
			'content' => 'hello :blobcat: world :blobcat: :broken:',
			'tag' => [
				['type' => 'Mention', 'href' => 'https://x.example/@a', 'name' => '@a'],
				['type' => 'Emoji', 'name' => ':blobcat:', 'icon' => ['type' => 'Image', 'url' => 'https://remote.example/emoji/blobcat.png']],
				['type' => 'Emoji', 'name' => ':broken:'],
				['type' => 'Emoji', 'name' => ':evil:', 'icon' => ['url' => 'javascript:alert(1)']],
				['type' => 'Emoji', 'name' => ':data:', 'icon' => ['url' => 'data:image/svg+xml,<svg/>']],
				// an instance still being set up is served over plain http,
				// and has to be able to render its own emoji
				['type' => 'Emoji', 'name' => ':plain:', 'icon' => ['url' => 'http://local.test/emoji/plain.png']],
			],
		]);

		$this->assertSame([[
			'shortcode' => 'blobcat',
			'url' => 'https://remote.example/emoji/blobcat.png',
			'static_url' => 'https://remote.example/emoji/blobcat.png',
			'visible_in_picker' => false,
		], [
			'shortcode' => 'plain',
			'url' => 'http://local.test/emoji/plain.png',
			'static_url' => 'http://local.test/emoji/plain.png',
			'visible_in_picker' => false,
		]], $stream->getEmojis(), 'an icon-less emoji, or one whose icon is not a web URL, is dropped');
	}

	public function testCustomEmojiSurviveTheDatabaseRoundTripViaTheStoredSource(): void {
		$wire = [
			'id' => 'https://remote.example/notes/1',
			'type' => 'Note',
			'tag' => [
				['type' => 'Emoji', 'name' => ':party:', 'icon' => ['url' => 'https://remote.example/emoji/party.gif']],
			],
		];
		$stream = new Stream();
		$stream->importFromDatabase([
			'id' => 'https://remote.example/notes/1',
			'source' => json_encode($wire),
		]);

		$this->assertSame('party', $stream->getEmojis()[0]['shortcode']);
		$this->assertSame($stream->getEmojis(), $stream->exportAsLocal()['emojis']);
	}

	/**
	 * A post read back from the database used to re-export naming nobody: every
	 * Update and every outbox entry told the peers that the people the post
	 * mentions are not mentioned by it, and a hashtag stopped reaching any tag
	 * timeline after the first reload. `tag` has had a column of its own since
	 * `Version1000Date20260912000007`; this is the fallback for a row written
	 * before it, which is still the only thing an un-backfilled row has.
	 */
	public function testMentionsAndHashtagsSurviveTheDatabaseRoundTripViaTheStoredSource(): void {
		$tags = [
			['type' => 'Mention', 'href' => 'https://remote.example/users/bob', 'name' => '@bob@remote.example'],
			['type' => 'Hashtag', 'href' => 'https://cloud.example/tags/nextcloud', 'name' => '#Nextcloud'],
		];
		$stream = new Note();

		$stream->importFromDatabase([
			'id' => 'https://cloud.example/apps/social/@alice/1',
			'type' => 'Note',
			'source' => json_encode(['id' => 'https://cloud.example/apps/social/@alice/1', 'tag' => $tags]),
		]);

		$this->assertSame($tags, $stream->getTags());
		$this->assertSame($tags, $stream->exportAsActivityPub()['tag']);
	}

	/**
	 * A reply reaches the instances that hold the post it answers and nowhere
	 * else, so a reader on a third instance sees a post with no replies unless
	 * there is a collection to walk. Mastodon publishes one on every note.
	 */
	public function testALocalNoteNamesTheCollectionOfItsReplies(): void {
		$note = new Note();
		$note->setId('https://cloud.example/apps/social/@alice/1');
		$note->setLocal(true);

		$this->assertSame(
			[
				'id' => 'https://cloud.example/apps/social/@alice/1/replies',
				'type' => 'OrderedCollection',
				'first' => 'https://cloud.example/apps/social/@alice/1/replies?page=1',
			],
			$note->exportAsActivityPub()['replies']
		);
	}

	/**
	 * A remote post's replies live on the server that holds it, under an id of
	 * its choosing. Naming a collection here, under an id this instance does
	 * not own and does not serve, sends every reader to a 404.
	 */
	public function testARemoteNoteIsNotGivenARepliesCollectionOfOurs(): void {
		$note = new Note();
		$note->setId('https://remote.example/notes/1');

		$this->assertArrayNotHasKey('replies', $note->exportAsActivityPub());
	}

	public function testJsonSerializeExposesTheAttachments(): void {
		$media = (new MediaAttachment())->setId('4');
		$stream = new Note();
		$stream->setAttachments([$media]);

		// as Documents, whatever format the attachment objects are in
		$this->assertSame([$media->asDocument()], $stream->jsonSerialize()['attachment']);
	}

	/**
	 * `attachment` is the ActivityPub name; the client format already carries
	 * the same list as `media_attachments`, and a second copy under a key
	 * Mastodon does not define was only ever confusing.
	 */
	public function testTheClientFormatHasNoActivityPubAttachmentKey(): void {
		$stream = new Note();
		$stream->setAttachments([(new MediaAttachment())->setId('4')]);
		$stream->setExportFormat(ACore::FORMAT_LOCAL);

		$serialised = $stream->jsonSerialize();

		$this->assertArrayNotHasKey('attachment', $serialised);
		$this->assertArrayHasKey('media_attachments', $serialised);
	}

	/**
	 * A reply with no `in_reply_to_id` is not a reply: Elk and Phanpy render it
	 * as a fresh top-level post, and the status handed back from `POST
	 * /statuses` lost the link the client needed to slot it under the post
	 * being answered. The row only carries the parent's ActivityPub id, so the
	 * two numbers come from a lookup.
	 */
	public function testAReplyCarriesTheParentsNumericIdsAndItsAuthors(): void {
		$parentActor = new Person();
		$parentActor->setNid(3);
		$parent = new Note();
		$parent->setNid(11)->setActor($parentActor);

		$streamRequest = $this->createMock(StreamRequest::class);
		$streamRequest->expects($this->once())
			->method('getStreamById')
			->with('https://cloud.example.org/apps/social/@bob/1')
			->willReturn($parent);
		\OC::$server->register(StreamRequest::class, $streamRequest);

		$reply = new Note();
		$reply->setNid(12)->setInReplyTo('https://cloud.example.org/apps/social/@bob/1');

		$status = $reply->exportAsLocal();

		$this->assertSame('11', $status['in_reply_to_id']);
		$this->assertSame('3', $status['in_reply_to_account_id']);
	}

	public function testTheResolvedParentIsLookedUpOncePerRequest(): void {
		$parent = new Note();
		$parent->setNid(11);

		$streamRequest = $this->createMock(StreamRequest::class);
		// a thread's replies all name the same parent, and a page of them must
		// not be a query each
		$streamRequest->expects($this->once())->method('getStreamById')->willReturn($parent);
		\OC::$server->register(StreamRequest::class, $streamRequest);

		foreach ([13, 14, 15] as $nid) {
			$reply = new Note();
			$reply->setNid($nid)->setInReplyTo('https://cloud.example.org/apps/social/@bob/1');

			$this->assertSame('11', $reply->exportAsLocal()['in_reply_to_id']);
		}
	}

	public function testAStatusThatIsNotAReplyHasNoParent(): void {
		$status = (new Note())->setNid(4)->exportAsLocal();

		$this->assertNull($status['in_reply_to_id']);
		$this->assertNull($status['in_reply_to_account_id']);
	}

	/**
	 * Nothing stores an edit timestamp of its own, but `PostService::editPost()`
	 * stamps `published` with the moment of the edit and leaves
	 * `published_time` — which `created_at` is built from — at the original.
	 */
	public function testEditedAtIsNullForAPostThatWasNeverEdited(): void {
		$stream = new Note();
		$stream->setNid(4)->setPublishedTime(1714564800);
		$stream->setPublished('2024-05-01T12:00:00+00:00');

		$this->assertNull($stream->exportAsLocal()['edited_at']);
	}

	public function testEditedAtIsWhenThePublishedStampMovedPastTheCreation(): void {
		$stream = new Note();
		$stream->setNid(4)->setPublishedTime(1714564800);
		$stream->setPublished('2024-05-02T09:30:00+00:00');

		$this->assertSame('2024-05-02T09:30:00.000Z', $stream->exportAsLocal()['edited_at']);
	}

	public function testEditedAtIsNullWithoutAPublishedStampToCompare(): void {
		$this->assertNull((new Note())->setNid(4)->exportAsLocal()['edited_at']);
	}

	public function testImportFromLocalReadsAMastodonStatus(): void {
		$stream = new Stream();

		$stream->importFromLocal([
			'id' => '123',
			'url' => 'https://mastodon.social/@alice/123',
			'local' => false,
			'content' => '<p>hello</p>',
			'sensitive' => true,
			'spoiler_text' => 'cw',
			'visibility' => 'unlisted',
			'language' => 'de',
			'favourited' => true,
			'reblogged' => false,
			'created_at' => '2024-05-01T12:00:00.000Z',
			'media_attachments' => [
				[
					'id' => '55',
					'type' => 'image',
					'url' => 'https://files.mastodon.social/media/cat.jpg',
					'preview_url' => 'https://files.mastodon.social/media/small/cat.jpg',
					'remote_url' => null,
					'description' => 'A cat',
					'blurhash' => 'UBL_:rOp',
					'meta' => ['original' => ['width' => 1200, 'height' => 800, 'size' => '1200x800'], 'small' => ['width' => 600, 'height' => 400], 'focus' => ['x' => 0, 'y' => 0]],
				],
			],
			'mentions' => [['id' => '2', 'username' => 'bob', 'url' => 'https://b.example/@bob', 'acct' => 'bob@b.example']],
			'account' => [
				'id' => '31',
				'username' => 'alice',
				'acct' => 'alice@mastodon.social',
				'display_name' => 'Alice W.',
				'locked' => true,
				'bot' => false,
				'discoverable' => true,
				'note' => '<p>bio</p>',
				'url' => 'https://mastodon.social/@alice',
				'avatar' => 'https://files.mastodon.social/avatars/alice.png',
				'header' => 'https://files.mastodon.social/headers/alice.jpg',
				'followers_count' => 10,
				'following_count' => 5,
				'statuses_count' => 42,
				'created_at' => '2019-01-01T00:00:00.000Z',
				'source' => ['privacy' => 'unlisted', 'sensitive' => true, 'language' => 'fr'],
			],
		]);

		$this->assertSame(123, $stream->getNid());
		$this->assertSame('https://mastodon.social/@alice/123', $stream->getId());
		$this->assertSame('https://mastodon.social/@alice/123', $stream->getUrl());
		$this->assertFalse($stream->isLocal());
		$this->assertSame('<p>hello</p>', $stream->getContent());
		$this->assertTrue($stream->isSensitive());
		$this->assertSame('cw', $stream->getSpoilerText());
		$this->assertSame('unlisted', $stream->getVisibility());
		$this->assertSame('de', $stream->getLanguage());
		$this->assertSame(1714564800, $stream->getPublishedTime());
		$this->assertTrue($stream->getAction()->getValueBool(StreamAction::LIKED));
		$this->assertFalse($stream->getAction()->getValueBool(StreamAction::BOOSTED));

		$attachments = $stream->getAttachments();
		$this->assertCount(1, $attachments);
		$this->assertInstanceOf(MediaAttachment::class, $attachments[0]);
		$this->assertSame('55', $attachments[0]->getId());
		$this->assertSame('A cat', $attachments[0]->getDescription());
		$this->assertSame(1200, $attachments[0]->getMeta()->getOriginal()->getWidth());
		$this->assertSame('bob@b.example', $stream->getMentions()[0]['acct']);

		$actor = $stream->getActor();
		$this->assertSame(31, $actor->getNid());
		$this->assertSame('alice', $actor->getPreferredUsername());
		$this->assertSame('alice@mastodon.social', $actor->getAccount());
		$this->assertSame('Alice W.', $actor->getDisplayName());
		$this->assertTrue($actor->isLocked());
		$this->assertFalse($actor->isBot());
		$this->assertTrue($actor->isDiscoverable());
		$this->assertSame('<p>bio</p>', $actor->getDescription());
		$this->assertSame('https://files.mastodon.social/avatars/alice.png', $actor->getAvatar());
		$this->assertSame('https://files.mastodon.social/headers/alice.jpg', $actor->getHeader());
		$this->assertSame('unlisted', $actor->getPrivacy());
		$this->assertTrue($actor->isSensitive());
		$this->assertSame('fr', $actor->getLanguage());
		$this->assertSame(1546300800, $actor->getCreation());
		$this->assertSame(10, $actor->getDetails('count')['followers']);
		$this->assertSame(42, $actor->getDetails('count')['post']);
		$this->assertSame(ACore::FORMAT_LOCAL, $actor->getExportFormat());
	}

	/**
	 * The flag had no column, so a status read back from the database reported
	 * sensitive only when it also carried a content warning: a client marking
	 * its media sensitive was answered 200 and the media then rendered
	 * unblurred everywhere the post was read again.
	 */
	public function testSensitiveSurvivesADatabaseRoundTrip(): void {
		$stream = new Stream();
		$stream->importFromDatabase([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'type' => 'Note',
			'published_time' => '2024-05-01 12:00:00',
			'content' => 'look away',
			'visibility' => 'public',
			'sensitive' => '1',
			'details' => '{}',
		]);

		$this->assertTrue($stream->isSensitive());
		$this->assertSame('', $stream->getSpoilerText(), 'no content warning is standing in for the flag');
	}

	public function testAStreamStoredWithoutTheFlagIsNotSensitive(): void {
		$stream = new Stream();
		$stream->importFromDatabase([
			'id' => 'https://mastodon.social/users/alice/statuses/2',
			'type' => 'Note',
			'published_time' => '2024-05-01 12:00:00',
			'content' => 'hello',
			'visibility' => 'public',
			'sensitive' => '0',
			'details' => '{}',
		]);

		$this->assertFalse($stream->isSensitive());
	}

	public function testImportFromDatabaseFillsRemoteCountsFromTheSource(): void {
		$stream = new Stream();

		$stream->importFromDatabase([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'type' => 'Note',
			'published_time' => '2024-05-01 12:00:00',
			'content' => '<p onclick="x()">hello</p>',
			'visibility' => 'unlisted',
			'details' => '{}',
			'filter_duplicate' => '1',
			'source' => '{"likes":{"totalItems":4},"shares":{"totalItems":2},"replies":{"totalItems":1}}',
			'cache' => '{"_items":["https://a.example/1"],"https://a.example/1":{"url":"https://a.example/1","content":"{}","status":200}}',
		]);

		$this->assertSame(1714564800, $stream->getPublishedTime());
		$this->assertSame('<p>hello</p>', $stream->getContent());
		$this->assertSame('unlisted', $stream->getVisibility());
		$this->assertTrue($stream->isFilterDuplicate());
		$this->assertSame(4, $stream->getDetailInt('remote_likes'));
		$this->assertSame(4, $stream->getDetailInt('likes'));
		$this->assertSame(2, $stream->getDetailInt('remote_boosts'));
		$this->assertSame(2, $stream->getDetailInt('boosts'));
		$this->assertSame(1, $stream->getDetailInt('replies'));
		$this->assertTrue($stream->hasCache());
		$this->assertTrue($stream->getCache()->hasItem('https://a.example/1'));
		$this->assertSame(200, $stream->getCache()->getItem('https://a.example/1')->getStatus());
	}

	public function testImportFromDatabaseKeepsLocalCountsWhenTheyExist(): void {
		$stream = new Stream();

		$stream->importFromDatabase([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'type' => 'Note',
			'details' => '{"likes":9,"remote_boosts":1,"boosts":1,"mentions":[{"acct":"bob@b.example"}]}',
			'source' => '{"likes":{"totalItems":4},"shares":{"totalItems":7}}',
		]);

		$this->assertSame(9, $stream->getDetailInt('likes'), 'local likes are not overwritten by the remote total');
		$this->assertSame(4, $stream->getDetailInt('remote_likes'));
		$this->assertSame(1, $stream->getDetailInt('remote_boosts'), 'already known remote boosts stay');
		$this->assertSame([['acct' => 'bob@b.example']], $stream->getMentions());
	}

	public function testAddCacheItemCreatesTheCacheOnDemandAndIgnoresDuplicates(): void {
		$stream = new Stream();
		$this->assertFalse($stream->hasCache());

		$stream->addCacheItem('https://a.example/1');
		$stream->addCacheItem('https://a.example/1');
		$stream->addCacheItem('https://a.example/2');

		$this->assertTrue($stream->hasCache());
		$this->assertCount(2, $stream->getCache()->getItems());
	}

	public function testAContentWarningMakesAStatusSensitive(): void {
		$stream = new Stream();
		$this->assertFalse($stream->isSensitive());

		$stream->setSpoilerText('season finale');

		// clients that know nothing of `spoiler_text` still hide the body
		$this->assertTrue($stream->isSensitive());
		$this->assertTrue($stream->exportAsActivityPub()['sensitive']);
	}

	public function testAnEmptyContentWarningLeavesSensitiveAlone(): void {
		$stream = new Stream();
		$stream->setSpoilerText('');
		$this->assertFalse($stream->isSensitive());

		$stream->setSensitive(true);
		$this->assertTrue($stream->isSensitive());
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function clientVisibilityProvider(): array {
		return [
			'public' => ['public', Stream::TYPE_PUBLIC],
			'unlisted' => ['unlisted', Stream::TYPE_UNLISTED],
			'private is our followers' => ['private', Stream::TYPE_FOLLOWERS],
			'followers is accepted as-is' => ['followers', Stream::TYPE_FOLLOWERS],
			'direct' => ['direct', Stream::TYPE_DIRECT],
			'unknown is never public' => ['nonsense', Stream::TYPE_DIRECT],
		];
	}

	#[DataProvider('clientVisibilityProvider')]
	public function testVisibilityFromClient(string $sent, string $expected): void {
		$this->assertSame($expected, Stream::visibilityFromClient($sent));
	}

	public function testVisibilityForClientSpeaksMastodon(): void {
		// `followers` is ours and means nothing to a client; every Mastodon
		// client expects `private` and some fail to decode the status without it
		$this->assertSame('private', Stream::visibilityForClient(Stream::TYPE_FOLLOWERS));
		$this->assertSame('public', Stream::visibilityForClient(Stream::TYPE_PUBLIC));
		$this->assertSame('unlisted', Stream::visibilityForClient(Stream::TYPE_UNLISTED));
		$this->assertSame('direct', Stream::visibilityForClient(Stream::TYPE_DIRECT));
	}

	/**
	 * A row written without a visibility -- an import, a seed, anything that
	 * reached the table by a path that did not set one -- used to hand the
	 * client `''`, which is not one of Mastodon's four and which this app's
	 * own timeline read as "not public": it stopped offering Boost on posts
	 * that had been boosted nine times. There were two thousand such rows on
	 * the instance this was found on.
	 */
	public function testAVisibilityThatWasNeverSetIsPublicToAClient(): void {
		$this->assertSame('public', Stream::visibilityForClient(''));
	}

	public function testIsKnownClientVisibility(): void {
		$this->assertTrue(Stream::isKnownClientVisibility('private'));
		$this->assertTrue(Stream::isKnownClientVisibility('PUBLIC'));
		$this->assertFalse(Stream::isKnownClientVisibility('nonsense'));
		$this->assertFalse(Stream::isKnownClientVisibility(''));
	}

	public function testAFollowersOnlyStatusIsExportedAsPrivate(): void {
		$stream = new Stream();
		$stream->setVisibility(Stream::TYPE_FOLLOWERS);

		$this->assertSame('private', $stream->exportAsLocal()['visibility']);
	}

	public function testAClientVisibilityRoundTripsThroughImportAndExport(): void {
		$stream = new Stream();
		$stream->importFromLocal(['visibility' => 'private']);

		$this->assertSame(Stream::TYPE_FOLLOWERS, $stream->getVisibility());
		$this->assertSame('private', $stream->exportAsLocal()['visibility']);
	}

	// language and `updated` — federation parity

	public function testImportReadsTheLanguageFromTheContentMap(): void {
		$stream = new Stream();

		$stream->import([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'type' => 'Note',
			'content' => '<p>Hallo</p>',
			'contentMap' => ['de' => '<p>Hallo</p>'],
		]);

		$this->assertSame('de', $stream->getLanguage());
	}

	public function testATopLevelLanguageWinsOverTheContentMap(): void {
		$stream = new Stream();

		$stream->import([
			'id' => 'https://pleroma.example/objects/1',
			'type' => 'Note',
			'language' => 'fr',
			'contentMap' => ['de' => '<p>x</p>'],
		]);

		$this->assertSame('fr', $stream->getLanguage());
	}

	/**
	 * A peer sends what it likes. `language` as an array used to be cast to a
	 * string, which is a PHP warning — and under `failOnWarning` a failed
	 * import of an otherwise perfectly good post.
	 */
	public function testALanguageThatIsNotAStringIsNoLanguage(): void {
		$stream = new Stream();

		$stream->import([
			'id' => 'https://weird.example/objects/1',
			'type' => 'Note',
			'language' => ['de', 'fr'],
			'content' => 'x',
		]);

		$this->assertSame('', $stream->getLanguage());
	}

	/** …and the contentMap still answers when the top-level value is rubbish. */
	public function testTheContentMapIsStillReadWhenTheLanguageIsNotAString(): void {
		$stream = new Stream();

		$stream->import([
			'id' => 'https://weird.example/objects/2',
			'type' => 'Note',
			'language' => ['de'],
			'contentMap' => ['pt-BR' => '<p>olá</p>'],
		]);

		$this->assertSame('pt-BR', $stream->getLanguage());
	}

	public function testImportLeavesTheLanguageEmptyWhenTheRemoteSaidNothing(): void {
		$stream = new Stream();

		$stream->import(['id' => 'https://mastodon.social/users/alice/statuses/1', 'type' => 'Note', 'content' => 'x']);

		$this->assertSame('', $stream->getLanguage(), 'no more pretending every remote post is English');
		$this->assertNull($stream->exportAsLocal()['language'], "Mastodon's language is nullable");
	}

	public function testAnUnusableContentMapKeyLeavesTheLanguageEmpty(): void {
		$stream = new Stream();

		$stream->import([
			'id' => 'https://evil.example/1',
			'type' => 'Note',
			'contentMap' => ['<script>alert(1)</script>' => 'x'],
		]);

		$this->assertSame('', $stream->getLanguage());
	}

	public function testImportReadsUpdated(): void {
		$stream = new Stream();

		$stream->import([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'type' => 'Note',
			'published' => '2024-05-01T12:00:00Z',
			'updated' => '2024-05-02T09:30:00Z',
		]);

		$this->assertSame('2024-05-02T09:30:00Z', $stream->getUpdated());
		$this->assertSame('2024-05-02T09:30:00.000Z', $stream->exportAsLocal()['edited_at']);
	}

	public function testExportAsActivityPubCarriesTheLanguageMapsAndUpdated(): void {
		$stream = new Note();
		$stream->setId('https://cloud.example.org/apps/social/@alice/1')
			->setContent('<p>Hallo</p>')
			->setSpoilerText('Vorsicht')
			->setLanguage('de')
			->setPublished('2024-05-01T12:00:00+00:00')
			->setUpdated('2024-05-02T09:30:00Z');

		$export = $stream->exportAsActivityPub();

		$this->assertSame(['de' => '<p>Hallo</p>'], $export['contentMap']);
		$this->assertSame(['de' => 'Vorsicht'], $export['summaryMap']);
		$this->assertSame('2024-05-01T12:00:00+00:00', $export['published'], 'published is when it was written');
		$this->assertSame('2024-05-02T09:30:00Z', $export['updated'], 'updated is what makes Mastodon apply an edit');
	}

	public function testExportAsActivityPubCarriesNoMapsWithoutALanguageAndNoUpdatedWithoutAnEdit(): void {
		$stream = new Note();
		$stream->setId('https://cloud.example.org/apps/social/@alice/1')
			->setContent('<p>hi</p>')
			->setSpoilerText('cw');

		$export = $stream->exportAsActivityPub();

		$this->assertArrayNotHasKey('contentMap', $export);
		$this->assertArrayNotHasKey('summaryMap', $export);
		$this->assertArrayNotHasKey('updated', $export);
	}

	public function testAnEmptySummaryHasNoSummaryMap(): void {
		$stream = new Note();
		$stream->setContent('<p>hi</p>')->setLanguage('en');

		$export = $stream->exportAsActivityPub();

		$this->assertSame(['en' => '<p>hi</p>'], $export['contentMap']);
		$this->assertArrayNotHasKey('summaryMap', $export);
	}

	public function testEditedAtComesFromUpdatedWhenThereIsOne(): void {
		$stream = new Note();
		$stream->setNid(4)->setPublishedTime(1714564800);
		$stream->setPublished('2024-05-01T12:00:00+00:00');
		$stream->setUpdated('2024-05-03T08:00:00Z');

		$this->assertSame('2024-05-03T08:00:00.000Z', $stream->exportAsLocal()['edited_at']);
	}

	public function testAnUnparsableUpdatedIsNotAnEdit(): void {
		$stream = new Note();
		$stream->setNid(4)->setPublishedTime(1714564800);
		$stream->setPublished('2024-05-01T12:00:00+00:00');
		$stream->setUpdated('not a date');

		$this->assertNull($stream->exportAsLocal()['edited_at']);
	}

	public function testImportFromDatabaseReadsUpdatedAndTheLanguageFromTheStoredSource(): void {
		$stream = new Note();

		$stream->importFromDatabase([
			'id' => 'https://mastodon.social/users/alice/statuses/1',
			'type' => 'Note',
			'content' => '<p>Hallo</p>',
			'published' => '2024-05-01T12:00:00Z',
			'published_time' => '2024-05-01 12:00:00',
			'source' => json_encode([
				'id' => 'https://mastodon.social/users/alice/statuses/1',
				'type' => 'Note',
				'content' => '<p>Hallo</p>',
				'contentMap' => ['de' => '<p>Hallo</p>'],
				'published' => '2024-05-01T12:00:00Z',
				'updated' => '2024-05-02T09:30:00Z',
			]),
		]);

		$this->assertSame('de', $stream->getLanguage());
		$this->assertSame('2024-05-02T09:30:00Z', $stream->getUpdated());
		$this->assertSame('2024-05-02T09:30:00.000Z', $stream->exportAsLocal()['edited_at']);
		$this->assertSame(['de' => '<p>Hallo</p>'], $stream->exportAsActivityPub()['contentMap']);
	}

	public function testImportFromDatabaseWithoutASourceHasNoLanguageAndNoEdit(): void {
		$stream = new Note();

		$stream->importFromDatabase([
			'id' => 'https://cloud.example.org/apps/social/@alice/1',
			'type' => 'Note',
			'content' => '<p>hi</p>',
			'published' => '2024-05-01T12:00:00+00:00',
			'published_time' => '2024-05-01 12:00:00',
		]);

		$this->assertSame('', $stream->getLanguage());
		$this->assertSame('', $stream->getUpdated());
		$this->assertNull($stream->exportAsLocal()['edited_at']);
	}

	public function testImportFromLocalAcceptsANullLanguage(): void {
		$stream = new Stream();

		$stream->importFromLocal([
			'id' => '123',
			'url' => 'https://mastodon.social/@alice/123',
			'local' => false,
			'content' => '<p>hello</p>',
			'visibility' => 'public',
			'language' => null,
			'created_at' => '2024-05-01T12:00:00.000Z',
			'media_attachments' => [],
			'mentions' => [],
			'account' => ['id' => '31', 'username' => 'alice', 'acct' => 'alice@mastodon.social', 'url' => 'https://mastodon.social/@alice'],
		]);

		$this->assertSame('', $stream->getLanguage());
	}

	/**
	 * @return array<string, array{string, string}>
	 */
	public static function languageProvider(): array {
		return [
			'a plain primary tag' => ['de', 'de'],
			'a three letter primary tag' => ['ast', 'ast'],
			'case is normalised' => ['DE', 'de'],
			'a region is kept, upper-cased' => ['pt-br', 'pt-BR'],
			'a script is kept, title-cased' => ['zh-hant-tw', 'zh-Hant-TW'],
			'a numeric region' => ['es-419', 'es-419'],
			'a POSIX style locale is accepted' => ['pt_BR', 'pt-BR'],
			'padding is ignored' => [' fr ', 'fr'],
			'a name is not a tag' => ['english', ''],
			'a dangling separator is refused' => ['en-', ''],
			'markup is refused' => ['<b>de</b>', ''],
			'nothing stays nothing' => ['', ''],
		];
	}

	#[DataProvider('languageProvider')]
	public function testNormalizeLanguage(string $sent, string $expected): void {
		$this->assertSame($expected, Stream::normalizeLanguage($sent));
	}

	public function testSetLanguageNormalisesWhatItIsGiven(): void {
		$this->assertSame('pt-BR', (new Note())->setLanguage('PT_br')->getLanguage());
		$this->assertSame('', (new Note())->setLanguage('nonsense words')->getLanguage());
	}

	/**
	 * Whatever a peer states is what every reader here is shown, and there is
	 * no way to verify it. A server stating four billion favourites is not
	 * describing a post, it is writing in somebody else's timeline — and the
	 * figure sits in the database until the post is deleted.
	 */
	public function testACountNobodyCouldBelieveIsRefusedRatherThanRepeated(): void {
		$stream = new Stream();
		$stream->import([
			'id' => 'https://remote.example/@bob/1',
			'likes' => ['totalItems' => 4000000000],
			'shares' => ['totalItems' => Stream::REMOTE_COUNT_CEILING + 1],
		]);

		$this->assertSame(0, $stream->getDetailInt('likes'));
		$this->assertSame(0, $stream->getDetailInt('boosts'));
	}

	public function testACountAtTheCeilingIsStillRepeated(): void {
		$stream = new Stream();
		$stream->import([
			'id' => 'https://remote.example/@bob/1',
			'likes' => ['totalItems' => Stream::REMOTE_COUNT_CEILING],
		]);

		$this->assertSame(Stream::REMOTE_COUNT_CEILING, $stream->getDetailInt('likes'));
	}

	/** Refused, not clamped: a clamp would state a figure this instance made up. */
	public function testAnImpossibleCountLeavesWhatWasAlreadyKnown(): void {
		$stream = new Stream();
		$stream->setDetailInt('likes', 7);

		$stream->import([
			'id' => 'https://remote.example/@bob/1',
			'likes' => ['totalItems' => -3],
		]);

		$this->assertSame(7, $stream->getDetailInt('likes'));
	}

	public function testACountThatIsNotANumberIsNotACount(): void {
		$stream = new Stream();
		$stream->import([
			'id' => 'https://remote.example/@bob/1',
			'likes' => ['totalItems' => 'lots'],
			'shares' => ['totalItems' => ['1', '2']],
			'replies' => ['totalItems' => 1.5],
		]);

		$this->assertSame(0, $stream->getDetailInt('likes'));
		$this->assertSame(0, $stream->getDetailInt('boosts'));
		$this->assertSame(0, $stream->getDetailInt('replies'));
	}

	/** A count sent as a numeric string is what several servers send. */
	public function testACountSentAsAStringIsRead(): void {
		$stream = new Stream();
		$stream->import([
			'id' => 'https://remote.example/@bob/1',
			'replies' => ['totalItems' => '12'],
		]);

		$this->assertSame(12, $stream->getDetailInt('replies'));
	}

	/**
	 * Loops keeps a remote video's address only when `url` is a string, and
	 * names the video by it for likes, comments and edits afterwards.
	 */
	public function testAPostWrittenHereFederatesItsPageAsUrl(): void {
		$note = new Note();
		$note->setId('https://cloud.example/apps/social/@alice/1791');
		$note->setAttributedTo('https://cloud.example/apps/social/users/alice');

		$this->assertSame('https://cloud.example/apps/social/@alice/1791', $note->exportAsActivityPub()['url'] ?? null);
	}

	public function testAPostKeepsTheUrlItCameWith(): void {
		$note = new Note();
		$note->setId('https://remote.example/users/bob/statuses/1');
		$note->setUrl('https://remote.example/@bob/1');

		$this->assertSame('https://remote.example/@bob/1', $note->exportAsActivityPub()['url'] ?? null);
	}

	public function testABoostCarriesNoPageOfItsOwn(): void {
		$boost = new Announce();
		$boost->setId('https://cloud.example/apps/social/@alice/1792/activity');

		$this->assertArrayNotHasKey('url', $boost->exportAsActivityPub());
	}

	public function testABlueskyPostSaysWhereItIsAndWhichLabelsItCarries(): void {
		$post = new Stream();
		$post->setId('https://bsky.app/profile/did:plc:z72i7hdynmk6r22z27h6tvur/post/3kpost');
		$post->setDetail(Details::PAGE, 'https://bsky.app/profile/bob.bsky.social/post/3kpost');
		$post->setDetailArray('atproto', ['uri' => 'at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.post/3kpost', 'label_sources' => [['src' => 'did:plc:l', 'val' => 'spoiler'], 'junk']]);
		$this->assertSame([
			'uri' => 'at://did:plc:z72i7hdynmk6r22z27h6tvur/app.bsky.feed.post/3kpost',
			'url' => 'https://bsky.app/profile/bob.bsky.social/post/3kpost',
			'labels' => [['src' => 'did:plc:l', 'val' => 'spoiler']],
		], $post->exportAsLocal()['bluesky']);

		$fediverse = new Stream();
		$fediverse->setId('https://mastodon.test/@bob/1');
		$fediverse->setDetailArray('atproto', ['uri' => 'at://forged']);
		$this->assertNull($fediverse->exportAsLocal()['bluesky'], 'only a post read from Bluesky');
	}
}
