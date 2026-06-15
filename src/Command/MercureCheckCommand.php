<?php

declare(strict_types=1);

namespace App\Command;

use App\Core\Mercure\MercureRuntime;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'mercure:check',
    description: 'Display the current Mercure status without installing, starting, stopping, or writing health state.',
)]
final class MercureCheckCommand extends Command
{
    public function __construct(private readonly MercureRuntime $runtime)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $pid = $this->runtime->processId();

        $io->title('Mercure status');
        $io->definitionList(
            ['Binary' => $this->runtime->binaryInstalled() ? 'available at '.$this->runtime->binaryPath() : 'not installed at '.$this->runtime->binaryPath()],
            ['Hub process' => $this->runtime->isRunning() ? 'running'.(null === $pid ? '' : ' (PID '.$pid.')') : 'not running'],
            ['Listen address' => $this->runtime->listenAddress()],
            ['Hub endpoint' => $this->runtime->hubReachable() ? 'reachable' : 'not reachable'],
            ['Publish endpoint' => $this->runtime->publishHubUrl()],
            ['Publish endpoint status' => $this->runtime->publishHealthProbe() ? 'functional' : 'not functional'],
            ['Public endpoint' => $this->runtime->publicHubUrl()],
            ['Public endpoint status' => $this->runtime->publicSubscribeProbe() ? 'reachable' : 'not reachable'],
        );

        return Command::SUCCESS;
    }
}
