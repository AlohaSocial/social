<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Controller;

use OCA\Social\Controller\ExternalInviteController;
use OCA\Social\Controller\ExternalUsersController;
use OCA\Social\Controller\SignupController;
use OCA\Social\Exceptions\ExternalUserException;
use OCA\Social\External\ExternalUserBackend;
use OCA\Social\Service\ExternalAdminState;
use OCA\Social\Service\ExternalSignupService;
use OCA\Social\Service\ExternalTwoFactorService;
use OCA\Social\Service\ExternalUserService;
use OCA\Social\Service\SignupChallengeService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\AppFramework\Services\IInitialState;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

class ExternalControllersTest extends TestCase {
	private function session(?IUser $user): IUserSession {
		$session = $this->createStub(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$session->method('isLoggedIn')->willReturn($user !== null);

		return $session;
	}

	private function signup(ExternalSignupService $service, ?IUser $viewer = null, ?IInitialState $state = null, ?SignupChallengeService $challenges = null): SignupController {
		$request = $this->createStub(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('192.0.2.7');
		$url = $this->createStub(IURLGenerator::class);
		$url->method('linkToRoute')->willReturn('/apps/social/');

		return new SignupController($request, $service, $state ?? $this->createStub(IInitialState::class), $this->session($viewer), $url, $challenges ?? $this->createStub(SignupChallengeService::class));
	}

	/** @return list<string> the attribute class names on a method */
	private static function attributes(string $class, string $method): array {
		return array_map(
			static fn (\ReflectionAttribute $attribute): string => $attribute->getName(),
			(new ReflectionMethod($class, $method))->getAttributes()
		);
	}

	public function testTheRegistrationPageIsDrawnInTheGuestLayoutWithItsState(): void {
		$service = $this->createStub(ExternalSignupService::class);
		$service->method('pageState')->willReturn(['open' => true]);
		$provided = [];
		$state = $this->createStub(IInitialState::class);
		$state->method('provideInitialState')->willReturnCallback(function (string $key, $data) use (&$provided): void {
			$provided[$key] = $data;
		});

		$response = $this->signup($service, null, $state)->page('token');

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame('signup', $response->getTemplateName());
		$this->assertSame(TemplateResponse::RENDER_AS_GUEST, $response->getRenderAs());
		$this->assertSame(['signup' => ['open' => true]], $provided);
		// the proof of work is solved in a Web Worker loaded from this server
		$this->assertStringContainsString("worker-src 'self'", $response->getContentSecurityPolicy()->buildPolicy());
	}

	public function testAChallengeIsIssuedToAVisitorAndNotToSomebodyLoggedIn(): void {
		$challenges = $this->createStub(SignupChallengeService::class);
		$challenges->method('issue')->willReturn(['challenge' => 'c', 'bits' => 20, 'expires' => 5]);
		$service = $this->createStub(ExternalSignupService::class);

		$this->assertSame(['challenge' => 'c', 'bits' => 20, 'expires' => 5], $this->signup($service, null, null, $challenges)->challenge()->getData());
		$this->assertSame(Http::STATUS_FORBIDDEN, $this->signup($service, $this->createStub(IUser::class), null, $challenges)->challenge()->getStatus());
	}

	public function testSomebodyLoggedInIsSentToSocialAndCannotRegister(): void {
		$service = $this->createMock(ExternalSignupService::class);
		$service->expects($this->never())->method('submit');
		$controller = $this->signup($service, $this->createStub(IUser::class));

		$this->assertInstanceOf(RedirectResponse::class, $controller->page());
		$this->assertSame(Http::STATUS_FORBIDDEN, $controller->submit('a', 'b', 'c')->getStatus());
	}

	public function testARegistrationIsHandedOverWithTheAddressItCameFrom(): void {
		$service = $this->createMock(ExternalSignupService::class);
		$service->expects($this->once())->method('submit')
			->with('alice', 'a@example.org', 'secret123', true, true, 'inv', '', '192.0.2.7', false, '', 'the-challenge', 'n0nce')
			->willReturn(['state' => 'verify', 'handle' => 'alice']);

		$response = $this->signup($service)->submit('alice', 'a@example.org', 'secret123', true, true, 'inv', '', false, '', 'the-challenge', 'n0nce');

		$this->assertSame(['state' => 'verify', 'handle' => 'alice'], $response->getData());
	}

	public function testARefusedRegistrationNamesTheField(): void {
		$service = $this->createStub(ExternalSignupService::class);
		$service->method('submit')->willThrowException(new ExternalUserException('This username is already taken.', 'handle'));

		$response = $this->signup($service)->submit('admin', 'a@example.org', 'secret123', true, true);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame(['message' => 'This username is already taken.', 'field' => 'handle'], $response->getData());
	}

	public function testTheConfirmationLinkShowsWhatHappened(): void {
		$service = $this->createStub(ExternalSignupService::class);
		$service->method('pageState')->willReturn(['open' => true]);
		$service->method('verify')->willThrowException(new ExternalUserException('This confirmation link is not valid any more.'));
		$provided = [];
		$state = $this->createStub(IInitialState::class);
		$state->method('provideInitialState')->willReturnCallback(function (string $key, $data) use (&$provided): void {
			$provided[$key] = $data;
		});

		$this->signup($service, null, $state)->verify('x');

		$this->assertSame(['state' => 'error', 'message' => 'This confirmation link is not valid any more.'], $provided['signup']['result']);
	}

	public function testTheRegistrationRoutesArePublicAndRateLimited(): void {
		foreach (['page', 'submit', 'verify', 'challenge'] as $method) {
			$this->assertContains(PublicPage::class, self::attributes(SignupController::class, $method), $method);
		}
		$this->assertContains(\OCP\AppFramework\Http\Attribute\AnonRateLimit::class, self::attributes(SignupController::class, 'challenge'));
		// the form is posted with the guest page's CSRF token
		$this->assertNotContains(NoCSRFRequired::class, self::attributes(SignupController::class, 'submit'));
		$this->assertContains(\OCP\AppFramework\Http\Attribute\AnonRateLimit::class, self::attributes(SignupController::class, 'submit'));
	}

	/**
	 * Who may have an account on this server is an administrator's decision:
	 * nothing here may relax the server's default of administrators only.
	 */
	public function testTheAdministrationRoutesAreForAdministratorsOnly(): void {
		$relaxing = [NoAdminRequired::class, PublicPage::class, NoCSRFRequired::class,
			'OCP\\AppFramework\\Http\\Attribute\\AuthorizedAdminSetting', 'OCP\\AppFramework\\Http\\Attribute\\SubAdminRequired'];

		foreach ((new ReflectionClass(ExternalUsersController::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
			if ($method->getDeclaringClass()->getName() !== ExternalUsersController::class || $method->isConstructor()) {
				continue;
			}
			$this->assertSame([], array_values(array_intersect($relaxing, self::attributes(ExternalUsersController::class, $method->getName()))), $method->getName());
		}

		foreach (['twoFactor', 'promote', 'delete'] as $method) {
			$this->assertContains(PasswordConfirmationRequired::class, self::attributes(ExternalUsersController::class, $method), $method);
		}
	}

	private function admin(?ExternalUserService $users = null, ?ExternalSignupService $signups = null, array $known = []): ExternalUsersController {
		$state = $this->createStub(ExternalAdminState::class);
		$state->method('current')->willReturn(['everything' => true]);
		$userManager = $this->createStub(IUserManager::class);
		$userManager->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $known[$uid] ?? null);
		if ($users === null) {
			$users = $this->createStub(ExternalUserService::class);
			$users->method('isExternal')->willReturnCallback(static fn (?IUser $user): bool => ExternalUserBackend::isExternal($user));
		}

		return new ExternalUsersController(
			$this->createStub(IRequest::class),
			$users,
			$signups ?? $this->createStub(ExternalSignupService::class),
			$this->createStub(ExternalTwoFactorService::class),
			$state,
			$userManager,
			$this->session($this->createStub(IUser::class)),
		);
	}

	private function external(): IUser&\PHPUnit\Framework\MockObject\MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getBackend')->willReturn($this->createStub(ExternalUserBackend::class));
		$user->method('getUID')->willReturn('alice');

		return $user;
	}

	public function testSettingsOutOfRangeAreA422(): void {
		$users = $this->createStub(ExternalUserService::class);
		$users->method('saveSettings')->willThrowException(new \InvalidArgumentException('max must be between 0 and 1000000'));

		$response = $this->admin($users)->save(true, -1);

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}

	public function testOnlyAnExternalAccountCanBeDisabledOrDeletedHere(): void {
		$local = $this->createMock(IUser::class);
		$local->method('getBackend')->willReturn(null);
		$local->expects($this->never())->method('delete');
		$local->expects($this->never())->method('setEnabled');
		$alice = $this->external();
		$alice->expects($this->once())->method('setEnabled')->with(false);
		$alice->expects($this->once())->method('delete')->willReturn(true);
		$controller = $this->admin(null, null, ['bob' => $local, 'alice' => $alice]);

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->setEnabled('bob', false)->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->delete('bob')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->delete('nobody')->getStatus());
		$this->assertSame(Http::STATUS_OK, $controller->setEnabled('alice', false)->getStatus());
		$this->assertSame(['everything' => true], $controller->delete('alice')->getData());
	}

	public function testApprovingAnswersWithEverythingOrTheReasonItFailed(): void {
		$signups = $this->createStub(ExternalSignupService::class);
		$signups->method('approve')->willReturnCallback(static function (int $id): string {
			if ($id === 2) {
				throw new ExternalUserException('This server is not accepting new accounts at the moment.');
			}

			return 'alice';
		});
		$controller = $this->admin(null, $signups);

		$this->assertSame(['everything' => true], $controller->approve(1)->getData());
		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $controller->approve(2)->getStatus());
	}

