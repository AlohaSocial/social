<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\AlohaDirectory;

use OCA\Social\AlohaDirectory\DirectoryConfig;
use OCA\Social\AlohaDirectory\ProfileBuilder;
use OCA\Social\Atproto\Service\AtprotoConfig;
use OCA\Social\Model\Instance;
use OCA\Social\Security\PrivateKeyCipher;
use OCA\Social\Service\ExternalUserService;
use OCA\Social\Service\InstanceService;
use OCP\IAppConfig;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class ProfileBuilderTest extends TestCase {
	private AppConfigStore $store;
	private string $mode = ExternalUserService::MODE_APPROVAL;
	private string $image = 'https://cloud.example.com/apps/social/img/social.svg';
	private ProfileBuilder $builder;

	protected function setUp(): void {
		$this->store = new AppConfigStore();
		$config = new DirectoryConfig($this->store->mock($this), $this->createStub(PrivateKeyCipher::class));

		$instances = $this->createStub(InstanceService::class);
		$instances->method('getLocal')->willReturnCallback(fn (): Instance => (new Instance())
			->setTitle('Cloud Social')
			->setShortDescription('<p>A friendly server.</p>')
			->setDescription('A friendly server for everyone.')
			->setEmail('admin@example.com')
			->setImage($this->image)
			->setLanguages(['de']));
		$instances->method('rules')->willReturn([['id' => '1', 'text' => 'Be kind.'], ['id' => '2', 'text' => 'No spam.']]);

		$external = $this->createStub(ExternalUserService::class);
		$external->method('mode')->willReturnCallback(fn (): string => $this->mode);
		$external->method('verifiesEmail')->willReturn(true);
		$external->method('minimumAge')->willReturn(16);
		$external->method('mediaQuota')->willReturn(0);

		$atproto = $this->createStub(AtprotoConfig::class);
		$atproto->method('isEnabled')->willReturn(true);
		$atproto->method('handleHost')->willReturn('cloud.example.com');

		$theming = $this->createStub(IAppConfig::class);
		$theming->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => $app . '.' . $key === 'theming.privacyUrl' ? 'https://example.com/privacy' : $default
		);
		$urls = $this->createStub(IURLGenerator::class);
		$urls->method('linkToRouteAbsolute')->willReturn('https://cloud.example.com/apps/social/');

		$this->builder = new ProfileBuilder($config, $instances, $external, $atproto, $theming, $urls);
	}

	public function testWhatTheServerKnowsIsTakenFromIt(): void {
		$profile = $this->builder->build();

		$this->assertSame('Cloud Social', $profile['identity']['name']);
		$this->assertSame('A friendly server.', $profile['identity']['description'], 'markup is stripped');
		$this->assertArrayNotHasKey('logo_url', $profile['identity'], 'the default SVG cannot be re-encoded by the directory');
		$this->assertSame(['Be kind.', 'No spam.'], $profile['terms']['rules']);
		$this->assertSame('https://example.com/privacy', $profile['terms']['privacy_url']);
		$this->assertSame('https://cloud.example.com/apps/social/about', $profile['terms']['terms_url']);
		$this->assertSame(16, $profile['terms']['min_age']);
		$this->assertSame(['de'], $profile['audience']['languages']);
		$this->assertSame('approval', $profile['account']['signup_mode']);
		$this->assertSame('admin@example.com', $profile['moderation']['abuse_email']);
		$this->assertSame('alice.cloud.example.com', $profile['atproto']['handle_example']);
		$this->assertMatchesRegularExpression('/^W\/"[0-9a-f]{16}"$/', $profile['etag']);
	}

	public function testWhatOnlyTheOperatorKnowsIsMissingUntilTheFormSaysIt(): void {
		$this->assertContains('operator.name', $this->builder->missing());
		$this->assertContains('account.storage_mb', $this->builder->missing());

		$this->builder->saveForm([
			'jurisdiction' => ['hosting_country' => 'de', 'operator_seat' => 'DE', 'gdpr' => false],
			'operator' => ['name' => 'Example e.V.', 'type' => 'association', 'funding' => 'Donations', 'commitment_until' => '2028-12', 'shutdown_notice_months' => '6'],
			'moderation' => ['moderators' => 3, 'response_hours' => 24],
			'account' => ['storage_mb' => 2048],
		]);

		$this->assertSame([], $this->builder->missing());
		$profile = $this->builder->build();
		$this->assertSame('DE', $profile['jurisdiction']['hosting_country']);
		$this->assertFalse($profile['jurisdiction']['gdpr'], 'false is an answer, not a gap');
		$this->assertSame(6, $profile['operator']['shutdown_notice_months']);
	}

	public function testTheFormKeepsOnlyKnownFieldsOfTheRightType(): void {
		$form = $this->builder->saveForm([
			'operator' => ['type' => 'pirate', 'website' => 'http://insecure.example', 'name' => '  Example  '],
			'audience' => ['languages' => 'de, EN, not a language'],
			'secret' => ['x' => 'y'],
		]);

		$this->assertSame(['audience' => ['languages' => ['de', 'en']], 'operator' => ['name' => 'Example']], $form);
	}

	public function testTheFormOverridesWhatTheServerSays(): void {
		$this->builder->saveForm(['identity' => ['name' => 'Better Name'], 'terms' => ['terms_url' => 'https://example.com/terms']]);

		$profile = $this->builder->build();
		$this->assertSame('Better Name', $profile['identity']['name']);
		$this->assertSame('https://example.com/terms', $profile['terms']['terms_url']);
	}

	public function testAnInviteOnlyServerHasNoSignupModeToList(): void {
		$this->mode = ExternalUserService::MODE_INVITE;

		$this->assertContains('account.signup_mode', $this->builder->missing());
	}

	public function testARasterLogoIsOffered(): void {
		$this->image = 'https://cloud.example.com/logo.png';

		$this->assertSame('https://cloud.example.com/logo.png', $this->builder->build()['identity']['logo_url']);
	}

	public function testTheEtagFollowsTheContent(): void {
		$before = $this->builder->build()['etag'];
		$this->builder->saveForm(['identity' => ['name' => 'Renamed']]);

		$this->assertNotSame($before, $this->builder->build()['etag']);
	}
}
