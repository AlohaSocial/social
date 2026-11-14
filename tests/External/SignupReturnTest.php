<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\External;

use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\External\SignupReturn;
use OCA\Social\Service\ConfigService;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Only this server's own authorization request is a way back from a registration. */
class SignupReturnTest extends TestCase {
	private const ROUTE = '/index.php/apps/social/oauth/authorize';
	private const QUERY = 'client_id=app&response_type=code&redirect_uri=https%3A%2F%2Faloha.social%2Foauth%2Fcallback&state=s1&code_challenge=abc&code_challenge_method=S256';

	private function signupReturn(string $webroot = '', ?string $socialUrl = 'https://social.example.org/apps/social/'): SignupReturn {
		$url = $this->createStub(IURLGenerator::class);
		$url->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example.org' . $webroot . $path);
		$url->method('getWebroot')->willReturn($webroot);
		$url->method('linkToRoute')->willReturnCallback(static fn (string $route, array $args = []): string => match ($route) {
			'social.OAuth.authorize' => $webroot . self::ROUTE,
			'core.login.showLoginForm' => $webroot . '/index.php/login',
		} . ($args === [] ? '' : '?' . http_build_query($args)));
		$config = $this->createStub(ConfigService::class);
		$config->method('getCloudUrl')->willReturn('https://cloud.example.org' . $webroot);
		$config->method('getSocialUrl')->willReturnCallback(static fn (): string => $socialUrl ?? throw new SocialAppConfigException());

		return new SignupReturn($url, $config);
	}

	/** @return array<string, array{string}> */
	public static function accepted(): array {
		return [
			'the route, through index.php' => ['https://cloud.example.org/index.php/apps/social/oauth/authorize?' . self::QUERY],
			'pretty URLs' => ['https://cloud.example.org/apps/social/oauth/authorize?' . self::QUERY],
			'the domain root, where the web server forwards /oauth/' => ['https://cloud.example.org/oauth/authorize?' . self::QUERY],
			'an explicit default port and a capital host' => ['https://Cloud.Example.org:443/oauth/authorize?' . self::QUERY],
			'the social address origin' => ['https://social.example.org/oauth/authorize?' . self::QUERY],
			'a path, as the page hands it back' => [self::ROUTE . '?' . self::QUERY],
		];
	}

	#[DataProvider('accepted')]
	public function testThisServersAuthorizationRequestIsTakenAndComesOutAsTheRoute(string $candidate): void {
		$this->assertSame(self::ROUTE . '?' . self::QUERY, $this->signupReturn()->normalize($candidate));
	}

	/** @return array<string, array{string}> */
	public static function refused(): array {
		return [
			'another host' => ['https://evil.example/oauth/authorize?' . self::QUERY],
			'a host that merely ends like this one' => ['https://notcloud.example.org/oauth/authorize?' . self::QUERY],
			'another scheme' => ['http://cloud.example.org/oauth/authorize?' . self::QUERY],
			'another port' => ['https://cloud.example.org:8443/oauth/authorize?' . self::QUERY],
			'a script' => ['javascript:alert(1)//oauth/authorize?client_id=app'],
			'protocol-relative' => ['//evil.example/oauth/authorize?' . self::QUERY],
			'three slashes' => ['///evil.example/oauth/authorize?' . self::QUERY],
			'a backslash' => ['/\\evil.example/oauth/authorize?' . self::QUERY],
			'a backslash in the host' => ['https://cloud.example.org\\@evil.example/oauth/authorize?' . self::QUERY],
			'credentials' => ['https://user@cloud.example.org/oauth/authorize?' . self::QUERY],
			'an @ in the query, which core refuses to redirect to' => ['/oauth/authorize?client_id=app&x=a@evil.example'],
			'a fragment' => ['/oauth/authorize?client_id=app#x'],
			'another route' => ['https://cloud.example.org/index.php/apps/files/?client_id=app'],
			'the token endpoint' => ['/oauth/token?client_id=app'],
			'a route below the authorize path' => ['/oauth/authorize/confirmed?client_id=app'],
			'an encoded path' => ['/oauth/%61uthorize?client_id=app'],
			'dot segments' => ['/index.php/apps/social/../social/oauth/authorize?client_id=app'],
			'no client' => ['/oauth/authorize?response_type=code'],
			'no query' => ['/oauth/authorize'],
			'a relative path' => ['oauth/authorize?client_id=app'],
			'whitespace' => [' /oauth/authorize?client_id=app'],
			'a newline' => ["/oauth/authorize?client_id=app\r\nLocation: https://evil.example"],
			'a broken percent escape' => ['/oauth/authorize?client_id=app%zz'],
			'nothing' => [''],
			'too long' => ['/oauth/authorize?client_id=' . str_repeat('a', SignupReturn::MAX_LENGTH)],
		];
	}

	#[DataProvider('refused')]
	public function testAnythingElseIsDropped(string $candidate): void {
		$this->assertSame('', $this->signupReturn()->normalize($candidate));
	}

	public function testUnderASubdirectoryTheRouteKeepsTheWebrootAndTheDomainRootStaysAtTheRoot(): void {
		$signupReturn = $this->signupReturn('/cloud');

		$this->assertSame('/cloud' . self::ROUTE . '?client_id=app', $signupReturn->normalize('https://cloud.example.org/oauth/authorize?client_id=app'));
		$this->assertSame('/cloud' . self::ROUTE . '?client_id=app', $signupReturn->normalize('https://cloud.example.org/cloud/apps/social/oauth/authorize?client_id=app'));
		$this->assertSame('', $signupReturn->normalize('https://cloud.example.org/apps/social/oauth/authorize?client_id=app'), 'outside the webroot');
	}

	public function testWithoutASocialAddressTheCloudOriginStillCounts(): void {
		$signupReturn = $this->signupReturn('', null);

		$this->assertSame(self::ROUTE . '?client_id=app', $signupReturn->normalize('https://cloud.example.org/oauth/authorize?client_id=app'));
		$this->assertSame('', $signupReturn->normalize('https://social.example.org/oauth/authorize?client_id=app'));
	}

	public function testTheLoginPageContinuesAnAcceptedRequestAndNothingElse(): void {
		$signupReturn = $this->signupReturn();

		$this->assertSame('/index.php/login?' . http_build_query(['redirect_url' => self::ROUTE . '?client_id=app']), $signupReturn->loginUrl('/oauth/authorize?client_id=app'));
		$this->assertSame('/index.php/login', $signupReturn->loginUrl('https://evil.example/oauth/authorize?client_id=app'));
		$this->assertSame('https://cloud.example.org/index.php/login', $signupReturn->absoluteLoginUrl(''));
	}
}
