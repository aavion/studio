<?php

declare(strict_types=1);

namespace App\Core\Message;

use App\Backend\BackendMessageKey;
use App\Content\ContentMessageKey;
use App\Core\Access\AccessMessageKey;
use App\Core\Asset\AssetMessageKey;
use App\Core\Config\ConfigMessageKey;
use App\Core\Event\EventMessageKey;
use App\Core\Lint\LintMessageKey;
use App\Core\Manifest\ManifestMessageKey;
use App\Core\Messenger\MessengerMessageKey;
use App\Core\Operation\Filesystem\FilesystemMessageKey;
use App\Core\Operation\OperationMessageKey;
use App\Core\Operation\Process\ProcessMessageKey;
use App\Core\Package\PackageMessageKey;
use App\Core\Routing\RoutingMessageKey;
use App\Core\Security\SystemSecurityMessageKey;
use App\Core\State\StateMessageKey;
use App\Core\Statistics\StatisticsMessageKey;
use App\Core\Translation\TranslationMessageKey;
use App\Navigation\NavigationMessageKey;
use App\Scheduler\SchedulerMessageKey;
use App\Security\SecurityMessageKey;
use App\Setup\SetupMessageKey;
use App\View\ViewMessageKey;

final class MessageKey
{
    /**
     * @return list<class-string>
     */
    public static function catalogues(): array
    {
        return [
            AccessMessageKey::class,
            AssetMessageKey::class,
            BackendMessageKey::class,
            ConfigMessageKey::class,
            ContentMessageKey::class,
            EventMessageKey::class,
            FilesystemMessageKey::class,
            LintMessageKey::class,
            ManifestMessageKey::class,
            MessengerMessageKey::class,
            NavigationMessageKey::class,
            OperationMessageKey::class,
            PackageMessageKey::class,
            ProcessMessageKey::class,
            RoutingMessageKey::class,
            SchedulerMessageKey::class,
            SecurityMessageKey::class,
            SetupMessageKey::class,
            StateMessageKey::class,
            StatisticsMessageKey::class,
            SystemSecurityMessageKey::class,
            TranslationMessageKey::class,
            ViewMessageKey::class,
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
