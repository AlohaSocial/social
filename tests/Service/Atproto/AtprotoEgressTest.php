<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\Atproto;

use OCA\Social\Db\ActorsRequest;
use OCA\Social\Db\AtprotoRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Atproto\AtprotoAccount;
use OCA\Social\Model\Atproto\AtprotoLink;
use OCA\Social\Service\Atproto\AtprotoClient;
use OCA\Social\Service\Atproto\AtprotoEgress;
use OCA\Social\Service\Atproto\AtprotoIngress;
use OCA\Social\Service\ConfigService;
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
	private AtprotoEgress $egress;

	protected function setUp(): void {
		$this->client = $this->createMock(AtprotoClient::class);
		$this->atprotoRequest = $this->createMock(AtprotoRequest::class);
		$this->actorsRequest = $this->createMock(ActorsRequest::class);
		$this->configService = $this->createMock(ConfigService::class);
		$this->configService->method('getAppValue')->willReturn('1');
		$this->egress = new AtprotoEgress(
			$this->client,
			$this->atprotoRequest,
			$this->actorsRequest,
			$this->configService,
			new NullLogger(),
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
}
