<?php

declare(strict_types=1);

namespace App\Content;

final class ContentMessageKey
{
    public const CONTENT_SLUG_INVALID = 'message.content.slug.invalid_format';
    public const CONTENT_SLUG_RESERVED = 'message.content.slug.reserved';
    public const CONTENT_PATH_EMPTY_OR_PADDED = 'message.content.path.empty_or_padded';
    public const CONTENT_PATH_UNCLEAN = 'message.content.path.unclean';
    public const CONTENT_PATH_EMPTY_SEGMENT = 'message.content.path.empty_segment';
    public const CONTENT_PATH_RESERVED_PREFIX = 'message.content.path.reserved_prefix';
    public const CONTENT_PATH_TRAVERSAL = 'message.content.path.traversal';
    public const CONTENT_PATH_VARIANT_INVALID = 'message.content.path.variant_invalid';
    public const CONTENT_LANGUAGE_FALLBACK = 'message.content.language.fallback';
    public const CONTENT_VARIANT_FALLBACK = 'message.content.variant.fallback';
    public const CONTENT_UID_INVALID = 'message.content.uid.invalid_format';
    public const CONTENT_STRING_LIST_EMPTY = 'message.content.string_list.empty';
    public const CONTENT_STRING_LIST_INVALID = 'message.content.string_list.invalid';
    public const CONTENT_METADATA_KEY_EMPTY = 'message.content.metadata.key_empty';
    public const CONTENT_METADATA_RESERVED_SCHEMA_FIELD = 'message.content.metadata.reserved_schema_field';
    public const CONTENT_FIELD_VALUE_VERSION_INVALID = 'message.content.field_value.version_invalid';
    public const CONTENT_LOCALE_TOKEN_INVALID = 'message.content.locale_token.invalid_format';
    public const CONTENT_FIELD_IDENTIFIER_INVALID = 'message.content.field_identifier.invalid_format';
    public const CONTENT_SCHEMA_IDENTIFIER_INVALID = 'message.content.schema.identifier_invalid';
    public const CONTENT_SCHEMA_VERSION_INVALID = 'message.content.schema.version_invalid';
    public const CONTENT_SCHEMA_REQUIRED_FIELD_MISSING = 'message.content.schema.required_field_missing';
    public const CONTENT_SCHEMA_FIELD_DUPLICATE = 'message.content.schema.field_duplicate';
}
