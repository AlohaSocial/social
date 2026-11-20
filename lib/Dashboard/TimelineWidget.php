<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Dashboard;

use Exception;
use OCA\Social\AppInfo\Application;
use OCA\Social\Model\ActivityPub\ACore;
use OCA\Social\Model\ActivityPub\Actor\Person;
use OCA\Social\Model\ActivityPub\Internal\SocialAppNotification;
use OCA\Social\Model\ActivityPub\Object\Announce;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Client\Options\ProbeOptions;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\StreamService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IOptionWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\Dashboard\Model\WidgetOptions;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Util;
use Psr\Log\LoggerInterface;

/**
 * Shared machinery for the widgets that show one of the reader's timelines.
 * A subclass picks the probe and says what to call it; everything from
 * resolving the viewer to turning a stream item into a tile row happens here.
 */
abstract class TimelineWidget implements IAPIWidgetV2, IIconWidget, IButtonWidget, IReloadableWidget, IOptionWidget {
	/** More than this on a dashboard tile is never read. */
	private const MAX_ITEMS = 20;

	/** A tile row shows one line; the rest is wasted payload. */
	private const MAX_CONTENT = 120;

	public function __construct(
		protected IL10N $l10n,
		protected IURLGenerator $urlGenerator,
		protected AccountService $accountService,
		protected CacheActorService $cacheActorService,
		protected StreamService $streamService,
		protected LoggerInterface $logger,
	) {
	}

	/** The probe this widget reads, one of the ProbeOptions constants. */
	abstract protected function getProbe(): string;

	/** The in-app timeline this widget opens, as it appears in /timeline/{path}. */
	abstract protected function getTimelinePath(): string;

	/** Shown when the reader has nothing of this kind at all. */
	abstract protected function getEmptyMessage(): string;

	/** Shown when there is older content but nothing recent. */
	abstract protected function getHalfEmptyMessage(): string;

	#[\Override]
	public function getIconClass(): string {
		return 'icon-social';
	}

	#[\Override]
	public function getIconUrl(): string {
		return $this->urlGenerator->imagePath(Application::APP_ID, 'social-dark.svg');
	}

	#[\Override]
	public function getUrl(): ?string {
		return $this->getTimelineUrl();
	}

	#[\Override]
	public function load(): void {
		Util::addStyle(Application::APP_ID, 'dashboard');
	}

	#[\Override]
	public function getWidgetButtons(string $userId): array {
		return [
			new WidgetButton(
				WidgetButton::TYPE_MORE,
				$this->getTimelineUrl(),
				$this->getButtonText()
			),
		];
	}

	#[\Override]
	public function getReloadInterval(): int {
		return 300;
	}

	#[\Override]
	public function getWidgetOptions(): WidgetOptions {
		// the item icons are avatars
		return new WidgetOptions(true);
	}

	#[\Override]
	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems {
		try {
			$account = $this->accountService->getActorFromUserId($userId, false);
			$viewer = $this->cacheActorService->getFromLocalAccount($account->getPreferredUsername());
			$viewer->setExportFormat(ACore::FORMAT_LOCAL);
			$this->streamService->setViewer($viewer);

			$options = new ProbeOptions();
			$options->setProbe($this->getProbe())
				->setLimit(max(1, min($limit, self::MAX_ITEMS)));

			// The client sends the sinceId of its newest row. setSince() keeps
			// newest-first order (setMinId() would invert it); kept as a string since
			// `(int)` clamps a wide nid.
			if ($since !== null && ctype_digit($since)) {
				$options->setSince($since);
			}

			$this->configureProbe($options);

			$link = $this->getTimelineUrl();
			$items = [];
			foreach ($this->streamService->getTimeline($options) as $stream) {
				$item = $this->itemFor($stream, $link);
				if ($item !== null) {
					$items[] = $item;
				}
			}

			// The half-empty message is drawn above the rows, so it is only for the
			// empty case.
			return new WidgetItems(
				$items,
				$this->getEmptyMessage(),
				$items === [] ? $this->getHalfEmptyMessage() : ''
			);
		} catch (Exception $e) {
			$this->logger->warning('could not build the {widget} dashboard widget', [
				'widget' => $this->getId(),
				'exception' => $e,
			]);
			$failed = $this->l10n->t('Could not load timeline');

			return new WidgetItems([], $failed, $failed);
		}
	}

	/** Narrow the probe further; the base asks only for the probe and a limit. */
	protected function configureProbe(ProbeOptions $options): void {
	}

	protected function getButtonText(): string {
		return $this->l10n->t('Open timeline');
	}

	protected function getTimelineUrl(): string {
		return $this->urlGenerator->linkToRoute(
			'social.Navigation.timeline',
			['path' => $this->getTimelinePath()]
		);
	}

	/**
	 * One tile row for a stream item, or null when there is no sensible line
	 * to show for it.
	 */
	protected function itemFor(Stream $stream, string $link): ?WidgetItem {
		$status = $this->statusOf($stream);
		if ($status === null) {
			return null;
		}

		$author = $this->actorOf($status->getAttributedTo());
		$content = $this->summarise($status->getContent());

		return new WidgetItem(
			$content !== '' ? $content : $this->l10n->t('(no content)'),
			$this->subtitleFor($stream, $status, $author),
			$link,
			$author?->getAvatar() ?? '',
			(string)$stream->getNid()
		);
	}

	/**
	 * The status a row is about. A boost and a notification both wrap the
	 * status they concern; a plain post is its own subject.
	 */
	protected function statusOf(Stream $stream): ?Stream {
		$object = $stream->hasObject() ? $stream->getObject() : null;
		if ($object instanceof Stream) {
			return $object;
		}

		// a boost or a notification whose subject did not resolve has no content
		if ($stream instanceof Announce || $stream instanceof SocialAppNotification) {
			return null;
		}

		return $stream;
	}

	/**
	 * The second line of a row: who wrote the status, and for a boost also who
	 * put it in front of the reader.
	 */
	protected function subtitleFor(Stream $stream, Stream $status, ?Person $author): string {
		$authorName = $this->nameOf($author, $status->getAttributedTo());
		if ($stream === $status) {
			return $authorName;
		}

		$via = $this->actorOf($stream->getAttributedTo());
		$viaName = $this->nameOf($via, $stream->getAttributedTo());
		if ($stream instanceof Announce) {
			return $this->l10n->t('%1$s boosted %2$s', [$viaName, $authorName]);
		}

		return $viaName;
	}

	protected function actorOf(string $actorId): ?Person {
		if ($actorId === '') {
			return null;
		}

		try {
			return $this->cacheActorService->getFromId($actorId);
		} catch (Exception $e) {
			return null;
		}
	}

	/** A display name, falling back to the handle and then to the raw id. */
	protected function nameOf(?Person $actor, string $actorId): string {
		if ($actor === null) {
			return $actorId;
		}

		return $actor->getName() ?: $actor->getPreferredUsername();
	}

	/** Markup out, then cut to what a row can show. */
	protected function summarise(string $content): string {
		return mb_substr(strip_tags($content), 0, self::MAX_CONTENT);
	}
}
