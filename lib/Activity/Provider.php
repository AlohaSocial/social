<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Activity;

use OCA\Social\AppInfo\Application;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\Activity\IProvider;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * Words this app's entries for the Activity app, in the reader's language.
 *
 * The actor is a rich `user` when they are an account of this server, so the
 * Activity page draws their Nextcloud avatar and links to their profile, and a
 * `highlight` linking to the profile page here otherwise. The post concerned,
 * when there is one, is the entry's message.
 */
class Provider implements IProvider {
	public function __construct(
		private IFactory $factory,
		private IURLGenerator $urlGenerator,
		private IManager $activityManager,
	) {
	}

	/**
	 * @param string $language
	 * @throws UnknownActivityException
	 */
	#[\Override]
	public function parse($language, IEvent $event, ?IEvent $previousEvent = null): IEvent {
		if ($event->getApp() !== Application::APP_ID) {
			throw new UnknownActivityException();
		}

		$l10n = $this->factory->get(Application::APP_ID, $language);
		$template = self::templates($l10n)[$event->getSubject()] ?? null;
		if ($template === null) {
			throw new UnknownActivityException();
		}

		$parameters = $event->getSubjectParameters();
		$account = $this->accountParameter($parameters);

		$event->setParsedSubject(str_replace('{account}', $account['name'], $template))
			->setRichSubject($template, str_contains($template, '{account}') ? ['account' => $account] : [])
			->setIcon($this->iconFor($event->getSubject()));

		$excerpt = (string)($parameters['excerpt'] ?? '');
		if ($excerpt !== '') {
			$event->setParsedMessage($excerpt)->setRichMessage($excerpt, []);
		}

		return $event;
	}

	/**
	 * One line per subject the bell knows (`NotificationService::SUBJECTS`,
	 * plus the two the bell is being taught), worded for the person told.
	 *
	 * @return array<string, string>
	 */
	public static function templates(IL10N $l10n): array {
		return [
			'mention' => $l10n->t('{account} mentioned you'),
			'favourite' => $l10n->t('{account} favourited your post'),
			'reblog' => $l10n->t('{account} boosted your post'),
			'follow' => $l10n->t('{account} followed you'),
			'follow_request' => $l10n->t('{account} asked to follow you'),
			'update' => $l10n->t('{account} edited a post you boosted'),
			'poll' => $l10n->t('A poll you took part in has ended'),
			'status' => $l10n->t('{account} posted'),
		];
	}

	/**
	 * @return array{type: string, id: string, name: string, link?: string}
	 */
	private function accountParameter(array $parameters): array {
		$name = (string)($parameters['account'] ?? '');
		$user = (string)($parameters['user'] ?? '');
		if ($user !== '') {
			return ['type' => 'user', 'id' => $user, 'name' => $name];
		}

		$acct = (string)($parameters['acct'] ?? '');
		$parameter = [
			'type' => 'highlight',
			'id' => ($acct !== '') ? $acct : (string)($parameters['actor'] ?? $name),
			'name' => $name,
		];
		if ($acct !== '') {
			$parameter['link'] = $this->urlGenerator->linkToRouteAbsolute(
				'social.ActivityPub.actorAlias', ['username' => $acct]
			);
		}

		return $parameter;
	}

	private function iconFor(string $subject): string {
		if ($this->activityManager->getRequirePNG()) {
			// the mail wants a bitmap: the app icon, dark for a light mail
			return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, 'social-dark.png'));
		}

		$icon = match ($subject) {
			'follow', 'follow_request' => 'add_user.svg',
			'reblog' => 'boost.svg',
			'mention' => 'reply.svg',
			default => 'notifications.svg',
		};

		return $this->urlGenerator->getAbsoluteURL($this->urlGenerator->imagePath(Application::APP_ID, $icon));
	}
}
