<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Providers;

use OCA\Social\Exceptions\AccountDoesNotExistException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Providers\ContactsMenuProvider;
use OCA\Social\Service\AccountService;
use OCP\Contacts\ContactsMenu\IActionFactory;
use OCP\Contacts\ContactsMenu\IEntry;
use OCP\Contacts\ContactsMenu\ILinkAction;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ContactsMenuProviderTest extends TestCase {
	/** @var IActionFactory&MockObject */
	private $actionFactory;
	/** @var IURLGenerator&MockObject */
	private $urlGenerator;
	/** @var IUserManager&MockObject */
	private $userManager;
	/** @var AccountService&MockObject */
	private $accountService;
	private ContactsMenuProvider $provider;

	protected function setUp(): void {
		$this->actionFactory = $this->createMock(IActionFactory::class);
		$this->urlGenerator = $this->createMock(IURLGenerator::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->accountService = $this->createMock(AccountService::class);
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(fn (string $text, array $params): string => vsprintf($text, $params));

		$this->provider = new ContactsMenuProvider(
			$this->actionFactory,
			$this->urlGenerator,
			$this->userManager,
			$l10n,
			$this->accountService
		);
	}

	/** @return IEntry&MockObject */
	private function entry(?string $uid, bool $localSystemBook = true): IEntry {
		$entry = $this->createMock(IEntry::class);
		$entry->method('getProperty')->willReturnMap([
			['UID', $uid],
			['isLocalSystemBook', $localSystemBook],
		]);

		return $entry;
	}

	private function knownUser(string $uid, string $displayName): void {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($displayName);
		$this->userManager->method('get')->with($uid)->willReturn($user);
	}

	public function testUsersWithASocialAccountGetAFollowAction(): void {
		$this->knownUser('bob', 'Bob Builder');
		$actor = $this->createStub(Person::class);
		$actor->method('getPreferredUsername')->willReturn('bob');
		$this->accountService->method('getActorFromUserId')->with('bob')->willReturn($actor);
		$this->urlGenerator->method('imagePath')->with('social', 'social-dark.svg')->willReturn('/apps/social/img/social-dark.svg');
		$this->urlGenerator->method('getAbsoluteURL')->with('/apps/social/img/social-dark.svg')->willReturn('https://cloud.example/apps/social/img/social-dark.svg');
		$this->urlGenerator->method('linkToRouteAbsolute')->with('social.ActivityPub.actorAlias', ['username' => 'bob'])
			->willReturn('https://cloud.example/apps/social/@bob');
		$action = $this->createStub(ILinkAction::class);
		$this->actionFactory->expects($this->once())->method('newLinkAction')
			->with(
				'https://cloud.example/apps/social/img/social-dark.svg',
				'Follow Bob Builder on Aloha Social',
				'https://cloud.example/apps/social/@bob',
				'social'
			)
			->willReturn($action);
		$entry = $this->entry('bob');
		$entry->expects($this->once())->method('addAction')->with($action);

		$this->provider->process($entry);
	}

	public function testUsersWithoutASocialAccountGetNoAction(): void {
		$this->knownUser('bob', 'Bob');
		$this->accountService->method('getActorFromUserId')->willThrowException(new AccountDoesNotExistException());
		$this->actionFactory->expects($this->never())->method('newLinkAction');
		$entry = $this->entry('bob');
		$entry->expects($this->never())->method('addAction');

		$this->provider->process($entry);
	}

	public function testEntriesWithoutAUidAreSkipped(): void {
		$this->userManager->expects($this->never())->method('get');
		$this->accountService->expects($this->never())->method('getActorFromUserId');
		$entry = $this->entry(null);
		$entry->expects($this->never())->method('addAction');

		$this->provider->process($entry);
	}

	public function testRemoteAddressBookEntriesAreSkipped(): void {
		$this->userManager->expects($this->never())->method('get');
		$entry = $this->entry('bob', false);
		$entry->expects($this->never())->method('addAction');

		$this->provider->process($entry);
	}

	public function testUnknownUsersAreSkipped(): void {
		$this->userManager->method('get')->with('ghost')->willReturn(null);
		$this->accountService->expects($this->never())->method('getActorFromUserId');
		$entry = $this->entry('ghost');
		$entry->expects($this->never())->method('addAction');

		$this->provider->process($entry);
	}
}
