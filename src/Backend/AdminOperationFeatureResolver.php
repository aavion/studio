<?php

declare(strict_types=1);

namespace App\Backend;

use App\Core\Operation\Live\LiveOperationQueueFactory;

final class AdminOperationFeatureResolver
{
    public function mutationFeatureForOperation(string $operation): ?string
    {
        return match ($operation) {
            LiveOperationQueueFactory::EXTENSION_DISCOVERY,
            LiveOperationQueueFactory::EXTENSION_LIFECYCLE,
            LiveOperationQueueFactory::EXTENSION_INSTALL_VERIFY,
            LiveOperationQueueFactory::EXTENSION_INSTALL_APPLY,
            LiveOperationQueueFactory::EXTENSION_OPERATION => 'admin.extensions',
            LiveOperationQueueFactory::EXTENSION_ASSET_REBUILD,
            LiveOperationQueueFactory::BACKEND_CACHE_CLEAR => 'admin.actions.maintenance',
            LiveOperationQueueFactory::GEOIP_DATABASE_UPDATE => 'admin.settings.statistics.geoip',
            LiveOperationQueueFactory::ACL_GROUP_APPLY => 'admin.users.acl',
            default => null,
        };
    }
}
