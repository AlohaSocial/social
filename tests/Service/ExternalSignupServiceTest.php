<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Db\ExternalInvitesRequest;
use OCA\Social\Db\ExternalSignupsRequest;
use OCA\Social\Exceptions\ExternalUserException;
use OCA\Social\Service\ExternalSignupService;
use OCA\Social\Service\ExternalUserService;
use OCA\Social\Service\InstanceService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\HintException;
use OCP\IAppConfig;
use OCP\IConfig;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\Mail\IEMailTemplate;
use OCP\Mail\IMailer;
use OCP\Mail\IMessage;
use OCP\Security\IHasher;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
class ExternalSignupServiceTest extends TestCase {
	private const NOW = 1000000;

	private ExternalUserService&MockObject $users;
	private ExternalSignupsRequest&MockObject $signups;
	private ExternalInvitesRequest&MockObject $invites;
	private IEventDispatcher&MockObject $dispatcher;
	private IMailer&MockObject $mailer;
	private string $mode = ExternalUserService::MODE_OPEN;
	private bool $verify = true;
	private bool $room = true;
	/** @var list<array{string, string}> subject, recipient */
	private array $sent = [];
	private bool $mailWorks = true;
	/** @var array<int, string> */
	private array $subjects = [];
	/** @var array<int, string> */
	private array $recipients = [];
	/** @var array<int, int> message => template */
	private array $templates = [];

	protected function setUp(): void {
		$this->users = $this->createMock(ExternalUserService::class);
		$this->users->method('mode')->willReturnCallback(fn (): string => $this->mode);
		$this->users->method('verifiesEmail')->willReturnCallback(fn (): bool => $this->verify);
		$this->users->method('hasRoom')->willReturnCallback(fn (): bool => $this->room);
		$this->users->method('isEnabled')->willReturn(true);
		$this->users->method('minimumAge')->willReturn(16);
		$this->users->method('assertHandleAvailable')->willReturnArgument(0);
		$this->users->method('assertEmailAvailable')->willReturnArgument(0);
		$this->signups = $this->createMock(ExternalSignupsRequest::class);
		$this->signups->method('create')->willReturn(42);
		$this->invites = $this->createMock(ExternalInvitesRequest::class);
		$this->invites->method('getByToken')->willReturnCallback(
			static fn (string $token): ?array => match ($token) {
				'good' => ['id' => 5, 'token' => 'good', 'creator' => 'admin', 'note' => '', 'maxUses' => 1, 'uses' => 0, 'expires' => 0, 'creation' => 0],
				'spent' => ['id' => 6, 'token' => 'spent', 'creator' => 'admin', 'note' => '', 'maxUses' => 1, 'uses' => 1, 'expires' => 0, 'creation' => 0],
				'old' => ['id' => 7, 'token' => 'old', 'creator' => 'admin', 'note' => '', 'maxUses' => 0, 'uses' => 0, 'expires' => self::NOW - 1, 'creation' => 0],
				default => null,
			}
		);
		$this->dispatcher = $this->createMock(IEventDispatcher::class);
		$this->mailer = $this->createMock(IMailer::class);
		$this->mailer->method('createEMailTemplate')->willReturnCallback(function (): IEMailTemplate {
			$template = $this->createStub(IEMailTemplate::class);
			$template->method('setSubject')->willReturnCallback(function (string $subject) use ($template): void {
				$this->subjects[spl_object_id($template)] = $subject;
			});

			return $template;
		});
		$this->mailer->method('createMessage')->willReturnCallback(function (): IMessage {
			$message = $this->createStub(IMessage::class);
			$message->method('setTo')->willReturnCallback(function (array $to) use ($message): IMessage {
				$this->recipients[spl_object_id($message)] = (string)$to[0];

				return $message;
			});
			$message->method('useTemplate')->willReturnCallback(function (IEMailTemplate $template) use ($message): IMessage {
				$this->templates[spl_object_id($message)] = spl_object_id($template);

				return $message;
			});

			return $message;
		});
		$this->mailer->method('send')->willReturnCallback(function (IMessage $message): array {
			if (!$this->mailWorks) {
				throw new \RuntimeException('no smtp');
			}
			$id = spl_object_id($message);
			$this->sent[] = [$this->subjects[$this->templates[$id] ?? 0] ?? '', $this->recipients[$id] ?? ''];

			return [];
		});
	}

