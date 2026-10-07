<?php
declare(strict_types=1);

namespace OCA\Social\Atproto\Repository;

use OCA\Social\Service\DocumentService;
use OCA\Social\Atproto\Identity\KeyManager;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

class BlobService {
	public const MAX_IMAGE_SIZE = 2_000_000; // 2MB per Bluesky spec
	public const MAX_VIDEO_SIZE = 300_000_000; // 300MB
	
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
		private readonly DocumentService $documentService,
		private readonly KeyManager $keyManager
	) {}
	
	public function uploadBlob(string $did, int $documentId, string $mimeType): array {
		$document = $this->documentService->getDocument($documentId);
		if (!$document) {
			throw new \InvalidArgumentException('Document not found');
		}
		
		$filePath = $this->documentService->getDocumentPath($document);
		if (!$filePath || !file_exists($filePath)) {
			throw new \RuntimeException('Document file not found');
		}
		
		$size = filesize($filePath);
		if ($size === false) {
			throw new \RuntimeException('Could not get file size');
		}
		
		// Check size limits
		if (str_starts_with($mimeType, 'image/') && $size > self::MAX_IMAGE_SIZE) {
			throw new \RuntimeException('Image exceeds 2MB limit');
		}
		
		if (str_starts_with($mimeType, 'video/') && $size > self::MAX_VIDEO_SIZE) {
			throw new \RuntimeException('Video exceeds 300MB limit');
		}
		
		// Read file and compute CID
		$content = file_get_contents($filePath);
		if ($content === false) {
			throw new \RuntimeException('Could not read file');
		}
		
		$hash = hash('sha256', $content, true);
		$multicodec = hex2bin('55'); // raw multicodec
		$multihash = hex2bin('1220') . $hash;
		$cidBytes = $multicodec . $multihash;
		$cid = 'b' . MerkleSearchTree::base32Encode($cidBytes);
		
		// Store blob reference
		$qb = $this->db->getQueryBuilder();
		$qb->insert('social_atproto_blob')
			->values([
				'did' => $qb->createNamedParameter($did),
				'cid' => $qb->createNamedParameter($cid),
				'document_id' => $qb->createNamedParameter($documentId, \PDO::PARAM_INT),
				'mime' => $qb->createNamedParameter($mimeType),
				'size' => $qb->createNamedParameter($size, \PDO::PARAM_INT)
			])
			->executeStatement();
		
		return ['cid' => $cid, 'mimeType' => $mimeType, 'size' => $size];
	}
	
	public function getBlob(string $did, string $cid): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_atproto_blob')
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->andWhere($qb->expr()->eq('cid', $qb->createNamedParameter($cid)));
		
		return $qb->executeQuery()->fetchAssociative() ?: null;
	}
	
	public function listBlobs(string $did): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_atproto_blob')
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)));
		
		return $qb->executeQuery()->fetchAllAssociative();
	}
	
	public function deleteBlob(string $did, string $cid): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete('social_atproto_blob')
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->andWhere($qb->expr()->eq('cid', $qb->createNamedParameter($cid)))
			->executeStatement();
	}
	
	public function cleanupUnreferencedBlobs(string $did): void {
		// Find blobs not referenced by any record
		// This is simplified - real implementation would check all records
		$qb = $this->db->getQueryBuilder();
		$qb->delete('social_atproto_blob')
			->where($qb->expr()->eq('did', $qb->createNamedParameter($did)))
			->andWhere(
				$qb->expr()->notIn(
					'cid',
					$qb->createNamedParameter($this->getReferencedBlobCids($did))
				)
			)
			->executeStatement();
	}
	
	private function getReferencedBlobCids(string $did): array {
		// Would scan records for blob references
		// Simplified for now
		return [];
	}
}