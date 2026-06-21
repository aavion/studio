<?php

declare(strict_types=1);

namespace App\Api\Documentation;

use App\Api\Endpoint\ApiEndpointRegistry;

final readonly class OpenApiTagFactory
{
    public function __construct(private ApiEndpointRegistry $endpoints)
    {
    }

    /**
     * @return list<array<string, string>>
     */
    public function tags(): array
    {
        $used = [];
        foreach ($this->endpoints->endpoints() as $endpoint) {
            foreach ($endpoint->tags() as $tag) {
                $used[$tag] = true;
            }
        }

        $tags = [];
        foreach ($this->tagMetadata() as $name => $metadata) {
            if (!isset($used[$name])) {
                continue;
            }

            $tags[] = ['name' => $name, ...$metadata];
            unset($used[$name]);
        }

        foreach (array_keys($used) as $name) {
            $tags[] = [
                'name' => $name,
                'summary' => ucfirst(str_replace(['-', '_'], ' ', $name)),
                'kind' => 'nav',
            ];
        }

        return $tags;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function tagMetadata(): array
    {
        return [
            'backend-admin' => ['summary' => 'Backend Admin', 'description' => 'Backend administration resources.', 'kind' => 'nav'],
            'backend-admin-backups' => ['summary' => 'Backend Admin Backups', 'description' => 'Administrative backup capabilities and future backup operations.', 'parent' => 'backend-admin', 'kind' => 'nav'],
            'backend-admin-logs' => ['summary' => 'Backend Admin Logs', 'description' => 'Administrative log source and log entry resources.', 'parent' => 'backend-admin', 'kind' => 'nav'],
            'backend-admin-operations' => ['summary' => 'Backend Admin Operations', 'description' => 'Administrative live-operation status, continuation, and maintenance resources.', 'parent' => 'backend-admin', 'kind' => 'nav'],
            'backend-admin-extensions' => ['summary' => 'Backend Admin Extensions', 'description' => 'Administrative extension management and lifecycle resources.', 'parent' => 'backend-admin', 'kind' => 'nav'],
            'backend-admin-permissions' => ['summary' => 'Backend Admin Permissions', 'description' => 'Endpoint access and API key capability matrix resources.', 'parent' => 'backend-admin', 'kind' => 'nav'],
            'backend-admin-scheduler' => ['summary' => 'Backend Admin Scheduler', 'description' => 'Administrative scheduler task and run resources.', 'parent' => 'backend-admin', 'kind' => 'nav'],
            'backend-admin-security' => ['summary' => 'Backend Admin Security', 'description' => 'Administrative security configuration, signals, and auto-ban resources.', 'parent' => 'backend-admin', 'kind' => 'nav'],
            'backend-admin-settings' => ['summary' => 'Backend Admin Settings', 'description' => 'Administrative settings sections and values.', 'parent' => 'backend-admin', 'kind' => 'nav'],
            'backend-admin-statistics' => ['summary' => 'Backend Admin Statistics', 'description' => 'Administrative access statistics resources.', 'parent' => 'backend-admin', 'kind' => 'nav'],
            'backend-admin-themes' => ['summary' => 'Backend Admin Themes', 'description' => 'Administrative frontend and backend theme resources.', 'parent' => 'backend-admin', 'kind' => 'nav'],
            'backend-admin-users' => ['summary' => 'Backend Admin Users', 'description' => 'Administrative user, ACL group, and review resources.', 'parent' => 'backend-admin', 'kind' => 'nav'],
            'backend-editor' => ['summary' => 'Backend Editor', 'description' => 'Backend editor resources for schema and structured content authoring.', 'kind' => 'nav'],
            'backend-editor-schemas' => ['summary' => 'Backend Editor Schemas', 'description' => 'Content schema metadata available to API authors.', 'parent' => 'backend-editor', 'kind' => 'nav'],
            'frontend-content' => ['summary' => 'Frontend Content', 'description' => 'Content item navigation, reads, and prepared mutation commands.', 'kind' => 'nav'],
            'frontend-content-items' => ['summary' => 'Frontend Content Items', 'description' => 'Content item resources and child, variant, revision, and mutation navigation.', 'parent' => 'frontend-content', 'kind' => 'nav'],
            'frontend-user' => ['summary' => 'Frontend User', 'description' => 'Authenticated user self-service resources.', 'kind' => 'nav'],
            'frontend-user-api-keys' => ['summary' => 'Frontend User API Keys', 'description' => 'Self-service API key list, creation, and revocation resources.', 'parent' => 'frontend-user', 'kind' => 'nav'],
            'frontend-user-profile' => ['summary' => 'Frontend User Profile', 'description' => 'Authenticated user profile resources.', 'parent' => 'frontend-user', 'kind' => 'nav'],
            'extensions-navigation' => ['summary' => 'Extension Navigation', 'description' => 'Extension API namespaces and registered extension endpoint navigation. Extension contribution tags should use extensions-{extension_slug}-*.', 'kind' => 'nav'],
            'system-api' => ['summary' => 'System API', 'description' => 'API documentation and API metadata resources.', 'kind' => 'nav'],
            'system-status' => ['summary' => 'System Status', 'description' => 'Status and healthcheck resources.', 'kind' => 'nav'],
        ];
    }
}
