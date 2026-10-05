<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service\Atproto;

use OCA\Social\Db\AtprotoRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Service\Atproto\AtprotoClient;
use OCA\Social\Service\Atproto\AtprotoIdentity;
use OCA\Social\Service\Atproto\AtprotoIngress;
use OCA\Social\Service\Atproto\RecordMapper;
use OCA\Social\Service\ImportService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class AtprotoIngressTest extends TestCase {
	public function testGoneRecordRemovesItsStaleMapping(): void {
		$client = $this->createMock(AtprotoClient::class);
		$identity = $this->createMock(AtprotoIdentity::class);
		$mapper = $this->createMock(RecordMapper::class);
		$import = $this->createMock(ImportService::class);
		$streams = $this->createMock(StreamRequest::class);
		$links = $this->createMock(AtprotoRequest::class);
		$id = 'https://cloud.example/apps/social/ap/bluesky/did:plc:gone/app.bsky.feed.post/3gone';
		$identity->method('didOf')->with($id)->willReturn('did:plc:gone');
		$identity->method('rkeyOf')->with($id)->willReturn('3gone');
		$identity->method('collectionOf')->with($id)->willReturn(AtprotoIngress::COLLECTION);
		$identity->method('pdsOf')->with('did:plc:gone')->willReturn('https://pds.example');
		$streams->method('getStreamById')->willThrowException(new StreamNotFoundException());
		$client->expects($this->once())->method('get')->willThrowException(new AtprotoException('record not found', 404));
		$links->expects($this->once())->method('deleteLinkByLocalId')->with($id);

		$ingress = new AtprotoIngress($client, $identity, $mapper, $import, $streams, $links, new NullLogger());

		$this->expectException(AtprotoException::class);
		$ingress->fetch($id);
	}
}
