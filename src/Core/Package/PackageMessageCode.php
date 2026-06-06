<?php

declare(strict_types=1);

namespace App\Core\Package;

final class PackageMessageCode
{
    public const PACKAGE_MANIFEST_UNREADABLE = 'package.manifest_unreadable';
    public const PACKAGE_REQUIRED_FILE_MISSING = 'package.required_file_missing';
    public const PACKAGE_REQUIRED_DIRECTORY_MISSING = 'package.required_directory_missing';
    public const PACKAGE_FILE_UNREADABLE = 'package.file_unreadable';
    public const PACKAGE_PHP_SYNTAX_ERROR = 'package.php_syntax_error';
    public const PACKAGE_PHP_NAMESPACE_INVALID = 'package.php_namespace_invalid';
    public const PACKAGE_TWIG_SYNTAX_ERROR = 'package.twig_syntax_error';
    public const PACKAGE_TRANSLATION_FALLBACK_MISSING = 'package.translation_fallback_missing';
    public const PACKAGE_TRANSLATION_NAMESPACE_INVALID = 'package.translation_namespace_invalid';
    public const PACKAGE_JSON_SYNTAX_ERROR = 'package.json_syntax_error';
    public const PACKAGE_YAML_SYNTAX_ERROR = 'package.yaml_syntax_error';
    public const PACKAGE_CSS_SYNTAX_ERROR = 'package.css_syntax_error';
    public const PACKAGE_JAVASCRIPT_SYNTAX_ERROR = 'package.javascript_syntax_error';
    public const PACKAGE_SCOPE_INVALID = 'package.scope_invalid';
    public const PACKAGE_TEMPLATE_PATH_INVALID = 'package.template_path_invalid';
    public const PACKAGE_POLICY_BLOCKED_PATH = 'package.policy.blocked_path';
    public const PACKAGE_POLICY_WARNED_PATH = 'package.policy.warned_path';
    public const PACKAGE_POLICY_BLOCKED_PHP_CAPABILITY = 'package.policy.blocked_php_capability';
    public const PACKAGE_COPY_SOURCE_MISSING = 'package.copy_source_missing';
    public const PACKAGE_COPY_SOURCE_SYMLINK = 'package.copy_source_symlink';
    public const PACKAGE_ASSET_SYNC_COMPLETED = 'package.asset_sync_completed';
    public const PACKAGE_ASSET_SYNC_FAILED = 'package.asset_sync_failed';
    public const PACKAGE_ASSET_REBUILD_QUEUED = 'package.asset_rebuild_queued';
    public const PACKAGE_ASSET_REBUILD_QUEUE_FAILED = 'package.asset_rebuild_queue_failed';
    public const PACKAGE_DISCOVERY_QUEUED = 'package.discovery_queued';
    public const PACKAGE_DISCOVERY_QUEUE_FAILED = 'package.discovery_queue_failed';
    public const PACKAGE_DISCOVERY_COMPLETED = 'package.discovery_completed';
    public const PACKAGE_VALIDATION_COMPLETED = 'package.validation_completed';
    public const PACKAGE_COPY_PLAN_CREATED = 'package.copy_plan_created';
    public const PACKAGE_REGISTRY_SYNC_COMPLETED = 'package.registry.sync_completed';
    public const PACKAGE_REGISTRY_PACKAGE_REGISTERED = 'package.registry.registered';
    public const PACKAGE_REGISTRY_PACKAGE_UPDATED = 'package.registry.updated';
    public const PACKAGE_REGISTRY_PACKAGE_REMOVED = 'package.registry.removed';
    public const PACKAGE_REGISTRY_PACKAGE_FAULTY = 'package.registry.faulty';
    public const PACKAGE_INSTALL_UPLOAD_INVALID = 'package.install.upload_invalid';
    public const PACKAGE_INSTALL_ZIP_INVALID = 'package.install.zip_invalid';
    public const PACKAGE_INSTALL_ROOT_INVALID = 'package.install.root_invalid';
    public const PACKAGE_INSTALL_READY = 'package.install.ready';
    public const PACKAGE_INSTALL_OVERWRITE = 'package.install.overwrite';
    public const PACKAGE_INSTALL_VERSION_BLOCKED = 'package.install.version_blocked';
    public const PACKAGE_INSTALL_COMPLETED = 'package.install.completed';
    public const PACKAGE_LIFECYCLE_PACKAGE_NOT_FOUND = 'package.lifecycle.not_found';
    public const PACKAGE_LIFECYCLE_STATUS_BLOCKED = 'package.lifecycle.status_blocked';
    public const PACKAGE_LIFECYCLE_ACTIVATED = 'package.lifecycle.activated';
    public const PACKAGE_LIFECYCLE_DEACTIVATED = 'package.lifecycle.deactivated';
    public const PACKAGE_LIFECYCLE_DEPENDENT_DEACTIVATED = 'package.lifecycle.dependent_deactivated';
    public const PACKAGE_LIFECYCLE_CLEANUP_COMPLETED = 'package.lifecycle.cleanup_completed';
    public const PACKAGE_LIFECYCLE_REMOVED = 'package.lifecycle.removed';
    public const PACKAGE_LIFECYCLE_PURGED = 'package.lifecycle.purged';
    public const PACKAGE_LIFECYCLE_FAULT_RESET = 'package.lifecycle.fault_reset';
    public const PACKAGE_LIFECYCLE_RUNTIME_FAILURE = 'package.lifecycle.runtime_failure';
    public const PACKAGE_LIFECYCLE_PHP_LOAD_FAILED = 'package.lifecycle.php_load_failed';
    public const PACKAGE_LIFECYCLE_ROLLED_BACK = 'package.lifecycle.rolled_back';
    public const PACKAGE_SETTING_READ_FAILED = 'package.setting.read_failed';
    public const PACKAGE_SETTING_WRITE_FAILED = 'package.setting.write_failed';
    public const PACKAGE_SETTING_DELETE_FAILED = 'package.setting.delete_failed';
    public const PACKAGE_SETTING_VALUE_INVALID = 'package.setting.value_invalid';
    public const PACKAGE_DEPENDENCY_MISSING = 'package.dependency.missing';
    public const PACKAGE_DEPENDENCY_INVALID = 'package.dependency.invalid';
    public const PACKAGE_SCHEDULER_CRON_INVALID = 'package.scheduler.cron_invalid';
    public const PACKAGE_DEPENDENCY_VERSION_UNSATISFIED = 'package.dependency.version_unsatisfied';
    public const PACKAGE_DEPENDENCY_STATUS_BLOCKED = 'package.dependency.status_blocked';
    public const PACKAGE_DEPENDENCY_CYCLE = 'package.dependency.cycle';
    public const PACKAGE_DEPENDENCY_RESOLVED = 'package.dependency.resolved';
    public const PACKAGE_IDENTIFIER_INVALID = 'package.identifier.invalid';
}
