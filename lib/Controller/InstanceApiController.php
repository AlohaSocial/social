<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Controller;

use OCA\Social\AppInfo\Application;
use OCA\Social\Exceptions\InstanceDoesNotExistException;
use OCA\Social\Exceptions\StreamNotFoundException;
use OCA\Social\Model\ActivityPub\Object\Document;
use OCA\Social\Model\ActivityPub\Object\Like;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Post;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\CacheActorService;
use OCA\Social\Service\ClientService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\EmojiService;
use OCA\Social\Service\FediverseService;
use OCA\Social\Service\FollowService;
use OCA\Social\Service\GifService;
use OCA\Social\Service\InstanceService;
use OCA\Social\Service\StreamService;
use OCA\Social\Service\TranslationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\FrontpageRoute;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What this instance says about itself.
 *
 * `/api/v1/instance` is the first request every Mastodon client makes, and
 * what it answers decides whether the client will talk to this server at all —
 * so these routes are read by strangers, answer without a token, and describe
 * the server rather than anybody on it: its rules, the domains it blocks, its
 * policies, the servers it knows, the languages it can translate between, the
 * oEmbed a link to one of its posts unfurls into, and the custom emoji and
 * GIFs its posts may use.
 */
class InstanceApiController extends MastodonApiController {
	public function __construct(
		IRequest $request,
		IURLGenerator $urlGenerator,
		IUserSession $userSession,
		LoggerInterface $logger,
		ClientService $clientService,
		AccountService $accountService,
		CacheActorService $cacheActorService,
		StreamService $streamService,
		FollowService $followService,
		private InstanceService $instanceService,
		private ISession $session,
		private ConfigService $configService,
		private EmojiService $emojiService,
		private FediverseService $fediverseService,
		private GifService $gifService,
		private TranslationService $translationService,
	) {
		parent::__construct($request, $urlGenerator, $userSession, $logger, $clientService, $accountService, $cacheActorService, $streamService, $followService);
	}

	/**
	 * Mastodon's V1::Instance entity — the first request every client makes.
	 *
	 * @throws InstanceDoesNotExistException
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/')]
	public function instance(): JSONResponse {
		$local = $this->instanceService->getLocal(Stream::FORMAT_LOCAL);

		return Revalidation::byContent($this->request, new DataResponse($local, Http::STATUS_OK));
	}

	/**
	 * The instance's rules, as their own resource.
	 *
	 * They were already served *inside* the instance entity, out of the `rules`
	 * app value — so the data was here and the route a client reads it from was
	 * a 404. An instance that has set none answers `[]`, which is the truthful
	 * answer and not an error.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/rules')]
	public function instanceRules(): DataResponse {
		return new DataResponse(
			$this->instanceService->getLocal(Stream::FORMAT_LOCAL)->getRules(),
			Http::STATUS_OK
		);
	}

	/**
	 * The instances this one has decided not to federate with.
	 *
	 * Mastodon publishes the deny list so that somebody choosing a server can
	 * see who it will not talk to. That is a disclosure decision rather than a
	 * lookup, so it is one an admin makes: with `publish_blocks` unset — the
	 * default — this answers `[]`, which is what an instance that has not opted
	 * in should say rather than refusing and inviting a client to guess.
	 *
	 * Only ever the deny list. In allow-list mode the same column holds the
	 * instances this server *does* talk to, and publishing that as a block list
	 * would be exactly backwards.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/domain_blocks')]
	public function instanceDomainBlocks(): DataResponse {
		if ($this->configService->getAppValue(ConfigService::SOCIAL_PUBLISH_BLOCKS) !== '1'
			|| $this->fediverseService->getAccessType() !== 'all_but') {
			return new DataResponse([], Http::STATUS_OK);
		}

		$blocks = [];
		foreach ($this->fediverseService->getListedAddresses() as $domain) {
			// `digest` is Mastodon's sha256 of the domain, `severity` the only
			// one this list has, and `comment` is not stored here
			$blocks[] = [
				'domain' => $domain,
				'digest' => hash('sha256', $domain),
				'severity' => 'suspend',
				'comment' => '',
			];
		}

		return new DataResponse($blocks, Http::STATUS_OK);
	}

	/**
	 * The long form of what this instance is, as Mastodon's
	 * `ExtendedDescription`.
	 *
	 * Taken from the `extended_description` app value, and falling back to the
	 * short description the instance entity already carries — an empty page
	 * where a server has written a description elsewhere is worse than
	 * repeating it.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/extended_description')]
	public function instanceExtendedDescription(): DataResponse {
		$text = trim($this->configService->getAppValue(ConfigService::SOCIAL_EXTENDED_DESCRIPTION));
		$instance = $this->instanceService->getLocal(Stream::FORMAT_LOCAL);

		return new DataResponse([
			'updated_at' => gmdate('Y-m-d\TH:i:s') . '.000Z',
			'content' => $text === '' ? $instance->getDescription() : $text,
		], Http::STATUS_OK);
	}

	/**
	 * The server's privacy policy.
	 *
	 * Nextcloud's own, from Theming, rather than one kept by this app: a
	 * server has one privacy policy, and a second one here would be a second
	 * answer to the same question. A server that has published none answers
	 * **404**, as Mastodon does — an empty document would read as a policy
	 * that says nothing.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/privacy_policy')]
	public function instancePrivacyPolicy(): DataResponse {
		$policy = $this->instanceService->privacyPolicy();
		if ($policy === null) {
			return new DataResponse(
				['error' => 'this server has published no privacy policy'], Http::STATUS_NOT_FOUND
			);
		}

		return new DataResponse($policy, Http::STATUS_OK);
	}

	/** The server's terms of service — Nextcloud's legal notice. See above. */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/terms_of_service')]
	public function instanceTermsOfService(): DataResponse {
		$terms = $this->instanceService->termsOfService();
		if ($terms === null) {
			return new DataResponse(
				['error' => 'this server has published no terms of service'], Http::STATUS_NOT_FOUND
			);
		}

		return new DataResponse($terms, Http::STATUS_OK);
	}

