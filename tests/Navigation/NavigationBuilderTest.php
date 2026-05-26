<?php

declare(strict_types=1);

namespace App\Tests\Navigation;

use App\Core\Access\AccessActor;
use App\Core\Config\Config;
use App\Navigation\Event\NavigationBuilderEvent;
use App\Navigation\NavigationBuilder;
use App\Navigation\NavigationItem;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

final class NavigationBuilderTest extends KernelTestCase
{
    public function testItBuildsSeededMainNavigation(): void
    {
        self::bootKernel();

        $navigation = self::getContainer()->get(NavigationBuilder::class)->build('main', 'en', actor: AccessActor::anonymous());

        self::assertSame(['Home', 'About', 'News', 'ui.user.login.title'], array_column($navigation, 'label'));
        self::assertSame(['/', '/about', '/news/first-update', '/user/login'], array_column($navigation, 'url'));
    }

    public function testItDispatchesNavigationBuilderHook(): void
    {
        self::bootKernel();
        $seenRequest = [];

        self::getContainer()->get(EventDispatcherInterface::class)->addListener(
            NavigationBuilderEvent::class,
            static function (NavigationBuilderEvent $event) use (&$seenRequest): void {
                $seenRequest = [
                    'max_depth' => $event->maxDepth(),
                    'start_level' => $event->startLevel(),
                    'root_uid' => $event->rootUid(),
                ];
                $event->addItem(new NavigationItem(
                    '30000000-0000-0000-0000-000000000999',
                    'Docs',
                    'url',
                    '/docs',
                    sortOrder: 99,
                ));
            },
        );

        $navigation = self::getContainer()->get(NavigationBuilder::class)->build('main', 'en', actor: AccessActor::anonymous());

        self::assertSame('Docs', $navigation[3]['label']);
        self::assertSame('/docs', $navigation[3]['url']);
        self::assertSame(['max_depth' => 3, 'start_level' => 1, 'root_uid' => null], $seenRequest);
    }

    public function testItBuildsInjectedParentChildHierarchyAndSortOrder(): void
    {
        self::bootKernel();

        self::getContainer()->get(EventDispatcherInterface::class)->addListener(
            NavigationBuilderEvent::class,
            static function (NavigationBuilderEvent $event): void {
                $event->addItem(new NavigationItem(
                    '30000000-0000-0000-0000-000000000991',
                    'Beta',
                    'url',
                    '/about/beta',
                    '30000000-0000-0000-0000-000000000102',
                    20,
                ));
                $event->addItem(new NavigationItem(
                    '30000000-0000-0000-0000-000000000992',
                    'Alpha',
                    'url',
                    '/about/alpha',
                    '30000000-0000-0000-0000-000000000102',
                    10,
                ));
                $event->addItem(new NavigationItem(
                    '30000000-0000-0000-0000-000000000993',
                    'Packages',
                    'url',
                    '/packages',
                    sortOrder: 25,
                ));
                $event->addItem(new NavigationItem(
                    '30000000-0000-0000-0000-000000000994',
                    'Package Child',
                    'url',
                    '/packages/child',
                    '30000000-0000-0000-0000-000000000993',
                    10,
                ));
            },
        );

        $navigation = self::getContainer()->get(NavigationBuilder::class)->build('main', 'en', actor: AccessActor::anonymous());

        self::assertSame(['Home', 'About', 'Packages', 'News', 'ui.user.login.title'], array_column($navigation, 'label'));
        self::assertSame(['Alpha', 'Beta'], array_column($navigation[1]['children'], 'label'));
        self::assertSame('Package Child', $navigation[2]['children'][0]['label']);
    }

