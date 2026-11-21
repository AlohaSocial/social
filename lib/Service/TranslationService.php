<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use OCA\Social\Exceptions\TranslationUnavailableException;
use OCA\Social\Model\ActivityPub\Object\Question;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Translation;
use OCP\TaskProcessing\Exception\Exception as TaskProcessingException;
use OCP\TaskProcessing\IManager;
use OCP\TaskProcessing\ShapeEnumValue;
use OCP\TaskProcessing\Task;
use OCP\TaskProcessing\TaskTypes\TextToTextTranslate;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Translating a status through this Nextcloud's translation provider; with
 * none, `translation.enabled` is false and the route answers 503, never the
 * original text.
 *
 * The body and content warning are always translated, then poll options and
 * alt texts up to `MAX_TEXTS` (each text is a provider task). Texts past the
 * bound are left out of the entity, as Mastodon's Translation lists only what
 * was translated.
 */
class TranslationService {
	/**
	 * How many texts one translation request may send to the provider; two go
	 * to the body and the warning.
	 */
	public const MAX_TEXTS = 12;

	/** What a source language of "work it out yourself" is called, by provider. */
	private const DETECT_VALUES = ['detect_language', 'auto', 'auto_detect', 'autodetect'];

	/** @var array<string, list<string>>|null the enum values, read once per request */
	private ?array $enumValues = null;

