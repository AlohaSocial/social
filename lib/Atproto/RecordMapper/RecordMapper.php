<?php
declare(strict_types=1);

namespace OCA\Social\Atproto\RecordMapper;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\Repository\BlobService;
use OCA\Social\Atproto\Repository\Repository;
use OCA\Social\Service\PostService;
use OCA\Social\Service\ConfigService;
use OCP\IDBConnection;
use OCP\ILogger;

class RecordMapper {
	public const BLUESKY_MAX_GRAPHEMES = 300;
	public const BLUESKY_MAX_BYTES = 3000;
	public const BLUESKY_TRUNCATE_GRAPHEMES = 280;
	public const MAX_IMAGES = 4;
	public const MAX_IMAGE_SIZE = 2_000_000;
	
	public function __construct(
		private readonly IDBConnection $db,
		private readonly ILogger $logger,
		private readonly IdentityService $identityService,
		private readonly Repository $repository,
		private readonly BlobService $blobService,
		private readonly ConfigService $configService
	) {}
	
	/**
	 * Map a Social post to AT Protocol record(s)
	 */
	public function mapPost(int $postId): ?array {
		$post = $this->getPost($postId);
		if (!$post) {
			return null;
		}
		
		// Only public posts go to Bluesky
		if ($post['visibility'] !== 'public') {
			return null;
		}
		
		$actorId = $post['actor_id'];
		$identity = $this->identityService->getIdentityByActor($actorId);
		if (!$identity || $identity['state'] !== IdentityService::STATE_ACTIVE) {
			return null;
		}
		
		$did = $identity['did'];
		
		// Generate TID for rkey
		$rkey = $this->generateTid();
		
		// Build post record
		$record = $this->buildPostRecord($post, $did, $rkey);
		
		// Handle pictures
		if (!empty($post['media_attachments'])) {
			$record = $this->addImagesToRecord($record, $post, $did);
		}
		
		// Handle quote
		if ($post['quoted_post_id']) {
			$record = $this->addQuoteToRecord($record, $post, $did);
		}
		
		// Handle reply
		if ($post['in_reply_to_id']) {
			$record = $this->addReplyToRecord($record, $post, $did);
		}
		
		// Handle poll
		if ($post['poll_id']) {
			$record = $this->addPollToRecord($record, $post);
		}
		
		// Handle content warning
		if ($post['spoiler_text']) {
			$record = $this->addContentWarning($record, $post);
		}
		
		// Handle language
		if ($post['language']) {
			$record['langs'] = [$post['language']];
		}
		
		// Handle link card
		if ($post['card_id'] && empty($post['media_attachments'])) {
			$record = $this->addLinkCard($record, $post);
		}
		
		return [
			'collection' => 'app.bsky.feed.post',
			'rkey' => $rkey,
			'record' => $record
		];
	}
	
	private function buildPostRecord(array $post, string $did, string $rkey): array {
		// Convert HTML to plain text with facets
		$textResult = $this->htmlToTextWithFacets($post['content'], $post['actor_id']);
		
		$record = [
			'$type' => 'app.bsky.feed.post',
			'text' => $textResult['text'],
			'facets' => $textResult['facets'],
			'createdAt' => $post['created_at'] ?? (new \DateTime())->format('c')
		];
		
		return $record;
	}
	
