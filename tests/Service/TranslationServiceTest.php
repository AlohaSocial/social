<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Exceptions\TranslationUnavailableException;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\Client\MediaAttachment;
use OCA\Social\Service\TranslationService;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\IProvider;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToTextTranslate;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Translating a status.
 *
 * The behaviour worth pinning is the one the route used to get wrong: when
 * there is no provider, nothing is translated and the caller is told so. A
 * service that answers with the original text on failure is indistinguishable
 * from one that worked, which is exactly how the old stub survived.
 */
class TranslationServiceTest extends TestCase {
	private IManager|Stub $taskManager;
	private TranslationService $service;
	/** @var array<int, array<string, mixed>> what was asked of the provider */
	private array $asked = [];

	protected function setUp(): void {
		$this->taskManager = $this->createStub(IManager::class);
		$this->service = new TranslationService($this->taskManager, new NullLogger());
	}

	/** Answers every translate task with the text, marked. */
	private function providerTranslates(): void {
		$this->taskManager->method('getAvailableTaskTypeIds')
			->willReturn([TextToTextTranslate::ID]);
		$this->taskManager->method('runTask')
			->willReturnCallback(function (Task $task): Task {
				$this->asked[] = $task->getInput();
				$task->setStatus(Task::STATUS_SUCCESSFUL);
				$task->setOutput(['output' => '[' . $task->getInput()['input'] . ']']);

				return $task;
			});
	}

	private function note(string $content, string $language = 'de'): Note {
		$note = new Note();
		$note->setContent($content);
		$note->setLanguage($language);

		return $note;
	}

	public function testWithoutAProviderNothingIsTranslated(): void {
		$this->taskManager->method('getAvailableTaskTypeIds')->willReturn([]);

		$this->expectException(TranslationUnavailableException::class);
		$this->service->translateStatus($this->note('Guten Morgen'), 'en', 'alice');
	}

	/**
	 * A Nextcloud with a speech-to-text provider and nothing else cannot
	 * translate, and `hasProviders()` would have said it could.
	 */
	public function testAnUnrelatedProviderIsNotATranslator(): void {
		$this->taskManager->method('getAvailableTaskTypeIds')
			->willReturn(['core:audio2text']);

		$this->assertFalse($this->service->isAvailable());
	}

	public function testTheBodyIsTranslated(): void {
		$this->providerTranslates();

		$translation = $this->service->translateStatus($this->note('Guten Morgen'), 'en', 'alice');

		$this->assertSame('[Guten Morgen]', $translation->getContent());
		$this->assertSame('de', $translation->getDetectedSourceLanguage());
		$this->assertSame('en', $this->asked[0]['target_language']);
	}

	/**
	 * The post says what language it is in, and that beats asking a provider
	 * to guess — but only when the provider offers that language.
	 */
	public function testTheDeclaredLanguageIsUsedWhenTheProviderOffersIt(): void {
		$this->providerTranslates();
		$this->taskManager->method('getAvailableTaskTypes')->willReturn([
			TextToTextTranslate::ID => [
				'inputShapeEnumValues' => [
					'origin_language' => [
						new ShapeEnumValue('Detect', 'detect_language'),
						new ShapeEnumValue('German', 'de'),
					],
					'target_language' => [new ShapeEnumValue('English', 'en')],
				],
			],
		]);

		$this->service->translateStatus($this->note('Guten Morgen'), 'en', 'alice');

		$this->assertSame('de', $this->asked[0]['origin_language']);
	}

	public function testAnUnofferedLanguageFallsBackToDetection(): void {
		$this->providerTranslates();
		$this->taskManager->method('getAvailableTaskTypes')->willReturn([
			TextToTextTranslate::ID => [
				'inputShapeEnumValues' => [
					'origin_language' => [new ShapeEnumValue('Detect', 'detect_language')],
					'target_language' => [new ShapeEnumValue('English', 'en')],
				],
			],
		]);

		$this->service->translateStatus($this->note('Guten Morgen', 'fi'), 'en', 'alice');

		$this->assertSame('detect_language', $this->asked[0]['origin_language']);
	}

