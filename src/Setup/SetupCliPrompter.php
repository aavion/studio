<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Message\MessageKey;

final class SetupCliPrompter
{
    /** @var resource */
    private mixed $input;

    /** @var resource */
    private mixed $output;

    public function __construct(
        private readonly string $projectDir,
        private readonly SetupMessageTranslator $translator = new SetupMessageTranslator(),
        mixed $input = null,
        mixed $output = null,
        private readonly ?bool $interactive = null,
    ) {
        $this->input = $input ?? STDIN;
        $this->output = $output ?? STDOUT;
    }

    /**
     * @param array<string, string|false> $options
     */
    public function isInteractive(array $options): bool
    {
        if (isset($options['no-interaction']) || isset($options['json'])) {
            return false;
        }

        return $this->interactive ?? stream_isatty($this->input);
    }

    /**
     * @param array<string, string|false> $options
     */
    public function value(array $options, string $name, string $default, bool $interactive, string $language, string $key): string
    {
        $value = $this->option($options, $name, $default);

        return $interactive && !isset($options[$name]) ? $this->prompt($language, $key, $value) : $value;
    }

    /**
     * @param array<string, string|false> $options
     */
    public function confirmedValue(
        array $options,
        string $name,
        string $default,
        bool $interactive,
        string $language,
        string $key,
        string $confirmKey,
    ): string {
        $value = $this->value($options, $name, $default, $interactive, $language, $key);

        if (!$interactive || isset($options[$name])) {
            return $value;
        }

        do {
            $confirmation = $this->prompt($language, $confirmKey, '');
            if ($confirmation === $value) {
                return $value;
            }

            $this->write($this->translator->translate($this->projectDir, $language, MessageKey::SETUP_PROMPT_PASSWORD_MISMATCH));
            $value = $this->prompt($language, $key, '');
        } while (true);
    }

    /**
     * @param array<string, string> $parameters
     */
    public function confirm(string $language, string $key, bool $default = false, array $parameters = []): bool
    {
        $defaultLabel = $default ? 'yes' : 'no';

        do {
            $value = strtolower($this->prompt($language, $key, $defaultLabel, $parameters));

            if (in_array($value, ['y', 'yes', 'j', 'ja'], true)) {
                return true;
            }

            if (in_array($value, ['n', 'no', 'nein'], true)) {
                return false;
            }

            $this->write($this->translator->translate($this->projectDir, $language, MessageKey::SETUP_PROMPT_INVALID_CHOICE, [
                '%choices%' => 'yes, no',
            ]));
        } while (true);
    }

    /**
     * @param list<string> $choices
     */
    public function choice(string $language, string $key, array $choices, string $default): string
    {
        do {
            $value = $this->prompt($language, $key, $default, ['%choices%' => implode(', ', $choices)]);
            if (in_array($value, $choices, true)) {
                return $value;
            }

            $this->write($this->translator->translate($this->projectDir, $language, MessageKey::SETUP_PROMPT_INVALID_CHOICE, [
                '%choices%' => implode(', ', $choices),
            ]));
        } while (true);
    }

    /**
     * @param array<string, string> $parameters
     */
    private function prompt(string $language, string $key, string $default, array $parameters = []): string
    {
        $label = $this->translator->translate($this->projectDir, $language, $key, $parameters);
        $this->write(sprintf('%s%s: ', $label, '' === $default ? '' : ' ['.$default.']'), false);
        $line = fgets($this->input);
        $value = false === $line ? '' : trim($line);

        return '' === $value ? $default : $value;
    }

    private function write(string $message, bool $newline = true): void
    {
        fwrite($this->output, $message.($newline ? PHP_EOL : ''));
    }

    /**
     * @param array<string, string|false> $options
     */
    private function option(array $options, string $name, string $default): string
    {
        $value = $options[$name] ?? null;

        return is_string($value) && '' !== $value ? $value : $default;
    }
}
