<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Package\PackageMessageCode;
use App\Core\Package\PackageMessageKey;
use App\Scheduler\SchedulerCron;

final readonly class PackageSchedulerCronValidator
{
    public function __construct(
        private PackageSchedulerCronInspector $schedulerCronInspector = new PackageSchedulerCronInspector(),
        private PackageValidationIssueFactory $issueFactory = new PackageValidationIssueFactory(),
    ) {
    }

    /**
     * @param list<string> $files
     *
     * @return list<Message>
     */
    public function validate(PackageCandidate $candidate, array $files): array
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

    private function issue(PackageCandidate $candidate, string $file, string $path, string $value): Message
    {
        return Message::create(
            PackageMessageCode::PACKAGE_SCHEDULER_CRON_INVALID,
            PackageMessageKey::PACKAGE_SCHEDULER_CRON_INVALID,
            ['%package%' => trim((string) $candidate->manifest()->get('PACKAGE_SLUG', ''))],
            [
                'source' => $candidate->source()->name(),
                'package' => $candidate->directory(),
                'path' => $path,
                'file' => $file,
                'value' => $value,
            ],
            MessageLevel::Error,
        );
    }
}
