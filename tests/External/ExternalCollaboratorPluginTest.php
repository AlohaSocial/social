<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\External;

use OCA\Social\External\ExternalCollaboratorPlugin;
use OCA\Social\External\ExternalUserBackend;
use OCP\Collaboration\Collaborators\ISearchResult;
use OCP\Collaboration\Collaborators\SearchResultType;
use PHPUnit\Framework\TestCase;

class ExternalCollaboratorPluginTest extends TestCase {
	public function testExternalUsersAreRemovedFromWideAndExactMatches(): void {
		$backend = $this->createStub(ExternalUserBackend::class);
		$backend->method('userExists')->willReturnCallback(static fn (string $uid): bool => in_array($uid, ['alice', 'carol'], true));

		$entry = static fn (string $uid): array => ['label' => $uid, 'value' => ['shareType' => 0, 'shareWith' => $uid]];
		$result = $this->createMock(ISearchResult::class);
		$result->method('asArray')->willReturn([
			'users' => [$entry('alice'), $entry('bob')],
			'exact' => ['users' => [$entry('carol')], 'groups' => []],
		]);
		$removed = [];
		$result->expects($this->exactly(2))->method('removeCollaboratorResult')
			->willReturnCallback(function (SearchResultType $type, string $uid) use (&$removed): bool {
				$this->assertSame('users', $type->getLabel());
				$removed[] = $uid;

				return true;
			});

		$more = (new ExternalCollaboratorPlugin($backend))->search('a', 10, 0, $result);

		$this->assertFalse($more);
		$this->assertSame(['alice', 'carol'], $removed);
	}

	public function testGuestsAppUsersRemainAvailableAsShareRecipients(): void {
		$backend = $this->createStub(ExternalUserBackend::class);
		$backend->method('userExists')->willReturnCallback(static fn (string $uid): bool => $uid === 'social-only');

		$entry = static fn (string $uid): array => ['label' => $uid, 'value' => ['shareType' => 0, 'shareWith' => $uid]];
		$result = $this->createMock(ISearchResult::class);
		$result->method('asArray')->willReturn([
			'users' => [$entry('social-only'), $entry('guest-account')],
			'exact' => ['users' => [], 'groups' => []],
		]);
		$result->expects($this->once())->method('removeCollaboratorResult')
			->with($this->callback(static fn (SearchResultType $type): bool => $type->getLabel() === 'users'), 'social-only');

		(new ExternalCollaboratorPlugin($backend))->search('guest', 10, 0, $result);
	}

	public function testNothingToRemoveTouchesNothing(): void {
		$backend = $this->createStub(ExternalUserBackend::class);
		$result = $this->createMock(ISearchResult::class);
		$result->method('asArray')->willReturn([]);
		$result->expects($this->never())->method('removeCollaboratorResult');

		(new ExternalCollaboratorPlugin($backend))->search('a', 10, 0, $result);
	}
}
