<?php
declare(strict_types=1);
namespace OCA\Social\Atproto\Repository;
use OCA\Social\Atproto\Protocol\{Bytes, Cid, DagCbor};
use OCA\Social\Atproto\Identity\KeyManager;
final class Commit {
	public const VERSION = 3;
	public function __construct(public readonly string $did, public readonly string $dataCid, public readonly string $rev,
		public readonly ?string $prevCid, public readonly string $sig) {}
	public static function create(string $did, string $dataCid, string $rev, ?string $prevCid, string $signingKey, ?KeyManager $keys = null): self {
		$unsigned = ['version' => 3, 'did' => $did, 'data' => new Cid($dataCid), 'rev' => $rev, 'prev' => null];
		$signature = ($keys ?? new KeyManager(null, null))->sign(DagCbor::encode($unsigned), $signingKey);
		return new self($did, $dataCid, $rev, null, $signature);
	}
	public function toCbor(): string {
		return DagCbor::encode(['version' => 3, 'did' => $this->did, 'data' => new Cid($this->dataCid), 'rev' => $this->rev, 'prev' => null, 'sig' => new Bytes($this->sig)]);
	}
	public function getCid(): string { return Cid::hash($this->toCbor()); }
}
