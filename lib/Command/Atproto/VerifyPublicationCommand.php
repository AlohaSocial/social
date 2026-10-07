<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command\Atproto;

use OCA\Social\Atproto\Identity\IdentityService;
use OCA\Social\Atproto\PublicPublicationVerifier;
use OCA\Social\Atproto\Repository\Repository;
use OCP\IDBConnection;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class VerifyPublicationCommand extends Command {
	public function __construct(
		private readonly IDBConnection $db,
		private readonly Repository $repository,
		private readonly IdentityService $identities,
		private readonly PublicPublicationVerifier $verifier,
	) {
		parent::__construct();
	}
	#[\Override]
	protected function configure(): void {
		$this->setName('social:atproto:verify-publication')
			->setDescription('Verify that a native Social post is indexed by public Bluesky')
			->addArgument('post', InputArgument::REQUIRED, 'Social post ID (the numeric ID returned by the composer API)');
	}
	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);
		try {
			if (!$this->identities->isEnabled()) {
				throw new \RuntimeException('AT Protocol is disabled');
			}
			$postId = (string)$input->getArgument('post');
			if (!ctype_digit($postId)) {
				throw new \InvalidArgumentException('Use the numeric Social post ID');
			}
			$qb = $this->db->getQueryBuilder();
			$row = $qb->select('did', 'collection', 'rkey')->from('social_atpds_record')->where($qb->expr()->eq('local_id', $qb->createNamedParameter($postId)))
				->andWhere($qb->expr()->eq('collection', $qb->createNamedParameter('app.bsky.feed.post')))->executeQuery()->fetchAssociative();
			if (!$row) {
				throw new \RuntimeException('No native post record exists yet; check the publication queue and selected transport');
			}
			$identity = $this->identities->getIdentityByDid($row['did']);
			if ($identity === null || $identity['state'] !== IdentityService::STATE_ACTIVE) {
				throw new \RuntimeException('The native identity is not active');
			}
			$this->repository->verify($row['did'], $identity['signing_public']);
			$record = $this->repository->getRecord($row['did'], $row['collection'], $row['rkey']);
			if ($record === null) {
				throw new \RuntimeException('Native post was removed during verification');
			}
			$io->text('Local signed repository verified: ' . $record->getAtUri());
			$result = $this->verifier->verify($record);
			if (!$result['indexed']) {
				$io->warning('The post is not indexed by public Bluesky yet. Check public DNS/TLS, PLC registration, relay subscription and indexing delay.');
				return Command::FAILURE;
			}
			$io->success('Public Bluesky indexes the matching post, author and CID: ' . $result['url']);
			return Command::SUCCESS;
		} catch (\Throwable $e) {
			$io->error('Public publication has not been verified: ' . $e->getMessage());
			return Command::FAILURE;
		}
	}
}
