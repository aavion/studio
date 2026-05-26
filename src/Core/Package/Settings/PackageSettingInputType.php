<?php

declare(strict_types=1);

namespace App\Core\Package\Settings;

use App\Core\Config\ConfigValueType;

enum PackageSettingInputType: string
{
    case Text = 'text';
    case Textarea = 'textarea';
    case Select = 'select';
    case MultiSelect = 'multiselect';
    case Checkbox = 'checkbox';
    case Number = 'number';
    case Color = 'color';

    /**
     * @param list<string|int|float|bool> $options
     */
    public static function infer(ConfigValueType $valueType, array $options = []): self
    {
        if ([] !== $options) {
            return ConfigValueType::Json === $valueType ? self::MultiSelect : self::Select;
        }

        return match ($valueType) {
            ConfigValueType::Boolean => self::Checkbox,
            ConfigValueType::Integer, ConfigValueType::Float => self::Number,
            ConfigValueType::Json => self::Textarea,
            ConfigValueType::String => self::Text,
        };
    }
}
