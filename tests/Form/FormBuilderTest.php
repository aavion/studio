<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Core\Config\ConfigValueType;
use App\Form\FormBuilder;
use App\Form\FormFieldDefinition;
use App\Form\FormInputType;
use PHPUnit\Framework\TestCase;

final class FormBuilderTest extends TestCase
{
    public function testItBuildsSortedFieldPayloadsWithValuesAndValidationAttributes(): void
    {
        $form = (new FormBuilder())->build(
            'settings',
            'Settings',
            [
                new FormFieldDefinition(
                    'site.url',
                    'Site URL',
                    'http://localhost',
                    validation: ['required' => true, 'max_length' => 255],
                    sortOrder: 20,
                ),
                new FormFieldDefinition(
                    'enabled',
                    'Enabled',
                    false,
                    ConfigValueType::Boolean,
                    sortOrder: 10,
                ),
                new FormFieldDefinition(
                    'widgets',
                    'Widgets',
                    ['system_status'],
                    ConfigValueType::Json,
                    FormInputType::MultiSelect,
                    options: ['system_status' => 'System status'],
                    sortOrder: 30,
                ),
            ],
            ['site.url' => 'https://example.test', 'enabled' => true],
        )->toArray();

        self::assertSame('settings', $form['id']);
        self::assertSame(['enabled', 'site.url', 'widgets'], array_column($form['fields'], 'name'));
        self::assertSame('checkbox', $form['fields'][0]['input_type']);
        self::assertTrue($form['fields'][0]['value']);
        self::assertSame('https://example.test', $form['fields'][1]['value']);
        self::assertTrue($form['fields'][1]['required']);
        self::assertSame(['maxlength' => 255], $form['fields'][1]['attributes']);
        self::assertSame('multiselect', $form['fields'][2]['input_type']);
        self::assertSame(['system_status' => 'System status'], $form['fields'][2]['options']);
    }

    public function testItInfersFieldTypesFromValueTypesAndOptions(): void
    {
        self::assertSame(FormInputType::Checkbox, FormInputType::infer(ConfigValueType::Boolean));
        self::assertSame(FormInputType::Number, FormInputType::infer(ConfigValueType::Integer));
        self::assertSame(FormInputType::Number, FormInputType::infer(ConfigValueType::Float));
        self::assertSame(FormInputType::Textarea, FormInputType::infer(ConfigValueType::Json));
        self::assertSame(FormInputType::Select, FormInputType::infer(ConfigValueType::String, ['daily' => 'Daily']));
        self::assertSame(FormInputType::MultiSelect, FormInputType::infer(ConfigValueType::Json, ['status' => 'Status']));
    }
}