	private function service(): ExternalSignupService {
		$hasher = $this->createStub(IHasher::class);
		$hasher->method('hash')->willReturnCallback(static fn (string $p): string => 'hash:' . $p);
		$random = $this->createStub(ISecureRandom::class);
		$random->method('generate')->willReturn('TOKEN');
		$url = $this->createStub(IURLGenerator::class);
		$url->method('linkToRouteAbsolute')->willReturnCallback(
			static fn (string $route, array $args = []): string => 'https://cloud.example/' . $route . '?' . http_build_query($args)
		);
		$url->method('getAbsoluteURL')->willReturn('https://cloud.example/');
		$time = $this->createStub(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$l10n = $this->createStub(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $args = []): string => vsprintf($text, $args));
		$instance = $this->createStub(InstanceService::class);
		$instance->method('rules')->willReturn([['id' => '1', 'text' => 'Be kind']]);

		return new ExternalSignupService(
			$this->users,
			$this->signups,
			$this->invites,
			$instance,
			$hasher,
			$random,
			$this->dispatcher,
			$this->mailer,
			$url,
			$this->createStub(IConfig::class),
			$this->createStub(IAppConfig::class),
			$time,
			$l10n,
			new NullLogger(),
		);
	}

	private function submit(string $invite = '', string $honeypot = '', bool $rules = true, bool $age = true, string $password = 'long enough'): array {
		return $this->service()->submit('alice', 'alice@example.org', $password, $rules, $age, $invite, $honeypot, '192.0.2.1');
	}

	public function testTheHoneypotIsAnsweredAsIfItWorkedAndNothingIsStored(): void {
		$this->signups->expects($this->never())->method('create');
		$this->users->expects($this->never())->method('createAccount');

		$this->assertSame(ExternalSignupService::STATE_VERIFY, $this->submit('', 'https://spam.example')['state']);
		$this->assertSame([], $this->sent);
	}

	public function testAnOpenRegistrationWaitsForItsEmailToBeConfirmed(): void {
		$this->signups->expects($this->once())->method('create')
			->with('alice', 'alice@example.org', 'hash:long enough', hash('sha256', 'TOKEN'), false, false, 0, $this->anything(), self::NOW);
		$this->users->expects($this->never())->method('createAccount');

		$this->assertSame(['state' => 'verify', 'handle' => 'alice'], $this->submit());
		$this->assertSame([['Confirm your email address', 'alice@example.org']], $this->sent);
	}

	public function testARegistrationIsDroppedWhenItsConfirmationCannotBeSent(): void {
		$this->mailWorks = false;
		$this->signups->expects($this->once())->method('delete')->with(42);

		$this->expectException(ExternalUserException::class);
		$this->submit();
	}

	public function testWithoutConfirmationAnOpenRegistrationIsAnAccountAtOnce(): void {
		$this->verify = false;
		$this->users->expects($this->once())->method('createAccount')
			->with('alice', 'alice@example.org', 'hash:long enough', 'open')
			->willReturn($this->createStub(IUser::class));

		$this->assertSame(['state' => 'created', 'handle' => 'alice'], $this->submit());
		$this->assertSame([['Your account is ready', 'alice@example.org']], $this->sent);
	}

	public function testWithoutConfirmationAnApprovalRegistrationGoesToTheQueue(): void {
		$this->verify = false;
		$this->mode = ExternalUserService::MODE_APPROVAL;
		$this->signups->expects($this->once())->method('create')
			->with('alice', $this->anything(), $this->anything(), '', true, true, 0, $this->anything(), self::NOW);
		$this->users->expects($this->never())->method('createAccount');

		$this->assertSame(['state' => 'approval', 'handle' => 'alice'], $this->submit());
	}

