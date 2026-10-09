<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\OAuth;

use OCA\Social\Atproto\OAuth\PermissionDescriber;
use OCA\Social\Atproto\OAuth\PermissionSets;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCP\IL10N;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class PermissionDescriberTest extends TestCase {
	public function testEachScopeIsALineAndASetItsOwnTitleWithWhatItHolds(): void {
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$config = $this->createMock(AtprotoConfig::class);
		$config->method('appViewDid')->willReturn('did:web:api.bsky.app');
		$sets = $this->createMock(PermissionSets::class);
		$sets->method('resolve')->willReturn(['title' => 'Posting', 'detail' => 'Write posts', 'title_lang' => ['de' => 'Beiträge'], 'detail_lang' => [], 'permissions' => []]);
		$sets->method('expand')->willReturn([['resource' => 'repo', 'collection' => ['app.bsky.feed.post'], 'action' => ['create', 'update', 'delete']]]);

		$lines = (new PermissionDescriber($l10n, $config, $sets))->describe([
			'atproto', 'repo:app.bsky.feed.like?action=create', 'rpc:app.bsky.feed.getTimeline?aud=did:web:api.bsky.app%23bsky_appview',
			'blob:image/*', 'account:email', 'include:app.example.authPosting', 'mystery:scope',
		], 'de');

		$this->assertSame([
			['Know which account you are', false],
			['Create: likes', true],
			['Use app.bsky.feed.getTimeline at Bluesky, as you', false],
			['Upload pictures', true],
			['See your e-mail address', false],
			['Beiträge', true],
			['mystery:scope', true],
		], array_map(static fn (array $line): array => [$line['label'], $line['writes']], $lines));
		$this->assertSame(['Create, change and delete posts'], $lines[5]['items']);
		$this->assertSame('Write posts', $lines[5]['detail']);
	}
}
