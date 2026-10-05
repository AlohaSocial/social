<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\Atproto;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\AtprotoRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Atproto\AtprotoAccount;
use OCA\Social\Model\Atproto\AtprotoLink;
use OCA\Social\Model\Details;
use OCA\Social\Model\StreamCard;
use OCA\Social\Service\Atproto\AtprotoClient;
use OCA\Social\Service\Atproto\AtprotoEgress;
use OCA\Social\Service\Atproto\AtprotoIdentity;
use OCA\Social\Service\Atproto\AtprotoIngress;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\DocumentService;
use OCP\Files\SimpleFS\ISimpleFile;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class AtprotoEgressTest extends TestCase {
	private const AUTHOR = 'https://cloud.example/apps/social/@alice';
	private const POST = self::AUTHOR . '/posts/1';
	private const DID = 'did:plc:aliceexample';

	private AtprotoClient|MockObject $client;
	private AtprotoRequest|MockObject $atprotoRequest;
	private ActorsRequest|MockObject $actorsRequest;
	private ConfigService|MockObject $configService;
	private AtprotoIdentity|MockObject $identity;
	private DocumentService|MockObject $documentService;
	private AtprotoEgress $egress;

	protected function setUp(): void {
		$this->client = $this->createMock(AtprotoClient::class);
		$this->atprotoRequest = $this->createMock(AtprotoRequest::class);
		$this->actorsRequest = $this->createMock(ActorsRequest::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->configService->method('getAppValue')->willReturn('1');
		$this->identity = $this->createMock(AtprotoIdentity::class);
		$this->documentService = $this->createMock(DocumentService::class);
		$this->egress = new AtprotoEgress(
			$this->client,
			$this->atprotoRequest,
			$this->actorsRequest,
			$this->configService,
			new NullLogger(),
			$this->identity,
			$this->documentService,
		);
	}

	private function post(string $visibility = Stream::TYPE_PUBLIC): Stream {
		$post = new Stream();
		$post->setId(self::POST)
			->setAttributedTo(self::AUTHOR)
			->setVisibility($visibility)
			->setContent('<p>Hello<br>Bluesky</p>')
			->setPublishedTime(1_760_000_000);

		return $post;
	}

	private function account(): AtprotoAccount {
		return (new AtprotoAccount())
			->setUserId('alice')
			->setHandle('alice.bsky.social')
			->setDid(self::DID)
			->setPds('https://pds.example');
	}

	private function localAuthor(): Person {
		return (new Person())->setId(self::AUTHOR)->setUserId('alice');
	}

	public function testPublicPostIsWrittenAsANativeRecordAndMapped(): void {
		$post = $this->post();
		$post->setLanguage('de');
		$account = $this->account();
		$this->atprotoRequest->expects($this->once())->method('getLinkByLocalId')
			->with(self::POST)->willReturn(null);
		$this->actorsRequest->expects($this->once())->method('getFromId')
			->with(self::AUTHOR)->willReturn($this->localAuthor());
		$this->atprotoRequest->expects($this->once())->method('getAccount')
			->with('alice')->willReturn($account);
		$this->client->expects($this->once())->method('authedPost')
			->with(
				'com.atproto.repo.putRecord',
				$this->callback(function (array $request): bool {
					$this->assertSame(self::DID, $request['repo']);
					$this->assertSame(AtprotoIngress::COLLECTION, $request['collection']);
					$this->assertMatchesRegularExpression('/^[234567abcdefghijklmnopqrstuvwxyz]{13}$/', $request['rkey']);
					$this->assertSame('Hello' . chr(10) . 'Bluesky', $request['record']['text']);
					$this->assertSame(['de'], $request['record']['langs']);

					return true;
				}),
				$account,
				'https://pds.example',
			)->willReturn([
				'uri' => 'at://' . self::DID . '/' . AtprotoIngress::COLLECTION . '/3mtestrecord',
				'cid' => 'bafytest',
			]);
		$this->atprotoRequest->expects($this->once())->method('saveLink')
			->with($this->callback(function (AtprotoLink $link): bool {
				return $link->getLocalId() === self::POST
					&& $link->getDid() === self::DID
					&& $link->getCollection() === AtprotoIngress::COLLECTION
					&& $link->getCid() === 'bafytest';
			}));

		$this->egress->publish($post);
	}

	public function testNonPublicPostNeverLeavesTheFediverse(): void {
		$this->atprotoRequest->expects($this->never())->method('getLinkByLocalId');
		$this->actorsRequest->expects($this->never())->method('getFromId');
		$this->client->expects($this->never())->method('authedPost');

		$this->egress->publish($this->post(Stream::TYPE_DIRECT));
	}

	public function testRichTextFacetsAndExternalCardAreWritten(): void {
		$post = $this->post();
		$post->setContent('<p>See <a href="https://example.test">example</a> #Tag</p>');
		$post->setCard((new StreamCard(self::POST, 'https://example.test'))
			->setTitle('Example')
			->setDescription('A preview'));
		$account = $this->account();
		$this->atprotoRequest->method('getLinkByLocalId')->willReturn(null);
		$this->actorsRequest->method('getFromId')->willReturn($this->localAuthor());
		$this->atprotoRequest->method('getAccount')->willReturn($account);
		$this->client->expects($this->once())->method('authedPost')->with(
			'com.atproto.repo.putRecord',
			$this->callback(function (array $request): bool {
				$this->assertSame('See example #Tag', $request['record']['text']);
				$this->assertSame('https://example.test', $request['record']['facets'][0]['features'][0]['uri']);
				$this->assertSame('app.bsky.richtext.facet#tag', $request['record']['facets'][1]['features'][0]['$type']);
				$this->assertSame('https://example.test', $request['record']['embed']['external']['uri']);

				return true;
			}),
			$account,
			'https://pds.example',
		)->willReturn([
			'uri' => 'at://' . self::DID . '/' . AtprotoIngress::COLLECTION . '/3mrich',
			'cid' => 'bafy-rich',
		]);
		$this->atprotoRequest->expects($this->once())->method('saveLink');

		$this->egress->publish($post);
	}

	public function testNativeQuoteUsesTheQuotedRecordReference(): void {
		$post = $this->post();
		$quotedId = self::POST . '/quoted';
		$post->setQuote($quotedId);
		$quoted = (new AtprotoLink())
			->setLocalId($quotedId)
			->setAtUri('at://did:plc:other/app.bsky.feed.post/3quoted')
			->setCid('bafy-quoted');
		$account = $this->account();
		$this->atprotoRequest->method('getLinkByLocalId')->willReturnCallback(
			static fn (string $id): ?AtprotoLink => $id === $quotedId ? $quoted : null
		);
		$this->actorsRequest->method('getFromId')->willReturn($this->localAuthor());
		$this->atprotoRequest->method('getAccount')->willReturn($account);
		$this->client->expects($this->once())->method('authedPost')->with(
			'com.atproto.repo.putRecord',
			$this->callback(static fn (array $request): bool => ($request['record']['embed'] ?? []) === [
				'$type' => 'app.bsky.embed.record',
				'record' => ['uri' => 'at://did:plc:other/app.bsky.feed.post/3quoted', 'cid' => 'bafy-quoted'],
			]),
			$account,
			'https://pds.example',
		)->willReturn([
			'uri' => 'at://' . self::DID . '/' . AtprotoIngress::COLLECTION . '/3quote',
			'cid' => 'bafy-quote',
		]);
		$this->atprotoRequest->expects($this->once())->method('saveLink');

		$this->egress->publish($post);
	}

	public function testMentionFacetUsesResolvedAtprotoDid(): void {
		$post = $this->post();
		$post->setContent('<p>Hello <a href="https://social.example/@alice.example">@alice.example</a></p>');
		$post->addTag(['type' => 'Mention', 'name' => '@alice.example', 'href' => 'https://social.example/@alice.example']);
		$account = $this->account();
		$this->identity->expects($this->once())->method('resolve')->with('alice.example')->willReturn([
			'did' => 'did:plc:mentioned', 'handle' => 'alice.example', 'pds' => 'https://pds.example',
		]);
		$this->atprotoRequest->method('getLinkByLocalId')->willReturn(null);
		$this->actorsRequest->method('getFromId')->willReturn($this->localAuthor());
		$this->atprotoRequest->method('getAccount')->willReturn($account);
		$this->client->expects($this->once())->method('authedPost')->with(
			'com.atproto.repo.putRecord',
			$this->callback(static fn (array $request): bool => ($request['record']['facets'][0]['features'][0] ?? []) === [
				'$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did:plc:mentioned',
			]),
			$account,
			'https://pds.example',
		)->willReturn([
			'uri' => 'at://' . self::DID . '/' . AtprotoIngress::COLLECTION . '/3mention',
			'cid' => 'bafy-mention',
		]);
		$this->atprotoRequest->expects($this->once())->method('saveLink');

		$this->egress->publish($post);
	}

	public function testFediverseOnlyPostNeverLeavesForBluesky(): void {
		$post = $this->post();
		$post->setDetail(Details::PUBLICATION_TARGET, 'fediverse');
		$this->atprotoRequest->expects($this->never())->method('getLinkByLocalId');
		$this->actorsRequest->expects($this->never())->method('getFromId');
		$this->client->expects($this->never())->method('authedPost');

		$this->egress->publish($post);
		$this->egress->update($post);
		$this->egress->delete($post);
	}

	public function testDeletionRemovesTheMappedRecordFromThePds(): void {
		$post = $this->post();
		$account = $this->account();
		$link = (new AtprotoLink())
			->setLocalId(self::POST)
			->setAtUri('at://' . self::DID . '/' . AtprotoIngress::COLLECTION . '/3mtestrecord')
			->setCid('bafytest')
			->setDid(self::DID)
			->setCollection(AtprotoIngress::COLLECTION)
			->setRkey('3mtestrecord');
		$this->atprotoRequest->expects($this->once())->method('getLinkByLocalId')
			->with(self::POST)->willReturn($link);
		$this->actorsRequest->expects($this->once())->method('getFromId')
			->with(self::AUTHOR)->willReturn($this->localAuthor());
		$this->atprotoRequest->expects($this->once())->method('getAccount')
			->with('alice')->willReturn($account);
		$this->client->expects($this->once())->method('authedPost')
			->with('com.atproto.repo.deleteRecord', [
				'repo' => self::DID,
				'collection' => AtprotoIngress::COLLECTION,
				'rkey' => '3mtestrecord',
			], $account, 'https://pds.example')->willReturn([]);
		$this->atprotoRequest->expects($this->once())->method('deleteLinkByLocalId')->with(self::POST);

		$this->egress->delete($post);
	}

	public function testOwnNativeDeletionUsesTheLinkedAccountAndRemovesTheMapping(): void {
		$account = $this->account();
		$link = (new AtprotoLink())
			->setLocalId(self::POST)
			->setAtUri('at://' . self::DID . '/' . AtprotoIngress::COLLECTION . '/3native')
			->setDid(self::DID)
			->setCollection(AtprotoIngress::COLLECTION)
			->setRkey('3native');
		$this->atprotoRequest->expects($this->once())->method('getAccount')->with('alice')->willReturn($account);
		$this->atprotoRequest->expects($this->once())->method('getLinkByLocalId')->with(self::POST)->willReturn($link);
		$this->client->expects($this->once())->method('authedPost')->with(
			'com.atproto.repo.deleteRecord',
			['repo' => self::DID, 'collection' => AtprotoIngress::COLLECTION, 'rkey' => '3native'],
			$account,
			'https://pds.example',
		)->willReturn([]);
		$this->atprotoRequest->expects($this->once())->method('deleteLinkByLocalId')->with(self::POST);

		$this->egress->deleteOwn('alice', self::POST);
	}

	public function testOwnNativeDeletionTreatsRemoteGoneAsAlreadyDeleted(): void {
		$account = $this->account();
		$link = (new AtprotoLink())
			->setLocalId(self::POST)
			->setDid(self::DID)
			->setCollection(AtprotoIngress::COLLECTION)
			->setRkey('3gone');
		$this->atprotoRequest->method('getAccount')->willReturn($account);
		$this->atprotoRequest->method('getLinkByLocalId')->willReturn($link);
		$this->client->expects($this->once())->method('authedPost')->willThrowException(new AtprotoException('record not found', 404));
		$this->atprotoRequest->expects($this->once())->method('deleteLinkByLocalId')->with(self::POST);

		$this->egress->deleteOwn('alice', self::POST);
	}

	public function testOwnNativeUpdatePreservesRecordMetadataAndSwapsTheCid(): void {
		$account = $this->account();
		$link = (new AtprotoLink())
			->setLocalId(self::POST)
			->setAtUri('at://' . self::DID . '/' . AtprotoIngress::COLLECTION . '/3native')
			->setCid('bafy-old')
			->setDid(self::DID)
			->setCollection(AtprotoIngress::COLLECTION)
			->setRkey('3native');
		$this->atprotoRequest->expects($this->once())->method('getAccount')->with('alice')->willReturn($account);
		$this->atprotoRequest->expects($this->once())->method('getLinkByLocalId')->with(self::POST)->willReturn($link);
		$this->client->expects($this->once())->method('authedGet')->with(
			'com.atproto.repo.getRecord',
			['repo' => self::DID, 'collection' => AtprotoIngress::COLLECTION, 'rkey' => '3native'],
			$account,
			'https://pds.example',
		)->willReturn(['cid' => 'bafy-old', 'value' => [
			'$type' => 'app.bsky.feed.post', 'text' => 'old', 'createdAt' => '2026-01-01T00:00:00Z',
			'langs' => ['de'], 'embed' => ['old' => true],
		]]);
		$this->client->expects($this->once())->method('authedPost')->with(
			'com.atproto.repo.putRecord',
			$this->callback(static function (array $request): bool {
				return $request['swapRecord'] === 'bafy-old'
					&& $request['record']['text'] === 'new text'
					&& $request['record']['createdAt'] === '2026-01-01T00:00:00Z'
					&& $request['record']['embed'] === ['old' => true]
					&& !isset($request['record']['facets']);
			}),
			$account,
			'https://pds.example',
		)->willReturn(['cid' => 'bafy-new']);
		$this->atprotoRequest->expects($this->once())->method('saveLink')->with($this->callback(
			static fn (AtprotoLink $saved): bool => $saved->getCid() === 'bafy-new'
		));

		$this->egress->updateOwn('alice', self::POST, 'new text');
	}

	public function testEditReplacesTheMappedRecordWithoutCreatingANewPost(): void {
		$post = $this->post();
		$post->setContent('<p>Edited<br>Bluesky</p>');
		$account = $this->account();
		$link = (new AtprotoLink())
			->setLocalId(self::POST)
			->setAtUri('at://' . self::DID . '/' . AtprotoIngress::COLLECTION . '/3mtestrecord')
			->setCid('bafyold')
			->setDid(self::DID)
			->setCollection(AtprotoIngress::COLLECTION)
			->setRkey('3mtestrecord');
		$this->atprotoRequest->expects($this->once())->method('getLinkByLocalId')
			->with(self::POST)->willReturn($link);
		$this->actorsRequest->expects($this->once())->method('getFromId')
			->with(self::AUTHOR)->willReturn($this->localAuthor());
		$this->atprotoRequest->expects($this->once())->method('getAccount')
			->with('alice')->willReturn($account);
		$this->client->expects($this->once())->method('authedPost')
			->with(
				'com.atproto.repo.putRecord',
				$this->callback(function (array $request): bool {
					$this->assertSame('3mtestrecord', $request['rkey']);
					$this->assertSame('Edited' . chr(10) . 'Bluesky', $request['record']['text']);

					return true;
				}),
				$account,
				'https://pds.example',
			)->willReturn([
				'uri' => 'at://' . self::DID . '/' . AtprotoIngress::COLLECTION . '/3mtestrecord',
				'cid' => 'bafynew',
			]);
		$this->atprotoRequest->expects($this->once())->method('saveLink')
			->with($this->callback(function (AtprotoLink $saved): bool {
				return $saved->getLocalId() === self::POST
					&& $saved->getRkey() === '3mtestrecord'
					&& $saved->getCid() === 'bafynew';
			}));

		$this->egress->update($post);
	}

	public function testLocalImageIsUploadedAsANativeBlobEmbed(): void {
		$document = (new Document())
			->setId('https://cloud.example/apps/social/document/image-1')
			->setAccount('alice')
			->setMimeType('image/png')
			->setSizeBytes(42)
			->setDescription('A small test image');
		$post = $this->post();
		$post->setAttachments([$document]);
		$account = $this->account();
		$file = $this->createMock(ISimpleFile::class);
		$file->expects($this->once())->method('getContent')->willReturn('png-bytes');
		$this->documentService->expects($this->once())->method('getFromCache')
			->with($document->getId(), 'image/png', true)->willReturn($file);
		$this->atprotoRequest->expects($this->once())->method('getLinkByLocalId')
			->with(self::POST)->willReturn(null);
		$this->actorsRequest->expects($this->once())->method('getFromId')
			->with(self::AUTHOR)->willReturn($this->localAuthor());
		$this->atprotoRequest->expects($this->once())->method('getAccount')
			->with('alice')->willReturn($account);
		$this->client->expects($this->once())->method('authedBlobPost')
			->with('png-bytes', 'image/png', $account, 'https://pds.example')
			->willReturn(['blob' => [
				'$type' => 'blob',
				'ref' => ['$link' => 'bafy-image'],
				'mimeType' => 'image/png',
				'size' => 9,
			]]);
		$this->client->expects($this->once())->method('authedPost')->with(
			'com.atproto.repo.putRecord',
			$this->callback(static fn (array $request): bool => ($request['record']['embed'] ?? []) === [
				'$type' => 'app.bsky.embed.images',
				'images' => [[
					'image' => [
						'$type' => 'blob',
						'ref' => ['$link' => 'bafy-image'],
						'mimeType' => 'image/png',
						'size' => 9,
					],
					'alt' => 'A small test image',
				]],
			]),
			$account,
			'https://pds.example',
		)->willReturn(['uri' => 'at://' . self::DID . '/' . AtprotoIngress::COLLECTION . '/3media', 'cid' => 'bafy-media']);
		$this->atprotoRequest->expects($this->once())->method('saveLink');

		$this->egress->publish($post);
	}
}