	private function htmlToTextWithFacets(string $html, int $actorId): array {
		// Use existing plainText helper
		$plainText = \OCA\Social\Utils\PlainText::convert($html);
		
		$facets = [];
		$byteOffset = 0;
		
		// Parse for URLs, mentions, hashtags
		// This is simplified - real implementation would use a proper parser
		
		// Find URLs
		preg_match_all('/\b(https?:\/\/[^\s]+)/', $plainText, $urlMatches, PREG_OFFSET_CAPTURE);
		foreach ($urlMatches[0] as $match) {
			$url = $match[0];
			$start = $match[1];
			$end = $start + strlen($url);
			
			$facets[] = [
				'index' => ['byteStart' => $start, 'byteEnd' => $end],
				'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]]
			];
		}
		
		// Find hashtags
		preg_match_all('/#(\p{L}[\p{L}\p{N}_]*)/u', $plainText, $tagMatches, PREG_OFFSET_CAPTURE);
		foreach ($tagMatches[0] as $match) {
			$tag = $match[0];
			$start = $match[1];
			$end = $start + strlen($tag);
			
			$facets[] = [
				'index' => ['byteStart' => $start, 'byteEnd' => $end],
				'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => substr($tag, 1)]]
			];
		}
		
		// Find mentions
		preg_match_all('/@([a-zA-Z0-9._-]+)/', $plainText, $mentionMatches, PREG_OFFSET_CAPTURE);
		foreach ($mentionMatches[0] as $match) {
			$mention = $match[0];
			$start = $match[1];
			$end = $start + strlen($mention);
			
			// Resolve to DID
			$handle = substr($mention, 1);
			$did = $this->resolveMentionToDid($handle);
			
			if ($did) {
				$facets[] = [
					'index' => ['byteStart' => $start, 'byteEnd' => $end],
					'features' => [['$type' => 'app.bsky.richtext.facet#mention', 'did' => $did]]
				];
			}
		}
		
		// Truncate if needed
		if (mb_strlen($plainText) > self::BLUESKY_MAX_GRAPHEMES || strlen($plainText) > self::BLUESKY_MAX_BYTES) {
			$truncated = $this->truncateText($plainText, $facets);
			$plainText = $truncated['text'];
			$facets = $truncated['facets'];
			
			// Add link to full post
			$postUrl = $post['url'] ?? '';
			if ($postUrl) {
				$linkText = "\n\n" . $postUrl;
				$linkStart = strlen($plainText);
				$linkEnd = $linkStart + strlen($linkText);
				
				$plainText .= $linkText;
				$facets[] = [
					'index' => ['byteStart' => $linkStart, 'byteEnd' => $linkEnd],
					'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $postUrl]]
				];
			}
		}
		
		return ['text' => $plainText, 'facets' => $facets];
	}
	
	private function resolveMentionToDid(string $handle): ?string {
		// Check if it's a local account
		$identity = $this->identityService->getIdentityByHandle($handle);
		if ($identity) {
			return $identity['did'];
		}
		
		// Could resolve Bluesky handle via PLC/AppView
		// For now, return null to treat as link
		return null;
	}
	
	private function truncateText(string $text, array $facets): array {
		// Truncate at word boundary before 280 graphemes
		$graphemes = grapheme_str_split($text);
		if (count($graphemes) <= self::BLUESKY_TRUNCATE_GRAPHEMES) {
			return ['text' => $text, 'facets' => $facets];
		}
		
		$truncated = implode('', array_slice($graphemes, 0, self::BLUESKY_TRUNCATE_GRAPHEMES));
		// Find last word boundary
		$lastSpace = strrpos($truncated, ' ');
		if ($lastSpace !== false) {
			$truncated = substr($truncated, 0, $lastSpace);
		}
		$truncated .= '…';
		
		// Filter facets to only those within truncated text
		$newFacets = [];
		$truncatedLen = strlen($truncated);
		foreach ($facets as $facet) {
			if ($facet['index']['byteStart'] < $truncatedLen) {
				$end = min($facet['index']['byteEnd'], $truncatedLen);
				$newFacets[] = [
					'index' => ['byteStart' => $facet['index']['byteStart'], 'byteEnd' => $end],
					'features' => $facet['features']
				];
			}
		}
		
		return ['text' => $truncated, 'facets' => $newFacets];
	}
	
