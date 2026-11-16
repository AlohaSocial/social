<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Firehose;

use OCA\Social\Atproto\Firehose\EventService;
use OCA\Social\Atproto\Firehose\FirehoseDaemon;
use OCA\Social\Atproto\Firehose\WebSocketServer;
use OCA\Social\Atproto\Lexicon\Lexicon;
use OCA\Social\Atproto\Protocol\Cid;
use OCA\Social\Atproto\Protocol\DagCbor;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Service\ConfigService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * `occ social:atproto:serve` end to end over the loopback: subscribers
 * connect while the daemon runs, each with the cursor a relay would send,
 * and what each one reads off its socket is the stream the protocol
 * promises.
 *
 * - no cursor: live frames only, from the head on;
 * - a cursor inside the window: every frame after it, in order, then live;
 * - a cursor older than the window: an `#info OutdatedCursor` first, then
 *   everything still held;
 * - a cursor past the head: a `FutureCursor` error frame, then the close.
 *
 * Every frame body fits its definition in the subscribeRepos lexicon.
 */
#[AllowMockObjectsWithoutExpectations]
class FirehoseDaemonTest extends TestCase {
	private const DID = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';
	private const T0 = 1760000000;

	private InMemoryEventRequest $store;
	private EventService $events;
	private string $bind = '';
	private ?LoggerInterface $logger = null;
	private int $calls = 0;
	/** @var array<int, callable(): void> what happens at the n-th reading of the clock */
	private array $script = [];

	protected function setUp(): void {
		$this->store = new InMemoryEventRequest();
		$eventTime = $this->createMock(ITimeFactory::class);
		$eventTime->method('getTime')->willReturn(self::T0);
		$this->events = new EventService($this->store, $eventTime);
		$this->bind = '127.0.0.1:' . random_int(20000, 60000);
	}

	protected function tearDown(): void {
		if (function_exists('pcntl_signal')) {
			pcntl_signal(SIGTERM, SIG_DFL);
			pcntl_signal(SIGINT, SIG_DFL);
		}
	}

	public function testEachSubscriberIsReplayedFromItsCursorAndThenFollowsLive(): void {
		// seq 1-4 were pruned; 5, 6 and 7 are in the window, 7 is the head
		$this->store->next = 5;
		$this->events->identity(self::DID, 'alice.social.example.com');
		$this->events->account(self::DID, false, 'deactivated');
		$this->events->account(self::DID, true);

		$clients = [];
		$this->script[3] = function () use (&$clients): void {
			$clients['live'] = $this->connect('');
			$clients['inside'] = $this->connect('?cursor=5');
			$clients['outdated'] = $this->connect('?cursor=2');
			$clients['future'] = $this->connect('?cursor=99');
			$clients['zero'] = $this->connect('?cursor=0');
		};
		$this->script[12] = function (): void {
			$this->events->identity(self::DID, 'alice.example.org');
		};

		$this->daemon()->run($this->bind, 16, false, static function (): void {
		});

		$frames = array_map(fn ($client): array => $this->framesOf($client), $clients);

		$this->assertSame([8], self::seqs($frames['live']), 'live only: what came after it connected');
		$this->assertSame([6, 7, 8], self::seqs($frames['inside']), 'everything after its cursor, then live');
		$this->assertSame('#info', $frames['outdated'][0]['header']['t']);
		$this->assertSame('OutdatedCursor', $frames['outdated'][0]['body']['name']);
		$this->assertSame([5, 6, 7, 8], self::seqs($frames['outdated']), 'what is still held');
		$this->assertSame([5, 6, 7, 8], self::seqs($frames['zero']));
		$this->assertSame([['op' => -1], ['error' => 'FutureCursor', 'message' => 'Cursor in the future.']], [$frames['future'][0]['header'], $frames['future'][0]['body']]);
		$this->assertCount(1, $frames['future'], 'and nothing after the error');

		$lexicon = new Lexicon();
		foreach ($frames as $name => $stream) {
			foreach ($stream as $frame) {
				if ($frame['header']['op'] === 1) {
					$lexicon->validate($frame['body'], 'com.atproto.sync.subscribeRepos' . $frame['header']['t']);
					$this->addToAssertionCount(1);
				}
			}
		}
		$identity = $frames['inside'][2];
		$this->assertSame(['t' => '#identity', 'op' => 1], $identity['header']);
		$this->assertSame('alice.example.org', $identity['body']['handle']);
		$account = $frames['inside'][0];
		$this->assertSame(['did' => self::DID, 'seq' => 6, 'time' => '2025-10-09T08:53:20.000Z', 'active' => false, 'status' => 'deactivated'], $account['body']);
	}

