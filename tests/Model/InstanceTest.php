<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Model;

use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\Instance;
use PHPUnit\Framework\TestCase;

class InstanceTest extends TestCase {
	public function testImportFromDatabaseReadsTheRowIncludingJsonColumns(): void {
		$instance = new Instance();

		$result = $instance->importFromDatabase([
			'local' => '1',
			'uri' => 'cloud.example.org',
			'title' => 'Aloha Social',
			'version' => '0.7.0',
			'short_description' => 'short',
			'description' => 'long',
			'email' => 'admin@cloud.example.org',
			'urls' => '{"streaming_api":"wss://cloud.example.org"}',
			'stats' => '{"user_count":3,"status_count":10,"domain_count":2}',
			'usage' => '{"users":{"active_month":2}}',
			'image' => 'https://cloud.example.org/thumb.png',
			'languages' => '["en","de"]',
			'account_prim' => 'abc',
		]);

		$this->assertSame($instance, $result);
		$this->assertTrue($instance->isLocal());
		$this->assertSame('cloud.example.org', $instance->getUri());
		$this->assertSame('Aloha Social', $instance->getTitle());
		$this->assertSame('0.7.0', $instance->getVersion());
		$this->assertSame('short', $instance->getShortDescription());
		$this->assertSame('long', $instance->getDescription());
		$this->assertSame('admin@cloud.example.org', $instance->getEmail());
		$this->assertSame(['streaming_api' => 'wss://cloud.example.org'], $instance->getUrls());
		$this->assertSame(['user_count' => 3, 'status_count' => 10, 'domain_count' => 2], $instance->getStats());
		$this->assertSame(['users' => ['active_month' => 2]], $instance->getUsage());
		$this->assertSame('https://cloud.example.org/thumb.png', $instance->getImage());
		$this->assertSame(['en', 'de'], $instance->getLanguages());
		$this->assertSame('abc', $instance->getAccountPrim());
		$this->assertFalse($instance->hasContactAccount());
	}

	private function populated(): Instance {
		return (new Instance())
			->setUri('cloud.example.org')
			->setTitle('Aloha Social')
			->setVersion('0.7.0')
			->setShortDescription('short')
			->setDescription('long')
			->setEmail('admin@cloud.example.org')
			->setUrls(['streaming_api' => 'wss://cloud.example.org'])
			->setStats(['user_count' => 3, 'status_count' => 0, 'domain_count' => 0])
			->setImage('https://cloud.example.org/thumb.png')
			->setLanguages(['en'])
			->setRegistrations(true)
			->setApprovalRequired(false)
			->setInvitesEnabled(true)
			->setConfiguration(['statuses' => ['max_characters' => 5000]])
			->setRules([['id' => '1', 'text' => 'Be kind.']]);
	}

	public function testJsonSerializeProducesTheMastodonInstanceEntity(): void {
		$json = $this->populated()->jsonSerialize();

		// assertEquals, not assertSame: the object-typed members are compared
		// by shape, not by identity
		$this->assertEquals([
			'uri' => 'cloud.example.org',
			'title' => 'Aloha Social',
			// Pleroma-style: clients gate features on the advertised version,
			// so the claim has to be one this app can honour
			'version' => '4.3.0 (compatible; Aloha Social 0.7.0)',
			'short_description' => 'short',
			'description' => 'long',
			'email' => 'admin@cloud.example.org',
			'urls' => (object)['streaming_api' => 'wss://cloud.example.org'],
			'stats' => (object)['user_count' => 3, 'status_count' => 0, 'domain_count' => 0],
			'thumbnail' => 'https://cloud.example.org/thumb.png',
			'languages' => ['en'],
			'registrations' => true,
			'approval_required' => false,
			'invites_enabled' => true,
			'configuration' => (object)['statuses' => ['max_characters' => 5000]],
			'rules' => [['id' => '1', 'text' => 'Be kind.']],
			// always present, so a client that declares it non-optional can
			// still decode the entity
			'contact_account' => null,
		], $json);
	}

