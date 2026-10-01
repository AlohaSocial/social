<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Cron;

use OCP\BackgroundJob\QueuedJob;
use OCP\BackgroundJob\TimedJob;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every job in `lib/Cron/` is one Nextcloud will actually run.
 *
 * A `TimedJob` runs only once something has added it to the job list, and for
 * a job that takes no argument that is `<background-jobs>` in
 * `appinfo/info.xml`. `Cron\BlocklistSync` was written, tested and documented
 * as the daily re-read of followed block lists, and never listed there: the
 * lists were read once, when an administrator pressed *Check now*, and never
 * again. Nothing but this would notice the next one.
 */
class BackgroundJobsRegisteredTest extends TestCase {
	/**
	 * Jobs that are added on demand with an argument and must not be in
	 * info.xml, which would add them once, at install, with none.
	 */
	private const QUEUED_ONLY = [
		'OCA\\Social\\Cron\\ActorCleanup',
		'OCA\\Social\\Cron\\DomainPurge',
		'OCA\\Social\\Cron\\ExternalPromoted',
		'OCA\\Social\\Cron\\TranscodeBeforeDelivery',
	];

	/** @return list<string> the job classes info.xml registers */
	private function registered(): array {
		$info = simplexml_load_file(__DIR__ . '/../../appinfo/info.xml');
		$this->assertNotFalse($info);

		$jobs = [];
		foreach ($info->{'background-jobs'}->job ?? [] as $job) {
			$jobs[] = trim((string)$job);
		}

		return $jobs;
	}

	/** @return array<string, class-string> every class in lib/Cron by name */
	private function cronClasses(): array {
		$classes = [];
		foreach (glob(__DIR__ . '/../../lib/Cron/*.php') ?: [] as $file) {
			$class = 'OCA\\Social\\Cron\\' . basename($file, '.php');
			$this->assertTrue(class_exists($class), $class . ' does not load');
			$classes[$class] = $class;
		}

		return $classes;
	}

	public function testEveryTimedJobIsRegisteredInInfoXml(): void {
		$registered = $this->registered();

		foreach ($this->cronClasses() as $class) {
			if (!(new ReflectionClass($class))->isSubclassOf(TimedJob::class)) {
				continue;
			}

			$this->assertContains(
				$class,
				$registered,
				$class . ' is a TimedJob that nothing schedules: add it to <background-jobs> in appinfo/info.xml'
			);
		}
	}

	public function testTheQueuedOnlyJobsAreQueuedJobsAndNotRegistered(): void {
		$registered = $this->registered();

		foreach (self::QUEUED_ONLY as $class) {
			$this->assertTrue((new ReflectionClass($class))->isSubclassOf(QueuedJob::class), $class);
			$this->assertNotContains($class, $registered, $class . ' needs an argument and cannot be registered');
		}
	}

	/** A job that is neither is one nobody has decided about. */
	public function testEveryJobIsRegisteredOrQueuedOnly(): void {
		$registered = $this->registered();

		foreach ($this->cronClasses() as $class) {
			$this->assertTrue(
				in_array($class, $registered, true) || in_array($class, self::QUEUED_ONLY, true),
				$class . ' is neither in appinfo/info.xml nor listed here as queued on demand'
			);
		}
	}

	public function testEverythingInfoXmlRegistersExists(): void {
		foreach ($this->registered() as $class) {
			$this->assertTrue(class_exists($class), $class . ' is registered in appinfo/info.xml and does not exist');
		}
	}
}