	/**
	 * Sequence numbers are taken at insert and seen at commit, so seq 7 can
	 * be there before seq 6: the daemon holds 7 back until 6 is there and
	 * then serves both in order, and a live subscriber misses nothing.
	 */
	public function testAFrameNumberedBelowOneAlreadyThereIsStillServedInOrder(): void {
		$this->store->next = 5;
		$this->events->identity(self::DID, 'alice.social.example.com');
		$client = null;
		$this->script[3] = function () use (&$client): void {
			$client = $this->connect('');
		};
		$this->script[5] = function (): void {
			$this->store->next = 7;
			$this->events->identity(self::DID, 'seven.example.org');
		};
		$this->script[7] = function (): void {
			$this->store->next = 6;
			$this->events->identity(self::DID, 'six.example.org');
			$this->store->next = 8;
		};
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->expects($this->never())->method('info');

		$this->daemon()->run($this->bind, 12, false, static function (): void {
		});

		$frames = $this->framesOf($client);
		$this->assertSame([6, 7], self::seqs($frames));
		$this->assertSame(['six.example.org', 'seven.example.org'], array_column(array_column($frames, 'body'), 'handle'));
	}

	/**
	 * A number that never turns up is a write that rolled back: after the
	 * grace period the stream goes on past it, and says so in the log.
	 */
	public function testAGapThatStaysIsPassedAfterTheGracePeriod(): void {
		$this->store->next = 5;
		$this->events->identity(self::DID, 'alice.social.example.com');
		$client = null;
		$this->script[3] = function () use (&$client): void {
			$client = $this->connect('');
		};
		$this->script[5] = function (): void {
			$this->store->next = 7;
			$this->events->identity(self::DID, 'seven.example.org');
		};
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->logger->expects($this->once())->method('info')->with($this->stringContains('seq 6'));

		$this->daemon()->run($this->bind, 12, false, static function (): void {
		});

		$this->assertSame([7], self::seqs($this->framesOf($client)));
	}

	/**
	 * A backlog far past what a subscriber's buffer may hold is replayed
	 * whole to a consumer that keeps reading, and a live subscriber
	 * connected at the same time is not held up by it.
	 */
	public function testABacklogLargerThanTheBufferIsReplayedToAConsumerThatReads(): void {
		$block = str_repeat('x', 512 * 1024);
		for ($i = 0; $i < 40; $i++) {
			$cid = Cid::forDagCbor(DagCbor::encode(['n' => $i]));
			$this->events->commit(self::DID, $cid, '3kznmn7xqxl' . sprintf('%02d', $i), null, null, [], [$cid->toString() => $block . $i]);
		}
		$replaying = null;
		$received = '';
		$live = null;
		for ($call = 3; $call < 50; $call++) {
			$this->script[$call] = function () use ($call, &$replaying, &$received, &$live): void {
				if ($call === 3) {
					$replaying = $this->connect('?cursor=0');
					stream_set_blocking($replaying, false);
				}
				if ($call === 4) {
					$live = $this->connect('');
				}
				if ($call === 6) {
					$this->events->identity(self::DID, 'alice.example.org');
				}
				if ($replaying !== null) {
					while (($chunk = fread($replaying, 1 << 20)) !== false && $chunk !== '') {
						$received .= $chunk;
					}
				}
			};
		}

		$this->daemon()->run($this->bind, 50, false, static function (): void {
		});

		$this->assertSame([41], self::seqs($this->framesOf($live)), 'the live subscriber got the new frame while the replay went on');
		stream_set_blocking($replaying, true);
		$buffer = $received;
		$end = strpos($buffer, "\r\n\r\n");
		$buffer = substr($buffer, (int)$end + 4);
		$seqs = [];
		$at = 0;
		while ($at + 2 <= strlen($buffer)) {
			$opcode = ord($buffer[$at]) & 0x0f;
			$length = ord($buffer[$at + 1]) & 0x7f;
			$at += 2;
			if ($length === 126) {
				$length = unpack('n', substr($buffer, $at, 2))[1];
				$at += 2;
			} elseif ($length === 127) {
				$length = unpack('J', substr($buffer, $at, 8))[1];
				$at += 8;
			}
			$payload = substr($buffer, $at, $length);
			$at += $length;
			if ($opcode === 0x2 && strlen($payload) === $length) {
				$offset = 0;
				DagCbor::decodeAt($payload, $offset);
				$seqs[] = DagCbor::decodeAt($payload, $offset)['seq'];
			}
		}
		fclose($replaying);
		$this->assertSame(range(1, 41), $seqs);
	}

