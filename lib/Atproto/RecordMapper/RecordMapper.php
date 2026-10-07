<?php

declare(strict_types=1);

namespace OCA\Social\Atproto\RecordMapper;

use OCA\Social\Atproto\Protocol\Tid;
use OCA\Social\Atproto\Repository\BlobService;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Service\DocumentService;
use Psr\Log\LoggerInterface;

/** One publication policy for all native outbound posts. */
class RecordMapper {
	public const BLUESKY_MAX_GRAPHEMES = 300;
	public const BLUESKY_MAX_BYTES = 3000;
	public const BLUESKY_TRUNCATE_GRAPHEMES = 280;
	public const MAX_IMAGES = 4;
	public function __construct(
		private readonly BlobService $blobs,
		private readonly DocumentService $documents,
		private readonly LoggerInterface $logger,
		private readonly StrongRefResolver $references,
		private readonly \OCA\Social\Db\ActorsRequest $actors,
	) {
	}
	public function map(Stream $post, string $did): ?array {
		if (!$post->isLocal() || $post->getVisibility() !== 'public' || !$post->addressesPublic()) {
			return null;
		}
		$text = self::plainText($post->getContent());
		$url = $post->getUrl() ?: $post->getId();
		$mustLink = false;
		$cw = self::plainText($post->getSpoilerText());
		if ($cw !== '') {
			$text = $cw . "\n\n" . $text;
		}
		$images = [];
		foreach ($post->getAttachments() as $attachment) {
			$data = $attachment->asLocal();
			if (($data['type'] ?? '') !== 'image') {
				$mustLink = true;
				continue;
			}
			if (count($images) >= 4) {
				$mustLink = true;
				continue;
			}
			try {
				$documents = $this->documents->getMediaFromArray([(string)$data['id']], $this->actors->getFromId($post->getAttributedTo())->getPreferredUsername());
				$document = reset($documents);
				if (!$document) {
					throw new \RuntimeException('Attachment missing');
				}
				if (!$this->documents->isAttachedToAPostBy($document, $post->getAttributedTo())) {
					throw new \RuntimeException('Attachment owner mismatch');
				}
				$mime = '';
				$file = $this->documents->getFromCache($document->getId(), $mime, true);
				$blob = $this->blobs->storeImage($did, $file->getContent(), $document->getId());
				$images[] = ['alt' => grapheme_substr((string)($data['description'] ?? ''), 0, 2000), 'image' => $blob['blob'], 'aspectRatio' => $blob['aspectRatio']];
			} catch (\Throwable $e) {
				$mustLink = true;
				$this->logger->warning('AT Protocol picture requires local fallback', ['exception' => $e]);
			}
		}
		$parent = $this->references->resolve($post->getInReplyTo());
		$quote = $this->references->resolve($post->getQuote());
		if ($post instanceof \OCA\Social\Model\ActivityPub\Object\Question || ($post->getQuote() !== '' && $quote === null) || ($post->getInReplyTo() !== '' && $parent === null)) {
			$mustLink = true;
		}
		$text = self::fitText($text, $url, $mustLink);
		$record = ['$type' => 'app.bsky.feed.post', 'text' => $text, 'createdAt' => gmdate('Y-m-d\TH:i:s\Z', $post->getPublishedTime())];
		$facets = self::facets($text);
		if ($facets !== []) {
			$record['facets'] = $facets;
		}
		if ($images !== []) {
			$record['embed'] = ['$type' => 'app.bsky.embed.images', 'images' => $images];
		}
		if ($parent !== null) {
			$record['reply'] = ['root' => $parent['root'], 'parent' => $parent['ref']];
		}
		if ($quote !== null) {
			$embed = ['$type' => 'app.bsky.embed.record', 'record' => $quote['ref']];
			$record['embed'] = $images === [] ? $embed : ['$type' => 'app.bsky.embed.recordWithMedia', 'record' => $embed, 'media' => $record['embed']];
		}
		if ($cw !== '') {
			$record['labels'] = ['$type' => 'com.atproto.label.defs#selfLabels', 'values' => [['val' => '!warn']]];
		}
		return ['collection' => 'app.bsky.feed.post', 'rkey' => Tid::next(), 'record' => $record];
	}
	public static function plainText(string $html): string {
		$html = preg_replace('#<(?:br\s*/?|/p|/div|/li)>#i', "\n", $html);
		return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
	}
	public static function fitText(string $text, string $url, bool $mustLink = false): string {
		if (!$mustLink && grapheme_strlen($text) <= 300 && strlen($text) <= 3000) {
			return $text;
		}
		$suffix = '… ' . $url;
		if (grapheme_strlen($suffix) > 300 || strlen($suffix) > 3000) {
			throw new \InvalidArgumentException('Local post URL exceeds Bluesky text limit');
		}
		$available = min(280, 300 - grapheme_strlen($suffix));
		$prefix = grapheme_substr($text, 0, $available);
		while (strlen($prefix . $suffix) > 3000) {
			$prefix = grapheme_substr($prefix, 0, grapheme_strlen($prefix) - 1);
		}
		return rtrim($prefix) . $suffix;
	}
	public static function facets(string $text): array {
		preg_match_all('#https?://[^\s<>]+#u', $text, $matches, PREG_OFFSET_CAPTURE);
		$facets = [];
		foreach ($matches[0] as [$url, $offset]) {
			$facets[] = ['index' => ['byteStart' => $offset, 'byteEnd' => $offset + strlen($url)], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]]];
		}
		return $facets;
	}
}
