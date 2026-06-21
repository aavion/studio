<?php

declare(strict_types=1);

namespace App\Core\Extension\Contribution;

use App\Api\ApiMessageKey;
use App\Api\Endpoint\ApiEndpointDefinition;
use App\Api\Endpoint\ApiEndpointHandlerInterface;
use App\Core\Event\PublicEventHookRegistry;
use App\Core\Extension\Content\ExtensionContentSchemaDefinition;
use App\Core\Extension\Database\ExtensionDatabaseTable;
use App\Core\Extension\ExtensionApiContributionGuard;
use App\Core\Extension\ExtensionEventListenerContribution;
use App\Core\Extension\ExtensionLiveContributionGuard;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Extension\ExtensionOperationDefinition;
use App\Core\Extension\ExtensionProviderContribution;
use App\Core\Extension\ExtensionScope;
use App\Core\Extension\ExtensionTranslationKey;
use App\Core\Extension\Settings\ExtensionSettingDefinition;
use App\Core\Message\MessageException;
use App\Core\Statistics\VisitorIdGenerator;
use App\Entity\Extension;
use App\Live\LiveEndpointDefinition;
use App\Live\LiveEndpointHandlerInterface;
use App\Privacy\Cookie\CookieConsentDefinition;
use App\Privacy\Cookie\CookieConsentManager;
use App\Scheduler\SchedulerTaskDefinition;
use App\Scheduler\SchedulerTaskType;
use App\View\Injection\ConfigurableStaticViewInjectionSet;
use App\View\Injection\DynamicViewInjection;
use App\View\Injection\StaticViewInjection;
use App\View\Injection\ViewSurface;
use Symfony\Component\HttpFoundation\Cookie;

