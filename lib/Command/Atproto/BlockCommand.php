<?php

declare(strict_types=1);

namespace OCA\Social\Command\Atproto;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class BlockCommand extends Command {

	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('social:atproto:block')
			->setDescription('Manage AT Protocol blocklist')
			->addArgument('type', InputArgument::REQUIRED, 'Type: host or did')
			->addArgument('value', InputArgument::REQUIRED, 'Host or DID to block')
			->addOption('unblock', null, InputOption::VALUE_NONE, 'Remove from blocklist')
			->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Reason for blocking');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);
		$type = $input->getArgument('type');
		$value = $input->getArgument('value');
		$unblock = $input->getOption('unblock');
		$reason = $input->getOption('reason') ?? '';

		$io->title('AT Protocol Blocklist');

		if (!in_array($type, ['host', 'did'])) {
			$io->error('Type must be "host" or "did"');
			return Command::FAILURE;
		}

		$qb = $this->db->getQueryBuilder();

		if ($unblock) {
			$qb->delete('social_atpds_blocklist')
				->where($qb->expr()->eq('kind', $qb->createNamedParameter($type)))
				->andWhere($qb->expr()->eq('value', $qb->createNamedParameter($value)))
				->executeStatement();

			$io->success("Removed $type '$value' from blocklist");
		} else {
			$qb->upsert('social_atpds_blocklist')
				->set('kind', $qb->createNamedParameter($type))
				->set('value', $qb->createNamedParameter($value))
				->set('reason', $qb->createNamedParameter($reason))
				->set('created', $qb->createNamedParameter((new \DateTime())->format('Y-m-d H:i:s')))
				->executeStatement();

			$io->success("Added $type '$value' to blocklist");
		}

		return Command::SUCCESS;
	}
}
