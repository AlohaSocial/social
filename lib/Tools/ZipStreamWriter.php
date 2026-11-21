<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools;

use Closure;
use DateTimeInterface;
use LogicException;

/**
 * A zip file written front to back to a sink as its files arrive, never
 * seeking back, so it can stream to the browser holding one piece at a time.
 *
 * Files are stored, not compressed (mostly media already). Each one's CRC and
 * sizes follow its bytes in a data descriptor and are repeated in the central
 * directory. Past 4 GB or 65535 files, offsets and end records are ZIP64.
 * Only a file declared as 4 GB or more gets ZIP64 sizes, since macOS's Archive
 * Utility cannot read those descriptors; a file of unknown size must stay
 * under 4 GB.
 */
class ZipStreamWriter {
	/** what is collected before the sink is called, so headers do not go out one by one */
	private const BUFFER = 64 * 1024;
	private const LIMIT_32 = 0xFFFFFFFF;
	private const LIMIT_16 = 0xFFFF;
	/** bit 3: CRC and sizes in a data descriptor; bit 11: the name is UTF-8 */
	private const FLAGS = 0x0808;
	private const VERSION = 20;
	private const VERSION_ZIP64 = 45;

	private string $buffer = '';
	private int $offset = 0;
	/** @var list<array{name: string, crc: int, size: int, offset: int, zip64: bool}> */
	private array $entries = [];
	private bool $finished = false;
	private int $dosTime;
	private int $dosDate;

	/**
	 * @param Closure(string): void $sink takes the archive's bytes, in order
	 * @param DateTimeInterface $time when every file is said to be from
	 */
	public function __construct(
		private Closure $sink,
		DateTimeInterface $time,
	) {
		$year = max(1980, (int)$time->format('Y'));
		$this->dosDate = (($year - 1980) << 9) | ((int)$time->format('n') << 5) | (int)$time->format('j');
		$this->dosTime = ((int)$time->format('G') << 11) | ((int)$time->format('i') << 5) | intdiv((int)$time->format('s'), 2);
	}

	/**
	 * Adds a file read from a stream, a chunk at a time; the stream stays
	 * the caller's.
	 *
	 * @param resource $stream
	 */
	public function addStream(string $name, $stream): void {
		$stat = fstat($stream);
		$size = $stat !== false && ($stat['mode'] & 0170000) === 0100000 ? $stat['size'] : null;
		$this->addPieces($name, self::chunks($stream), $size);
	}

	/**
	 * Adds a file from its bytes in pieces, each written as it comes.
	 *
	 * @param iterable<string> $pieces
	 * @param int|null $size how large it will be, when that is known: 4 GB or more for ZIP64 sizes
	 * @throws LogicException when a file not said to be 4 GB or more is
	 */
	public function addPieces(string $name, iterable $pieces, ?int $size = null): void {
		if ($this->finished) {
			throw new LogicException('the archive is finished');
		}
		$zip64 = $size !== null && $size >= self::LIMIT_32;
		$offset = $this->offset;
		$extra = $zip64 ? pack('vvPP', 0x0001, 16, 0, 0) : '';
		$this->write(pack('VvvvvvVVVvv', 0x04034b50, $zip64 ? self::VERSION_ZIP64 : self::VERSION, self::FLAGS, 0, $this->dosTime, $this->dosDate, 0, $zip64 ? self::LIMIT_32 : 0, $zip64 ? self::LIMIT_32 : 0, strlen($name), strlen($extra)) . $name . $extra);

		$crc = hash_init('crc32b');
		$written = 0;
		foreach ($pieces as $piece) {
			hash_update($crc, $piece);
			$written += strlen($piece);
			$this->write($piece);
		}
		if (!$zip64 && $written >= self::LIMIT_32) {
			throw new LogicException($name . ' is 4 GB or more, which was not said before it was written');
		}
		$crc = (int)unpack('N', hash_final($crc, true))[1];
		$this->write($zip64 ? pack('VVPP', 0x08074b50, $crc, $written, $written) : pack('VVVV', 0x08074b50, $crc, $written, $written));

		$this->entries[] = ['name' => $name, 'crc' => $crc, 'size' => $written, 'offset' => $offset, 'zip64' => $zip64];
	}

	/**
	 * Writes the central directory and the end records, and hands over
	 * what is still buffered. Nothing can be added after.
	 */
	public function finish(): void {
		if ($this->finished) {
			return;
		}
		$this->finished = true;
		$start = $this->offset;
		foreach ($this->entries as $entry) {
			$this->write($this->centralEntry($entry));
		}
		$size = $this->offset - $start;
		$count = count($this->entries);
		if ($count >= self::LIMIT_16 || $size >= self::LIMIT_32 || $start >= self::LIMIT_32) {
			$record = $this->offset;
			$this->write(pack('VPvvVVPPPP', 0x06064b50, 44, self::VERSION_ZIP64, self::VERSION_ZIP64, 0, 0, $count, $count, $size, $start));
			$this->write(pack('VVPV', 0x07064b50, 0, $record, 1));
		}
		$this->write(pack(
			'VvvvvVVv',
			0x06054b50, 0, 0,
			min($count, self::LIMIT_16), min($count, self::LIMIT_16),
			min($size, self::LIMIT_32), min($start, self::LIMIT_32),
			0,
		));
		$this->flush();
	}

	/**
	 * @param array{name: string, crc: int, size: int, offset: int, zip64: bool} $entry
	 */
	private function centralEntry(array $entry): string {
		// the ZIP64 field holds, in this order, those of the three that do not fit
		$extra = '';
		$size = $entry['size'];
		$offset = $entry['offset'];
		if ($entry['zip64']) {
			$extra .= pack('PP', $size, $size);
			$size = self::LIMIT_32;
		}
		if ($offset >= self::LIMIT_32) {
			$extra .= pack('P', $offset);
			$offset = self::LIMIT_32;
		}
		if ($extra !== '') {
			$extra = pack('vv', 0x0001, strlen($extra)) . $extra;
		}
		$version = $extra !== '' ? self::VERSION_ZIP64 : self::VERSION;

		return pack(
			'VvvvvvvVVVvvvvvVV',
			0x02014b50, $version, $version, self::FLAGS, 0, $this->dosTime, $this->dosDate,
			$entry['crc'], $size, $size,
			strlen($entry['name']), strlen($extra), 0, 0, 0, 0, $offset,
		) . $entry['name'] . $extra;
	}

	private function write(string $bytes): void {
		$this->offset += strlen($bytes);
		$this->buffer .= $bytes;
		if (strlen($this->buffer) >= self::BUFFER) {
			$this->flush();
		}
	}

	private function flush(): void {
		if ($this->buffer !== '') {
			$bytes = $this->buffer;
			$this->buffer = '';
			($this->sink)($bytes);
		}
	}

	/**
	 * @param resource $stream
	 * @return \Generator<int, string>
	 */
	private static function chunks($stream): \Generator {
		while (!feof($stream)) {
			$chunk = fread($stream, 1024 * 1024);
			if ($chunk === false) {
				throw new \RuntimeException('could not read the file');
			}
			if ($chunk !== '') {
				yield $chunk;
			}
		}
	}
}
