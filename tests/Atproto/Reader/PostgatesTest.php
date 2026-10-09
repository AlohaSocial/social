<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Reader;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Identity\PlcClient;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Move\PdsClient;
use OCA\Social\Atproto\Reader\Postgates;
use OCA\Social\Atproto\Reader\PostMapper;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Exceptions\AtprotoException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\Note;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class PostgatesTest extends TestCase {
	private const AUTHOR = 'did:plc:bob';
	private const POST = 'at://did:plc:bob/app.bsky.feed.post/3kpost';

	/** the gate the author's PDS answers, null for none */
	private ?array $gate = null;
	private bool $unreachable = false;
	private string $me = 'did:plc:alice';
	private array $asked = [];

	private function gates(): Postgates {
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('isEnabled')->willReturn(true);
		$plc = $this->createMock(PlcClient::class);
		$plc->method('data')->with(self::AUTHOR)->willReturn(['services' => ['atproto_pds' => ['endpoint' => 'https://pds.bob.test']]]);
		$pds = $this->createMock(PdsClient::class);
		$pds->method('origin')->willReturnArgument(0);
		$pds->method('call')->willReturnCallback(function (string $origin, string $method, string $verb, array $query): array {
			if ($this->unreachable) {
				throw new AtprotoException('down');
			}
			$this->asked[] = [$origin, $method, $query];

			return $this->gate === null
				? ['status' => 400, 'body' => ['error' => 'RecordNotFound']]
				: ['status' => 200, 'body' => ['value' => $this->gate]];
		});
		$identities = $this->createMock(IdentityService::class);
		$identities->method('forActor')->willReturnCallback(fn (): Identity => new Identity(1, 'https://social.test/@alice', $this->me, 'alice.social.test', '', '', '', Identity::STATE_ACTIVE, '', 0));

		return new Postgates($config, $plc, $pds, $identities, new NullLogger());
	}

	private function quoted(string $id = 'https://bsky.app/profile/did:plc:bob/post/3kpost'): Note {
		$note = new Note();
		$note->setId($id);
		$note->setDetailArray(PostMapper::DETAIL, ['uri' => self::POST]);

		return $note;
	}

	private function disabled(): array {
		return ['$type' => Postgates::GATE, 'post' => self::POST, 'embeddingRules' => [['$type' => Postgates::GATE . '#disableRule']], 'createdAt' => '2026-10-09T10:00:00.000Z'];
	}

	public function testAPostWhoseAuthorTurnedQuotingOffIsNotQuoted(): void {
		$this->gate = $this->disabled();

		$this->assertSame(Postgates::REFUSAL, $this->gates()->refusal(new Person(), $this->quoted()));
		$this->assertSame([['https://pds.bob.test', 'com.atproto.repo.getRecord', ['repo' => self::AUTHOR, 'collection' => Postgates::GATE, 'rkey' => '3kpost']]], $this->asked, 'the gate under the post\'s own key, from the author\'s PDS');
	}

	public function testAnyOtherAnswerLetsTheQuoteGoOut(): void {
		$this->assertSame('', $this->gates()->refusal(new Person(), $this->quoted()), 'no gate');

		$this->gate = ['$type' => Postgates::GATE, 'post' => self::POST, 'detachedEmbeddingUris' => ['at://did:plc:carol/app.bsky.feed.post/3k'], 'createdAt' => '2026-10-09T10:00:00.000Z'];
		$this->assertSame('', $this->gates()->refusal(new Person(), $this->quoted()), 'a gate that only detached other quotes');

		$this->gate = ['post' => 'at://did:plc:bob/app.bsky.feed.post/3kother'] + $this->disabled();
		$this->assertSame('', $this->gates()->refusal(new Person(), $this->quoted()), 'a gate for another post');

		$this->gate = $this->disabled();
		$this->unreachable = true;
		$this->assertSame('', $this->gates()->refusal(new Person(), $this->quoted()), 'the PDS did not answer');
	}

	public function testTheAuthorAndPostsFromElsewhereAreNotAsked(): void {
		$this->gate = $this->disabled();
		$this->me = self::AUTHOR;
		$this->assertSame('', $this->gates()->refusal(new Person(), $this->quoted()), 'the author quoting their own post');
		$this->assertSame('', $this->gates()->refusal(new Person(), $this->quoted('https://social.test/@bob/1')), 'not a Bluesky post');
		$this->assertSame([], $this->asked);
	}

	public function testAQuoteItsAuthorDetachedIsListedInTheirGate(): void {
		$mine = 'at://did:plc:alice/app.bsky.feed.post/3kmine';
		$this->assertFalse($this->gates()->detached($this->quoted(), $mine), 'no gate');

		$this->gate = ['$type' => Postgates::GATE, 'post' => self::POST, 'detachedEmbeddingUris' => [$mine], 'createdAt' => '2026-10-09T10:00:00.000Z'];
		$this->assertTrue($this->gates()->detached($this->quoted(), $mine));
		$this->assertFalse($this->gates()->detached($this->quoted(), 'at://did:plc:carol/app.bsky.feed.post/3k'), 'somebody else\'s quote');
		$this->assertFalse($this->gates()->detached($this->quoted('https://social.test/@bob/1'), $mine), 'not a Bluesky post');
	}
}