	public function testTheContentWarningAndAltTextsAreTranslatedToo(): void {
		$this->providerTranslates();

		$note = $this->note('Guten Morgen');
		$note->setSpoilerText('Politik');
		$attachment = new MediaAttachment();
		$attachment->setId('7')->setDescription('Ein Hund');
		$note->setAttachments([$attachment]);

		$translation = $this->service->translateStatus($note, 'en', 'alice');
		$serialised = $translation->jsonSerialize();

		$this->assertSame('[Politik]', $translation->getSpoilerText());
		$this->assertSame(
			[['id' => '7', 'description' => '[Ein Hund]']], $serialised['media_attachments']
		);
	}

	/** An attachment with no alt text has nothing to translate and is left out. */
	public function testAnUndescribedAttachmentIsNotListed(): void {
		$this->providerTranslates();

		$note = $this->note('Guten Morgen');
		$note->setAttachments([(new MediaAttachment())->setId('7')]);

		$this->assertSame([], $this->service->translateStatus($note, 'en', 'alice')->jsonSerialize()['media_attachments']);
	}

	public function testPollOptionsAreTranslatedUnderTheStatusId(): void {
		$this->providerTranslates();

		$question = new Question();
		$question->setContent('Welche?')->setLanguage('de');
		$question->setNid(42);
		$question->setPollData(['Rot', 'Blau'], false, 3600);

		$poll = $this->service->translateStatus($question, 'en', 'alice')->jsonSerialize()['poll'];

		$this->assertSame('42', $poll['id']);
		$this->assertSame([['title' => '[Rot]'], ['title' => '[Blau]']], $poll['options']);
	}

	/**
	 * Every text is a round-trip to the provider, so one request cannot be
	 * allowed to make twenty of them. What is past the bound is left out of
	 * the entity rather than returned untranslated — a client then shows the
	 * original, which is true, instead of the original labelled as a
	 * translation, which is not.
	 */
	public function testTheNumberOfTextsIsBounded(): void {
		$this->providerTranslates();

		$note = $this->note('Guten Morgen');
		$attachments = [];
		for ($i = 0; $i < 20; $i++) {
			$attachments[] = (new MediaAttachment())->setId((string)$i)->setDescription('Bild ' . $i);
		}
		$note->setAttachments($attachments);

		$translated = $this->service->translateStatus($note, 'en', 'alice')->jsonSerialize();

		$this->assertCount(TranslationService::MAX_TEXTS - 1, $translated['media_attachments']);
		$this->assertCount(TranslationService::MAX_TEXTS, $this->asked);
	}

	public function testAFailedTaskFailsTheRequest(): void {
		$this->taskManager->method('getAvailableTaskTypeIds')
			->willReturn([TextToTextTranslate::ID]);
		$this->taskManager->method('runTask')
			->willReturnCallback(static function (Task $task): Task {
				$task->setStatus(Task::STATUS_FAILED);

				return $task;
			});

		$this->expectException(TranslationUnavailableException::class);
		$this->service->translateStatus($this->note('Guten Morgen'), 'en', 'alice');
	}

	/**
	 * The languages the instance advertises are the provider's own, not a list
	 * written here: a source language is never offered as its own target.
	 */
	public function testAdvertisedLanguagesComeFromTheProvider(): void {
		$this->taskManager->method('getAvailableTaskTypes')->willReturn([
			TextToTextTranslate::ID => [
				'inputShapeEnumValues' => [
					'origin_language' => [
						new ShapeEnumValue('Detect', 'detect_language'),
						new ShapeEnumValue('German', 'de'),
						new ShapeEnumValue('English', 'en'),
					],
					'target_language' => [
						new ShapeEnumValue('German', 'de'),
						new ShapeEnumValue('English', 'en'),
					],
				],
			],
		]);

		$this->assertSame(['de' => ['en'], 'en' => ['de']], $this->service->languages());
	}

	public function testWithoutAProviderNoLanguagesAreAdvertised(): void {
		$this->taskManager->method('getAvailableTaskTypes')->willReturn([]);

		$this->assertSame([], $this->service->languages());
	}

	public function testTheProviderIsNamed(): void {
		$this->providerTranslates();
		$provider = $this->createStub(IProvider::class);
		$provider->method('getName')->willReturn('DeepL');
		$this->taskManager->method('getPreferredProvider')->willReturn($provider);

		$this->assertSame(
			'DeepL', $this->service->translateStatus($this->note('Hallo'), 'en', 'alice')->getProvider()
		);
	}
}
