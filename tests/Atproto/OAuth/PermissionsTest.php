<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Atproto\OAuth;

use OCA\Social\Atproto\OAuth\Permissions;
use PHPUnit\Framework\TestCase;

class PermissionsTest extends TestCase {
	public function testTheSpecsExamplesAreRead(): void {
		$this->assertSame(['resource' => 'identity', 'attr' => '*'], Permissions::parse('identity:*'));
		$this->assertSame(['resource' => 'rpc', 'lxm' => ['*'], 'aud' => 'did:web:api.example.com#svc_appview'], Permissions::parse('rpc?lxm=*&aud=did:web:api.example.com%23svc_appview'));
		$this->assertSame(['resource' => 'blob', 'accept' => ['video/*', 'text/html']], Permissions::parse('blob?accept=video/*&accept=text/html'));
		$this->assertSame(['resource' => 'repo', 'collection' => ['app.example.profile'], 'action' => ['create', 'update', 'delete']], Permissions::parse('repo:app.example.profile?action=create&action=update&action=delete'));
		$this->assertSame(['resource' => 'include', 'nsid' => 'app.example.authFull', 'aud' => 'did:web:api.example.com#svc_chat'], Permissions::parse('include:app.example.authFull?aud=did:web:api.example.com%23svc_chat'));
		$this->assertSame(['resource' => 'account', 'attr' => 'repo', 'action' => ['manage']], Permissions::parse('account:repo?action=manage'));
		$this->assertSame(['resource' => 'repo', 'collection' => ['*'], 'action' => ['create', 'update', 'delete']], Permissions::parse('repo:*'), 'every action unless named');
	}

	public function testWhatIsWrittenWrongIsNoScope(): void {
		foreach (['repo', 'repo:not a nsid', 'repo:app.bsky.feed.post?action=destroy', 'rpc:*?aud=*', 'rpc:app.bsky.feed.getTimeline', 'blob:images', 'account:phone', 'identity:email', 'include:nope', 'chat:everything', 'transition:generic'] as $scope) {
			$this->assertNull(Permissions::parse($scope), $scope);
		}
	}

	public function testTheTransitionalScopesAllowWhatAnAppPasswordDoesButChat(): void {
		$generic = Permissions::of(['atproto', 'transition:generic']);

		$this->assertTrue($generic->mayWrite('app.bsky.feed.post', 'delete'));
		$this->assertTrue($generic->mayCall('app.bsky.feed.getTimeline', 'did:web:api.bsky.app#bsky_appview'));
		$this->assertFalse($generic->mayCall('chat.bsky.convo.listConvos', 'did:web:api.bsky.chat#bsky_chat'));
		$this->assertTrue($generic->mayUpload('video/mp4'));
		$this->assertFalse($generic->mayReadEmail());
		$this->assertTrue(Permissions::of(['atproto', 'transition:email'])->mayReadEmail());
	}

	public function testTheChatScopeReachesTheDirectMessagesAndNothingElse(): void {
		$chat = Permissions::of(['atproto', 'transition:chat.bsky']);

		$this->assertTrue($chat->mayCall('chat.bsky.convo.listConvos', 'did:web:api.bsky.chat#bsky_chat'));
		$this->assertFalse($chat->mayCall('app.bsky.feed.getTimeline', 'did:web:api.bsky.app#bsky_appview'));
		$this->assertTrue(Permissions::of(['atproto', 'transition:generic', 'transition:chat.bsky'])->mayCall('chat.bsky.convo.sendMessage', 'did:web:api.bsky.chat#bsky_chat'));
	}

	public function testGranularScopesAllowWhatTheyNameOnly(): void {
		$permissions = Permissions::of([
			'atproto', 'repo:app.bsky.feed.like?action=create&action=delete', 'rpc:app.bsky.feed.getTimeline?aud=did:web:api.bsky.app%23bsky_appview',
			'rpc:app.bsky.video.getUploadLimits?aud=*', 'blob:image/*', 'account:email',
		]);

		$this->assertTrue($permissions->mayWrite('app.bsky.feed.like', 'delete'));
		$this->assertFalse($permissions->mayWrite('app.bsky.feed.like', 'update'));
		$this->assertFalse($permissions->mayWrite('app.bsky.feed.post', 'create'));
		$this->assertTrue($permissions->mayCall('app.bsky.feed.getTimeline', 'did:web:api.bsky.app#bsky_appview'));
		$this->assertTrue($permissions->mayCall('app.bsky.feed.getTimeline', 'did:web:api.bsky.app'), 'a bare DID for any of its services');
		$this->assertFalse($permissions->mayCall('app.bsky.feed.getTimeline', 'did:web:other.example#bsky_appview'));
		$this->assertTrue($permissions->mayCall('app.bsky.video.getUploadLimits', 'did:web:video.bsky.app'));
		$this->assertFalse($permissions->mayCall('app.bsky.feed.getAuthorFeed', 'did:web:api.bsky.app#bsky_appview'));
		$this->assertTrue($permissions->mayUpload('image/jpeg; charset=binary'));
		$this->assertFalse($permissions->mayUpload('video/mp4'));
		$this->assertTrue($permissions->mayReadEmail());
		$this->assertFalse(Permissions::of(['atproto'])->mayCall('app.bsky.feed.getTimeline', 'did:web:api.bsky.app#bsky_appview'), 'atproto alone allows nothing');
	}
}