    public function testItExposesCollectedFlatItemsForCustomBuilders(): void
    {
        self::bootKernel();

        self::getContainer()->get(EventDispatcherInterface::class)->addListener(
            NavigationBuilderEvent::class,
            static function (NavigationBuilderEvent $event): void {
                $event->addItem(new NavigationItem(
                    '30000000-0000-0000-0000-000000000995',
                    'Module Root',
                    'url',
                    '/module',
                    sortOrder: 40,
                ));
                $event->addItem(new NavigationItem(
                    '30000000-0000-0000-0000-000000000996',
                    'Module Child',
                    'url',
                    '/module/child',
                    '30000000-0000-0000-0000-000000000995',
                    10,
                ));
            },
        );

        $items = self::getContainer()->get(NavigationBuilder::class)->collectItems('main', 'en');

        self::assertContains('Module Root', array_map(static fn (NavigationItem $item): string => $item->label(), $items));
        self::assertContains('Module Child', array_map(static fn (NavigationItem $item): string => $item->label(), $items));
        $moduleChild = array_values(array_filter(
            $items,
            static fn (NavigationItem $item): bool => 'Module Child' === $item->label(),
        ))[0];
        self::assertSame('30000000-0000-0000-0000-000000000995', $moduleChild->parentUid());
        self::assertSame([], $moduleChild->children());
    }

    public function testItLimitsNavigationDepth(): void
    {
        self::bootKernel();

        $this->addDeepAboutNavigation();

        $navigation = self::getContainer()->get(NavigationBuilder::class)->build('main', 'en');

        self::assertSame('Team', $navigation[1]['children'][0]['label']);
        self::assertSame('Profile', $navigation[1]['children'][0]['children'][0]['label']);
        self::assertSame([], $navigation[1]['children'][0]['children'][0]['children']);
    }

    public function testItBuildsSplitMenuFromStartLevel(): void
    {
        self::bootKernel();

        $this->addDeepAboutNavigation();

        $navigation = self::getContainer()->get(NavigationBuilder::class)->build(
            'main',
            'en',
            maxDepth: 2,
            startLevel: 2,
            actor: AccessActor::anonymous(),
        );

        self::assertSame(['Team'], array_column($navigation, 'label'));
        self::assertSame(2, $navigation[0]['level']);
        self::assertSame('Profile', $navigation[0]['children'][0]['label']);
        self::assertSame([], $navigation[0]['children'][0]['children']);
    }

    public function testItBuildsSplitMenuFromRootUid(): void
    {
        self::bootKernel();

        $this->addDeepAboutNavigation();

        $navigation = self::getContainer()->get(NavigationBuilder::class)->build(
            'main',
            'en',
            maxDepth: 2,
            rootUid: '30000000-0000-0000-0000-000000000102',
        );

        self::assertSame(['Team'], array_column($navigation, 'label'));
        self::assertSame(1, $navigation[0]['level']);
        self::assertSame('Profile', $navigation[0]['children'][0]['label']);
    }

    public function testItKeepsStoredNavigationWhenHookFails(): void
    {
        self::bootKernel();

        self::getContainer()->get(EventDispatcherInterface::class)->addListener(
            NavigationBuilderEvent::class,
            static function (NavigationBuilderEvent $event): void {
                $event->setItems([]);

                throw new RuntimeException('Navigation failed');
            },
        );

        $navigation = self::getContainer()->get(NavigationBuilder::class)->build('main', 'en');

        self::assertSame(['Home', 'About', 'News'], array_column($navigation, 'label'));
    }

    public function testItBuildsRouteTargetsAndActiveState(): void
    {
        self::bootKernel();

        self::getContainer()->get(EventDispatcherInterface::class)->addListener(
            NavigationBuilderEvent::class,
            static function (NavigationBuilderEvent $event): void {
                $event->addItem(new NavigationItem(
                    '30000000-0000-0000-0000-000000000971',
                    'Admin',
                    'route',
                    'backend_admin_index',
                    sortOrder: 1,
                    metadata: ['min_access_level' => 8],
                ));
            },
        );

        $navigation = self::getContainer()->get(NavigationBuilder::class)->build(
            'backend.admin',
            actor: AccessActor::fromAccess(8),
            activeRoute: 'backend_admin_index',
        );

        self::assertSame('/admin', $navigation[0]['url']);
        self::assertTrue($navigation[0]['active']);
    }

    public function testItBuildsBackendViewsFromRegistry(): void
    {
        self::bootKernel();

        $navigation = self::getContainer()->get(NavigationBuilder::class)->build(
            'backend.admin',
            actor: AccessActor::fromAccess(8),
            activeUrl: '/admin/packages',
            activeRoute: 'backend_admin_route',
        );

        self::assertSame(['admin.navigation.dashboard', 'admin.navigation.packages'], array_column($navigation, 'label'));
        self::assertSame(['/admin', '/admin/packages'], array_column($navigation, 'url'));
        self::assertFalse($navigation[0]['active']);
        self::assertTrue($navigation[1]['active']);
    }

