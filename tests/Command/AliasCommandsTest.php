<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Command;

use OCA\Social\Command\Alias;
use OCA\Social\Command\NoteLike;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\LikeService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\StreamService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The debugging commands moved under `social:debug:` so `occ list social`
 * keeps them apart from the commands an administrator runs. Their former
 * names still run, out of `occ list`, so existing scripts keep working.
 */
#[AllowMockObjectsWithoutExpectations]
class AliasCommandsTest extends TestCase {
	/** former name => name now */
	private const ALIASES = [
		'social:benchmark' => 'social:debug:benchmark',
		'social:details' => 'social:debug:details',
		'social:note:boost' => 'social:debug:note:boost',
		'social:note:create' => 'social:debug:note:create',
		'social:note:like' => 'social:debug:note:like',
		'social:timeline' => 'social:debug:timeline',
	];

	/** @param class-string $class */
	private function built(string $class): Command {
		$command = (new ReflectionClass($class))->newInstanceWithoutConstructor();
		(new ReflectionMethod(Command::class, '__construct'))->invoke($command);

		return $command;
	}

	/** @return array<string, Command> former name => the command registered under it */
	private function aliases(): array {
		$aliases = [];
		foreach (glob(__DIR__ . '/../../lib/Command/Alias/*.php') ?: [] as $file) {
			$alias = $this->built('OCA\\Social\\Command\\Alias\\' . basename($file, '.php'));
			$aliases[(string)$alias->getName()] = $alias;
		}
		ksort($aliases);

		return $aliases;
	}

	public function testEveryFormerNameIsAHiddenSecondNameForTheSameCommandLine(): void {
		$aliases = $this->aliases();
		$this->assertSame(array_keys(self::ALIASES), array_keys($aliases));

		foreach ($aliases as $old => $alias) {
			$this->assertTrue($alias->isHidden(), $old . ' shows in occ list');
			$target = $this->built((string)get_parent_class($alias));
			$this->assertSame(self::ALIASES[$old], $target->getName());
			$this->assertFalse($target->isHidden(), $target->getName() . ' is hidden');
			$this->assertSame(
				$target->getDefinition()->getSynopsis(),
				$alias->getDefinition()->getSynopsis(),
				$old . ' takes another command line than ' . $target->getName()
			);
		}
	}

	public function testEveryFormerNameIsRegistered(): void {
		$info = (string)file_get_contents(__DIR__ . '/../../appinfo/info.xml');
		foreach (glob(__DIR__ . '/../../lib/Command/Alias/*.php') ?: [] as $file) {
			$this->assertStringContainsString('<command>OCA\\Social\\Command\\Alias\\' . basename($file, '.php') . '</command>', $info);
		}
	}

	/** The arguments and options reach the command, whichever name was used. */
	public function testAFormerNameRunsTheCommand(): void {
		$actor = new Person();
		$accountService = $this->createMock(AccountService::class);
		$accountService->expects($this->exactly(2))->method('getActorFromUserId')->with('alice')->willReturn($actor);
		$likeService = $this->createMock(LikeService::class);
		$likeService->expects($this->once())->method('create')->with($actor, 'https://remote.example/notes/1')->willReturn(new Stream());
		$likeService->expects($this->once())->method('delete')->with($actor, 'https://remote.example/notes/1')->willReturn(new Stream());
		$services = [$accountService, $this->createMock(StreamService::class), $likeService, $this->createMock(MiscService::class)];

		// the command echoes its result, which the tester does not capture
		ob_start();
		try {
			$this->assertSame(0, (new CommandTester(new NoteLike(...$services)))
				->execute(['user_id' => 'alice', 'note_id' => 'https://remote.example/notes/1']));
			$this->assertSame(0, (new CommandTester(new Alias\NoteLike(...$services)))
				->execute(['user_id' => 'alice', 'note_id' => 'https://remote.example/notes/1', '--unlike' => true]));
		} finally {
			ob_end_clean();
		}
	}
}
