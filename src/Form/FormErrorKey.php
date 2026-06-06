<?php

declare(strict_types=1);

namespace App\Form;

final readonly class FormErrorKey
{
    public const CHOICE = 'admin.settings.form.errors.choice';
    public const DEFAULT_ACL_GROUP_UNAVAILABLE = 'admin.settings.form.errors.default_acl_group_unavailable';
    public const EMAIL_INVALID = 'admin.settings.form.errors.email_invalid';
    public const INTEGER = 'admin.settings.form.errors.integer';
    public const INVALID = 'admin.settings.form.errors.invalid';
    public const INVALID_CSRF = 'admin.settings.form.errors.invalid_csrf';
    public const JSON = 'admin.settings.form.errors.json';
    public const MAX = 'admin.settings.form.errors.max';
    public const MAX_LENGTH = 'admin.settings.form.errors.max_length';
    public const MIN = 'admin.settings.form.errors.min';
    public const MIN_LENGTH = 'admin.settings.form.errors.min_length';
    public const NUMBER = 'admin.settings.form.errors.number';
    public const PATTERN = 'admin.settings.form.errors.pattern';
    public const REQUIRED = 'admin.settings.form.errors.required';
    public const SAVE_FAILED = 'admin.settings.form.errors.save_failed';
}
