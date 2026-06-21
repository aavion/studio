<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Entity\AclGroup;
use App\Security\UserRole;
use App\View\Alert\UiAlertDelivery;
use App\View\Alert\UiAlertDeliveryOptions;
use App\View\Alert\UiAlertDispatcherInterface;
use App\View\Alert\UiAlertMode;
use App\View\Alert\UiAlertPresentation;
use App\View\Alert\UiAlertTopicFactory;
use App\View\Alert\UiAlertTranslation;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

final readonly class ExtensionAlertFacade
{
    public function __construct(
        private UiAlertDispatcherInterface $alerts,
        private UiAlertTopicFactory $topics,
        private ?EntityManagerInterface $entityManager = null,
    ) {
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $options
     */
    public function alert(string $extensionName, string $level, string $message, array $parameters = [], array $options = []): bool
    {
        if (!ExtensionManifestSpec::isValidSlug($extensionName)) {
            return false;
        }

        $alert = UiAlertTranslation::forLevel($this->level($level), $this->translationKey($message), $this->parameters($message, $parameters));
        $target = strtolower(trim((string) ($options['target'] ?? 'current')));
        $delivery = $this->delivery($options, 'current' === $target || '' === $target);
        $presentation = $this->presentation($options);

        try {
            return match ($target) {
                '', 'current', 'request' => $this->alerts->addAlert($alert, $delivery, $presentation),
                'session' => $this->alertSession($alert, $delivery, $presentation, $options),
                'user' => $this->alertUser($alert, $delivery, $presentation, $options),
                'acl_group', 'group' => $this->alertAclGroup($alert, $delivery, $presentation, $options),
                'role' => $this->alertRole($alert, $delivery, $presentation, $options),
                'topic' => $this->alertTopic($alert, $delivery, $presentation, $options),
                default => false,
            };
        } catch (Throwable) {
            return false;
        }
    }

    private function level(string $level): string
    {
        return match (strtolower(trim($level))) {
            'success', 'notice' => 'success',
            'exception', 'critical', 'error' => 'error',
            'warning', 'warn' => 'warning',
            'debug', 'info' => 'info',
            default => 'info',
        };
    }

    private function translationKey(string $message): string
    {
        $message = trim($message);

        return 1 === preg_match('/^message(?:\.[a-z][a-z0-9_]*)+$/', $message)
            ? $message
            : ExtensionMessageKey::EXTENSION_RUNTIME_LOG;
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array<string, mixed>
     */
    private function parameters(string $message, array $parameters): array
    {
        if ($this->translationKey($message) !== ExtensionMessageKey::EXTENSION_RUNTIME_LOG) {
            return $parameters;
        }

        return [...$parameters, '%message%' => '' !== trim($message) ? trim($message) : 'Extension alert'];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function delivery(array $options, bool $currentTarget): UiAlertDeliveryOptions
    {
        $delivery = strtolower(trim((string) ($options['delivery'] ?? ($currentTarget ? 'direct' : 'queue'))));
        $locale = is_string($options['locale'] ?? null) ? trim((string) $options['locale']) : null;
        $locale = '' !== $locale ? $locale : null;

        return match ($delivery) {
            'push' => UiAlertDeliveryOptions::push($locale),
            'direct', 'flash' => UiAlertDeliveryOptions::direct($locale),
            default => UiAlertDeliveryOptions::queued($locale),
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    private function presentation(array $options): ?UiAlertPresentation
    {
        $mode = strtolower(trim((string) ($options['mode'] ?? '')));
        $mode = match ($mode) {
            UiAlertMode::Hidden->value => UiAlertMode::Hidden,
            UiAlertMode::Persistent->value => UiAlertMode::Persistent,
            UiAlertMode::Auto->value => UiAlertMode::Auto,
            default => null,
        };

        $title = is_string($options['title'] ?? null) ? trim((string) $options['title']) : null;
        $id = is_string($options['id'] ?? null) ? trim((string) $options['id']) : null;
        $actions = is_array($options['actions'] ?? null) ? $options['actions'] : [];
        $loading = is_bool($options['loading'] ?? null) ? (bool) $options['loading'] : null;

        if (null === $mode && null === $title && null === $id && [] === $actions && null === $loading) {
            return null;
        }

        return new UiAlertPresentation($mode, '' !== $title ? $title : null, '' !== $id ? $id : null, $actions, $loading);
    }

    private function alertSession(UiAlertTranslation $alert, UiAlertDeliveryOptions $delivery, ?UiAlertPresentation $presentation, array $options): bool
    {
        $session = $this->stringOption($options, ['session', 'id', 'uid']);

        return null !== $session && $this->alerts->addAlertToSession($session, $alert, $delivery, $presentation);
    }

    private function alertUser(UiAlertTranslation $alert, UiAlertDeliveryOptions $delivery, ?UiAlertPresentation $presentation, array $options): bool
    {
        $uid = $this->stringOption($options, ['user', 'uid', 'id']);
        if (!$this->isUuid($uid)) {
            return false;
        }

        return $this->alerts->addAlertToUser(strtolower((string) $uid), $alert, $delivery, $presentation);
    }

    private function alertAclGroup(UiAlertTranslation $alert, UiAlertDeliveryOptions $delivery, ?UiAlertPresentation $presentation, array $options): bool
    {
        $group = $this->resolveAclGroup($this->stringOption($options, ['acl_group', 'group', 'uid', 'identifier', 'id']));
        if (!$group instanceof AclGroup) {
            return false;
        }

        return $this->alerts->addAlertToTopic($this->topics->aclGroupTopic($group), $alert, $delivery, $presentation);
    }

    private function alertRole(UiAlertTranslation $alert, UiAlertDeliveryOptions $delivery, ?UiAlertPresentation $presentation, array $options): bool
    {
        $role = UserRole::tryFrom((string) $this->stringOption($options, ['role', 'value', 'id']));
        if (!$role instanceof UserRole || UserRole::Public === $role) {
            return false;
        }

        return $this->alerts->addAlertToTopic($this->topics->roleTopic($role), $alert, $delivery, $presentation);
    }

    private function alertTopic(UiAlertTranslation $alert, UiAlertDeliveryOptions $delivery, ?UiAlertPresentation $presentation, array $options): bool
    {
        $topic = $this->stringOption($options, ['topic', 'id']);

        return null !== $topic
            && $this->topics->isUiAlertTopic($topic)
            && $this->alerts->addAlertToTopic($topic, $alert, $delivery, $presentation);
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $keys
     */
    private function stringOption(array $options, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $options[$key] ?? null;
            if (is_string($value) && '' !== trim($value)) {
                return trim($value);
            }
        }

        return null;
    }

    private function resolveAclGroup(?string $identifier): ?AclGroup
    {
        if (null === $identifier || !$this->entityManager instanceof EntityManagerInterface) {
            return null;
        }

        try {
            $repository = $this->entityManager->getRepository(AclGroup::class);

            if ($this->isUuid($identifier)) {
                $group = $repository->find(strtolower($identifier));

                return $group instanceof AclGroup ? $group : null;
            }

            $group = $repository->findOneBy(['identifier' => $identifier]);

            return $group instanceof AclGroup ? $group : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function isUuid(?string $value): bool
    {
        return is_string($value)
            && 1 === preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $value);
    }
}
