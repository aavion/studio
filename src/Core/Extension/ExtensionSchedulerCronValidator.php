<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Scheduler\SchedulerCron;

final readonly class ExtensionSchedulerCronValidator
{
    public function __construct(
        private ExtensionSchedulerCronInspector $schedulerCronInspector = new ExtensionSchedulerCronInspector(),
        private ExtensionValidationIssueFactory $issueFactory = new ExtensionValidationIssueFactory(),
    ) {
    }

    /**
     * @param list<string> $files
     *
     * @return list<Message>
     */
    public function validate(ExtensionCandidate $candidate, array $files): array
    {
        $issues = [];

        foreach ($files as $file) {
            $path = $candidate->directory().DIRECTORY_SEPARATOR.$file;
            $contents = file_get_contents($path);

            if (false === $contents) {
                $issues[] = $this->issueFactory->unreadableFile($candidate, $file, $path);
                continue;
            }

            foreach ($this->schedulerCronInspector->cronArguments($contents) as $expression) {
                if (null === $expression || !SchedulerCron::isValid($expression)) {
                    $issues[] = $this->issue($candidate, $file, $path, $expression ?? '');
                }
            }
        }

        return $issues;
    }

    private function issue(ExtensionCandidate $candidate, string $file, string $path, string $value): Message
    {
        return Message::create(
            ExtensionMessageCode::EXTENSION_SCHEDULER_CRON_INVALID,
            ExtensionMessageKey::EXTENSION_SCHEDULER_CRON_INVALID,
            ['%extension%' => trim((string) $candidate->manifest()->get('EXTENSION_SLUG', ''))],
            [
                'source' => $candidate->source()->name(),
                'extension' => $candidate->directory(),
                'path' => $path,
                'file' => $file,
                'value' => $value,
            ],
            MessageLevel::Error,
        );
    }
}
