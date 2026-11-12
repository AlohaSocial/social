<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Service;

use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Db\ActorsRequest;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Service\AboutService;
use OCA\Social\Service\CheckService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\InstanceService;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

class AboutServiceTest extends TestCase {
	/** @var array<string, string> */
	private array $app = [];
	/** @var array<string, string> */
	private array $theming = [];
	/** @var array<array{id: string, text: string}> */
	private array $rules = [];
	private ?Person $contact = null;
	private bool $clientApiAtRoot = false;
	/** the confirmed Nextcloud address, '' while there is none */
	private string $cloudAuthority = 'cloud.example';

	private function service(): AboutService {
		$config = $this->createStub(ConfigService::class);
		$config->accessTypeList = ['BLACKLIST' => 'all_but', 'WHITELIST' => 'none_but'];
		$defaults = [ConfigService::SOCIAL_ACCESS_TYPE => 'all_but', ConfigService::SOCIAL_ACCESS_LIST => '[]'];
		$config->method('getAppValue')->willReturnCallback(fn (string $key): string => $this->app[$key] ?? $defaults[$key] ?? '');
		$config->method('getAppValueBool')->willReturnCallback(fn (string $key): bool => ($this->app[$key] ?? '0') === '1');
		$config->method('getCloudAuthority')->willReturnCallback(
			fn (): string => $this->cloudAuthority !== '' ? $this->cloudAuthority : throw new SocialAppConfigException()
		);

		$instance = $this->createStub(InstanceService::class);
		$instance->method('rules')->willReturnCallback(fn (): array => $this->rules);

		$actors = $this->createStub(ActorsRequest::class);
		$actors->method('getFromUserId')->willReturnCallback(
			fn (string $userId): Person => $this->contact ?? throw new ActorDoesNotExistException()
		);

		$appConfig = $this->createStub(IAppConfig::class);
		$appConfig->method('getValueString')->willReturnCallback(
			fn (string $app, string $key, string $default = ''): string => $app === 'theming' ? ($this->theming[$key] ?? $default) : $default
		);

		$check = $this->createStub(CheckService::class);
		$check->method('clientApiRootIsKnownGood')->willReturnCallback(fn (): bool => $this->clientApiAtRoot);

		$atproto = new AtprotoConfig($config);
		$config->method('getCloudUrl')->willReturnCallback(fn (): string => 'https://' . $this->cloudAuthority);

		return new AboutService($config, $instance, $actors, $appConfig, $check, $atproto);
	}

	private function complete(): void {
		$this->app[ConfigService::CONTACT_EMAIL] = 'moderators@cloud.example';
		$this->rules = [['id' => '1', 'text' => 'Be kind']];
		$this->theming = ['name' => 'Cloud', 'privacyUrl' => 'https://cloud.example/privacy', 'imprintUrl' => 'https://cloud.example/imprint'];
	}

	public function testTheAboutPageReadsTheSettingsThatAlreadyExist(): void {
		$this->complete();
		$this->app[ConfigService::SOCIAL_EXTENDED_DESCRIPTION] = "  A server for the club.\n";
		$this->app[ConfigService::SOCIAL_CONTACT_ACCOUNT] = 'carol';
		$this->contact = new Person();
		$this->contact->setPreferredUsername('carol');
		$this->contact->setName('Carol');
		$this->contact->setLocal(true);

		$this->assertSame([
			'name' => 'Cloud',
			'description' => 'A server for the club.',
			'rules' => [['id' => '1', 'text' => 'Be kind']],
			'contact' => ['email' => 'moderators@cloud.example', 'account' => ['username' => 'carol', 'display_name' => 'Carol']],
			'privacy_policy_url' => 'https://cloud.example/privacy',
			'legal_notice_url' => 'https://cloud.example/imprint',
			'apps' => ['mastodon' => '', 'bluesky' => ''],
		], $this->service()->about());
		$this->assertSame([], $this->service()->missingBasics());
	}

	public function testAContactAccountThatIsGoneIsNoContact(): void {
		$this->app[ConfigService::SOCIAL_CONTACT_ACCOUNT] = 'deleted';

		$this->assertNull($this->service()->about()['contact']['account']);
		$this->assertContains(AboutService::MISSING_CONTACT, $this->service()->missingBasics());
	}

	public function testALinkThatIsNotAWebAddressIsNotShown(): void {
		$this->theming = ['privacyUrl' => 'javascript:alert(1)', 'imprintUrl' => '  '];

		$about = $this->service()->about();

		$this->assertSame('', $about['privacy_policy_url']);
		$this->assertSame('', $about['legal_notice_url']);
	}

	public function testEverythingMissingIsNamedInOrder(): void {
		$this->assertSame(
			[AboutService::MISSING_CONTACT, AboutService::MISSING_RULES, AboutService::MISSING_PRIVACY, AboutService::MISSING_LEGAL_NOTICE],
			$this->service()->missingBasics()
		);

		$this->app[ConfigService::SOCIAL_CONTACT_ACCOUNT] = 'carol';
		$this->contact = new Person();
		$this->contact->setPreferredUsername('carol');
		$this->contact->setLocal(true);
		$this->assertNotContains(AboutService::MISSING_CONTACT, $this->service()->missingBasics(), 'an account is contact enough');
	}

	public function testWhetherTheServerIsPublicOrFederating(): void {
		$this->assertFalse($this->service()->isOpenForSignUp());
		$this->assertTrue($this->service()->isFederating(), 'the fediverse is open unless an allow list says otherwise');

		$this->app[ConfigService::SOCIAL_ACCESS_TYPE] = 'none_but';
		$this->assertFalse($this->service()->isFederating(), 'an empty allow list federates with nobody');

		$this->app[ConfigService::SOCIAL_ACCESS_LIST] = '["friends.example"]';
		$this->assertTrue($this->service()->isFederating());

		$this->app[ConfigService::SOCIAL_ACCESS_LIST] = '[]';
		$this->app[ConfigService::ATPROTO_ENABLED] = '1';
		$this->assertTrue($this->service()->isFederating(), 'Bluesky is another network to reach');

		$this->app[ConfigService::SOCIAL_EXTERNAL_ENABLED] = '1';
		$this->assertTrue($this->service()->isOpenForSignUp());
	}

	public function testNoAppAddressBeforeTheServerIsSetUpForApps(): void {
		$this->assertSame(['mastodon' => '', 'bluesky' => ''], $this->service()->appAddresses());
	}

	/** a Mastodon app builds `https://<domain>/api/v1/...`, so only a working root rewrite earns the domain */
	public function testTheDomainIsOfferedToMastodonAppsOnceTheClientApiAnswersAtTheRoot(): void {
		$this->clientApiAtRoot = true;
		$this->cloudAuthority = 'cloud.example:8443';

		$this->assertSame(['mastodon' => 'cloud.example:8443', 'bluesky' => ''], $this->service()->appAddresses());
	}

	public function testBlueskyAppsAreGivenTheHostingProviderWhileBlueskyIsOn(): void {
		$this->app[ConfigService::ATPROTO_ENABLED] = '1';

		$this->assertSame(['mastodon' => '', 'bluesky' => 'https://cloud.example'], $this->service()->appAddresses());
	}

	public function testNoAddressWhileTheServerHasNoneConfirmed(): void {
		$this->clientApiAtRoot = true;
		$this->app[ConfigService::ATPROTO_ENABLED] = '1';
		$this->cloudAuthority = '';

		$this->assertSame(['mastodon' => '', 'bluesky' => ''], $this->service()->appAddresses());
	}
}
