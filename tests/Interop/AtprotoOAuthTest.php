<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Interop;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\OAuth\AuthorizationServer;
use OCA\Social\Tests\Interop\Bluesky\DevNetwork;
use OCP\Server;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A Bluesky app signs in with Bluesky sign-in (OAuth), by the official
 * client library (`@atproto/oauth-client-node`): it resolves the account,
 * finds this server through the PDS's protected resource metadata, pushes
 * its request with DPoP, and — after the person agrees — exchanges the code,
 * uses the PDS, refreshes and signs out. The person's agreement is what
 * the consent page's button does, made here directly.
 */
class AtprotoOAuthTest extends TestCase {
	private DevNetwork $network;
	private LocalAccount $alice;
	private string $stateDir;

	protected function setUp(): void {
		$network = DevNetwork::fromEnvironment();
		if ($network === null) {
			$this->markTestSkipped('no Bluesky development network (ATPROTO_NETWORK_FILE)');
		}
		$this->network = $network;
		$this->alice = LocalAccount::create('oa');
		$this->stateDir = sys_get_temp_dir() . '/atproto-oauth-' . bin2hex(random_bytes(4));
	}

	public function testABlueskyAppSignsInWithOAuthAndActsForTheAccount(): void {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$userId = $this->alice->actor->getUserId();
		$words = 'Posted with Bluesky sign-in ' . bin2hex(random_bytes(4));

		$authorizeUrl = trim($this->client('start', $identity->did, $words));
		$this->assertStringStartsWith((string)(getenv('NEXTCLOUD_URL') ?: 'https://nextcloud.test') . '/oauth/authorize?', $authorizeUrl, 'the shared authorization endpoint');
		parse_str((string)parse_url($authorizeUrl, PHP_URL_QUERY), $query);
		$this->assertStringStartsWith(AuthorizationServer::REQUEST_URI_PREFIX, (string)$query['request_uri'], 'a pushed request');

		// the person presses Allow on the consent page
		$oauth = Server::get(AuthorizationServer::class);
		$this->assertSame(['atproto', 'transition:generic'], $oauth->pending((string)$query['client_id'], (string)$query['request_uri'], $userId)['scopes']);
		$callback = $oauth->approve((string)$query['client_id'], (string)$query['request_uri'], $userId);
		$this->assertStringStartsWith('http://127.0.0.1/callback?', $callback);

		$result = json_decode($this->client('finish', (string)parse_url($callback, PHP_URL_QUERY), $words), true);
		$this->assertIsArray($result);
		$this->assertSame($identity->did, $result['did'], 'the account the app verified');
		$this->assertSame('atproto transition:generic', $result['scope']);
		$this->assertSame(200, $result['who']['status'], json_encode($result['who']));
		$this->assertSame($identity->handle, $result['who']['body']['handle']);
		$this->assertSame(200, $result['timeline']['status'], 'the AppView proxied with the session');
		$this->assertSame(200, $result['posted']['status'], json_encode($result['posted']));
		$this->assertStringStartsWith('at://' . $identity->did . '/app.bsky.feed.post/', (string)$result['posted']['body']['uri']);
		$this->assertSame(200, $result['again']['status'], 'after a refresh');

		$mine = array_filter($this->alice->statusesOf($this->alice->accountId()), static fn (array $s): bool => str_contains((string)($s['content'] ?? ''), $words));
		$this->assertCount(1, $mine, 'the post is a Social post');
		$this->assertSame([], $oauth->sessionsOf($userId), 'signing out ended the session');
	}

	public function testAnAppGivenGranularPermissionsMayDoWhatTheyNameOnly(): void {
		$identity = Server::get(IdentityService::class)->forActor($this->alice->actor);
		$this->assertNotNull($identity);
		$userId = $this->alice->actor->getUserId();
		$words = 'Posted with a narrow sign-in ' . bin2hex(random_bytes(4));
		$scope = 'atproto repo:app.bsky.feed.post?action=create';

		parse_str((string)parse_url(trim($this->client('start', $identity->did, $words, $scope)), PHP_URL_QUERY), $query);
		$oauth = Server::get(AuthorizationServer::class);
		$this->assertSame(['atproto', 'repo:app.bsky.feed.post?action=create'], $oauth->pending((string)$query['client_id'], (string)$query['request_uri'], $userId)['scopes']);
		$callback = $oauth->approve((string)$query['client_id'], (string)$query['request_uri'], $userId);
		$result = json_decode($this->client('finish', (string)parse_url($callback, PHP_URL_QUERY), $words, $scope), true);

		$this->assertIsArray($result);
		$this->assertSame($scope, $result['scope']);
		$this->assertSame(200, $result['who']['status'], 'who it is, always');
		$this->assertSame(200, $result['posted']['status'], 'a post: what it was given ' . json_encode($result['posted']));
		$this->assertSame(403, $result['timeline']['status'], 'the timeline: not given');
		$this->assertSame([403, 'InsufficientScope'], [$result['liked']['status'], $result['liked']['error']], 'a like: not given');
	}

	/**
	 * Runs the client library's script: one step of the sign-in.
	 */
	private function client(string $command, string $argument, string $words, string $scope = ''): string {
		$process = proc_open(
			['node', 'oauth-client.mjs', $command, $argument],
			[1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
			__DIR__ . '/atproto',
			[
				'PATH' => (string)getenv('PATH'),
				'HOME' => (string)getenv('HOME'),
				'OAUTH_STATE_DIR' => $this->stateDir,
				'PLC_URL' => $this->network->plc,
				'OAUTH_POST_TEXT' => $words,
				'OAUTH_SCOPE' => $scope,
				'NODE_EXTRA_CA_CERTS' => (string)(getenv('NODE_EXTRA_CA_CERTS') ?: '/tmp/tls/ca.crt'),
			]
		);
		if (!is_resource($process)) {
			throw new RuntimeException('node did not start');
		}
		$out = (string)stream_get_contents($pipes[1]);
		$err = (string)stream_get_contents($pipes[2]);
		$status = proc_close($process);
		if ($status !== 0) {
			throw new RuntimeException('oauth-client.mjs ' . $command . ' failed (' . $status . '): ' . $err . $out);
		}

		return $out;
	}
}
