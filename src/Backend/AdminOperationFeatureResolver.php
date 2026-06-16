<?php

declare(strict_types=1);

namespace App\Backend;

use App\Core\Operation\Live\LiveOperationQueueFactory;

final class AdminOperationFeatureResolver
{
    public function mutationFeatureForOperation(string $operation): ?string
    {
        return match ($operation) {
            LiveOperationQueueFactory::PACKAGE_DISCOVERY,
            LiveOperationQueueFactory::PACKAGE_LIFECYCLE,
            LiveOperationQueueFactory::PACKAGE_INSTALL_VERIFY,
            LiveOperationQueueFactory::PACKAGE_INSTALL_APPLY => 'admin.packages',
            LiveOperationQueueFactory::PACKAGE_ASSET_REBUILD,
            LiveOperationQueueFactory::BACKEND_CACHE_CLEAR => 'admin.actions.maintenance',
            LiveOperationQueueFactory::GEOIP_DATABASE_UPDATE => 'admin.settings.statistics.geoip',
            LiveOperationQueueFactory::ACL_GROUP_APPLY => 'admin.users.acl',
            default => null,
        };
    }
}
