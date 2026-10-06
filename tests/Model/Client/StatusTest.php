<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model\Client;

use OCA\Social\Model\Client\Status;
use PHPUnit\Framework\TestCase;

class StatusTest extends TestCase {
	public function testImportReadsTheMastodonPostStatusesParameters(): void {
		$status = new Status();

		$result = $status->import([
			'status' => 'Hello @bob #nextcloud',
			'visibility' => 'unlisted',
			'spoiler_text' => 'cw',
			'sensitive' => 'true',
			'media_ids' => ['12', '13'],
			'in_reply_to_id' => '99',
			'language' => 'de',
		]);

		$this->assertSame($status, $result);
		$this->assertSame('Hello @bob #nextcloud', $status->getStatus());
		$this->assertSame('unlisted', $status->getVisibility());
		$this->assertSame('cw', $status->getSpoilerText());
		$this->assertTrue($status->isSensitive());
		$this->assertSame([12, 13], $status->getMediaIds());
		$this->assertSame(99, $status->getInReplyToId());
		$this->assertSame('de', $status->getLanguage());
	}

	public function testImportAcceptsJsonEncodedMediaIdsAndBooleanSensitive(): void {
		$status = new Status();

		$status->import(['status' => 'x', 'sensitive' => false, 'media_ids' => '["7"]']);

		$this->assertFalse($status->isSensitive());
		$this->assertSame([7], $status->getMediaIds());
	}

	public function testImportOfAnEmptyRequestKeepsTheDefaults(): void {
		$status = new Status();

		$status->import([]);

		$this->assertSame('', $status->getStatus());
		$this->assertSame('', $status->getVisibility());
		$this->assertSame('', $status->getSpoilerText());
		$this->assertFalse($status->isSensitive());
		$this->assertSame([], $status->getMediaIds());
		$this->assertSame(0, $status->getInReplyToId());
		$this->assertSame('', $status->getLanguage());
	}

	public function testJsonSerializeExposesTheImportedValues(): void {
		$status = new Status();
		$status->setStatus('hi')
			->setVisibility('public')
			->setSpoilerText('')
			->setSensitive(true)
			->setMediaIds(['3'])
			->setLanguage('en');

		$this->assertSame([
			'sensitive' => true,
			'mediaIds' => [3],
			'visibility' => 'public',
			'spoilerText' => '',
			'language' => 'en',
			'status' => 'hi',
		], $status->jsonSerialize());
	}

	public function testImportReadsTheLanguage(): void {
		$status = new Status();

		$status->import(['status' => 'Hallo', 'language' => 'de']);

		$this->assertSame('de', $status->getLanguage());
	}

	public function testAnUnusableLanguageIsDroppedSoTheDefaultApplies(): void {
		$status = new Status();

		$status->import(['status' => 'x', 'language' => 'not a language']);

		$this->assertSame('', $status->getLanguage());
	}

	public function testALanguageIsNormalisedTheWayItIsFederated(): void {
		$this->assertSame('pt-BR', (new Status())->import(['language' => 'pt_br'])->getLanguage());
	}

	public function testANullLanguageIsNoLanguage(): void {
		$this->assertSame('', (new Status())->import(['language' => null])->getLanguage());
	}
}
