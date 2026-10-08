<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Moderation;

use OCA\Social\Atproto\AppView\ServiceAuth;
use OCA\Social\Atproto\Crypto\Curve;
use OCA\Social\Atproto\Crypto\PrivateKey;
use OCA\Social\Atproto\Identity\InstanceKeyService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Moderation\BlueskyReporter;
use OCA\Social\Atproto\Protocol\Encoding;
use OCA\Social\Atproto\Publisher\PostRefs;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\Report;
use OCA\Social\Service\CurlService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class BlueskyReporterTest extends TestCase {
	private const MOD = 'did:plc:ar7c4by46qjdydhdevvrndac';
	private const BOB = 'did:plc:z72i7hdynmk6r22z27h6tvur';

	/** @var CurlService&MockObject */
	private CurlService $curl;
	/** @var PostRefs&MockObject */
	private PostRefs $refs;
	private BlueskyReporter $reporter;
	/** @var StreamRequest&MockObject */
	private StreamRequest $streams;
	private PrivateKey $serviceKey;

	protected function setUp(): void {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$config->method('moderationDid')->willReturn(self::MOD);
		$config->method('serviceDid')->willReturn('did:web:social.test');
		$this->serviceKey = PrivateKey::generate(Curve::K256);
		$keys = $this->createMock(InstanceKeyService::class);
		$keys->method('serviceKey')->willReturn($this->serviceKey);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(1760000000);
		$plc = $this->createMock(PlcClient::class);
		$plc->method('document')->with(self::MOD)->willReturn(['service' => [
			['id' => '#atproto_pds', 'serviceEndpoint' => 'https://pds.example'],
			['id' => '#atproto_labeler', 'type' => 'AtprotoLabeler', 'serviceEndpoint' => 'https://mod.bsky.app/'],
		]]);
		$this->refs = $this->createMock(PostRefs::class);
		$this->curl = $this->createMock(CurlService::class);
		$this->streams = $this->createMock(StreamRequest::class);
		$this->streams->method('getStreamByNid')->willReturnCallback(static function (): Note {
			$note = new Note();
			$note->setId('https://bsky.app/profile/' . self::BOB . '/post/3k');

			return $note;
		});
		$this->reporter = new BlueskyReporter($config, $keys, new ServiceAuth($time), $plc, $this->refs, $this->streams, $this->curl, new NullLogger());
	}

	public function testAReportGoesToTheModerationServiceInTheInstancesNameNotTheReporters(): void {
		$this->refs->method('strongRef')->willReturnCallback(static fn (string $id): ?array => $id === 'https://bsky.app/profile/' . self::BOB . '/post/3k' ? ['uri' => 'at://' . self::BOB . '/app.bsky.feed.post/3k', 'cid' => 'bafyreicid'] : null);
		$this->curl->expects($this->once())->method('doRequest')->with('post', 'https://mod.bsky.app/xrpc/com.atproto.moderation.createReport', $this->callback(function (array $options): bool {
			[$header, $payload, $signature] = explode('.', substr($options['headers']['Authorization'], 7));
			$claims = json_decode(Encoding::base64UrlDecode($payload), true);
			$this->assertSame('did:web:social.test', $claims['iss'], 'this server, never the reporter');
			$this->assertSame(self::MOD, $claims['aud']);
			$this->assertSame('com.atproto.moderation.createReport', $claims['lxm']);
			$this->assertTrue($this->serviceKey->publicKey()->verify($header . '.' . $payload, Encoding::base64UrlDecode($signature)));
			$body = json_decode($options['body'], true);
			$this->assertSame('com.atproto.moderation.defs#reasonSpam', $body['reasonType']);
			$this->assertSame(['$type' => 'com.atproto.repo.strongRef', 'uri' => 'at://' . self::BOB . '/app.bsky.feed.post/3k', 'cid' => 'bafyreicid'], $body['subject']);
			$this->assertSame('buy now', $body['reason']);
			$this->assertTrue($options['accept_errors']);

			return true;
		}))->willReturnCallback(static function (string $m, string $u, array $o, &$ct, &$status): string {
			$status = 200;

			return '{"id":42,"reasonType":"com.atproto.moderation.defs#reasonSpam"}';
		});

		$this->assertSame('42', $this->reporter->report($this->report(['https://social.test/@x/1', '1791458880860688332']), $this->bob()));
	}

	public function testAnAccountReportNamesTheRepositoryAndARefusalIsNotATakenReport(): void {
		$this->refs->method('strongRef')->willReturn(null);
		$report = $this->report([], Report::CATEGORY_LEGAL, '');
		$body = $this->reporter->body($report, $this->bob());
		$this->assertSame(['$type' => 'com.atproto.admin.defs#repoRef', 'did' => self::BOB], $body['subject']);
		$this->assertSame('com.atproto.moderation.defs#reasonViolation', $body['reasonType']);
		$this->assertArrayNotHasKey('reason', $body);

		$this->curl->method('doRequest')->willReturnCallback(static function (string $m, string $u, array $o, &$ct, &$status): string {
			$status = 401;

			return '{"error":"AuthRequired"}';
		});
		$this->assertSame('', $this->reporter->report($report, $this->bob()));
	}

	public function testOnlyLocalReportsAboutBlueskyAccountsGo(): void {
		$fediverse = new Person();
		$fediverse->setId('https://mastodon.test/users/bob');
		$this->assertFalse($this->reporter->canReport($this->report([]), $fediverse));
		$remote = $this->report([]);
		$remote->setLocal(false);
		$this->assertFalse($this->reporter->canReport($remote, $this->bob()), 'a Flag from elsewhere is not ours to pass on');
		$this->curl->expects($this->never())->method('doRequest');
		$this->assertSame('', $this->reporter->report($remote, $this->bob()));
	}

	private function bob(): Person {
		$bob = new Person();
		$bob->setId('https://bsky.app/profile/' . self::BOB);

		return $bob;
	}

	private function report(array $statusIds, string $category = Report::CATEGORY_SPAM, string $comment = ' buy now '): Report {
		$report = new Report();
		$report->setActorId('https://social.test/@alice')->setStatusIds($statusIds)->setComment($comment)->setCategory($category)->setLocal(true);

		return $report;
	}
}
