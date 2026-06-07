<?php

declare(strict_types=1);

namespace App\Core\Message;

use App\Api\ApiMessageCode;
use App\Backend\BackendMessageCode;
use App\Content\ContentMessageCode;
use App\Core\Access\AccessMessageCode;
use App\Core\Asset\AssetMessageCode;
use App\Core\Config\ConfigMessageCode;
use App\Core\Event\EventMessageCode;
use App\Core\Lint\LintMessageCode;
use App\Core\Manifest\ManifestMessageCode;
use App\Core\Message\CommonMessageCode;
use App\Core\Messenger\MessengerMessageCode;
use App\Core\Operation\Filesystem\FilesystemMessageCode;
use App\Core\Operation\OperationMessageCode;
use App\Core\Operation\Process\ProcessMessageCode;
use App\Core\Package\PackageMessageCode;
use App\Core\Routing\RoutingMessageCode;
use App\Core\Security\SystemSecurityMessageCode;
use App\Core\Translation\TranslationMessageCode;
use App\Scheduler\SchedulerMessageCode;
use App\Security\SecurityMessageCode;
use App\Setup\SetupMessageCode;
use App\View\ViewMessageCode;

final class MessageCode
{
    /**
     * @return list<class-string>
     */
    public static function catalogues(): array
    {
        return [
            AccessMessageCode::class,
            ApiMessageCode::class,
            AssetMessageCode::class,
            BackendMessageCode::class,
            CommonMessageCode::class,
            ConfigMessageCode::class,
            ContentMessageCode::class,
            EventMessageCode::class,
            FilesystemMessageCode::class,
            LintMessageCode::class,
            ManifestMessageCode::class,
            MessengerMessageCode::class,
            OperationMessageCode::class,
            PackageMessageCode::class,
            ProcessMessageCode::class,
            RoutingMessageCode::class,
            SchedulerMessageCode::class,
            SecurityMessageCode::class,
            SetupMessageCode::class,
            SystemSecurityMessageCode::class,
            TranslationMessageCode::class,
            ViewMessageCode::class,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return MessageCatalogue::constants(self::catalogues());
    }
}
