<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Service\AiContentService;
use OCA\Social\Service\ConfigService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * What counts as a post made with AI, and who wants those kept out.
 *
 * The detection is deliberately about labels — a hashtag, a provenance mark
 * on a picture — and nothing else, so what is asserted here is the matching:
 * that the defaults are there, that an administrator's additions join them
 * under the same normalisation, and that a boost is read as the post it
 * boosts.
 */
class AiContentServiceTest extends TestCase {
	private ConfigService|Stub $configService;
	private AiContentService $service;
	/** @var array<string, string> user id . key => stored value */
	private array $userValues = [];
	/** @var array<string, string> app values */
	private array $appValues = [];

	protected function setUp(): void {
		$this->configService = $this->createStub(ConfigService::class);
		$this->configService->method('getUserValue')
			->willReturnCallback(fn (string $key, string $userId = '', string $app = ''): string
				=> $this->userValues[$userId . '/' . $key] ?? '');
		$this->configService->method('setValueForUser')
			->willReturnCallback(function (string $userId, string $key, string $value): void {
				$this->userValues[$userId . '/' . $key] = $value;
			});
		$this->configService->method('getAppValue')
			->willReturnCallback(fn (string $key): string => $this->appValues[$key] ?? '');

		$this->service = new AiContentService($this->configService);
	}

	// the tag set

	public function testTheDefaultsAreTheListWhenTheAdministratorAddedNothing(): void {
		$this->assertSame(AiContentService::DEFAULT_TAGS, $this->service->tags());
	}

	public function testTheDefaultsAreLowerCaseAndBare(): void {
		foreach (AiContentService::DEFAULT_TAGS as $tag) {
			$this->assertSame(AiContentService::normalise($tag), $tag, $tag . ' is not stored normalised');
		}
	}

	public function testTheAdministratorsTagsJoinTheDefaults(): void {
		$this->appValues[ConfigService::SOCIAL_AI_TAGS] = ' #Synthetic, aislop ,, AIGENERATED,firefly';

		$tags = $this->service->tags();

		foreach (AiContentService::DEFAULT_TAGS as $tag) {
			$this->assertContains($tag, $tags);
		}
		// normalised like a stored hashtag: no '#', no case, no padding
		$this->assertContains('synthetic', $tags);
		$this->assertContains('aislop', $tags);
		$this->assertContains('firefly', $tags);
		// a default named again is one entry, not two
		$this->assertSame(1, count(array_keys($tags, 'aigenerated', true)));
		$this->assertNotContains('', $tags);
	}

	public function testTheTagSetIsReadOncePerRequest(): void {
		$this->appValues[ConfigService::SOCIAL_AI_TAGS] = 'synthetic';
		$this->service->tags();
		$this->appValues[ConfigService::SOCIAL_AI_TAGS] = 'another';

		$this->assertContains('synthetic', $this->service->tags());
		$this->assertNotContains('another', $this->service->tags());
	}

	// isLabelled, on an exported status

	public function testAStatusTaggedWithADefaultIsLabelledWhateverTheCase(): void {
		foreach (['aigenerated', 'AIgenerated', '#MidJourney', 'Sora'] as $name) {
			$this->assertTrue(
				$this->service->isLabelled(['tags' => [['name' => $name, 'url' => '']]]), $name
			);
		}
	}

	public function testAStatusTaggedWithAnAdministratorsTagIsLabelled(): void {
		$this->appValues[ConfigService::SOCIAL_AI_TAGS] = 'synthetic';

		$this->assertTrue($this->service->isLabelled(['tags' => [['name' => 'Synthetic', 'url' => '']]]));
	}

	public function testAnOrdinaryStatusIsNotLabelled(): void {
		$this->assertFalse($this->service->isLabelled([
			'content' => '<p>a photo of my cat, who is real</p>',
			'tags' => [['name' => 'cats', 'url' => ''], ['name' => 'aisle', 'url' => '']],
			'media_attachments' => [['id' => '1', 'ai_generated' => false]],
			'reblog' => null,
		]));
	}

