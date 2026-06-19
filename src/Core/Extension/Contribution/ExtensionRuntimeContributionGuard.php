<?php

declare(strict_types=1);

namespace App\Core\Extension\Contribution;

use App\Api\ApiMessageKey;
use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Core\Extension\Content\ExtensionContentSchemaDefinition;
use App\Core\Extension\Database\ExtensionDatabaseTable;
use App\Core\Extension\ExtensionApiContributionGuard;
use App\Core\Extension\ExtensionLiveContributionGuard;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Extension\ExtensionScope;
use App\Core\Message\MessageException;
use App\Core\Statistics\VisitorIdGenerator;
use App\Entity\Extension;
use App\Live\LiveEndpointDefinition;
use App\Live\LiveEndpointHandlerInterface;
use App\Privacy\Cookie\CookieConsentDefinition;
use App\Privacy\Cookie\CookieConsentManager;
use App\Scheduler\SchedulerTaskDefinition;
use Symfony\Component\HttpFoundation\Cookie;

final readonly class ExtensionRuntimeContributionGuard
{
    private const RESERVED_COOKIE_NAMES = [
        CookieConsentManager::CONSENT_COOKIE_NAME,
        'PHPSESSID',
        VisitorIdGenerator::COOKIE_NAME,
    ];

    public function assertSchedulerTask(Extension $extension, SchedulerTaskDefinition $definition): void
    {
        if ($definition->source() !== $extension->extensionName()) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_SCHEDULER_SOURCE_INVALID, [
                '%task%' => $definition->identifier(),
                '%extension%' => $extension->extensionName(),
                '%source%' => $definition->source(),
            ]);
        }

        if ($definition->trusted()) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_SCHEDULER_TRUSTED_BLOCKED, [
                '%task%' => $definition->identifier(),
                '%extension%' => $extension->extensionName(),
            ]);
        }
    }

    public function assertApiEndpoint(Extension $extension, ApiEndpointDefinition $definition): void
    {
        $this->assertApiScope($extension);
        ExtensionApiContributionGuard::assertEndpoint($extension, $definition);
    }

    public function assertApiEndpointHandler(Extension $extension, ApiEndpointHandlerInterface $handler): void
    {
        $this->assertApiScope($extension);
        ExtensionApiContributionGuard::assertHandler($extension, $handler);
    }

    public function assertLiveEndpoint(Extension $extension, LiveEndpointDefinition $definition): void
    {
        ExtensionLiveContributionGuard::assertEndpoint($extension, $definition);
    }

    public function assertLiveEndpointHandler(Extension $extension, LiveEndpointHandlerInterface $handler): void
    {
        ExtensionLiveContributionGuard::assertHandler($extension, $handler);
    }

    /**
     * @param list<string> $existingNames
     */
    public function assertCookieConsentDefinition(Extension $extension, CookieConsentDefinition $definition, array $existingNames): void
    {
        if (in_array($definition->name(), [...self::RESERVED_COOKIE_NAMES, ...$existingNames], true)) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_RUNTIME_CONTRIBUTION_UNSUPPORTED, [
                '%extension%' => $extension->extensionName(),
                '%type%' => CookieConsentDefinition::class.'('.$definition->name().') duplicate',
            ]);
        }

        if ($definition->isNecessary() && !$this->necessaryExtensionCookieAllowed($extension, $definition->cookie())) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_RUNTIME_CONTRIBUTION_UNSUPPORTED, [
                '%extension%' => $extension->extensionName(),
                '%type%' => CookieConsentDefinition::class.'::necessary('.$definition->name().')',
            ]);
        }
    }

    public function assertDatabaseTable(Extension $extension, ExtensionDatabaseTable $table): void
    {
        if (!$extension->hasScope(ExtensionScope::Database)) {
            throw MessageException::forMessage(ExtensionMessageCode::EXTENSION_DATABASE_CONTRIBUTION_INVALID, ExtensionMessageKey::EXTENSION_DATABASE_CONTRIBUTION_INVALID, [
                '%reason%' => 'scope_missing',
            ], ['extension' => $extension->extensionName(), 'required_scope' => ExtensionScope::Database->value]);
        }
    }

    public function assertContentSchema(Extension $extension, ExtensionContentSchemaDefinition $definition): void
    {
        if (!$extension->hasScope(ExtensionScope::ContentSchema)) {
            throw MessageException::forMessage(ExtensionMessageCode::EXTENSION_CONTENT_SCHEMA_CONTRIBUTION_INVALID, ExtensionMessageKey::EXTENSION_CONTENT_SCHEMA_CONTRIBUTION_INVALID, [
                '%reason%' => 'scope_missing',
            ], ['extension' => $extension->extensionName(), 'required_scope' => ExtensionScope::ContentSchema->value]);
        }
    }

    private function assertApiScope(Extension $extension): void
    {
        if ($extension->hasScope(ExtensionScope::Api)) {
            return;
        }

        throw MessageException::invalidArgument(ApiMessageKey::API_ENDPOINT_OWNER_INVALID, [
            '%owner%' => $extension->extensionName(),
        ], ['extension' => $extension->extensionName(), 'required_scope' => ExtensionScope::Api->value]);
    }

    private function necessaryExtensionCookieAllowed(Extension $extension, Cookie $cookie): bool
    {
        $prefixes = $this->cookieNamePrefixes($extension);
        $sameSite = $cookie->getSameSite();

        return $this->cookieNameHasExtensionPrefix($cookie->getName(), $prefixes)
            && (null === $cookie->getDomain() || '' === trim($cookie->getDomain()))
            && in_array($sameSite, [Cookie::SAMESITE_LAX, Cookie::SAMESITE_STRICT], true);
    }

    /**
     * @return list<string>
     */
    private function cookieNamePrefixes(Extension $extension): array
    {
        $slug = strtolower($extension->extensionName());

        return array_values(array_unique([
            $slug.'_',
            str_replace('-', '_', $slug).'_',
        ]));
    }

    /**
     * @param list<string> $prefixes
     */
    private function cookieNameHasExtensionPrefix(string $name, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
