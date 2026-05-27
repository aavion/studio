<?php

declare(strict_types=1);

namespace App\Tests\Core\Message;

use App\Core\Message\MessageKey;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Yaml\Yaml;

final class MessageKeyTest extends TestCase
{
    public function testItDefinesUniqueTranslationReadyKeys(): void
    {
        $constants = (new ReflectionClass(MessageKey::class))->getConstants();
        $values = array_values($constants);

        self::assertNotEmpty($values);
        self::assertSame($values, array_unique($values));

        foreach ($values as $value) {
            self::assertIsString($value);
            self::assertStringStartsWith('message.', $value);
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $value);
        }
    }

    public function testItKeepsMessageCataloguesSynchronizedWithKnownKeys(): void
    {
        $constants = (new ReflectionClass(MessageKey::class))->getConstants();
        $knownKeys = array_values($constants);
        $root = dirname(__DIR__, 3);

        $englishKeys = array_keys(self::flatten(Yaml::parseFile($root . '/translations/runtime/messages.en.yaml')));
        $germanKeys = array_keys(self::flatten(Yaml::parseFile($root . '/translations/runtime/messages.de.yaml')));

        self::assertSame([], array_values(array_diff($knownKeys, $englishKeys)));
        self::assertSame([], array_values(array_diff($knownKeys, $germanKeys)));
        self::assertSame([], array_values(array_diff($englishKeys, $germanKeys)));
        self::assertSame([], array_values(array_diff($germanKeys, $englishKeys)));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, string>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = '' === $prefix ? (string) $key : $prefix . '.' . (string) $key;

            if (is_array($value)) {
                $flat += self::flatten($value, $path);
                continue;
            }

            $flat[$path] = (string) $value;
        }

        return $flat;
    }
}
