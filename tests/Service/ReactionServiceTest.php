<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use Exception;
use OCA\Social\AP;
use OCA\Social\Db\ReactionsRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Object\EmojiReact;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\CustomEmoji;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\EmojiService;
use OCA\Social\Service\ModerationService;
use OCA\Social\Service\ReactionService;
use OCA\Social\Service\SignatureService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;

/**
 * What the app will accept as a reaction, and what a reaction bar comes out
 * looking like.
 *
 * The input test decides what a peer may put in a reaction bar on somebody's
 * timeline: Unicode emoji and a bounded shortcode label are allowed, while
 * arbitrary text and markup are refused.
 */
class ReactionServiceTest extends TestCase {
	protected function tearDown(): void {
		AP::set(null);
	}

	public static function acceptedProvider(): array {
		return [
			'a plain emoji' => ['👍'],
			'a heart with its variation selector' => ['❤️'],
			'a flag, which is two regional indicators' => ['🇩🇪'],
			'a skin tone modifier' => ['👍🏽'],
			'a zero-width-joiner family' => ['👨‍👩‍👧'],
			'a rainbow flag, joiner and all' => ['🏳️‍🌈'],
			'space around it is trimmed' => [' 🎉 '],
			'a bounded custom emoji label' => [':blobcat:'],
			'a remote custom emoji label qualified by its host' => [':blobcat@misskey.example:'],
		];
	}

	#[DataProvider('acceptedProvider')]
	public function testAReactionLabelIsAccepted(string $emoji): void {
		$this->assertTrue(ReactionService::isUsableEmoji($emoji));
	}

	public static function refusedProvider(): array {
		return [
			'nothing at all' => [''],
			'only space' => ['   '],
			'an empty shortcode' => ['::'],
			'a shortcode with a space' => [':blob cat:'],
			'a malformed remote shortcode host' => [':blobcat@-misskey.example:'],
			'a word' => ['nice'],
			'a single letter' => ['a'],
			'a digit' => ['7'],
			'a sentence' => ['this is not an emoji'],
			'an emoji with a word stuck to it' => ['👍 nice'],
			'punctuation' => ['!'],
			'a keycap, which carries a digit' => ['1️⃣'],
			'an html fragment' => ['<b>'],
			'longer than the column' => [str_repeat('👍', 20)],
		];
	}

	public function testTheUnicodeOnlyOutboundGuardDoesNotAcceptShortcodesWithoutMetadata(): void {
		$this->assertFalse(ReactionService::isUsableOutboundEmoji(':blobcat:'));
		$this->assertFalse(ReactionService::isUsableOutboundEmoji(':blobcat@misskey.example:'));
		$this->assertTrue(ReactionService::isUsableOutboundEmoji('👍'));
	}

	public function testAnInstanceOwnedCustomEmojiIsPublishedWithItsVerifiedTag(): void {
		$args = [];
		foreach ((new ReflectionClass(AP::class))->getConstructor()->getParameters() as $parameter) {
			$class = $parameter->getType()->getName();
			$mock = $this->createStub($class);
			if ($class === ConfigService::class) {
				$mock->method('getCloudUrl')->willReturn('https://social.example');
			}
			$args[] = $mock;
		}
		AP::set(new AP(...$args));

		$streams = $this->createMock(StreamRequest::class);
		$post = new Stream();
		$post->setId('https://remote.example/posts/1');
		$post->setAttributedTo('https://remote.example/users/bob');
		$streams->method('getStreamById')->with($post->getId(), true)->willReturn($post);
		$reactions = $this->createStub(ReactionsRequest::class);
		$signature = $this->createMock(SignatureService::class);
		$signature->expects($this->once())->method('signObject')->willReturnCallback(
			function (Person $signedBy, ACore $signed): void {
				$this->assertInstanceOf(Person::class, $signedBy);
				$this->assertSame(':blobcat:', $signed->getTags()[0]['name'] ?? '');
			}
		);
		$activity = $this->createMock(ActivityService::class);
		$activity->expects($this->once())->method('request')->willReturn('delivery-token');
		$actors = $this->createStub(CacheActorService::class);
		$actors->method('getFromId')->willThrowException(new Exception('use the target actor id as inbox'));
		$moderation = $this->createStub(ModerationService::class);
		$emoji = $this->createMock(EmojiService::class);
		$emoji->expects($this->once())->method('byShortcode')->with('blobcat')->willReturn(
			(new CustomEmoji('blobcat', 'blobcat.png', 'image/png'))->setUrl('https://social.example/emoji/blobcat')
		);
		$service = new ReactionService(
			$reactions,
			$streams,
			$signature,
			$activity,
			$actors,
			$moderation,
			new NullLogger(),
			$emoji,
		);
		$actor = new Person();
		$actor->setId('https://social.example/users/alice');

		$reaction = $service->create($actor, $post->getId(), ':blobcat:');

		$this->assertInstanceOf(EmojiReact::class, $reaction);
		$this->assertSame(':blobcat:', $reaction->getContent());
		$this->assertSame([[
			'type' => 'Emoji',
			'name' => ':blobcat:',
			'icon' => ['type' => 'Image', 'mediaType' => 'image/png', 'url' => 'https://social.example/emoji/blobcat'],
		]], $reaction->getTags());
	}

	#[DataProvider('refusedProvider')]
	public function testSomethingThatIsNotAnEmojiIsRefused(string $emoji): void {
		$this->assertFalse(ReactionService::isUsableEmoji($emoji));
	}

	/**
	 * A run of symbols that is not text is still not an emoji: `+` and `=` are
	 * neither letters nor punctuation to Unicode, and without the second test
	 * `+=` would be a reaction.
	 */
	public function testARunOfSymbolsIsNotAnEmoji(): void {
		$this->assertFalse(ReactionService::isUsableEmoji('+='));
		$this->assertFalse(ReactionService::isUsableEmoji('^'));
	}

}
