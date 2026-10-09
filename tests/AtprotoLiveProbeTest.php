<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests;

use PHPUnit\Framework\TestCase;

/**
 * `contrib/atproto-live-probe.sh` is run by hand on a live host, so nothing
 * else notices when it stops parsing; and the checks it makes are the ones
 * the live test tells an administrator about.
 */
class AtprotoLiveProbeTest extends TestCase {
	private const SCRIPT = __DIR__ . '/../contrib/atproto-live-probe.sh';

	public function testTheScriptParsesAndAsksForWhatItNeeds(): void {
		exec('bash -n ' . escapeshellarg(self::SCRIPT) . ' 2>&1', $output, $status);
		$this->assertSame(0, $status, implode("\n", $output));

		$output = [];
		exec('bash ' . escapeshellarg(self::SCRIPT) . ' 2>&1', $output, $status);
		$this->assertSame(64, $status);
		$this->assertStringContainsString('usage:', implode("\n", $output));
	}

	public function testTheLiveTestRunsTheProbe(): void {
		$guide = (string)file_get_contents(__DIR__ . '/../docs/Atproto-Live-Test.md');

		$this->assertStringContainsString('contrib/atproto-live-probe.sh <host> alice.<host>', $guide);
	}
}