	public function __construct(
		private IManager $taskManager,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * Whether anything on this server can translate, asked of the task type
	 * since other providers (speech-to-text) do not count.
	 */
	public function isAvailable(): bool {
		try {
			return in_array(TextToTextTranslate::ID, $this->taskManager->getAvailableTaskTypeIds(), true);
		} catch (Throwable $e) {
			// a provider that throws while listing itself is not one to offer
			$this->logger->debug('[TranslationService] could not list task types', ['exception' => $e]);

			return false;
		}
	}

	/**
	 * Mastodon's `/api/v1/instance/translation_languages`: for each source
	 * language, the target languages, from the provider's enum values. A provider
	 * that only auto-detects yields an empty object.
	 *
	 * @return array<string, list<string>>
	 */
	public function languages(): array {
		$sources = $this->concrete($this->values('origin_language'));
		$targets = $this->concrete($this->values('target_language'));

		$languages = [];
		foreach ($sources as $source) {
			$to = array_values(array_filter($targets, static fn (string $t): bool => $t !== $source));
			if ($to !== []) {
				$languages[$source] = $to;
			}
		}

		return $languages;
	}

	/**
	 * One status, in the reader's language.
	 *
	 * @throws TranslationUnavailableException when no provider answered
	 */
	public function translateStatus(Stream $post, string $target, ?string $userId): Translation {
		if (!$this->isAvailable()) {
			throw new TranslationUnavailableException('no translation provider is configured');
		}

		$source = $this->sourceFor($post->getLanguage());
		$budget = self::MAX_TEXTS;

		$translation = new Translation();
		$translation->setDetectedSourceLanguage(
			($post->getLanguage() !== '') ? $post->getLanguage() : 'und'
		);
		$translation->setProvider($this->providerName());

		$translation->setContent($this->run($post->getContent(), $source, $target, $userId));
		$budget--;

		if ($post->getSpoilerText() !== '') {
			$translation->setSpoilerText($this->run($post->getSpoilerText(), $source, $target, $userId));
			$budget--;
		}

		if ($post instanceof Question) {
			$options = [];
			foreach ($post->getOptions() as $option) {
				$title = (string)($option['title'] ?? '');
				if ($budget < 1 || $title === '') {
					break;
				}

				$options[] = ['title' => $this->run($title, $source, $target, $userId)];
				$budget--;
			}

			if ($options !== []) {
				// The poll's id is the status's, as `Question::jsonSerialize()` serves it,
				// so a client can match the options.
				$translation->setPoll((string)$post->getNid(), $options);
			}
		}

		$attachments = [];
		foreach ($post->getAttachments() as $attachment) {
			$description = $attachment->getDescription();
			if ($budget < 1 || $description === '') {
				break;
			}

			$attachments[] = [
				'id' => $attachment->getId(),
				'description' => $this->run($description, $source, $target, $userId),
			];
			$budget--;
		}
		$translation->setMediaAttachments($attachments);

		return $translation;
	}

	/**
	 * One text through the provider. A failure fails the whole request, so a
	 * partly translated post cannot pass for a translated one.
	 *
	 * @throws TranslationUnavailableException
	 */
	private function run(string $text, string $source, string $target, ?string $userId): string {
		if (trim($text) === '') {
			return $text;
		}

		$task = new Task(
			TextToTextTranslate::ID,
			[
				'input' => $text,
				'origin_language' => $source,
				'target_language' => $target,
			],
			'social',
			$userId
		);

		try {
			$finished = $this->taskManager->runTask($task);
		} catch (TaskProcessingException|Throwable $e) {
			$this->logger->warning('[TranslationService] the translation provider failed', [
				'exception' => $e,
			]);

			throw new TranslationUnavailableException('the translation provider failed');
		}

		if ($finished->getStatus() !== Task::STATUS_SUCCESSFUL) {
			throw new TranslationUnavailableException(
				$finished->getErrorMessage() ?? 'the translation provider failed'
			);
		}

		$output = $finished->getOutput()['output'] ?? '';
		if (!is_string($output) || $output === '') {
			throw new TranslationUnavailableException('the translation provider returned nothing');
		}

		return $output;
	}

	/**
	 * What to tell the provider the post is written in: the post's declared
	 * language if offered, else auto-detection, else the declared language
	 * anyway for a provider with an open enum.
	 */
	private function sourceFor(string $language): string {
		$offered = $this->values('origin_language');

		if ($language !== '' && in_array($language, $offered, true)) {
			return $language;
		}

		foreach ($offered as $value) {
			if (in_array(strtolower($value), self::DETECT_VALUES, true)) {
				return $value;
			}
		}

		return $language;
	}

	/** The provider's name, for the `provider` field, or its id, or nothing. */
	private function providerName(): string {
		try {
			return $this->taskManager->getPreferredProvider(TextToTextTranslate::ID)->getName();
		} catch (Throwable $e) {
			return '';
		}
	}

	/**
	 * The values one enum input of the translate task accepts.
	 *
	 * @return list<string>
	 */
	private function values(string $key): array {
		if ($this->enumValues === null) {
			$this->enumValues = $this->readEnumValues();
		}

		return $this->enumValues[$key] ?? [];
	}

	/**
	 * @return array<string, list<string>>
	 */
	private function readEnumValues(): array {
		try {
			$types = $this->taskManager->getAvailableTaskTypes();
		} catch (Throwable $e) {
			$this->logger->debug('[TranslationService] could not read task types', ['exception' => $e]);

			return [];
		}

		$enums = $types[TextToTextTranslate::ID]['inputShapeEnumValues'] ?? [];

		$values = [];
		foreach ($enums as $key => $entries) {
			$values[(string)$key] = array_values(
				array_filter(
					array_map(
						static fn (mixed $entry): string
							=> ($entry instanceof ShapeEnumValue) ? $entry->getValue() : '',
						is_array($entries) ? $entries : []
					),
					static fn (string $value): bool => $value !== ''
				)
			);
		}

		return $values;
	}

	/**
	 * The language codes among a list of enum values: the ones that name a
	 * language, rather than the pseudo-value that means "detect it".
	 *
	 * @param list<string> $values
	 * @return list<string>
	 */
	private function concrete(array $values): array {
		return array_values(
			array_filter(
				$values,
				static fn (string $value): bool
					=> !in_array(strtolower($value), self::DETECT_VALUES, true)
			)
		);
	}
}
