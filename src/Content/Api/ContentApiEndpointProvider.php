<?php

declare(strict_types=1);

namespace App\Content\Api;

use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointProviderInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class ContentApiEndpointProvider implements ApiEndpointProviderInterface
{
    public const HANDLER_CONTENT_INDEX = 'content.index';
    public const HANDLER_CONTENT_ITEMS = 'content.items';
    public const HANDLER_CONTENT_MUTATION_STUB = 'content.mutation_stub';
    private const ITEM_PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*(?:/items/[a-z0-9]+(?:-[a-z0-9]+)*)*';

    public function apiEndpoints(): array
    {
        return [
            new ApiEndpointDefinition('content', Request::METHOD_GET, '/api/v1/content', 'api_v1_endpoint_dispatch', 'listContentApiEndpoints', 'List content API children.', self::HANDLER_CONTENT_INDEX, ['content'], allowPublic: true),
            new ApiEndpointDefinition('content', Request::METHOD_GET, '/api/v1/content/items', 'api_v1_endpoint_dispatch', 'listPublishedContentItems', 'List public published content item metadata.', self::HANDLER_CONTENT_ITEMS, ['content'], parameters: $this->collectionParameters(), responseSchema: ['type' => 'object'], allowPublic: true),
            new ApiEndpointDefinition('content', Request::METHOD_GET, '/api/v1/content/items/{item_path}', 'api_v1_endpoint_dispatch', 'getContentItem', 'Return one published content item by canonical API path.', self::HANDLER_CONTENT_ITEMS, ['content'], parameters: $this->itemParameters(), responseSchema: ['type' => 'object'], allowPublic: true, pathPattern: $this->itemPattern(true)),
            new ApiEndpointDefinition('content', Request::METHOD_GET, '/api/v1/content/items/{item_path}/items', 'api_v1_endpoint_dispatch', 'listContentItemChildren', 'List visible direct children for one content item.', self::HANDLER_CONTENT_ITEMS, ['content'], parameters: $this->itemParameters(), responseSchema: ['type' => 'object'], allowPublic: true, pathPattern: $this->collectionPattern('items')),
            new ApiEndpointDefinition('content', Request::METHOD_GET, '/api/v1/content/items/{item_path}/variants', 'api_v1_endpoint_dispatch', 'listContentItemVariants', 'List available variants for one content item.', self::HANDLER_CONTENT_ITEMS, ['content'], parameters: $this->itemParameters(), responseSchema: ['type' => 'object'], allowPublic: true, pathPattern: $this->collectionPattern('variants')),
            new ApiEndpointDefinition('content', Request::METHOD_GET, '/api/v1/content/items/{item_path}/revisions', 'api_v1_endpoint_dispatch', 'listContentItemRevisions', 'List revisions for one content item.', self::HANDLER_CONTENT_ITEMS, ['content'], parameters: $this->itemParameters(), responseSchema: ['type' => 'object'], allowPublic: true, pathPattern: $this->collectionPattern('revisions')),
            new ApiEndpointDefinition('content', Request::METHOD_GET, '/api/v1/content/items/{item_path}/revisions/{revision}', 'api_v1_endpoint_dispatch', 'getContentItemRevision', 'Return one content item revision.', self::HANDLER_CONTENT_ITEMS, ['content'], parameters: $this->revisionParameters(), responseSchema: ['type' => 'object'], allowPublic: true, pathPattern: $this->revisionPattern()),

            // Provisional content command map: these endpoints are advisory placeholders for the upcoming
            // Editor/Content domain model, not a final API contract. Future work must adapt these definitions
            // to the finalized domain commands instead of shaping the content model around them. Remove this
            // note and obsolete placeholders once real command handlers define the stable contract.
            new ApiEndpointDefinition('content', Request::METHOD_POST, '/api/v1/content/items/create', 'api_v1_endpoint_dispatch', 'createContentItem', 'Create a root content item draft and first unpublished revision.', self::HANDLER_CONTENT_MUTATION_STUB, ['content'], requestSchema: $this->mutationSchema(), responseSchema: ['type' => 'object'], successStatus: 201),
            new ApiEndpointDefinition('content', Request::METHOD_POST, '/api/v1/content/items/{item_path}/items/create', 'api_v1_endpoint_dispatch', 'createContentChildItem', 'Create a child content item draft and first unpublished revision.', self::HANDLER_CONTENT_MUTATION_STUB, ['content'], parameters: $this->itemParameters(), requestSchema: $this->mutationSchema(), responseSchema: ['type' => 'object'], successStatus: 201, pathPattern: $this->actionPattern('items/create')),
            new ApiEndpointDefinition('content', Request::METHOD_POST, '/api/v1/content/items/{item_path}/edit', 'api_v1_endpoint_dispatch', 'editContentItem', 'Create a new unpublished content revision from submitted field and entity changes.', self::HANDLER_CONTENT_MUTATION_STUB, ['content'], parameters: $this->itemParameters(), requestSchema: $this->mutationSchema(), responseSchema: ['type' => 'object'], pathPattern: $this->actionPattern('edit')),
            new ApiEndpointDefinition('content', Request::METHOD_POST, '/api/v1/content/items/{item_path}/validate', 'api_v1_endpoint_dispatch', 'validateContentItemMutation', 'Validate a content mutation and return OK, WARN, or FAIL with structured issues.', self::HANDLER_CONTENT_MUTATION_STUB, ['content'], parameters: $this->itemParameters(), requestSchema: $this->mutationSchema(), responseSchema: ['type' => 'object'], pathPattern: $this->actionPattern('validate')),
            new ApiEndpointDefinition('content', Request::METHOD_POST, '/api/v1/content/items/{item_path}/diff', 'api_v1_endpoint_dispatch', 'diffContentItemMutation', 'Validate a content mutation and return a structured diff without persisting changes.', self::HANDLER_CONTENT_MUTATION_STUB, ['content'], parameters: $this->itemParameters(), requestSchema: $this->mutationSchema(), responseSchema: ['type' => 'object'], pathPattern: $this->actionPattern('diff')),
            new ApiEndpointDefinition('content', Request::METHOD_POST, '/api/v1/content/items/{item_path}/delete', 'api_v1_endpoint_dispatch', 'deleteContentItem', 'Mark a content item as deleted and clear the active revision.', self::HANDLER_CONTENT_MUTATION_STUB, ['content'], parameters: $this->itemParameters(), responseSchema: ['type' => 'object'], pathPattern: $this->actionPattern('delete')),
            new ApiEndpointDefinition('content', Request::METHOD_POST, '/api/v1/content/items/{item_path}/publish', 'api_v1_endpoint_dispatch', 'publishContentItem', 'Publish a content item revision.', self::HANDLER_CONTENT_MUTATION_STUB, ['content'], parameters: $this->itemParameters(), requestSchema: $this->versionCommandSchema(), responseSchema: ['type' => 'object'], pathPattern: $this->actionPattern('publish')),
            new ApiEndpointDefinition('content', Request::METHOD_POST, '/api/v1/content/items/{item_path}/unpublish', 'api_v1_endpoint_dispatch', 'unpublishContentItem', 'Unpublish a content item without deleting its revisions.', self::HANDLER_CONTENT_MUTATION_STUB, ['content'], parameters: $this->itemParameters(), responseSchema: ['type' => 'object'], pathPattern: $this->actionPattern('unpublish')),
            new ApiEndpointDefinition('content', Request::METHOD_POST, '/api/v1/content/items/{item_path}/revisions/{revision}/publish', 'api_v1_endpoint_dispatch', 'publishContentItemRevision', 'Publish a specific content item revision.', self::HANDLER_CONTENT_MUTATION_STUB, ['content'], parameters: $this->revisionParameters(), responseSchema: ['type' => 'object'], pathPattern: $this->revisionActionPattern('publish')),
            new ApiEndpointDefinition('content', Request::METHOD_POST, '/api/v1/content/items/{item_path}/revisions/{revision}/unpublish', 'api_v1_endpoint_dispatch', 'unpublishContentItemRevision', 'Unpublish a specific content item revision without deleting it.', self::HANDLER_CONTENT_MUTATION_STUB, ['content'], parameters: $this->revisionParameters(), responseSchema: ['type' => 'object'], pathPattern: $this->revisionActionPattern('unpublish')),
            new ApiEndpointDefinition('content', Request::METHOD_POST, '/api/v1/content/items/{item_path}/variants/{variant}/create', 'api_v1_endpoint_dispatch', 'createContentItemVariant', 'Create a content variant from the active or selected revision.', self::HANDLER_CONTENT_MUTATION_STUB, ['content'], parameters: $this->variantParameters(), requestSchema: $this->mutationSchema(), responseSchema: ['type' => 'object'], pathPattern: $this->variantActionPattern('create')),
            new ApiEndpointDefinition('content', Request::METHOD_POST, '/api/v1/content/items/{item_path}/variants/{variant}/edit', 'api_v1_endpoint_dispatch', 'editContentItemVariant', 'Create a new unpublished revision for a content variant.', self::HANDLER_CONTENT_MUTATION_STUB, ['content'], parameters: $this->variantParameters(), requestSchema: $this->mutationSchema(), responseSchema: ['type' => 'object'], pathPattern: $this->variantActionPattern('edit')),
            new ApiEndpointDefinition('content', Request::METHOD_POST, '/api/v1/content/items/{item_path}/variants/{variant}/delete', 'api_v1_endpoint_dispatch', 'deleteContentItemVariant', 'Delete a content variant from a new unpublished revision.', self::HANDLER_CONTENT_MUTATION_STUB, ['content'], parameters: $this->variantParameters(), responseSchema: ['type' => 'object'], pathPattern: $this->variantActionPattern('delete')),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectionParameters(): array
    {
        return [
            ['name' => 'language', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
            ['name' => 'variant', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
            ['name' => 'status', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['published', 'draft', 'deleted', 'all']]],
            ['name' => 'schema', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
            ['name' => 'parent', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
            ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1]],
            ['name' => 'limit', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
            ['name' => 'sort', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function itemParameters(): array
    {
        return [
            ['name' => 'item_path', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
            ['name' => 'language', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
            ['name' => 'variant', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
            ['name' => 'version', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer']],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function variantParameters(): array
    {
        return [
            ...$this->itemParameters(),
            ['name' => 'variant', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function revisionParameters(): array
    {
        return [
            ...$this->itemParameters(),
            ['name' => 'revision', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mutationSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'schema' => ['type' => 'string'],
                'language' => ['type' => 'string'],
                'variant' => ['type' => 'string'],
                'fields' => ['type' => 'object'],
                'entity' => ['type' => 'object'],
                'base_version' => ['type' => 'integer'],
            ],
            'required' => ['schema'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function versionCommandSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['version' => ['type' => 'integer']],
            'required' => ['version'],
        ];
    }

    private function itemPattern(bool $includeVariant): string
    {
        $variant = $includeVariant ? '(?:/variants/[a-z0-9]+(?:-[a-z0-9]+)*)?' : '';

        return '#^/api/v1/content/items/'.self::ITEM_PATTERN.$variant.'$#';
    }

    private function collectionPattern(string $collection): string
    {
        return '#^/api/v1/content/items/'.self::ITEM_PATTERN.'/'.$collection.'$#';
    }

    private function actionPattern(string $action): string
    {
        return '#^/api/v1/content/items/'.self::ITEM_PATTERN.'/'.$action.'$#';
    }

    private function variantActionPattern(string $action): string
    {
        return '#^/api/v1/content/items/'.self::ITEM_PATTERN.'/variants/[a-z0-9]+(?:-[a-z0-9]+)*/'.$action.'$#';
    }

    private function revisionActionPattern(string $action): string
    {
        return '#^/api/v1/content/items/'.self::ITEM_PATTERN.'/revisions/[1-9][0-9]*/'.$action.'$#';
    }

    private function revisionPattern(): string
    {
        return '#^/api/v1/content/items/'.self::ITEM_PATTERN.'/revisions/[1-9][0-9]*$#';
    }
}
