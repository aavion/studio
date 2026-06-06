<?php

declare(strict_types=1);

namespace App\Core\Package;

final readonly class PackageSchedulerCronInspector
{
    public function __construct(
        private PackageSchedulerDefinitionCallScanner $callScanner = new PackageSchedulerDefinitionCallScanner(),
        private PackagePhpCallArgumentParser $argumentParser = new PackagePhpCallArgumentParser(),
    ) {
    }

    /**
     * @return list<string>
     */
    public function expressions(string $contents): array
    {
        return array_values(array_filter(
            $this->cronArguments($contents),
            static fn (?string $expression): bool => null !== $expression,
        ));
    }

    /**
     * @return list<string|null>
     */
    public function cronArguments(string $contents): array
    {
        if (!str_contains($contents, 'SchedulerTaskDefinition')) {
            return [];
        }

        $arguments = [];

        foreach ($this->callScanner->definitionCalls($contents) as $call) {
            $arguments[] = $this->argumentParser->literalArgumentAt(
                $call['body'],
                $call['cron_argument_position'],
                'defaultCronExpression',
            );
        }

        return array_values(array_unique($arguments));
    }
}