final readonly class ExtensionRuntimeContributionGuard
{
    private const RESERVED_COOKIE_NAMES = [
        CookieConsentManager::CONSENT_COOKIE_NAME,
        'PHPSESSID',
        VisitorIdGenerator::COOKIE_NAME,
    ];

    public function __construct(private ?PublicEventHookRegistry $eventHookRegistry = null)
    {
    }

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

        $prefix = $extension->extensionName().'.';
        if (!str_starts_with($definition->identifier(), $prefix)) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_RUNTIME_CONTRIBUTION_UNSUPPORTED, [
                '%extension%' => $extension->extensionName(),
                '%type%' => SchedulerTaskDefinition::class.'('.$definition->identifier().') foreign_identifier',
            ], [
                'extension' => $extension->extensionName(),
                'identifier' => $definition->identifier(),
                'expected_prefix' => $prefix,
            ]);
        }

        if (
            in_array($definition->type(), [SchedulerTaskType::Callable, SchedulerTaskType::ActionQueue], true)
            && !str_starts_with($definition->target(), $prefix)
        ) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_RUNTIME_CONTRIBUTION_UNSUPPORTED, [
                '%extension%' => $extension->extensionName(),
                '%type%' => SchedulerTaskDefinition::class.'('.$definition->identifier().') foreign_target',
            ], [
                'extension' => $extension->extensionName(),
                'identifier' => $definition->identifier(),
                'target' => $definition->target(),
                'expected_prefix' => $prefix,
            ]);
        }
    }

    public function assertStaticViewInjection(Extension $extension, StaticViewInjection $injection): void
    {
        $this->assertViewTemplate($extension, $injection->surface(), $injection->template(), StaticViewInjection::class.'('.$injection->uid().')');
    }

    public function assertConfigurableStaticViewInjectionSet(Extension $extension, ConfigurableStaticViewInjectionSet $set): void
    {
        if ($set->extensionName() !== $extension->extensionName()) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_RUNTIME_CONTRIBUTION_UNSUPPORTED, [
                '%extension%' => $extension->extensionName(),
                '%type%' => ConfigurableStaticViewInjectionSet::class.'('.$set->extensionName().') foreign_owner',
            ], [
                'extension' => $extension->extensionName(),
                'definition_extension' => $set->extensionName(),
            ]);
        }

        foreach ($set->staticViewInjections($set->defaultBaseSlug()) as $injection) {
            $this->assertStaticViewInjection($extension, $injection);
        }
    }

    public function assertDynamicViewInjection(Extension $extension, DynamicViewInjection $injection): void
    {
        $this->assertViewTemplate($extension, $injection->surface(), $injection->template(), DynamicViewInjection::class.'('.$injection->uid().')');
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

    public function assertSettingDefinition(Extension $extension, ExtensionSettingDefinition $definition): void
    {
        if ($definition->extensionName() === $extension->extensionName()) {
            return;
        }

        throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_RUNTIME_CONTRIBUTION_UNSUPPORTED, [
            '%extension%' => $extension->extensionName(),
            '%type%' => ExtensionSettingDefinition::class.'('.$definition->extensionName().'.'.$definition->key().') foreign_owner',
        ], [
            'extension' => $extension->extensionName(),
            'definition_extension' => $definition->extensionName(),
            'definition_key' => $definition->key(),
        ]);
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

    public function assertEventListenerContribution(Extension $extension, ExtensionEventListenerContribution $contribution): void
    {
        if (isset($this->eventHooks()[$contribution->eventClass()])) {
            return;
        }

        throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_RUNTIME_CONTRIBUTION_UNSUPPORTED, [
            '%extension%' => $extension->extensionName(),
            '%type%' => ExtensionEventListenerContribution::class.'('.$contribution->eventClass().') unregistered_event',
        ], [
            'extension' => $extension->extensionName(),
            'event' => $contribution->eventClass(),
        ]);
    }

    public function assertProviderContribution(Extension $extension, ExtensionProviderContribution $contribution): void
    {
        if (!$contribution->scope()->isProvider()) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_RUNTIME_CONTRIBUTION_UNSUPPORTED, [
                '%extension%' => $extension->extensionName(),
                '%type%' => ExtensionProviderContribution::class.'('.$contribution->scope()->value.') non_provider_scope',
            ], [
                'extension' => $extension->extensionName(),
                'scope' => $contribution->scope()->value,
            ]);
        }

        if ($extension->hasScope($contribution->scope())) {
            return;
        }

        throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_RUNTIME_CONTRIBUTION_UNSUPPORTED, [
            '%extension%' => $extension->extensionName(),
            '%type%' => ExtensionProviderContribution::class.'('.$contribution->scope()->value.') scope_missing',
        ], [
            'extension' => $extension->extensionName(),
            'required_scope' => $contribution->scope()->value,
        ]);
    }

    public function assertOperationDefinition(Extension $extension, ExtensionOperationDefinition $definition): void
    {
        $prefix = $extension->extensionName().'.';
        if (!str_starts_with($definition->identifier(), $prefix) || !str_starts_with($definition->target(), $prefix)) {
            throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_RUNTIME_CONTRIBUTION_UNSUPPORTED, [
                '%extension%' => $extension->extensionName(),
                '%type%' => ExtensionOperationDefinition::class.'('.$definition->identifier().') foreign_target',
            ], [
                'extension' => $extension->extensionName(),
                'identifier' => $definition->identifier(),
                'target' => $definition->target(),
                'expected_prefix' => $prefix,
            ]);
        }

        if (
            ExtensionTranslationKey::isOwnedBy($extension->extensionName(), $definition->labelKey())
            && ExtensionTranslationKey::isOwnedBy($extension->extensionName(), $definition->descriptionKey())
        ) {
            return;
        }

        throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_RUNTIME_CONTRIBUTION_UNSUPPORTED, [
            '%extension%' => $extension->extensionName(),
            '%type%' => ExtensionOperationDefinition::class.'('.$definition->identifier().') foreign_translation_key',
        ], [
            'extension' => $extension->extensionName(),
            'identifier' => $definition->identifier(),
            'label_key' => $definition->labelKey(),
            'description_key' => $definition->descriptionKey(),
        ]);
    }

    private function assertViewTemplate(Extension $extension, ViewSurface $surface, string $template, string $type): void
    {
        $expectedPrefix = match ($surface) {
            ViewSurface::Public => '@frontend/'.$extension->extensionName().'/',
            ViewSurface::Admin, ViewSurface::Editor => '@backend/'.$extension->extensionName().'/',
        };

        if (str_starts_with($template, $expectedPrefix)) {
            return;
        }

        throw MessageException::invalidArgument(ExtensionMessageKey::EXTENSION_RUNTIME_CONTRIBUTION_UNSUPPORTED, [
            '%extension%' => $extension->extensionName(),
            '%type%' => $type.' foreign_template',
        ], [
            'extension' => $extension->extensionName(),
            'surface' => $surface->value,
            'template' => $template,
            'expected_prefix' => $expectedPrefix,
        ]);
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

    /**
     * @return array<class-string, mixed>
     */
    private function eventHooks(): array
    {
        return ($this->eventHookRegistry ?? new PublicEventHookRegistry())->byEventClass();
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
