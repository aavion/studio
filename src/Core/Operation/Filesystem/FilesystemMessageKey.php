<?php

declare(strict_types=1);

namespace App\Core\Operation\Filesystem;

final class FilesystemMessageKey
{
    public const FILESYSTEM_SOURCE_MISSING = 'message.filesystem.source_missing';
    public const FILESYSTEM_SOURCE_SYMLINK = 'message.filesystem.source_symlink';
    public const FILESYSTEM_TARGET_SYMLINK = 'message.filesystem.target_symlink';
    public const FILESYSTEM_PARENT_SYMLINK = 'message.filesystem.parent_symlink';
    public const FILESYSTEM_FILE_EXISTS = 'message.filesystem.file_exists';
    public const FILESYSTEM_FILE_CONFLICT = 'message.filesystem.file_conflict';
    public const FILESYSTEM_DIRECTORY_CONFLICT = 'message.filesystem.directory_conflict';
    public const FILESYSTEM_PARENT_MISSING = 'message.filesystem.parent_missing';
    public const FILESYSTEM_PARENT_CREATE_FAILED = 'message.filesystem.parent_create_failed';
    public const FILESYSTEM_FILE_WRITE_FAILED = 'message.filesystem.file_write_failed';
    public const FILESYSTEM_FILE_COPY_FAILED = 'message.filesystem.file_copy_failed';
    public const FILESYSTEM_DIRECTORY_CREATE_FAILED = 'message.filesystem.directory_create_failed';
    public const FILESYSTEM_FILE_WRITTEN = 'message.filesystem.file_written';
    public const FILESYSTEM_FILE_COPIED = 'message.filesystem.file_copied';
    public const FILESYSTEM_PATH_REMOVED = 'message.filesystem.path_removed';
    public const FILESYSTEM_DIRECTORY_READY = 'message.filesystem.directory_ready';
    public const FILESYSTEM_PARENT_DIRECTORY_READY = 'message.filesystem.parent_directory_ready';
}
