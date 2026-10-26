<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\AppInfo;

use PHPUnit\Framework\TestCase;
use RuntimeException;

class ComposerInstallRetryTest extends TestCase {
	private string $tempDir;

	protected function setUp(): void {
		$this->tempDir = sys_get_temp_dir() . '/social-composer-retry-' . bin2hex(random_bytes(6));
		mkdir($this->tempDir . '/bin', 0700, true);
	}

	protected function tearDown(): void {
		foreach (glob($this->tempDir . '/bin/*') ?: [] as $path) {
			@unlink($path);
		}
		@rmdir($this->tempDir . '/bin');
		@unlink($this->tempDir . '/calls');
		@rmdir($this->tempDir);
	}

	public function testGitHubArchiveTlsFailuresRetryThenFallBackToVcsSource(): void {
		$result = $this->runHelper('archive-tls', ['install', '--prefer-dist', '--no-interaction']);

		$this->assertSame(0, $result['status'], $result['stderr'] . $result['stdout']);
		$this->assertCount(6, $result['calls'], 'five dist attempts are followed by one source install');
		foreach (array_slice($result['calls'], 0, 5) as $call) {
			$this->assertStringContainsString('--prefer-dist', $call);
		}
		$this->assertStringNotContainsString('--prefer-dist', $result['calls'][5]);
		$this->assertStringContainsString('--prefer-install=source', $result['calls'][5]);
		$this->assertStringContainsString('--no-interaction', $result['calls'][5]);
	}

	public function testNonGitHubErrorsDoNotTriggerSourceFallback(): void {
		$result = $this->runHelper('ordinary-error', ['install', '--prefer-dist', '--no-interaction']);

		$this->assertSame(1, $result['status']);
		$this->assertCount(5, $result['calls']);
		foreach ($result['calls'] as $call) {
			$this->assertStringContainsString('--prefer-dist', $call);
			$this->assertStringNotContainsString('--prefer-install=source', $call);
		}
	}

	/**
	 * @param list<string> $arguments
	 * @return array{status: int, stdout: string, stderr: string, calls: list<string>}
	 */
	private function runHelper(string $scenario, array $arguments): array {
		$composer = $this->tempDir . '/bin/composer';
		file_put_contents($composer, <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$COMPOSER_CALLS"
case " $* " in
	*" --prefer-install=source "*) exit 0 ;;
esac
if [ "$COMPOSER_SCENARIO" = "archive-tls" ]; then
	echo 'Failed to download example/package from dist: curl error 35 while downloading https://api.github.com/repos/example/package/zipball/abc: TLS connect error' >&2
else
	echo 'Composer failed for another reason' >&2
fi
exit 1
SH
		);
		chmod($composer, 0700);
		$sleep = $this->tempDir . '/bin/sleep';
		file_put_contents($sleep, "#!/bin/sh\nexit 0\n");
		chmod($sleep, 0700);

		$command = ['/bin/bash', dirname(__DIR__, 2) . '/.github/scripts/composer-install-retry.sh', ...$arguments];
		$descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$process = proc_open($command, $descriptors, $pipes, $this->tempDir, [
			'PATH' => $this->tempDir . '/bin:/usr/bin:/bin',
			'COMPOSER_CALLS' => $this->tempDir . '/calls',
			'COMPOSER_SCENARIO' => $scenario,
		]);
		if (!is_resource($process)) {
			throw new RuntimeException('Could not start Composer retry helper test process');
		}
		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$status = proc_close($process);
		$calls = file($this->tempDir . '/calls', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

		return ['status' => $status, 'stdout' => $stdout ?: '', 'stderr' => $stderr ?: '', 'calls' => $calls];
	}
}