	private function addImagesToRecord(array $record, array $post, string $did): array {
		$images = [];
		$count = 0;
		
		foreach ($post['media_attachments'] as $attachment) {
			if ($count >= self::MAX_IMAGES) {
				break;
			}
			
			if (!isset($attachment['type']) || $attachment['type'] !== 'image') {
				continue;
			}
			
			// Upload blob
			$documentId = $attachment['document_id'] ?? 0;
			if (!$documentId) continue;
			
			try {
				$blob = $this->blobService->uploadBlob($did, $documentId, $attachment['mime_type'] ?? 'image/jpeg');
				
				$images[] = [
					'alt' => $attachment['description'] ?? '',
					'aspectRatio' => $this->calculateAspectRatio($attachment),
					'image' => [
						'$type' => 'blob',
						'ref' => [
							'$link' => $blob['cid']
						],
						'mimeType' => $blob['mimeType'],
						'size' => $blob['size']
					]
				];
				
				$count++;
			} catch (\Throwable $e) {
				$this->logger->error('Failed to upload image to Bluesky', [
					'document_id' => $documentId,
					'error' => $e->getMessage()
				]);
			}
		}
		
		if (!empty($images)) {
			$record['embed'] = [
				'$type' => 'app.bsky.embed.images',
				'images' => $images
			];
			
			// Add "+N more pictures" text if there are more
			$totalImages = count(array_filter($post['media_attachments'], fn($a) => ($a['type'] ?? '') === 'image'));
			if ($totalImages > $count) {
				$record['text'] .= "\n\n+" . ($totalImages - $count) . " more pictures";
			}
		}
		
		return $record;
	}
	
	private function calculateAspectRatio(array $attachment): array {
		$width = $attachment['meta']['original']['width'] ?? 1;
		$height = $attachment['meta']['original']['height'] ?? 1;
		return ['width' => (int)$width, 'height' => (int)$height];
	}
	
	private function addQuoteToRecord(array $record, array $post, string $did): array {
		$quotedPost = $this->getPost($post['quoted_post_id']);
		if (!$quotedPost) {
			return $record;
		}
		
		// Check if quoted post is on Bluesky
		$quotedIdentity = $this->identityService->getIdentityByActor($quotedPost['actor_id']);
		if ($quotedIdentity && $quotedIdentity['state'] === IdentityService::STATE_ACTIVE) {
			// Local post that was published to Bluesky
			$quotedRecord = $this->findBlueskyRecord($quotedPost['id']);
			if ($quotedRecord) {
				$record['embed'] = [
					'$type' => 'app.bsky.embed.record',
					'record' => [
						'$type' => 'app.bsky.embed.record#view',
						'uri' => $quotedRecord['uri'],
						'cid' => $quotedRecord['cid'],
						'author' => $this->getActorView($quotedIdentity['did']),
						'value' => $quotedRecord['value']
					]
				];
			}
		} else {
			// External post - use external embed
			$record['embed'] = [
				'$type' => 'app.bsky.embed.external',
				'external' => [
					'uri' => $quotedPost['url'],
					'title' => $quotedPost['title'] ?? 'Post',
					'description' => $quotedPost['summary'] ?? '',
					'thumb' => $quotedPost['image'] ? $this->getBlobRef($quotedPost['image']) : null
				]
			];
		}
		
		return $record;
	}
	
	private function addReplyToRecord(array $record, array $post, string $did): array {
		$parentPost = $this->getPost($post['in_reply_to_id']);
		if (!$parentPost) {
			return $record;
		}
		
		// Find root of thread
		$rootPost = $this->findThreadRoot($parentPost);
		
		$parentRecord = $this->findBlueskyRecord($parentPost['id']);
		$rootRecord = $this->findBlueskyRecord($rootPost['id']);
		
		if ($parentRecord && $rootRecord) {
			$record['reply'] = [
				'$type' => 'app.bsky.feed.post#replyRef',
				'parent' => [
					'uri' => $parentRecord['uri'],
					'cid' => $parentRecord['cid']
				],
				'root' => [
					'uri' => $rootRecord['uri'],
					'cid' => $rootRecord['cid']
				]
			];
		} else {
			// Parent not on Bluesky - publish as top-level with link
			$record['text'] .= "\n\n" . ($parentPost['url'] ?? '');
		}
		
		return $record;
	}
	
	private function findThreadRoot(array $post): array {
		// Walk up the reply chain
		while ($post['in_reply_to_id']) {
			$parent = $this->getPost($post['in_reply_to_id']);
			if (!$parent) break;
			$post = $parent;
		}
		return $post;
	}
	
