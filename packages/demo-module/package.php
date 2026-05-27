<?php

declare(strict_types=1);

use App\Core\Package\Settings\PackageSettingDefinition;
use App\Form\FormInputType;
use App\View\Injection\ConfigurableStaticViewInjectionRoute;
use App\View\Injection\ConfigurableStaticViewInjectionSet;
use App\View\Injection\DynamicViewInjection;
use App\View\Injection\DynamicViewInjectionFilter;
use App\View\Injection\DynamicViewInjectionSlot;
use App\View\Injection\ViewSurface;

return [
    new ConfigurableStaticViewInjectionSet(
        'demo-module',
        'demo.route',
        ViewSurface::Public,
        'demo',
        [
            new ConfigurableStaticViewInjectionRoute(
                'pkg-demo-module-public-demo',
                '',
                'pkg.demo-module.navigation.demo',
                '@frontend/demo-module/frontend.html.twig',
                sortOrder: 700,
            ),
            new ConfigurableStaticViewInjectionRoute(
                'pkg-demo-module-public-backend',
                'backend',
                'pkg.demo-module.navigation.backend',
                '@backend/demo-module/shell.html.twig',
                sortOrder: 720,
            ),
            new ConfigurableStaticViewInjectionRoute(
                'pkg-demo-module-public-typography',
                'typography',
                'pkg.demo-module.navigation.typography',
                '@frontend/demo-module/typography.html.twig',
                sortOrder: 730,
            ),
        ],
    ),
    new DynamicViewInjection(
        'pkg-demo-module-after-content',
        ViewSurface::Public,
        DynamicViewInjectionSlot::AfterContent,
        '@frontend/demo-module/after-content.html.twig',
        DynamicViewInjectionFilter::realContent(),
        sortOrder: 700,
        label: 'pkg.demo-module.dynamic.after_content.label',
    ),
    new PackageSettingDefinition(
        'demo-module',
        'display.mode',
        'pkg.demo-module.settings.display_mode.label',
        'compact',
        description: 'pkg.demo-module.settings.display_mode.help',
        options: [
            'compact' => 'pkg.demo-module.settings.display_mode.options.compact',
            'expanded' => 'pkg.demo-module.settings.display_mode.options.expanded',
        ],
        inputType: FormInputType::Select,
    ),
    new PackageSettingDefinition(
        'demo-module',
        'demo.route',
        'pkg.demo-module.settings.demo_route.label',
        'demo',
        description: 'pkg.demo-module.settings.demo_route.help',
        inputType: FormInputType::Text,
        validation: ['required' => true, 'pattern' => '^[a-z0-9][a-z0-9\/-]*$'],
        sortOrder: 10,
    ),
];