	/**
	 * oEmbed for one of this server's own public posts.
	 *
	 * What it is for: a site that is handed the link to a post asks this to
	 * find out who wrote it and where, instead of scraping the page. Mastodon
	 * serves the same route.
	 *
	 * `type` is `link`, not Mastodon's `rich`. A rich response is an `<iframe>`
	 * and this app has no embed page to put in one — every post URL here opens
	 * the whole app. A consumer handed `link` shows an attributed link, which
	 * is true; one handed `rich` with a frame that renders an application
	 * would embed something nobody meant to publish.
	 *
	 * Public posts only, and only this server's: an unlisted or followers-only
	 * post is not something to hand to whoever asks, and a post of somebody
	 * else's is theirs to describe.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/oembed')]
	public function oembed(string $url = '', string $format = 'json'): DataResponse {
		try {
			if ($format !== '' && strtolower($format) !== 'json') {
				// the only format this serves; oEmbed says to answer 501 for
				// one it does not, rather than to answer JSON anyway
				return new DataResponse(
					['error' => 'only the json format is served'], Http::STATUS_NOT_IMPLEMENTED
				);
			}

			$post = $this->streamService->getStreamById(trim($url));
			if (!$post->isLocal() || $post->getVisibility() !== Stream::TYPE_PUBLIC) {
				throw new StreamNotFoundException('Stream not found');
			}

			$author = $this->cacheActorService->getFromId($post->getAttributedTo());
			$instance = $this->instanceService->getLocal(Stream::FORMAT_LOCAL);

			return new DataResponse([
				'type' => 'link',
				'version' => '1.0',
				'author_name' => ($author->getDisplayName() !== '')
					? $author->getDisplayName() : $author->getPreferredUsername(),
				'author_url' => $author->getId(),
				'provider_name' => $instance->getTitle(),
				'provider_url' => $this->configService->getCloudUrl(),
				'cache_age' => 86400,
				'url' => $post->getId(),
			], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Which languages this server can translate between: for each language it
	 * translates from, the ones it translates to.
	 *
	 * Read from the translation provider rather than declared here, so it is
	 * the truth about this Nextcloud. An instance with no provider answers an
	 * empty object, which is the same thing `translation.enabled: false` says
	 * in `/api/v2/instance` — a client that reads either one stops offering
	 * the button.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/translation_languages')]
	public function instanceTranslationLanguages(): DataResponse {
		try {
			return new DataResponse(
				(object)$this->translationService->languages(), Http::STATUS_OK
			);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Mastodon's V2::Instance entity. Newer clients ask for this one first and
	 * fall back to v1 on a 404; answering it saves them the round trip.
	 *
	 * @throws InstanceDoesNotExistException
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v2/instance')]
	public function instanceV2(): JSONResponse {
		$local = $this->instanceService->getLocal(Stream::FORMAT_LOCAL);

		return Revalidation::byContent($this->request, new DataResponse($local->asV2(), Http::STATUS_OK));
	}

	/**
	 * The instances this one has heard of.
	 *
	 * Mastodon's `/api/v1/instance/peers`, which instance browsers and
	 * "about this server" pages read. A bare array of hostnames, which is what
	 * the peer of every cached remote actor amounts to, and the same walk the
	 * `domain_count` statistic uses — two walks would be two answers.
	 *
	 * Public, as Mastodon's is: it says who this instance federates with, not
	 * who its users are.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/peers')]
	public function instancePeers(): DataResponse {
		try {
			return new DataResponse($this->instanceService->getPeers(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * Mastodon's weekly activity series: twelve weeks of statuses, logins and
	 * registrations.
	 *
	 * `registrations` is always `0` and says so in the docs: an account here is
	 * a Nextcloud user, created by the server rather than by this app, so there
	 * is no registration for it to count. `logins` is likewise not this app's
	 * to know. What it does know is how many statuses were published in a week,
	 * which is the series a client actually plots.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/instance/activity')]
	public function instanceActivity(): DataResponse {
		try {
			return new DataResponse($this->instanceService->getWeeklyActivity(), Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The emoji this instance publishes.
	 *
	 * Answered `[]` unconditionally until the instance had any: emoji from
	 * every other server rendered here and this one could publish none, which
	 * is the asymmetry somebody moving here notices first. Only the ones
	 * marked visible are listed — that is what the field means, and a picker
	 * is what reads this route.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/custom_emojis')]
	public function customEmojis(): JSONResponse {
		return Revalidation::byContent(
			$this->request, new DataResponse($this->emojiService->visible(), Http::STATUS_OK)
		);
	}

	/**
	 * The picture behind a shortcode.
	 *
	 * Unauthenticated, like `mediaOpen()` and for the same reason: this is
	 * what a remote server dereferences out of an `Emoji` tag on a post it
	 * received, and it has no token of ours to present. An emoji is published
	 * by definition — it is on every post that uses it, everywhere that post
	 * went — so there is nothing here to keep from anybody.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/emoji/{shortcode}')]
	public function emojiOpen(string $shortcode): Response {
		try {
			$emoji = $this->emojiService->byShortcode($shortcode);
			if ($emoji === null) {
				return new DataResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
			}

			$response = new FileDisplayResponse(
				$this->emojiService->picture($shortcode),
				Http::STATUS_OK,
				['Content-Type' => $emoji->getMediaType()]
			);
			// the shortcode names one picture and replacing it is a deliberate
			// act, so a day is cheap; a shared cache may keep it, since the
			// route answers everybody the same bytes
			$response->cacheFor(86400, false, true);

			return $response;
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The instance's GIF library, or the part of it that matches `q`.
	 *
	 * A viewer is required: this is a picker inside the composer, not
	 * something the public page needs, and there is no reason to hand the
	 * whole library to anybody who asks.
	 *
	 * Paged, which it did not need to be while the library was whatever an
	 * administrator had added: with the animated emoji in it there are 881,
	 * and a grid of 881 is 881 pictures this instance would go and fetch
	 * because somebody opened the picker. `limit` is how many to answer with
	 * and `offset` where to carry on from, so the picker asks for the next
	 * screenful when the reader scrolls to it.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/api/v1/gifs')]
	public function gifs(string $q = '', int $limit = 24, int $offset = 0): DataResponse {
		try {
			$this->initViewer(true);

			$limit = max(1, min(200, $limit));
			$found = $this->gifService->search($q);

			return new DataResponse([
				'gifs' => array_slice($found, max(0, $offset), $limit),
				'total' => count($found),
				// said wherever the pack is shown, because CC BY asks for it
				'attribution' => $this->gifService->attribution(),
			], Http::STATUS_OK);
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}

	/**
	 * The bytes behind a slug.
	 *
	 * Unauthenticated, like `emojiOpen()`: the picker draws a grid of these
	 * and they are the same bytes for everybody on the instance. Nothing here
	 * is private — a library picture is one an administrator put there for
	 * everybody — and requiring a session would mean the grid could not be
	 * cached by anything.
	 */
	#[NoCSRFRequired]
	#[PublicPage]
	#[FrontpageRoute(verb: 'GET', url: '/gif/{slug}')]
	public function gifOpen(string $slug): Response {
		try {
			// Nothing here reads the session, and holding it is what made the
			// picker unusable: PHP serialises requests that hold one, so the
			// sixty thumbnails a picker draws went out one at a time — and for
			// one of the shipped emoji this instance has not seen, each of
			// those is a fetch from Google. Measured on a cold instance, the
			// search that followed them waited forty seconds for its turn.
			// Letting it go makes them parallel and costs nothing: these are
			// the same bytes for everybody, which is why the route is public.
			$this->session->close();

			$gif = $this->gifService->bySlug($slug);
			if ($gif === null) {
				return new DataResponse(['error' => 'Record not found'], Http::STATUS_NOT_FOUND);
			}

			$response = new FileDisplayResponse(
				$this->gifService->file($slug),
				Http::STATUS_OK,
				['Content-Type' => $gif->getMediaType()]
			);
			// the slug names one picture and replacing it is a deliberate act,
			// so a day is cheap; a shared cache may keep it, since the route
			// answers everybody the same bytes
			$response->cacheFor(86400, false, true);

			return $response;
		} catch (Throwable $e) {
			return $this->error($e);
		}
	}
}