	public function testAnInvitationSkipsApprovalAndIsUsedUp(): void {
		$this->verify = false;
		$this->mode = ExternalUserService::MODE_APPROVAL;
		$this->invites->expects($this->once())->method('consume')->with(5)->willReturn(true);
		$this->users->expects($this->once())->method('createAccount')
			->with('alice', $this->anything(), $this->anything(), 'invite:5')
			->willReturn($this->createStub(IUser::class));

		$this->assertSame('created', $this->submit('good')['state']);
	}

	public function testByInvitationOnlyNobodyElseGetsIn(): void {
		$this->mode = ExternalUserService::MODE_INVITE;

		foreach (['', 'spent', 'old', 'made-up'] as $invite) {
			try {
				$this->submit($invite);
				$this->fail('admitted without a valid invitation: "' . $invite . '"');
			} catch (ExternalUserException $e) {
			}
		}

		$this->invites->method('consume')->willReturn(true);
		$this->assertSame('verify', $this->submit('good')['state']);
	}

	public function testAnInvitationUsedUpByARaceAdmitsNobody(): void {
		$this->invites->method('consume')->willReturn(false);
		$this->signups->expects($this->never())->method('create');

		$this->expectException(ExternalUserException::class);
		$this->submit('good');
	}

	public function testAFullServerTakesNoRegistration(): void {
		$this->room = false;

		$this->expectException(ExternalUserException::class);
		$this->submit();
	}

	public function testTheRulesTheAgeAndThePasswordAreRequired(): void {
		$field = function (callable $call): string {
			try {
				$call();
			} catch (ExternalUserException $e) {
				return $e->getField();
			}
			$this->fail('accepted');
		};

		$this->assertSame('rules', $field(fn () => $this->submit('', '', false)));
		$this->assertSame('age', $field(fn () => $this->submit('', '', true, false)));
		$this->assertSame('password', $field(fn () => $this->submit('', '', true, true, 'short')));

		$this->dispatcher->method('dispatchTyped')->willThrowException(new HintException('weak', 'That password is too common.'));
		$this->assertSame('password', $field(fn () => $this->submit()));
	}

	public function testOneAddressCannotRegisterWithoutEnd(): void {
		$this->signups->method('countFromIpSince')->willReturn(ExternalSignupService::PER_IP_PER_HOUR);
		$this->signups->expects($this->never())->method('create');

		$this->expectException(ExternalUserException::class);
		$this->submit();
	}

	public function testConfirmingTheEmailMakesTheAccount(): void {
		$this->signups->method('getByTokenHash')->with(hash('sha256', 'abc'))->willReturn($this->pending(false, false));
		$this->users->expects($this->once())->method('createAccount')->with('bob', 'bob@example.org', 'H', 'open', 9)
			->willReturn($this->createStub(IUser::class));
		$this->signups->expects($this->once())->method('delete')->with(9);

		$this->assertSame(['state' => 'created', 'handle' => 'bob'], $this->service()->verify('abc'));
	}

	public function testConfirmingAnApprovalRegistrationQueuesIt(): void {
		$this->signups->method('getByTokenHash')->willReturn($this->pending(false, true));
		$this->signups->expects($this->once())->method('markVerified')->with(9);
		$this->users->expects($this->never())->method('createAccount');

		$this->assertSame('approval', $this->service()->verify('abc')['state']);
	}

	public function testAnExpiredOrUnknownLinkDoesNothing(): void {
		$this->signups->method('getByTokenHash')->willReturnCallback(
			fn (string $hash): ?array => ($hash === hash('sha256', 'old')) ? ['creation' => self::NOW - ExternalSignupService::VERIFY_TTL - 1] + $this->pending(false, false) : null
		);
		$this->users->expects($this->never())->method('createAccount');

		foreach (['old', 'unknown', ''] as $token) {
			try {
				$this->service()->verify($token);
				$this->fail('accepted "' . $token . '"');
			} catch (ExternalUserException $e) {
			}
		}
	}

