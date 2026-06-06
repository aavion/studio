<?php

declare(strict_types=1);

namespace App\Core\Message;

use ReflectionClass;

final class MessageCatalogue
{
    /**
     * @param iterable<class-string> $classes
     *
     * @return array<string, string>
     */
    public static function constants(iterable $classes): array
    {
        $constants = [];

        foreach ($classes as $class) {
            foreach ((new ReflectionClass($class))->getConstants() as $name => $value) {
                if (!is_string($value) || array_key_exists($name, $constants)) {
                    continue;
                }

                $constants[$name] = $value;
            }
        }

        return $constants;
    }
}
