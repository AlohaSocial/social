<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto;

use OCA\Social\Atproto\Firehose\FirehoseHandler;
use OCA\Social\Atproto\Protocol\Bytes;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use PHPUnit\Framework\TestCase;
use Ratchet\RFC6455\Messaging\Frame;

class FirehoseFrameTest extends TestCase {
	public function testFrameIsBinaryAndContainsTwoCborObjectsWithDatabaseSequence(): void {
		$body = ['repo' => 'did:plc:abcdefghijklmnopqrstuvwx', 'commit' => new Cid(Cid::hash('commit')), 'blocks' => new Bytes("\0\xffCAR")];
		$frame = FirehoseHandler::frame(['seq' => 1234, 'kind' => '#commit', 'bytes' => DagCbor::encode($body)]);
		self::assertSame(Frame::OP_BINARY, $frame->getOpcode());
		$header = DagCbor::encode(['op' => 1, 't' => '#commit']);
		self::assertStringStartsWith($header, $frame->getPayload());
		$decoded = DagCbor::decode(substr($frame->getPayload(), strlen($header)));
		self::assertSame(1234, $decoded['seq']);
		self::assertSame("\0\xffCAR", $decoded['blocks']->value);
		self::assertInstanceOf(Cid::class, $decoded['commit']);
	}
}
