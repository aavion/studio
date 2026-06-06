<?php

declare(strict_types=1);

namespace App\Setup;

final readonly class SetupTailwindPreflightProbe
{
    public function __construct(
        private SetupPreflightCheckFactory $checkFactory = new SetupPreflightCheckFactory(),
        private SetupPreflightProcessProbe $processProbe = new SetupPreflightProcessProbe(),
    ) {
    }

    /**
     * @return array{key: string, status: string, required: bool, healable: bool, label_key: string, help_key: string, instruction_key: string, value_key: string, value_parameters: array<string, string>}
     */
    public function check(string $projectDir): array
    {
        $binary = $this->tailwindBinary($projectDir);
        if (null === $binary) {
            return $this->checkFactory->row('tailwind_build', 'warning', false, false, 'tailwind_not_prepared');
        }

        if ($this->tailwindSmokeBuildWorks($binary, $projectDir)) {
            return $this->checkFactory->row('tailwind_build', 'ok', false, false, 'tailwind_available');
        }

        return $this->checkFactory->row('tailwind_build', 'warning', false, false, 'tailwind_blocked');
    }

    private function tailwindBinary(string $projectDir): ?string
    {
        $candidates = glob($projectDir.'/var/tailwind/*/tailwindcss-*') ?: [];
        $candidates = array_values(array_filter(
            $candidates,
            static fn (string $candidate): bool => is_file($candidate) && is_executable($candidate),
        ));
        rsort($candidates);

        return $candidates[0] ?? null;
    }

    private function tailwindSmokeBuildWorks(string $binary, string $projectDir): bool
    {
        $temporaryDirectory = sys_get_temp_dir().'/system_tailwind_preflight_'.bin2hex(random_bytes(4));

        if (!@mkdir($temporaryDirectory, 0775, true) && !is_dir($temporaryDirectory)) {
            return false;
        }

        $input = $temporaryDirectory.'/input.css';
        $output = $temporaryDirectory.'/output.css';
        @file_put_contents($input, ".system-tailwind-preflight{color:red}\n");

        try {
            return $this->processProbe->commandWorks([$binary, '-i', $input, '-o', $output], $projectDir, timeout: 10.0)
                && is_file($output);
        } catch (\Throwable) {
            return false;
        } finally {
            @unlink($input);
            @unlink($output);
            @rmdir($temporaryDirectory);
        }
    }
}
