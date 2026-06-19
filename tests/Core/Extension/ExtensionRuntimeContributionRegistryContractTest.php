<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\Content\ExtensionContentSchemaDefinition;
use App\Core\Extension\Database\ExtensionDatabaseColumn;
use App\Core\Extension\Database\ExtensionDatabaseTable;
use App\Core\Extension\ExtensionRuntimeContributionRegistry;
use App\Core\Extension\ExtensionScope;
use App\Core\Extension\ExtensionStatus;
use App\Api\Endpoint\ApiEndpointDefinition;
use App\Entity\Extension;
use PHPUnit\Framework\TestCase;

final class ExtensionRuntimeContributionRegistryContractTest extends TestCase
{
    public function testItAcceptsDatabaseAndContentSchemaContributionsForMatchingScopes(): void
    {
        $extension = $this->extension([ExtensionScope::Module, ExtensionScope::Database, ExtensionScope::ContentSchema]);
        $table = ExtensionDatabaseTable::create('entry', [ExtensionDatabaseColumn::string('uid', 36)], ['uid']);
        $schema = ExtensionContentSchemaDefinition::create('article', ['en' => 'Article'], [
            'fields' => [
                ['identifier' => 'title', 'type' => 'string'],
                ['identifier' => 'subtitle', 'type' => 'string'],
            ],
        ]);
        $registry = new ExtensionRuntimeContributionRegistry();

        $registry->add($extension, [$table, $schema]);

        self::assertSame([$table], $registry->extensionDatabaseTables());
        self::assertSame([$schema], $registry->extensionContentSchemas());
    }

    public function testItRejectsDatabaseContributionsWithoutDatabaseScope(): void
    {
        $this->expectExceptionMessage('message.extension.database.contribution_invalid');

        (new ExtensionRuntimeContributionRegistry())->add(
            $this->extension([ExtensionScope::Module]),
            ExtensionDatabaseTable::create('entry', [ExtensionDatabaseColumn::string('uid', 36)], ['uid']),
        );
    }

    public function testItRejectsContentSchemaContributionsWithoutContentSchemaScope(): void
    {
        $this->expectExceptionMessage('message.extension.content_schema.contribution_invalid');

        (new ExtensionRuntimeContributionRegistry())->add(
            $this->extension([ExtensionScope::Module]),
            ExtensionContentSchemaDefinition::create('article', ['en' => 'Article'], [
                'fields' => [
                    ['identifier' => 'title', 'type' => 'string'],
                    ['identifier' => 'subtitle', 'type' => 'string'],
                ],
            ]),
        );
    }

    public function testItRejectsApiContributionsWithoutApiScope(): void
    {
        $this->expectExceptionMessage('message.api.endpoint.owner_invalid');

        (new ExtensionRuntimeContributionRegistry())->add(
            $this->extension([ExtensionScope::Module]),
            new ApiEndpointDefinition(
                'extension',
                'GET',
                '/api/v1/extensions/demo-module/demo',
                'api_v1_endpoint_dispatch',
                'getDemoModuleExtensionEndpoint',
                'Return extension demo data.',
                'extensions.demo-module.demo',
                ['extensions-demo-module-demo'],
            ),
        );
    }

    /**
     * @param list<ExtensionScope> $scopes
     */
    private function extension(array $scopes): Extension
    {
        return new Extension(
            '10000000-0000-7000-8000-000000000701',
            $scopes,
            'demo-module',
            'extensions/demo-module',
            ExtensionStatus::Active,
        );
    }
}
