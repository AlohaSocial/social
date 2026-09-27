<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Notification;

use OCA\Social\AppInfo\Application;
use OCA\Social\Model\Moderation;
use OCA\Social\Model\Strike;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Class Notifier
 *
 * @package OCA\Social\Notification
 */
class Notifier implements INotifier {
	public function __construct(
		private IL10N $l10n,
		protected IFactory $factory,
		protected IURLGenerator $url,
	) {
	}

	/**
	 * Identifier of the notifier, only use [a-z0-9_]
	 *
	 * @return string
	 * @since 17.0.0
	 */
	#[\Override]
	public function getID(): string {
		return Application::APP_ID;
	}

	/**
	 * Human readable name describing the notifier
	 *
	 * @return string
	 * @since 17.0.0
	 */
	#[\Override]
	public function getName(): string {
		return $this->l10n->t('Social');
	}

	/**
	 * @param INotification $notification
	 * @param string $languageCode The code of the language that should be used to prepare the notification
	 *
	 * @return INotification
	 * @throws UnknownNotificationException a notification this app does not own
	 */
	#[\Override]
	public function prepare(INotification $notification, string $languageCode): INotification {
		if ($notification->getApp() !== Application::APP_ID) {
			// `UnknownNotificationException` and not the `InvalidArgumentException`
			// it extends: the server asks every notifier about every
			// notification, so "not mine" is the ordinary answer and is given
			// hundreds of times a day. Answered with the general exception it
			// is a deprecation warning each time — 31 000 lines in a
			// development instance's log, drowning anything real in it.
			throw new UnknownNotificationException();
		}

		$l10n = $this->factory->get(Application::APP_ID, $languageCode);

		$notification->setIcon(
			$this->url->getAbsoluteURL($this->url->imagePath('social', 'social_dark.svg'))
		);
		$params = $notification->getSubjectParameters();

		switch ($notification->getSubject()) {
			case 'mention':
				$notification->setParsedSubject(
					$l10n->t('%s mentioned you in a post', [$this->account($params)])
				);
				$this->point($notification, $params);
				break;

			case 'favourite':
				$notification->setParsedSubject(
					$l10n->t('%s favourited your post', [$this->account($params)])
				);
				$this->point($notification, $params);
				break;

			case 'reblog':
				$notification->setParsedSubject(
					$l10n->t('%s boosted your post', [$this->account($params)])
				);
				$this->point($notification, $params);
				break;

			case 'follow':
				$notification->setParsedSubject(
					$l10n->t('%s is now following you', [$this->account($params)])
				);
				$this->point($notification, $params);
				break;

			case 'follow_request':
				$notification->setParsedSubject(
					$l10n->t('%s wants to follow you', [$this->account($params)])
				);
				$this->point($notification, $params);
				$this->offerToAnswer($notification, $params, $l10n);
				break;
			case 'poll':
				$notification->setParsedSubject(
					$l10n->t('The poll by %s has ended', [$this->account($params)])
				);
				$this->point($notification, $params);
				break;
			case 'status':
				// the bell on a profile: a post from an account the reader asked
				// to be told about
				$notification->setParsedSubject(
					$l10n->t('%s posted', [$this->account($params)])
				);
				$this->point($notification, $params);
				break;

			case 'update':
				$notification->setParsedSubject(
					$l10n->t('%s edited a post you boosted', [$this->account($params)])
				);
				$this->point($notification, $params);
				break;

			case 'report_new':
				$account = (string)($params['account'] ?? '');
				$notification->setParsedSubject(
					($params['local'] ?? true) === true
						? $l10n->t('New report about %s', [$account])
						: $l10n->t('New report about %s from another instance', [$account])
				);
				$notification->setParsedMessage(
					$l10n->t('Review it in the Social section of the administration settings.')
				);
				$notification->setLink(
					$this->url->linkToRouteAbsolute('settings.AdminSettings.index', ['section' => 'social'])
				);
				break;

			case 'moderation_warning':
				// the account was told nothing before this: a decision it was
				// not told about is one it can only discover by noticing that
				// its posts stopped appearing
				$text = trim((string)($params['text'] ?? ''));
				$notification->setParsedSubject(match ((string)($params['action'] ?? '')) {
					Moderation::SILENCE => $l10n->t(
						'Your account has been silenced by a moderator of this server'
					),
					Moderation::SUSPEND => $l10n->t(
						'Your account has been suspended by a moderator of this server'
					),
					Strike::TAKEDOWN => $l10n->t(
						'A post of yours has been taken down by a moderator of this server'
					),
					// the one entry in the history that is good news, and the
					// one the account most needs told: until it is, somebody
					// let off has no way of knowing they were
					Strike::LIFT => $l10n->t(
						'What stood against your account has been lifted by a moderator of this server'
					),
					default => $l10n->t('You have received a warning from a moderator of this server'),
				});
				$notification->setParsedMessage($text === ''
					? $l10n->t('No reason was given.')
					: $text);
				break;

			default:
				// a subject this app does not know: the same answer, for the
				// same reason
				throw new UnknownNotificationException();
		}

		return $notification;
	}

