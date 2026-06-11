<?php

declare(strict_types=1);

namespace App\Api\Endpoint;

final class ApiListQueryParameterDefinition
{
    /**
     * @return array<string, mixed>
     */
    public static function search(string $name = 'q'): array
    {
        return ['name' => $name, 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']];
    }

    /**
     * @return array<string, mixed>
     */
    public static function page(): array
    {
        return ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1]];
    }

    /**
     * @param list<int> $allowedValues
     *
     * @return array<string, mixed>
     */
    public static function limit(array $allowedValues = [25, 50, 100], bool $allowAll = true): array
    {
        $schema = ['oneOf' => [
            ['type' => 'integer', 'enum' => $allowedValues, 'minimum' => 1],
        ]];

        if ($allowAll) {
            $schema['oneOf'][] = ['type' => 'string', 'enum' => ['all']];
        }

        return ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => $schema];
    }

    /**
     * @return array<string, mixed>
     */
    public static function limitRange(int $maximum = 100, bool $allowAll = false): array
    {
        $schema = ['type' => 'integer', 'minimum' => 1, 'maximum' => $maximum];

        if ($allowAll) {
            $schema = [
                'oneOf' => [
                    $schema,
                    ['type' => 'string', 'enum' => ['all']],
                ],
            ];
        }

        return ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => $schema];
    }

    /**
     * @param list<string> $values
     *
     * @return array<string, mixed>
     */
    public static function choice(string $name, array $values): array
    {
        return ['name' => $name, 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => $values]];
    }

    /**
     * @return array<string, mixed>
     */
    public static function sort(): array
    {
        return ['name' => 'sort', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']];
    }

    /**
     * @return array<string, mixed>
     */
    public static function direction(): array
    {
        return self::choice('direction', ['asc', 'desc']);
    }

    private function __construct()
    {
    }
}
