<?php

declare(strict_types=1);

namespace App\Core\Operation\Filesystem;

final class FilesystemMessageCode
{
    public const FILESYSTEM_SOURCE_MISSING = 'filesystem.source_missing';
    public const FILESYSTEM_SOURCE_SYMLINK = 'filesystem.source_symlink';
    public const FILESYSTEM_TARGET_SYMLINK = 'filesystem.target_symlink';
    public const FILESYSTEM_PARENT_SYMLINK = 'filesystem.parent_symlink';
    public const FILESYSTEM_FILE_EXISTS = 'filesystem.file_exists';
    public const FILESYSTEM_FILE_CONFLICT = 'filesystem.file_conflict';
    public const FILESYSTEM_DIRECTORY_CONFLICT = 'filesystem.directory_conflict';
    public const FILESYSTEM_PARENT_MISSING = 'filesystem.parent_missing';
    public const FILESYSTEM_PARENT_CREATE_FAILED = 'filesystem.parent_create_failed';
    public const FILESYSTEM_FILE_WRITE_FAILED = 'filesystem.file_write_failed';
    public const FILESYSTEM_FILE_COPY_FAILED = 'filesystem.file_copy_failed';
    public const FILESYSTEM_DIRECTORY_CREATE_FAILED = 'filesystem.directory_create_failed';
    public const FILESYSTEM_FILE_WRITTEN = 'filesystem.file_written';
    public const FILESYSTEM_FILE_COPIED = 'filesystem.file_copied';
    public const FILESYSTEM_PATH_REMOVED = 'filesystem.path_removed';
    public const FILESYSTEM_DIRECTORY_READY = 'filesystem.directory_ready';
    public const FILESYSTEM_PARENT_DIRECTORY_READY = 'filesystem.parent_directory_ready';
}
