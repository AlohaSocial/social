<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\Chat;

use OCA\Social\Atproto\Chat\ChatDeclaration;
use OCA\Social\Atproto\Client\ClientSession;
use OCA\Social\Atproto\Lexicon\Lexicon;
use OCA\Social\Atproto\Model\Identity;
use OCA\Social\Atproto\Publisher\Publisher;
use OCA\Social\Atproto\Xrpc\XrpcException;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\NotificationPolicyService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Who may send direct messages, as the record Bluesky's chat service goes by.
 */
#[AllowMockObjectsWithoutExpectations]
class ChatDeclarationTest extends TestCase {
	private const ALICE = 'did:plc:ewvi7nxzyoun6zhxrhs64oiz';

	/** @var list<array> the records written */
	private array $written = [];
	private bool $has = false;
	private string $from = 'following';
	/** @var list<string> what the setting here was changed to */
	private array $saved = [];

	private function declaration(): ChatDeclaration {
		$alice = new Person();
		$alice->setId('https://social.test/users/alice');
		$alice->setUserId('alice');
		$alice->setLocal(true);
		$publisher = $this->createMock(Publisher::class);
		$publisher->method('hasSelfRecord')->willReturnCallback(fn (): bool => $this->has);
		$publisher->method('writeSelfRecord')->willReturnCallback(function (Person $actor, string $collection, array $record): bool {
			$this->written[] = $record;

			return true;
		});
		$accounts = $this->createMock(AccountService::class);
		$accounts->method('getActorFromUserId')->willReturn($alice);
		$actors = $this->createMock(ActorsRequest::class);
		$actors->method('getFromId')->willReturn($alice);
		$policies = $this->createMock(NotificationPolicyService::class);
		$policies->method('directMessagesFrom')->willReturnCallback(fn (): string => $this->from);
		$policies->method('saveDirectMessagesFrom')->willReturnCallback(function (string $userId, string $from): string {
			$this->saved[] = $from;
			$this->from = $from;

			return $from;
		});

		return new ChatDeclaration($publisher, $accounts, $actors, $policies, new NullLogger());
	}

	private static function session(): ClientSession {
		return new ClientSession('alice', new Identity(1, 'https://social.test/users/alice', self::ALICE, 'alice.social.test', 'sealed', '', '', Identity::STATE_ACTIVE, '', 0), 'jti');
	}

	public function testTheRecordFitsItsLexicon(): void {
		$lexicon = new Lexicon([__DIR__ . '/../../../lib/Atproto/lexicons']);
		foreach (NotificationPolicyService::DIRECT_FROM as $from) {
			$this->assertTrue($lexicon->fits(ChatDeclaration::record($from)), $from);
		}
		$this->assertSame(['$type' => 'chat.bsky.actor.declaration', 'allowIncoming' => 'none'], ChatDeclaration::record('none'));
	}

	public function testAChangeHereIsWritten(): void {
		$this->declaration()->changed('alice', 'none');

		$this->assertSame([ChatDeclaration::record('none')], $this->written);
	}

	public function testTheFirstReadOnlyNarrowsWhatBlueskyAllowsByDefault(): void {
		foreach (['all', 'following'] as $from) {
			$this->from = $from;
			$this->declaration()->ensure(self::session()->identity);
		}
		$this->assertSame([], $this->written, 'nobody is opened to strangers on Bluesky unasked');

		$this->from = 'none';
		$this->declaration()->ensure(self::session()->identity);
		$this->has = true;
		$this->declaration()->ensure(self::session()->identity);

		$this->assertSame([ChatDeclaration::record('none')], $this->written, 'a record already there is left as it is');
	}

	public function testWhatAnAppDeclaresIsTheSettingHere(): void {
		$declaration = $this->declaration();
		$declaration->fromApp(self::session(), 'self', ['$type' => ChatDeclaration::COLLECTION, 'allowIncoming' => 'none']);
		$declaration->fromApp(self::session(), 'self', ['$type' => ChatDeclaration::COLLECTION, 'allowIncoming' => 'none']);

		$this->assertSame(['none', 'none'], $this->saved);
		$this->assertSame([ChatDeclaration::record('none')], $this->written, 'unchanged here, so the record is written for the app');
	}

	public function testAnAppsDeclarationOutsideTheThreeAnswersIsRefused(): void {
		$declaration = $this->declaration();
		foreach ([['other', 'all'], ['self', 'friends'], ['self', null]] as [$rkey, $from]) {
			try {
				$declaration->fromApp(self::session(), $rkey, ['allowIncoming' => $from]);
				$this->fail('accepted ' . $rkey . ' ' . var_export($from, true));
			} catch (XrpcException) {
			}
		}
		$this->assertSame([], $this->saved);
	}
}