	/**
	 * Who acted, as it is written into the sentence. An account that could not
	 * be resolved when the notification was raised leaves this empty rather
	 * than guessing a name — `%s mentioned you in a post` with nothing in
	 * front of it still says what happened.
	 */
	private function account(array $params): string {
		return (string)($params['account'] ?? '');
	}

	/**
	 * Points the notification at the post or the profile it is about, and
	 * shows the acting account's avatar instead of the app icon.
	 *
	 * Both are taken only when they are absolute http(s) URLs: the parameters
	 * come from a stored notification, whose actor may be on another server,
	 * and a relative or exotic value there would be rendered as a link out of
	 * the Nextcloud interface to something nobody vouched for.
	 */
	private function point(INotification $notification, array $params): void {
		$link = (string)($params['link'] ?? '');
		if ($this->isWebUrl($link)) {
			$notification->setLink($link);
		}

		$avatar = (string)($params['avatar'] ?? '');
		if ($this->isWebUrl($avatar)) {
			$notification->setIcon($avatar);
		}
	}

	/**
	 * Accept and Decline, on the bell entry itself. Both POST to the routes a
	 * Mastodon client uses for the same answer, and the answer takes the entry
	 * down (`NotificationService::onFollowRequestAnswered()`). Offered only
	 * when the follower's id is known -- an entry raised before it was stored
	 * still says who asked and links to them.
	 */
	private function offerToAnswer(INotification $notification, array $params, IL10N $l10n): void {
		$nid = $params['nid'] ?? '0';
		if ((!is_string($nid) && !is_int($nid)) || !ctype_digit((string)$nid) || \OCA\Social\Tools\Nid::compare($nid, '0') < 1) {
			return;
		}
		$nid = \OCA\Social\Tools\Nid::normalize($nid);

		$accept = $notification->createAction();
		$accept->setLabel('accept')
			->setParsedLabel($l10n->t('Accept'))
			->setPrimary(true)
			->setLink($this->url->linkToRouteAbsolute('social.AccountApi.followRequestAuthorize', ['id' => $nid]), 'POST');
		$notification->addAction($accept);

		$decline = $notification->createAction();
		$decline->setLabel('decline')
			->setParsedLabel($l10n->t('Decline'))
			->setPrimary(false)
			->setLink($this->url->linkToRouteAbsolute('social.AccountApi.followRequestReject', ['id' => $nid]), 'POST');
		$notification->addAction($decline);
	}

	private function isWebUrl(string $url): bool {
		return (filter_var($url, FILTER_VALIDATE_URL) !== false)
			&& (str_starts_with($url, 'https://') || str_starts_with($url, 'http://'));
	}
}
