<?php

declare(strict_types=1);

namespace OCA\Social\Tests\Service\Atproto;

use OCA\Social\Db\AtprotoRequest;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Atproto\AtprotoAccount;
use OCA\Social\Model\Atproto\AtprotoLink;
use OCA\Social\Service\Atproto\AtprotoClient;
use OCA\Social\Service\Atproto\AtprotoEngagementService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class AtprotoEngagementServiceTest extends TestCase {
	private AtprotoClient|MockObject $client;
	private AtprotoRequest|MockObject $request;
	private AtprotoEngagementService $service;

	protected function setUp(): void {
		$this->client = $this->createMock(AtprotoClient::class);
		$this->request = $this->createMock(AtprotoRequest::class);
		$this->service = new AtprotoEngagementService($this->client, $this->request);
	}

	private function post(): Stream {
		return (new Stream())->setId('https://social.example/ap/bluesky/did:plc:alice/app.bsky.feed.post/3abc');
	}

	private function account(): AtprotoAccount {
		return (new AtprotoAccount())->setUserId('alice')->setDid('did:plc:alice')->setPds('https://pds.example');
	}

	public function testLikeCreatesNativeRecord(): void {
		$link = (new AtprotoLink())->setLocalId($this->post()->getId())->setAtUri('at://did:plc:bob/app.bsky.feed.post/3xyz')->setCid('bafy-post');
		$this->request->expects($this->once())->method('getLinkByLocalId')->willReturn($link);
		$this->request->expects($this->once())->method('getAccount')->with('alice')->willReturn($this->account());
		$this->client->expects($this->once())->method('authedGet')->willReturn(['records' => []]);
		$this->client->expects($this->once())->method('authedPost')->with(
			'com.atproto.repo.createRecord',
			$this->callback(fn (array $body): bool => $body['collection'] === 'app.bsky.feed.like'
				&& $body['record']['subject']['uri'] === 'at://did:plc:bob/app.bsky.feed.post/3xyz'),
			$this->isInstanceOf(AtprotoAccount::class), 'https://pds.example'
		)->willReturn([]);

		$this->service->setLiked('alice', $this->post(), true);
	}

	public function testUnlikeDeletesNativeRecord(): void {
		$link = (new AtprotoLink())->setLocalId($this->post()->getId())->setAtUri('at://did:plc:bob/app.bsky.feed.post/3xyz')->setCid('bafy-post');
		$this->request->method('getLinkByLocalId')->willReturn($link);
		$this->request->method('getAccount')->willReturn($this->account());
		$this->client->method('authedGet')->willReturn(['records' => [[
			'uri' => 'at://did:plc:alice/app.bsky.feed.like/3like',
			'value' => ['subject' => ['uri' => $link->getAtUri()]],
		]]]);
		$this->client->expects($this->once())->method('authedPost')->with(
			'com.atproto.repo.deleteRecord',
			['repo' => 'did:plc:alice', 'collection' => 'app.bsky.feed.like', 'rkey' => '3like'],
			$this->isInstanceOf(AtprotoAccount::class), 'https://pds.example'
		)->willReturn([]);

		$this->service->setLiked('alice', $this->post(), false);
	}
}