	public function testAStatusWhosePictureStatesProvenanceIsLabelled(): void {
		$this->assertTrue($this->service->isLabelled([
			'tags' => [],
			'media_attachments' => [
				['id' => '1', 'ai_generated' => false],
				['id' => '2', 'ai_generated' => true],
			],
		]));
	}

	public function testAnAttachmentIsOnlyLabelledByATrueBoolean(): void {
		// '1' or 1 off a stale cache is not the contract; the exporter emits a bool
		$this->assertFalse($this->service->isLabelled(['media_attachments' => [['ai_generated' => '1']]]));
		$this->assertFalse($this->service->isLabelled(['media_attachments' => [['ai_generated' => 1]]]));
	}

	public function testABoostIsLabelledByThePostItBoosts(): void {
		$boost = [
			'id' => '9',
			'tags' => [],
			'media_attachments' => [],
			'reblog' => ['id' => '3', 'tags' => [['name' => 'aiart', 'url' => '']]],
		];

		$this->assertTrue($this->service->isLabelled($boost));
		$this->assertFalse($this->service->isLabelled(array_merge($boost, ['reblog' => ['id' => '3', 'tags' => []]])));
	}

	public function testAStatusAlreadyMarkedByItsExporterIsLabelled(): void {
		$this->assertTrue($this->service->isLabelled(['ai_generated' => true, 'tags' => []]));
	}

	public function testAStatusWithNoTagsAndNoAttachmentsIsNotLabelled(): void {
		$this->assertFalse($this->service->isLabelled([]));
		$this->assertFalse($this->service->isLabelled(['id' => '1']));
	}

	// labelsPost, on the model

	public function testANoteIsLabelledByItsHashtags(): void {
		$note = (new Note())->setHashtags(['cats', 'StableDiffusion']);

		$this->assertTrue($this->service->labelsPost($note));
		$this->assertFalse($this->service->labelsPost((new Note())->setHashtags(['cats'])));
		$this->assertFalse($this->service->labelsPost(new Note()));
	}

	public function testABoostIsLabelledByTheNoteItBoosts(): void {
		$boost = new Announce();
		$boost->setObject((new Note())->setHashtags(['#GenAI']));

		$this->assertTrue($this->service->labelsPost($boost));

		$plain = new Announce();
		$plain->setObject((new Note())->setHashtags(['cats']));
		$this->assertFalse($this->service->labelsPost($plain));
	}

	// the switch

	public function testTheSwitchIsOffUntilTurnedOn(): void {
		$this->assertFalse($this->service->hides('alice'));
		$this->assertSame(['hide' => false], $this->service->export('alice'));
	}

	public function testTurningTheSwitchOnIsStoredAndReadBack(): void {
		$this->service->setHides('alice', true);

		$this->assertSame('1', $this->userValues['alice/' . AiContentService::USER_KEY]);
		$this->assertTrue($this->service->hides('alice'));
		$this->assertSame(['hide' => true], $this->service->export('alice'));

		$this->assertTrue((new AiContentService($this->configService))->hides('alice'), 'a fresh request reads it back');
	}

	public function testTurningTheSwitchOffIsStoredAsOffRatherThanForgotten(): void {
		$this->service->setHides('alice', true);
		$this->service->setHides('alice', false);

		$this->assertSame('0', $this->userValues['alice/' . AiContentService::USER_KEY]);
		$this->assertFalse($this->service->hides('alice'));
	}

	public function testOneReadersSwitchIsNotAnothers(): void {
		$this->service->setHides('alice', true);

		$this->assertFalse($this->service->hides('bob'));
	}

	public function testNobodyHidesNothing(): void {
		$this->userValues['/' . AiContentService::USER_KEY] = '1';

		$this->assertFalse($this->service->hides(''), 'an anonymous reader has no switch');
	}
}
