<?php

declare(strict_types=1);

namespace App\Core\Extension;

final readonly class ExtensionSchedulerCronInspector
{
    public function __construct(
        private ExtensionSchedulerDefinitionCallScanner $callScanner = new ExtensionSchedulerDefinitionCallScanner(),
        private ExtensionPhpCallArgumentParser $argumentParser = new ExtensionPhpCallArgumentParser(),
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
