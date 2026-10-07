<?php
declare(strict_types=1);
namespace OCA\Social\Atproto\Repository;
use OCA\Social\Atproto\Protocol\Cid;
use OCP\IDBConnection;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\DB\QueryBuilder\IQueryBuilder;
class BlobService {
	public const MAX_IMAGE_SIZE = 2_000_000;
	public function __construct(private readonly IDBConnection $db, private readonly IAppData $appData) {}
	private function folder(): ISimpleFolder {
		try { return $this->appData->getFolder('atproto-blobs'); } catch (NotFoundException) { return $this->appData->newFolder('atproto-blobs'); }
	}
	public function storeImage(string $did, string $bytes, ?string $documentId = null): array {
		if (strlen($bytes) > 20 * 1024 * 1024) { throw new \InvalidArgumentException('Source image exceeds limit'); }
		$info = @getimagesizefromstring($bytes);
		if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) || $info[0] * $info[1] > 40_000_000) { throw new \InvalidArgumentException('Unsupported image'); }
		$image = @imagecreatefromstring($bytes); if (!$image) { throw new \InvalidArgumentException('Invalid image'); }
		try {
			$scale = min(1, 2000 / max($info[0], $info[1])); $width = max(1, (int)($info[0] * $scale)); $height = max(1, (int)($info[1] * $scale));
			if ($scale < 1) { $scaled = imagescale($image, $width, $height); if (!$scaled) { throw new \RuntimeException('Image resize failed'); } $image = $scaled; }
			// Re-encoding deliberately strips EXIF/XMP metadata before federation.
			ob_start(); imagejpeg($image, null, 85); $bytes = ob_get_clean();
			if (strlen($bytes) > self::MAX_IMAGE_SIZE) { ob_start(); imagejpeg($image, null, 65); $bytes = ob_get_clean(); }
		} finally { imagedestroy($image); }
		if (strlen($bytes) > self::MAX_IMAGE_SIZE) { throw new \InvalidArgumentException('Image exceeds Bluesky limit after compression'); }
		$cid = Cid::hash($bytes, 0x55); $folder = $this->folder();
		if (!$folder->fileExists($cid)) { $folder->newFile($cid, $bytes); }
		if (!$this->getBlob($did, $cid)) {
			$qb = $this->db->getQueryBuilder(); $qb->insert('social_atproto_blob')->values(['did' => $qb->createNamedParameter($did), 'cid' => $qb->createNamedParameter($cid),
				'document_id' => $qb->createNamedParameter($documentId, $documentId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR),
				'mime' => $qb->createNamedParameter('image/jpeg'), 'size' => $qb->createNamedParameter(strlen($bytes), IQueryBuilder::PARAM_INT)])->executeStatement();
		}
		return ['blob' => ['$type' => 'blob', 'ref' => ['$link' => $cid], 'mimeType' => 'image/jpeg', 'size' => strlen($bytes)], 'aspectRatio' => ['width' => $width, 'height' => $height]];
	}
	public function getBlob(string $did, string $cid): ?array {
		$qb = $this->db->getQueryBuilder(); $qb->select('*')->from('social_atproto_blob')->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))->andWhere($qb->expr()->eq('cid', $qb->createNamedParameter($cid)));
		return $qb->executeQuery()->fetchAssociative() ?: null;
	}
	public function read(string $did, string $cid): ?array {
		$blob = $this->getBlob($did, $cid); if (!$blob) { return null; }
		$bytes = $this->folder()->getFile($cid)->getContent();
		if (Cid::hash($bytes, 0x55) !== $cid) { throw new \RuntimeException('Stored blob hash mismatch'); }
		return ['bytes' => $bytes, 'mime' => $blob['mime']];
	}
	public function listBlobs(string $did): array {
		$qb = $this->db->getQueryBuilder(); $qb->select('cid')->from('social_atproto_blob')->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))->orderBy('cid', 'ASC'); return $qb->executeQuery()->fetchAllAssociative();
	}
}