	public function testAnAdministratorsInvitationIsMadeAsTheirs(): void {
		$signups = $this->createMock(ExternalSignupService::class);
		$signups->expects($this->once())->method('createInvite')->with('', 'team', 5, 30, true)->willReturn(['id' => 1]);

		$this->assertSame(['id' => 1], $this->admin(null, $signups)->invite('team', 5, 30)->getData());
	}

	public function testAUsersInvitationsAreTheirsAndOnlyWhileAllowed(): void {
		$signups = $this->createMock(ExternalSignupService::class);
		$signups->method('mayInvite')->with('carol')->willReturn(false);
		$signups->expects($this->never())->method('invites');
		$carol = $this->createStub(IUser::class);
		$carol->method('getUID')->willReturn('carol');

		$controller = new ExternalInviteController($this->createStub(IRequest::class), $signups, $this->session($carol));

		$this->assertSame(['allowed' => false, 'invites' => []], $controller->list()->getData());
		foreach (['list', 'create', 'revoke'] as $method) {
			$this->assertContains(NoAdminRequired::class, self::attributes(ExternalInviteController::class, $method), $method);
		}
	}

	public function testAUserCanOnlyWithdrawTheirOwn(): void {
		$signups = $this->createMock(ExternalSignupService::class);
		$signups->expects($this->once())->method('revokeInvite')->with(3, 'carol')->willReturn(false);
		$carol = $this->createStub(IUser::class);
		$carol->method('getUID')->willReturn('carol');

		$controller = new ExternalInviteController($this->createStub(IRequest::class), $signups, $this->session($carol));

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->revoke(3)->getStatus());
	}

	public function testAnAllowedUserSeesTheirInvitations(): void {
		$signups = $this->createMock(ExternalSignupService::class);
		$signups->method('mayInvite')->with('carol')->willReturn(true);
		$signups->expects($this->once())->method('invites')->with('carol')->willReturn([['id' => 3]]);
		$carol = $this->createStub(IUser::class);
		$carol->method('getUID')->willReturn('carol');

		$controller = new ExternalInviteController($this->createStub(IRequest::class), $signups, $this->session($carol));

		$this->assertSame(['allowed' => true, 'invites' => [['id' => 3]]], $controller->list()->getData());
	}

	/** An external user's invitation is refused by the service, and answered as such. */
	public function testARefusedInvitationIsA422(): void {
		$signups = $this->createMock(ExternalSignupService::class);
		$signups->method('createInvite')->with('eve', '', 1, ExternalSignupService::USER_INVITE_DAYS, false)
			->willThrowException(new \OCA\Social\Exceptions\ExternalUserException('Invitations are not available on this server.'));
		$eve = $this->createStub(IUser::class);
		$eve->method('getUID')->willReturn('eve');

		$response = (new ExternalInviteController($this->createStub(IRequest::class), $signups, $this->session($eve)))->create();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
	}
}