	/** One address may start only so many replays a minute; the rest are told so and closed. */
	public function testReplaysFromOneAddressAreLimited(): void {
		$this->events->identity(self::DID, 'alice.social.example.com');
		$this->events->identity(self::DID, 'alice.example.org');
		$clients = [];
		$this->script[3] = function () use (&$clients): void {
			for ($i = 0; $i < 7; $i++) {
				$clients[] = $this->connect('?cursor=0');
			}
		};

		$this->daemon()->run($this->bind, 20, false, static function (): void {
		});

		$frames = array_map(fn ($client): array => $this->framesOf($client), $clients);
		for ($i = 0; $i < 6; $i++) {
			$this->assertSame([1, 2], self::seqs($frames[$i]));
		}
		$this->assertSame('RateLimitExceeded', $frames[6][0]['body']['error']);
	}

	/**
	 * A commit frame as the daemon serves it: the body the repository
	 * wrote, the row's sequence number added, the CAR's root the commit.
	 */
	public function testACommitFrameCarriesItsSeqAndItsCar(): void {
		$record = DagCbor::encode(['$type' => 'app.bsky.feed.post', 'text' => 'hi', 'createdAt' => '2026-10-09T12:00:00.000Z']);
		$recordCid = Cid::forDagCbor($record);
		$commitCid = Cid::forDagCbor(DagCbor::encode(['stand-in' => 'commit']));
		$seq = $this->events->commit(self::DID, $commitCid, '3kznmn7xqxl22', null, null, [
			['action' => 'create', 'path' => 'app.bsky.feed.post/3kznmn7xqxl22', 'cid' => $recordCid, 'prev' => null],
		], [$commitCid->toString() => DagCbor::encode(['stand-in' => 'commit']), $recordCid->toString() => $record]);

		$offset = 0;
		$frame = $this->store->events[$seq]->frame();
		$header = DagCbor::decodeAt($frame, $offset);
		$body = DagCbor::decodeAt($frame, $offset);

		$this->assertSame(strlen($frame), $offset, 'two values, nothing after');
		$this->assertSame(['t' => '#commit', 'op' => 1], $header);
		$this->assertSame($seq, $body['seq']);
		$this->assertNull($body['since']);
		$this->assertArrayNotHasKey('prevData', $body, 'the first commit has nothing before it');
		$this->assertArrayNotHasKey('prev', $body['ops'][0], 'a create has no previous record');
		(new Lexicon())->validate($body, 'com.atproto.sync.subscribeRepos#commit');
	}

	private function daemon(): FirehoseDaemon {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturnCallback(function (): int {
			$this->calls++;
			if (isset($this->script[$this->calls])) {
				($this->script[$this->calls])();
			}

			return self::T0 + $this->calls;
		});

		return new FirehoseDaemon($this->createMock(AtprotoConfig::class), $this->events, $this->createMock(ConfigService::class), $time, $this->logger ?? $this->createMock(LoggerInterface::class));
	}

	/**
	 * @return resource
	 */
	private function connect(string $query) {
		$client = stream_socket_client('tcp://' . $this->bind, $errno, $error, 2);
		$this->assertNotFalse($client, (string)$error);
		fwrite($client, 'GET ' . WebSocketServer::PATH . $query . " HTTP/1.1\r\nHost: social.example.com\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n\r\n");

		return $client;
	}

	/**
	 * The binary frames a client received, each split into the header and
	 * the body; the handshake and the closing frame are dropped.
	 *
	 * @param resource $client
	 * @return list<array{header: array, body: array}>
	 */
	private function framesOf($client): array {
		stream_set_timeout($client, 2);
		$buffer = '';
		while (!feof($client)) {
			$chunk = fread($client, 65536);
			if ($chunk === false || $chunk === '') {
				break;
			}
			$buffer .= $chunk;
		}
		fclose($client);
		$end = strpos($buffer, "\r\n\r\n");
		$this->assertNotFalse($end);
		$this->assertStringStartsWith('HTTP/1.1 101', $buffer);
		$buffer = substr($buffer, $end + 4);

		$frames = [];
		while (($frame = WebSocketServer::readFrame($buffer)) !== null) {
			[$opcode, $payload] = $frame;
			if ($opcode !== 0x2) {
				continue;
			}
			$offset = 0;
			$header = DagCbor::decodeAt($payload, $offset);
			$body = DagCbor::decodeAt($payload, $offset);
			$this->assertSame(strlen($payload), $offset);
			$frames[] = ['header' => $header, 'body' => $body];
		}

		return $frames;
	}

	/**
	 * @param list<array{header: array, body: array}> $frames
	 * @return list<int>
	 */
	private static function seqs(array $frames): array {
		return array_values(array_map(static fn (array $frame): int => $frame['body']['seq'], array_filter($frames, static fn (array $frame): bool => isset($frame['body']['seq']))));
	}
}
