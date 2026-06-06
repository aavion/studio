<?php

declare(strict_types=1);

namespace App\Core\Manifest;

final class ManifestMessageCode
{
    public const MANIFEST_UNREADABLE = 'manifest.unreadable';
    public const MANIFEST_INVALID_LINE = 'manifest.invalid_line';
    public const MANIFEST_INVALID_KEY = 'manifest.invalid_key';
    public const MANIFEST_DUPLICATE_KEY = 'manifest.duplicate_key';
    public const MANIFEST_MISSING_REQUIRED_KEY = 'manifest.missing_required_key';
    public const MANIFEST_UNKNOWN_KEY = 'manifest.unknown_key';
    public const MANIFEST_PARSED = 'manifest.parsed';
    public const MANIFEST_VALIDATED = 'manifest.validated';
}