	private function findBlueskyRecord(string $postId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_atproto_record')
			->where($qb->expr()->eq('local_id', $qb->createNamedParameter($postId, \PDO::PARAM_INT)))
			->andWhere($qb->expr()->eq('collection', $qb->createNamedParameter('app.bsky.feed.post')));
		
		return $qb->executeQuery()->fetchAssociative() ?: null;
	}
	
	private function addPollToRecord(array $record, array $post): array {
		$poll = $this->getPoll($post['poll_id']);
		if (!$poll) return $record;
		
		$options = implode(' / ', array_column($poll['options'], 'title'));
		$record['text'] = "Poll: {$poll['question']} — {$options}\n\n" . ($record['text'] ?? '');
		
		return $record;
	}
	
	private function addContentWarning(array $record, array $post): array {
		$warning = $post['spoiler_text'];
		$record['text'] = "CW: {$warning}\n\n" . ($record['text'] ?? '');
		
		// Add self-label
		$record['labels'] = [
			['$type' => 'com.atproto.label.defs#selfLabels', 'values' => [['val' => '!warn']]]
		];
		
		// Also mark images as graphic-media if sensitive
		if ($post['sensitive'] && isset($record['embed']['images'])) {
			foreach ($record['embed']['images'] as &$image) {
				$image['image']['labels'] = [
					['$type' => 'com.atproto.label.defs#selfLabels', 'values' => [['val' => 'graphic-media']]]
				];
			}
		}
		
		return $record;
	}
	
	private function addLinkCard(array $record, array $post): array {
		$card = $this->getCard($post['card_id']);
		if (!$card) return $record;
		
		$thumbBlob = null;
		if ($card['image']) {
			$thumbBlob = $this->getBlobRef($card['image']);
		}
		
		$record['embed'] = [
			'$type' => 'app.bsky.embed.external',
			'external' => [
				'uri' => $card['url'],
				'title' => $card['title'] ?? '',
				'description' => $card['description'] ?? '',
				'thumb' => $thumbBlob
			]
		];
		
		return $record;
	}
	
	private function getActorView(string $did): array {
		$identity = $this->identityService->getIdentityByDid($did);
		if (!$identity) return [];
		
		return [
			'did' => $did,
			'handle' => $identity['handle'],
			'displayName' => '', // Would fetch from profile
			'avatar' => '' // Would fetch from profile
		];
	}
	
	private function getBlobRef(int $documentId): ?array {
		// Would upload and return blob ref
		return null;
	}
	
	private function generateTid(): string {
		$microtime = (int)(microtime(true) * 1000000);
		$bytes = '';
		for ($i = 5; $i >= 0; $i--) {
			$bytes .= chr(($microtime >> ($i * 8)) & 0xFF);
		}
		$bytes = str_pad($bytes, 8, "\0", STR_PAD_LEFT);
		$alphabet = 'abcdefghijklmnopqrstuvwxyz234567';
		$bits = '';
		foreach (str_split($bytes) as $char) {
			$bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
		}
		$bits = str_pad($bits, (int)ceil(strlen($bits) / 5) * 5, '0', STR_PAD_RIGHT);
		$result = '';
		for ($i = 0; $i < strlen($bits); $i += 5) {
			$chunk = substr($bits, $i, 5);
			$result .= $alphabet[bindec($chunk)];
		}
		return substr($result, 0, 13);
	}
	
	// Helper methods to fetch related data
	private function getPost(int $postId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_post')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($postId, \PDO::PARAM_INT)));
		return $qb->executeQuery()->fetchAssociative() ?: null;
	}
	
	private function getPoll(int $pollId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_poll')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($pollId, \PDO::PARAM_INT)));
		return $qb->executeQuery()->fetchAssociative() ?: null;
	}
	
	private function getCard(int $cardId): ?array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from('social_stream_card')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($cardId, \PDO::PARAM_INT)));
		return $qb->executeQuery()->fetchAssociative() ?: null;
	}
}