	public function testApprovingAConfirmedRegistrationMakesTheAccountAndSaysSo(): void {
		$this->signups->method('get')->with(9)->willReturn($this->pending(true, true));
		$this->users->expects($this->once())->method('createAccount')->with('bob', 'bob@example.org', 'H', 'approval', 9)
			->willReturn($this->createStub(IUser::class));

		$this->assertSame('bob', $this->service()->approve(9));
		$this->assertSame([['Your account is ready', 'bob@example.org']], $this->sent);
	}

	public function testAnUnconfirmedRegistrationCannotBeApproved(): void {
		$this->signups->method('get')->willReturn($this->pending(false, true));
		$this->users->expects($this->never())->method('createAccount');

		$this->expectException(ExternalUserException::class);
		$this->service()->approve(9);
	}

	public function testARejectedRegistrationIsForgottenAndThePersonTold(): void {
		$this->signups->method('get')->willReturn($this->pending(true, true));
		$this->signups->expects($this->once())->method('delete')->with(9)->willReturn(true);

		$this->service()->reject(9, 'Not now');

		$this->assertSame([['Your registration was not accepted', 'bob@example.org']], $this->sent);
	}

	public function testAUsersInvitationIsSingleUseForAWeek(): void {
		$this->users->method('usersMayInvite')->willReturn(true);
		$this->invites->method('list')->willReturn([]);
		$this->invites->expects($this->once())->method('create')
			->with('TOKEN', 'carol', 'for dave', 1, self::NOW + 7 * 86400, self::NOW)->willReturn(3);
		$this->invites->method('get')->willReturn(['id' => 3, 'token' => 'TOKEN', 'creator' => 'carol', 'note' => 'for dave', 'maxUses' => 1, 'uses' => 0, 'expires' => self::NOW + 7 * 86400, 'creation' => self::NOW]);

		$invite = $this->service()->createInvite('carol', 'for dave', 100, 365, false);

		$this->assertSame('https://cloud.example/social.Signup.page?invite=TOKEN', $invite['url']);
		$this->assertSame(1, $invite['maxUses']);
	}

	public function testUsersCannotInviteUnlessAllowed(): void {
		$this->users->method('usersMayInvite')->willReturn(false);
		$this->invites->expects($this->never())->method('create');

		$this->expectException(ExternalUserException::class);
		$this->service()->createInvite('carol', '', 1, 7, false);
	}

	public function testAUserCanOnlyWithdrawTheirOwnInvitation(): void {
		$this->invites->method('get')->willReturn(['id' => 3, 'token' => 'T', 'creator' => 'carol', 'note' => '', 'maxUses' => 1, 'uses' => 0, 'expires' => 0, 'creation' => 0]);
		$this->invites->method('delete')->willReturn(true);

		$this->assertFalse($this->service()->revokeInvite(3, 'mallory'));
		$this->assertTrue($this->service()->revokeInvite(3, 'carol'));
		$this->assertTrue($this->service()->revokeInvite(3));
	}

	public function testThePageSaysWhyItIsClosed(): void {
		$this->mode = ExternalUserService::MODE_INVITE;
		$state = $this->service()->pageState();
		$this->assertFalse($state['open']);
		$this->assertSame('This server accepts registrations by invitation only.', $state['reason']);

		$state = $this->service()->pageState('good');
		$this->assertTrue($state['open']);
		$this->assertTrue($state['invited']);
		$this->assertSame(['Be kind'], $state['rules']);
	}

	private function pending(bool $verified, bool $approval): array {
		return ['id' => 9, 'handle' => 'bob', 'email' => 'bob@example.org', 'password' => 'H', 'token' => 'x',
			'verified' => $verified, 'approval' => $approval, 'inviteId' => 0, 'ipHash' => '', 'creation' => self::NOW - 60];
	}
}