	/**
	 * `urls`, `stats` and `configuration` are dictionaries in Mastodon's schema.
	 * An empty PHP array json-encodes as `[]`, and a client decoding
	 * `stats.user_count` out of a list fails and reports the whole instance as
	 * unreachable — which is what an unconfigured instance used to send.
	 */
	public function testTheDictionaryFieldsNeverSerialiseAsLists(): void {
		$json = json_decode((string)json_encode((new Instance())->jsonSerialize()), false);

		$this->assertIsObject($json->urls);
		$this->assertIsObject($json->stats);
		$this->assertIsObject($json->configuration);
	}

	public function testTheV2EntityCarriesTheSameFactsInMastodonsNewerShape(): void {
		$v2 = json_decode((string)json_encode($this->populated()->asV2()), false);

		$this->assertSame('cloud.example.org', $v2->domain);
		$this->assertSame('Aloha Social', $v2->title);
		$this->assertSame('4.3.0 (compatible; Aloha Social 0.7.0)', $v2->version);
		$this->assertSame('short', $v2->description);
		$this->assertSame('https://cloud.example.org/thumb.png', $v2->thumbnail->url);
		$this->assertSame(3, $v2->usage->users->active_month);
		$this->assertTrue($v2->registrations->enabled);
		$this->assertSame('admin@cloud.example.org', $v2->contact->email);
		$this->assertIsObject($v2->configuration->urls);
		$this->assertSame([['id' => '1', 'text' => 'Be kind.']], json_decode(json_encode($v2->rules), true));
	}

	/**
	 * The string is a promise, and the promise has to be kept in both
	 * directions: claiming too much makes a client offer a broken button,
	 * claiming too little makes it hide a finished one. This app has editing
	 * with `/source` and `/history`, v2 filters, `/api/v2/instance` and
	 * `notifications/unread_count` — all of them 4.x, all of them hidden while
	 * it said 3.5.0.
	 */
	public function testTheAdvertisedVersionIsOneTheAppCanHonour(): void {
		$this->assertSame('4.3.0', Instance::COMPAT_VERSION);
	}

	/**
	 * A client that knows about `api_versions` reads it *instead of* parsing
	 * the version string — which is the right thing to do against a fork,
	 * whose own version number says nothing about which Mastodon API it
	 * implements.
	 */
	public function testTheV2EntityNamesTheApiGenerationRatherThanOnlyAVersionString(): void {
		$v2 = json_decode((string)json_encode($this->populated()->asV2()), false);

		$this->assertSame(3, $v2->api_versions->mastodon, 'the generation 4.3 reports');
	}

	/**
	 * What it does *not* have, it says so rather than letting a client find
	 * out by calling: translation off, and no streaming URL to connect to.
	 */
	public function testTheFourPointXFeaturesThatAreMissingAreAnnouncedAsMissing(): void {
		// not the populated fixture: what `InstanceService::getLocal()` builds
		// for a real instance carries no streaming URL, because there is no
		// streaming endpoint to name
		$v2 = json_decode((string)json_encode((new Instance())->asV2()), false);

		$this->assertFalse($v2->configuration->translation->enabled);
		$this->assertSame([], (array)$v2->configuration->urls, 'no streaming endpoint to hand out');
	}

	public function testContactAccountIsExposedWhenSet(): void {
		$admin = new Person();
		$admin->setPreferredUsername('admin');
		$instance = new Instance();

		$instance->setContactAccount($admin);

		$this->assertTrue($instance->hasContactAccount());
		$this->assertSame($admin, $instance->getContactAccount());
		$this->assertSame($admin, $instance->jsonSerialize()['contact_account']);
		$this->assertSame($admin, $instance->asV2()['contact']->account);
	}

	public function testAccountPrimIsNullUntilSet(): void {
		$instance = new Instance();

		$this->assertNull($instance->getAccountPrim());
		$this->assertSame('abc', $instance->setAccountPrim('abc')->getAccountPrim());
	}
}
