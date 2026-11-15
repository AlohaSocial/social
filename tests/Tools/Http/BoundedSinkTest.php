<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Aloha Social contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tests\Tools\Http;

use OCA\Social\Tools\Http\BoundedSink;
use PHPUnit\Framework\TestCase;

class BoundedSinkTest extends TestCase {
	public function testAnAnswerAtTheCeilingIsKeptWhole(): void {
		$sink = BoundedSink::temporary(8);

		$this->assertSame(4, $sink->write('abcd'));
		$this->assertSame(4, $sink->write('efgh'));

		$this->assertFalse($sink->overflowed());
		$sink->rewind();
		$this->assertSame('abcdefgh', $sink->getContents());
	}

	/**
	 * One byte past the ceiling is kept, so a reader can tell, and every
	 * write after that is refused, which is what makes curl end the transfer.
	 */
	public function testTheBytePastTheCeilingIsKeptAndTheRestRefused(): void {
		$sink = BoundedSink::temporary(8);

		$this->assertSame(9, $sink->write(str_repeat('a', 20)));
		$this->assertTrue($sink->overflowed());
		$this->assertSame(0, $sink->write('more'));
		$this->assertSame(9, $sink->getSize());
	}

	public function testTheBodyIsHandedOverAsAResource(): void {
		$sink = BoundedSink::temporary(8);
		$sink->write('abc');
		$sink->rewind();

		$resource = $sink->detach();

		$this->assertIsResource($resource);
		$this->assertSame('abc', stream_get_contents($resource));
	}
}
