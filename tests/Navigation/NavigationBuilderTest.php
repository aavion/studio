<?php

declare(strict_types=1);

namespace App\Tests\Navigation;

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

        $navigation = self::getContainer()->get(NavigationBuilder::class)->build('main', 'en');

        self::assertSame(['Home', 'About', 'News'], array_column($navigation, 'label'));
        self::assertSame(['/', '/about', '/news/first-update'], array_column($navigation, 'url'));
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

        $navigation = self::getContainer()->get(NavigationBuilder::class)->build('main', 'en');

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

        $navigation = self::getContainer()->get(NavigationBuilder::class)->build('main', 'en');

        self::assertSame(['Home', 'About', 'Packages', 'News'], array_column($navigation, 'label'));
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

        self::assertSame(
            ['Home', 'About', 'News', 'Module Root', 'Module Child'],
            array_map(static fn (NavigationItem $item): string => $item->label(), $items),
        );
        self::assertSame('30000000-0000-0000-0000-000000000995', $items[4]->parentUid());
        self::assertSame([], $items[4]->children());
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

        $navigation = self::getContainer()->get(NavigationBuilder::class)->build('main', 'en', maxDepth: 2, startLevel: 2);

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
}
