<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Exception;
use OCA\Social\Model\ActivityPub\Stream;
use OCA\Social\Model\Post;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\PostService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class NoteCreate
 *
 * @package OCA\Social\Command
 */
class NoteCreate extends SocialCommand {
	private ConfigService $configService;

	private AccountService $accountService;

	private PostService $postService;

	private MiscService $miscService;

	public function __construct(
		private ActivityService $activityService,
		AccountService $accountService,
		PostService $postService,
		private CurlService $curlService,
		ConfigService $configService,
		MiscService $miscService,
	) {
		parent::__construct();
		$this->accountService = $accountService;
		$this->postService = $postService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}

	/**
	 *
	 */
	#[\Override]
	protected function configure() {
		parent::configure();
		$this->setName('social:debug:note:create')
			->addOption(
				'replyTo', 'r', InputOption::VALUE_OPTIONAL, 'in reply to an existing thread'
			)
			->addOption(
				'to', 't', InputOption::VALUE_OPTIONAL, 'mentioning people'
			)
			->addOption(
				'type', 'y', InputOption::VALUE_OPTIONAL,
				'type: public (default), unlisted, private (or followers), direct'
			)
			->addOption(
				'hashtag', 'g', InputOption::VALUE_OPTIONAL,
				'hashtag, without the leading #'
			)
			->addArgument('user_id', InputArgument::REQUIRED, 'userId of the author')
			->addArgument('content', InputArgument::REQUIRED, 'content of the post')
			->setDescription('Post as a user from the command line (for testing)');
	}

	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @throws Exception
	 */
	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = $input->getArgument('user_id');
		$content = $input->getArgument('content');
		$to = $input->getOption('to');
		$hashtag = $input->getOption('hashtag');
		$replyTo = $input->getOption('replyTo');
		$type = $input->getOption('type');

		// an omitted --type is what the option's own help promises, public; an
		// unrecognised one used to become a direct post addressed to nobody,
		// silently, which is not something a command line should do quietly
		$type = ($type === null || $type === '') ? 'public' : (string)$type;
		if (!Stream::isKnownClientVisibility($type)) {
			$output->writeln(
				'<error>unknown type "' . $type . '"; expected one of: '
				. implode(', ', Stream::clientVisibilities()) . '</error>'
			);

			return 1;
		}

		$actor = $this->accountService->getActorFromUserId($userId);
		$post = new Post($actor);
		$post->setContent($content);
		$post->setType($type);
		$post->setReplyTo(($replyTo === null) ? '' : $replyTo);
		$post->addTo(($to === null) ? '' : $to);
		$post->setHashtags(($hashtag === null) ? [] : [$hashtag]);
		// written by whoever runs occ, who administers this server
		$post->setReview(Post::REVIEW_EXEMPT);

		$token = '';
		$activity = $this->postService->createPost($post, $token);

		// through the output interface rather than echo, so the result can be
		// redirected, captured or formatted like every other occ command's
		$output->writeln(
			'object: ' . json_encode($activity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
		);
		$output->writeln('token: ' . $token);

		return 0;
	}
}
