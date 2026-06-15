<?php

declare(strict_types=1);

namespace App\Core\Config\Settings;

use App\Api\ApiFeaturePolicy;
use App\Core\Config\Config;
use App\Core\Access\AccessLevel;
use App\Core\Validation\EmailAddress;
use App\Entity\AclGroup;
use App\Form\FormErrorKey;
use App\Form\FormFieldDefinition;
use App\Form\FormSubmissionHandler;
use App\Form\FormSubmissionResult;
use App\Security\UserFlowConfig;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CoreSettingsFormHandler
{
    public function __construct(
        private CoreSettingsRegistry $registry,
        private Config $config,
        private FormSubmissionHandler $submissionHandler,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param array<string, mixed> $submitted
     */
    public function submit(string $section, array $submitted, ?string $modifiedBy = null): FormSubmissionResult
    {
        $definitions = $this->registry->definitions($section);
        $result = $this->submissionHandler->submit(
            array_map(static fn (CoreSettingDefinition $definition): FormFieldDefinition => $definition->formField(), $definitions),
            $submitted,
        );

        if (!$result->isValid()) {
            return $result;
        }

        if ($domainResult = $this->validateDomainSettings($section, $result)) {
            return $domainResult;
        }

        foreach ($definitions as $definition) {
            if (false === ($definition->metadata()['persist'] ?? true)) {
                continue;
            }

            $metadata = $definition->metadata();
            if (
                true === ($metadata['sensitive'] ?? false)
                && $this->isEmptySensitiveValue($result->value($definition->key()))
            ) {
                continue;
            }

            if (!$this->config->set(
                $definition->key(),
                $result->value($definition->key()),
                $definition->valueType(),
                sensitive: true === ($metadata['sensitive'] ?? false),
                modifiedBy: $modifiedBy,
            )) {
                return new FormSubmissionResult($result->values(), [
                    '__form' => [FormErrorKey::SAVE_FAILED],
                ]);
            }
        }

        return $result;
    }

    private function validateDomainSettings(string $section, FormSubmissionResult $result): ?FormSubmissionResult
    {
        if ('api' === $section) {
            return $this->validateApiSettings($result);
        }

        if ('users' !== $section) {
            return null;
        }

        foreach ([UserFlowConfig::REGISTRATION_ADMIN_NOTIFICATION_EMAIL_KEY, UserFlowConfig::SECURITY_NOTIFICATION_EMAIL_KEY] as $key) {
            if (!$this->isValidOptionalEmail($result->value($key))) {
                return new FormSubmissionResult($result->values(), [
                    $key => [FormErrorKey::EMAIL_INVALID],
                ]);
            }
        }

        $identifier = $result->value(UserFlowConfig::DEFAULT_ACL_GROUP_KEY);

        if (null === $identifier) {
            return null;
        }

        if (!is_string($identifier)) {
            return new FormSubmissionResult($result->values(), [
                UserFlowConfig::DEFAULT_ACL_GROUP_KEY => [FormErrorKey::DEFAULT_ACL_GROUP_UNAVAILABLE],
            ]);
        }

        $identifier = trim($identifier);

        if ('' === $identifier) {
            return null;
        }

        $group = $this->entityManager->getRepository(AclGroup::class)->findOneBy(['identifier' => $identifier]);

        if (!$group instanceof AclGroup || $group->minRole() > AccessLevel::USER) {
            return new FormSubmissionResult($result->values(), [
                UserFlowConfig::DEFAULT_ACL_GROUP_KEY => [FormErrorKey::DEFAULT_ACL_GROUP_UNAVAILABLE],
            ]);
        }

        return null;
    }

    private function validateApiSettings(FormSubmissionResult $result): ?FormSubmissionResult
    {
        $origins = $result->value(ApiFeaturePolicy::CORS_ALLOWED_ORIGINS_KEY);

        if (null === $origins) {
            return null;
        }

        if (!is_array($origins) || array_is_list($origins) === false) {
            return new FormSubmissionResult($result->values(), [
                ApiFeaturePolicy::CORS_ALLOWED_ORIGINS_KEY => [FormErrorKey::INVALID],
            ]);
        }

        foreach ($origins as $origin) {
            if (!is_string($origin) || !$this->isValidCorsOrigin($origin)) {
                return new FormSubmissionResult($result->values(), [
                    ApiFeaturePolicy::CORS_ALLOWED_ORIGINS_KEY => [FormErrorKey::INVALID],
                ]);
            }
        }

        return null;
    }

    private function isValidCorsOrigin(string $origin): bool
    {
        $origin = trim($origin);

        if ('*' === $origin) {
            return true;
        }

        $parts = parse_url($origin);

        return is_array($parts)
            && in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && is_string($parts['host'] ?? null)
            && !isset($parts['path'], $parts['query'], $parts['fragment']);
    }

    private function isValidOptionalEmail(mixed $email): bool
    {
        if (null === $email) {
            return true;
        }

        return is_string($email) && ('' === trim($email) || EmailAddress::isValid($email));
    }

    private function isEmptySensitiveValue(mixed $value): bool
    {
        return null === $value || (is_string($value) && '' === trim($value));
    }
}
