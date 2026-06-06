<?php

declare(strict_types=1);

namespace App\Debug;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

final class SystemDebugCollector implements EventSubscriberInterface
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $hooks = [];

    /**
     * @var array<string, list<string>>
     */
    private array $templatePaths = [];

    public function __construct(private readonly bool $debug)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 2048],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $this->hooks = [];
        $this->templatePaths = [];
    }

    public function enabled(): bool
    {
        return $this->debug;
    }

    /**
     * @param array<string, mixed> $context
     */
    public function recordHook(
        string $event,
        string $domain,
        string $mode,
        bool $mutable,
        string $status,
        array $context = [],
        ?string $package = null,
        int $issues = 0,
    ): void {
        if (!$this->debug) {
            return;
        }

        $this->hooks[] = [
            'event' => $event,
            'domain' => $domain,
            'mode' => $mode,
            'mutable' => $mutable,
            'status' => $status,
            'context' => $this->normalize($context),
            'package' => $package,
            'issues' => $issues,
        ];
    }

    /**
     * @param array<string, list<string>> $pathsByNamespace
     */
    public function recordTemplatePaths(array $pathsByNamespace): void
    {
        if (!$this->debug) {
            return;
        }

        foreach ($pathsByNamespace as $namespace => $paths) {
            $this->templatePaths[$namespace] = array_values($paths);
        }
    }

    /**
     * @return array{hooks: list<array<string, mixed>>, template_paths: array<string, list<string>>}
     */
    public function summary(): array
    {
        return [
            'hooks' => $this->hooks,
            'template_paths' => $this->templatePaths,
        ];
    }

    public function htmlComment(): string
    {
        if (!$this->debug) {
            return '';
        }

        $json = json_encode($this->summary(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || '{}' === $json) {
            return '';
        }

        return "\n<!-- system-debug\n".str_replace('--', '- -', $json)."\n-->";
    }

    private function normalize(mixed $value, int $depth = 0): mixed
    {
        if (2 < $depth) {
            return '[depth-limit]';
        }

        if (null === $value || is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            $normalized = [];
            foreach ($value as $key => $item) {
                if (!is_string($key) && !is_int($key)) {
                    continue;
                }

                $normalized[$key] = $this->normalize($item, $depth + 1);
            }

            return $normalized;
        }

        if (is_object($value)) {
            return $value::class;
        }

        return get_debug_type($value);
    }
}
