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
    public function __construct(
        private readonly RouteRenderer $renderer,
        private readonly string $environment,
    ) {
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
            ->addOption('header', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'HTTP header to send with the synthetic request, for example "Accept: application/json".')
            ->addOption('setup-completed', null, InputOption::VALUE_REQUIRED, 'Set to 0 to render setup-required routes without the debug completion bypass.', '1')
            ->addOption('include-status', null, InputOption::VALUE_NONE, 'Print the response status line before the response body.')
            ->addOption('include-headers', null, InputOption::VALUE_NONE, 'Print response headers before the response body.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->error('The render:route command is available only for development and test environments.');

            return Command::FAILURE;
        }

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
                headers: $this->headers($input->getOption('header')),
            ));
        } catch (\Throwable $error) {
            $io->error($error->getMessage());

            return Command::FAILURE;
        }

        if ((bool) $input->getOption('include-status')) {
            $output->writeln(sprintf('HTTP %d', $result->statusCode));
        }

        if ((bool) $input->getOption('include-headers')) {
            foreach ($result->headers as $name => $values) {
                foreach ($values as $value) {
                    $output->writeln($name.': '.$value);
                }
            }
            $output->writeln('');
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

    /**
     * @return array<string, list<string>>
     */
    private function headers(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $headers = [];
        foreach ($values as $value) {
            if (!is_string($value) || !str_contains($value, ':')) {
                throw new \InvalidArgumentException('Headers must use "Name: value" syntax.');
            }

            [$name, $headerValue] = explode(':', $value, 2);
            $name = trim($name);
            $headerValue = trim($headerValue);

            if ('' === $name || 1 !== preg_match('/^[A-Za-z0-9-]+$/', $name)) {
                throw new \InvalidArgumentException(sprintf('Header name "%s" is invalid.', $name));
            }

            if (1 === preg_match('/[\r\n\x00]/', $headerValue)) {
                throw new \InvalidArgumentException(sprintf('Header "%s" contains unsupported control characters.', $name));
            }

            $headers[$name][] = $headerValue;
        }

        return $headers;
    }

    private function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value, " \t\n\r\0\x0B'\"")), ['1', 'true', 'yes'], true);
    }
}
