<?php

declare(strict_types=1);

namespace Infrastructure\Crunz\Console;

use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

// Only PSR-11 + Symfony Console, so it works in any host, not just Laravel.
#[AsCommand(name: 'app:scheduler:invoke', description: 'Invoke one scheduled task (used by the crunz engine)')]
final class InvokeTaskCommand extends Command
{
    public function __construct(private readonly ContainerInterface $container)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('task', InputArgument::REQUIRED, 'Task class (FQCN)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $taskClass = $input->getArgument('task');
        if (!is_string($taskClass)) {
            return self::INVALID;
        }

        $task = $this->container->get($taskClass);

        if (!is_callable($task)) {
            $output->writeln(sprintf('<error>%s is not invokable.</error>', get_debug_type($task)));

            return self::FAILURE;
        }

        $task();

        return self::SUCCESS;
    }
}
