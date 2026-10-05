<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\MoveInService;
use OCP\Server;
use PHPUnit\Framework\TestCase;

/**
 * Moving here from nothing but the old handle, against the real Mastodon.
 *
 * The `Move` tests cover what the protocol carries, the followers. This
 * covers what the wizard does before that from the new side: read the old
 * account's public `following` and `outbox` and bring both over. Mastodon's
 * `interop` account is the old one — it follows `admin` from the delivery
 * suite, and this test has it write a post first, so both collections have
 * something in them. Its own local user, because a move-in names the old
 * account as an alias of the new one.
 */
class MoveInFromMastodonTest extends TestCase {
	private const USER = 'puller';
	private const SOURCE = 'interop';

	private Mastodon $mastodon;
	private Person $actor;

	protected function setUp(): void {
		$mastodon = Mastodon::fromEnvironment();
		if ($mastodon === null) {
			$this->markTestSkipped('no Mastodon to talk to: set MASTODON_BASE_URL and MASTODON_TOKEN');
		}
		$this->mastodon = $mastodon;
		$this->actor = Server::get(AccountService::class)->getActorFromUserId(self::USER, true);
	}

	public function testTheOldAccountIsReadAndItsFollowsAndPostsComeOver(): void {
		$host = (string)getenv('MASTODON_HOST');
		$handle = '@' . self::SOURCE . '@' . $host;
		$text = 'A post to bring over ' . bin2hex(random_bytes(4));
		$this->mastodon->post('/api/v1/statuses', ['status' => $text, 'visibility' => 'public']);

		$moveIn = Server::get(MoveInService::class);

		$seen = $moveIn->inspect($handle);
		$this->assertSame(self::SOURCE . '@' . $host, $seen['acct']);
		$this->assertTrue($seen['following']['readable'], 'Mastodon publishes the following collection unless told to hide it');
		$this->assertTrue($seen['posts']['readable'], 'Mastodon publishes the outbox');
		$this->assertGreaterThan(0, $seen['posts']['total']);

		$options = $moveIn->prepare(self::USER, $handle, true, true, false);
		$this->assertContains(
			'https://' . $host . '/users/' . self::SOURCE,
			Server::get(AccountService::class)->getActorFromUserId(self::USER)->getAlsoKnownAs(),
			'the old account is named as an alias before anything else'
		);

		$report = $moveIn->run($this->actor, $options, static function (): void {
		});

		$this->assertTrue($report['following_readable']);
		$this->assertTrue($report['posts_readable']);
		$this->assertGreaterThanOrEqual(1, $report['followed'], 'interop follows admin, so at least admin is followed from here');
		$this->assertSame([], $report['failures'], 'every account the old one follows could be followed');
		$this->assertGreaterThanOrEqual(1, $report['imported']);
		$this->assertSame([], $report['post_failures']);

		// the copy is here, written by the new account, with its words
		$options = new ProbeOptions();
		$options->setFormat(ACore::FORMAT_ACTIVITYPUB)
			->setProbe(ProbeOptions::ACCOUNT)
			->setAccountId($this->actor->getId())
			->setLimit(50);
		$streamRequest = Server::get(StreamRequest::class);
		$streamRequest->setViewer($this->actor);
		$copied = array_filter(
			$streamRequest->getTimeline($options),
			static fn ($post): bool => str_contains((string)$post->getContent(), $text)
		);
		$this->assertCount(1, $copied, 'the post written on Mastodon a moment ago is here as a copy');
		$this->assertSame($this->actor->getId(), reset($copied)->getAttributedTo());
	}
}
