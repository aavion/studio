<?php

declare(strict_types=1);

namespace App\Core\Manifest;

final class ManifestMessageKey
{
    public const MANIFEST_UNREADABLE = 'message.manifest.unreadable';
    public const MANIFEST_INVALID_LINE = 'message.manifest.invalid_line';
    public const MANIFEST_INVALID_KEY = 'message.manifest.invalid_key';
    public const MANIFEST_DUPLICATE_KEY = 'message.manifest.duplicate_key';
    public const MANIFEST_MISSING_REQUIRED_KEY = 'message.manifest.missing_required_key';
    public const MANIFEST_UNKNOWN_KEY = 'message.manifest.unknown_key';
    public const MANIFEST_PARSED = 'message.manifest.parsed';
    public const MANIFEST_VALIDATED = 'message.manifest.validated';
}