    public function testItFiltersNavigationItemsByAccessLevel(): void
    {
        self::bootKernel();

        self::getContainer()->get(EventDispatcherInterface::class)->addListener(
            NavigationBuilderEvent::class,
            static function (NavigationBuilderEvent $event): void {
                $event->addItem(new NavigationItem(
                    '30000000-0000-0000-0000-000000000972',
                    'Allowed',
                    'url',
                    '/allowed',
                    metadata: ['min_access_level' => 3],
                ));
                $event->addItem(new NavigationItem(
                    '30000000-0000-0000-0000-000000000973',
                    'Blocked',
                    'url',
                    '/blocked',
                    metadata: ['min_access_level' => 8],
                ));
            },
        );

        $navigation = self::getContainer()->get(NavigationBuilder::class)->build(
            'backend.editor',
            actor: AccessActor::fromAccess(3),
        );

        self::assertContains('Allowed', array_column($navigation, 'label'));
        self::assertNotContains('Blocked', array_column($navigation, 'label'));
    }

    public function testItAddsUserNavigationWithAccessAwareChildren(): void
    {
        self::bootKernel();

        $anonymousNavigation = self::getContainer()->get(NavigationBuilder::class)->build(
            'main',
            actor: AccessActor::anonymous(),
        );
        $account = $anonymousNavigation[3];

        self::assertSame('ui.user.login.title', $account['label']);
        self::assertSame('/user/login', $account['url']);
        self::assertSame([], $account['children']);

        $editorNavigation = self::getContainer()->get(NavigationBuilder::class)->build(
            'main',
            actor: AccessActor::fromAccess(3, userUid: '10000000-0000-0000-0000-000000000001'),
        );
        $account = $editorNavigation[3];

        self::assertSame('ui.user.profile.title', $account['label']);
        self::assertSame(['ui.user.api_keys.title', 'ui.user.navigation.studio', 'ui.user.logout.title'], array_column($account['children'], 'label'));
        self::assertNotContains('ui.user.login.title', array_column($account['children'], 'label'));
        self::assertNotContains('ui.user.navigation.admin', array_column($account['children'], 'label'));
        self::assertSame([
            'data-turbo' => 'false',
            'data-turbo-prefetch' => 'false',
        ], $account['children'][2]['metadata']['link_attributes']);

        $adminNavigation = self::getContainer()->get(NavigationBuilder::class)->build(
            'main',
            actor: AccessActor::fromAccess(8, userUid: '10000000-0000-0000-0000-000000000002'),
        );
        $account = $adminNavigation[3];

        self::assertContains('ui.user.navigation.admin', array_column($account['children'], 'label'));
    }

    public function testItCanDisableSystemUserNavigation(): void
    {
        self::bootKernel();
        $this->setConfig('user.menu.enabled', false);

        try {
            $navigation = self::getContainer()->get(NavigationBuilder::class)->build(
                'main',
                actor: AccessActor::anonymous(),
            );

            self::assertNotContains('ui.user.login.title', array_column($navigation, 'label'));
        } finally {
            $this->setConfig('user.menu.enabled', true);
        }
    }

    private function addDeepAboutNavigation(): void
    {
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(
            NavigationBuilderEvent::class,
            static function (NavigationBuilderEvent $event): void {
                $event->addItem(new NavigationItem(
                    '30000000-0000-0000-0000-000000000981',
                    'Team',
                    'url',
                    '/about/team',
                    '30000000-0000-0000-0000-000000000102',
                    10,
                ));
                $event->addItem(new NavigationItem(
                    '30000000-0000-0000-0000-000000000982',
                    'Profile',
                    'url',
                    '/about/team/profile',
                    '30000000-0000-0000-0000-000000000981',
                    10,
                ));
                $event->addItem(new NavigationItem(
                    '30000000-0000-0000-0000-000000000983',
                    'Deep',
                    'url',
                    '/about/team/profile/deep',
                    '30000000-0000-0000-0000-000000000982',
                    10,
                ));
            },
        );
    }

    private function setConfig(string $key, mixed $value): void
    {
        self::getContainer()->get(Config::class)->set($key, $value);
    }
}
