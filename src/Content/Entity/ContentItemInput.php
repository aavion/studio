<?php

declare(strict_types=1);

namespace App\Content\Entity;

use App\Content\ContentMessageKey;
use App\Content\Routing\ContentSystemRoute;
use App\Content\Schema\ContentSchemaField;
use App\Core\Message\MessageException;
use App\Core\Validation\Uid;

final readonly class ContentItemInput
{
    public static function uid(string $uid, string $label): string
    {
        return Uid::assert($uid, $label);
    }

    public static function parentUid(string $parentUid): string
    {
        if (ContentSystemRoute::ROOT_PARENT_UID === $parentUid) {
            return $parentUid;
        }

        if (ContentSystemRoute::VIRTUAL_PARENT_UID === $parentUid) {
            return $parentUid;
        }

        return self::uid($parentUid, 'Parent content UID');
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    public static function metadata(array $metadata): array
    {
        foreach (array_keys($metadata) as $key) {
            if (!is_string($key) || '' === trim($key)) {
                throw MessageException::invalidArgument(ContentMessageKey::CONTENT_METADATA_KEY_EMPTY);
            }

            self::metadataKey($key);
        }

        return $metadata;
    }

    public static function metadataKey(string $key): void
    {
        if (ContentSchemaField::isRequiredBaseIdentifier($key)) {
            throw MessageException::invalidArgument(ContentMessageKey::CONTENT_METADATA_RESERVED_SCHEMA_FIELD, [
                '%field_identifier%' => $key,
            ]);
        }
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    public static function nonEmptyStringList(array $values, string $label): array
    {
        if ([] === $values) {
            throw MessageException::invalidArgument(ContentMessageKey::CONTENT_STRING_LIST_EMPTY, [
                '%label%' => $label,
            ]);
        }

        return self::stringList($values, $label);
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    public static function stringList(array $values, string $label): array
    {
        foreach ($values as $value) {
            if (!is_string($value) || '' === trim($value)) {
                throw MessageException::invalidArgument(ContentMessageKey::CONTENT_STRING_LIST_INVALID, [
                    '%label%' => $label,
                ]);
            }
        }

        return array_values(array_unique($values));
    }

}
