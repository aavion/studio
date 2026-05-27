<?php

declare(strict_types=1);

namespace App\Navigation;

use App\Core\Access\AccessLevel;
use App\Navigation\Event\NavigationBuilderEvent;
use App\Security\UserFlowConfig;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class UserNavigationSubscriber implements EventSubscriberInterface
{
    private const LOGIN_ROOT_UID = 'virtual-user-login-root';
    private const PROFILE_ROOT_UID = 'virtual-user-profile-root';

    public function __construct(private UserFlowConfig $config)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            NavigationBuilderEvent::class => 'onNavigationBuilder',
        ];
    }

    public function onNavigationBuilder(NavigationBuilderEvent $event): void
    {
        if ('main' !== $event->identifier() || !$this->config->menuEnabled()) {
            return;
        }

        $event->addItem(new NavigationItem(
            self::LOGIN_ROOT_UID,
            'ui.user.login.title',
            'route',
            'user_login',
            sortOrder: $this->config->menuSortOrder(),
            metadata: ['anonymous_only' => true],
        ));

        $event->addItem(new NavigationItem(
            self::PROFILE_ROOT_UID,
            'ui.user.profile.title',
            'route',
            'user_profile',
            sortOrder: $this->config->menuSortOrder(),
            metadata: ['min_access_level' => AccessLevel::REGISTERED],
        ));

        $this->addChild($event, 'api-keys', 'ui.user.api_keys.title', 'user_api_keys', 20, ['min_access_level' => AccessLevel::REGISTERED]);
        $this->addChild($event, 'studio', 'ui.user.navigation.studio', 'backend_editor_index', 80, ['min_access_level' => AccessLevel::EDITOR]);
        $this->addChild($event, 'admin', 'ui.user.navigation.admin', 'backend_admin_index', 90, ['min_access_level' => 8]);
        $this->addChild($event, 'logout', 'ui.user.logout.title', 'user_logout', 1000, [
            'min_access_level' => AccessLevel::REGISTERED,
            'link_attributes' => [
                'data-turbo' => 'false',
                'data-turbo-prefetch' => 'false',
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function addChild(
        NavigationBuilderEvent $event,
        string $uid,
        string $label,
        string $route,
        int $sortOrder,
        array $metadata = [],
    ): void {
        $event->addItem(new NavigationItem(
            'virtual-user-'.$uid,
            $label,
            'route',
            $route,
            self::PROFILE_ROOT_UID,
            $sortOrder,
            $metadata,
        ));
    }
}
