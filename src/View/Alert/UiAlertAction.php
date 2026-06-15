<?php

declare(strict_types=1);

namespace App\View\Alert;

final readonly class UiAlertAction
{
    /**
     * @param array<string, mixed> $detail
     */
    private function __construct(
        private string $label,
        private ?string $href = null,
        private ?string $target = null,
        private ?string $event = null,
        private array $detail = [],
    ) {
    }

    public static function link(string $label, string $href, ?string $target = null): self
    {
        return new self($label, href: $href, target: $target);
    }

    /**
     * @param array<string, mixed> $detail
     */
    public static function event(string $label, string $event, array $detail = []): self
    {
        return new self($label, event: $event, detail: $detail);
    }

    /**
     * @param array<string, mixed> $action
     *
     * @return array{label: string, href?: string, target?: string, event?: string, detail?: array<string, mixed>}|null
     */
    public static function normalize(array $action): ?array
    {
        $label = trim((string) ($action['label'] ?? ''));
        if ('' === $label) {
            return null;
        }

        $normalized = ['label' => $label];
        $href = isset($action['href']) ? trim((string) $action['href']) : '';
        $event = isset($action['event']) ? trim((string) $action['event']) : '';

        if ('' !== $href) {
            if (!self::hrefAllowed($href)) {
                return null;
            }

            $normalized['href'] = $href;
            $target = isset($action['target']) ? trim((string) $action['target']) : '';
            if ('' !== $target && self::targetAllowed($target)) {
                $normalized['target'] = $target;
            }

            return $normalized;
        }

        if ('' !== $event) {
            $normalized['event'] = $event;
        }

        if (isset($action['detail']) && is_array($action['detail'])) {
            $normalized['detail'] = $action['detail'];
        }

        return isset($normalized['event']) ? $normalized : null;
    }

    /**
     * @return array{label: string, href?: string, target?: string, event?: string, detail?: array<string, mixed>}
     */
    public function toArray(): array
    {
        $action = ['label' => trim($this->label)];

        if (null !== $this->href && '' !== trim($this->href) && self::hrefAllowed($this->href)) {
            $action['href'] = trim($this->href);
        }

        if (isset($action['href']) && null !== $this->target && '' !== trim($this->target) && self::targetAllowed($this->target)) {
            $action['target'] = trim($this->target);
        }

        if (null !== $this->event && '' !== trim($this->event)) {
            $action['event'] = $this->event;
        }

        if ([] !== $this->detail) {
            $action['detail'] = $this->detail;
        }

        return $action;
    }

    private static function hrefAllowed(string $href): bool
    {
        $href = trim($href);
        if ('' === $href || str_contains($href, '\\') || preg_match('/[\x00-\x1F\x7F]/', $href)) {
            return false;
        }

        if (str_starts_with($href, '/')) {
            return !str_starts_with($href, '//');
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        if ('' === $scheme) {
            return false;
        }

        if (in_array($scheme, ['http', 'https'], true)) {
            $host = parse_url($href, PHP_URL_HOST);

            return is_string($host) && '' !== trim($host);
        }

        return 'mailto' === $scheme;
    }

    private static function targetAllowed(string $target): bool
    {
        return in_array(trim($target), ['_blank', '_self', '_parent', '_top'], true);
    }
}
