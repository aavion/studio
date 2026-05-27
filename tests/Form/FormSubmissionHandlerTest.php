<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Core\Config\ConfigValueType;
use App\Form\FormFieldDefinition;
use App\Form\FormInputType;
use App\Form\FormSubmissionHandler;
use PHPUnit\Framework\TestCase;

final class FormSubmissionHandlerTest extends TestCase
{
    public function testItCastsSubmittedValuesAndValidatesOptions(): void
    {
        $result = (new FormSubmissionHandler())->submit([
            new FormFieldDefinition('title', 'Title', '', validation: ['required' => true, 'max_length' => 20]),
            new FormFieldDefinition('enabled', 'Enabled', false, ConfigValueType::Boolean),
            new FormFieldDefinition('sort_order', 'Sort order', 900, ConfigValueType::Integer, FormInputType::Number, validation: ['min' => 0, 'max' => 999]),
            new FormFieldDefinition('widgets', 'Widgets', [], ConfigValueType::Json, FormInputType::MultiSelect, options: ['system_status' => 'System status', 'packages' => 'Packages']),
        ], [
            'title' => 'Example',
            'enabled' => '1',
            'sort_order' => '42',
            'widgets' => ['system_status', 'packages'],
        ]);

        self::assertTrue($result->isValid());
        self::assertSame('Example', $result->value('title'));
        self::assertTrue($result->value('enabled'));
        self::assertSame(42, $result->value('sort_order'));
        self::assertSame(['system_status', 'packages'], $result->value('widgets'));
    }

    public function testItCollectsValidationErrorsWithoutThrowing(): void
    {
        $result = (new FormSubmissionHandler())->submit([
            new FormFieldDefinition('title', 'Title', '', validation: ['required' => true]),
            new FormFieldDefinition('home', 'Home path', '/home', validation: ['pattern' => '^/.*$']),
            new FormFieldDefinition('mode', 'Mode', 'daily', ConfigValueType::String, FormInputType::Select, options: ['daily' => 'Daily']),
            new FormFieldDefinition('sort_order', 'Sort order', 900, ConfigValueType::Integer),
        ], [
            'title' => '',
            'home' => 'home',
            'mode' => 'weekly',
            'sort_order' => 'nope',
        ]);

        self::assertFalse($result->isValid());
        self::assertSame(['admin.settings.form.errors.required'], $result->errors()['title']);
        self::assertSame(['admin.settings.form.errors.pattern'], $result->errors()['home']);
        self::assertSame(['admin.settings.form.errors.choice'], $result->errors()['mode']);
        self::assertSame(['admin.settings.form.errors.integer'], $result->errors()['sort_order']);
    }
}
