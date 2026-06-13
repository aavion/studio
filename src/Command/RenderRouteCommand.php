<?php

declare(strict_types=1);

namespace App\Command;

use App\Debug\RouteRenderOptions;
use App\Debug\RouteRenderer;
use App\Security\UserRole;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'render:route',
    description: 'Render an application route from the CLI with an optional debug user or role context.',
)]
final class RenderRouteCommand extends Command
{
    public function __construct(private readonly RouteRenderer $renderer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('path', InputArgument::REQUIRED, 'Route path to render, for example /admin.')
            ->addOption('method', null, InputOption::VALUE_REQUIRED, 'HTTP method to render with.', 'GET')
            ->addOption('role', null, InputOption::VALUE_REQUIRED, 'Synthetic debug role context; use "public" for anonymous rendering.')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'Existing username to render as.')
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'HTTP host for the synthetic request.', 'localhost')
            ->addOption('https', null, InputOption::VALUE_NONE, 'Render the request as HTTPS.')
            ->addOption('setup-completed', null, InputOption::VALUE_REQUIRED, 'Set to 0 to render setup-required routes without the debug completion bypass.', '1')
            ->addOption('include-status', null, InputOption::VALUE_NONE, 'Print the response status line before the response body.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $role = $this->nullableString($input->getOption('role'));
            $username = $this->nullableString($input->getOption('user'));

            if (null !== $role && null !== $username) {
                throw new \InvalidArgumentException('The --role option cannot override an existing --user role.');
            }

            $result = $this->renderer->render(new RouteRenderOptions(
                path: (string) $input->getArgument('path'),
                method: (string) $input->getOption('method'),
                role: null === $role ? null : $this->role($role),
                username: $username,
                setupCompleted: $this->truthy((string) $input->getOption('setup-completed')),
                host: (string) $input->getOption('host'),
                secure: (bool) $input->getOption('https'),
            ));
        } catch (\Throwable $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }

        if ((bool) $input->getOption('include-status')) {
            $output->writeln(sprintf('HTTP %d', $result->statusCode));
        }

        $output->write($result->content);

        return $result->statusCode >= 500 ? Command::FAILURE : Command::SUCCESS;
    }

    private function role(string $value): ?UserRole
    {
        $normalized = strtolower(trim($value));
        $normalized = str_starts_with($normalized, 'role_') ? substr($normalized, 5) : $normalized;

        foreach (UserRole::cases() as $role) {
            if ($role->value === $normalized) {
                return $role;
            }
        }

        throw new \InvalidArgumentException(sprintf('Render role "%s" is invalid.', $value));
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && '' !== trim($value) ? trim($value) : null;
    }

    private function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value, " \t\n\r\0\x0B'\"")), ['1', 'true', 'yes'], true);
    }
}
