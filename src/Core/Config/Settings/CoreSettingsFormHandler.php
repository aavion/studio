<?php

declare(strict_types=1);

namespace App\Core\Config\Settings;

use App\Core\Config\Config;
use App\Core\Access\AccessLevel;
use App\Core\Validation\EmailAddress;
use App\Entity\AclGroup;
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

            if (!$this->config->set($definition->key(), $result->value($definition->key()), $definition->valueType(), modifiedBy: $modifiedBy)) {
                return new FormSubmissionResult($result->values(), [
                    '__form' => ['admin.settings.form.errors.save_failed'],
                ]);
            }
        }

        return $result;
    }

    private function validateDomainSettings(string $section, FormSubmissionResult $result): ?FormSubmissionResult
    {
        if ('users' !== $section) {
            return null;
        }

        foreach ([UserFlowConfig::REGISTRATION_ADMIN_NOTIFICATION_EMAIL_KEY, UserFlowConfig::SECURITY_NOTIFICATION_EMAIL_KEY] as $key) {
            if (!$this->isValidOptionalEmail($result->value($key))) {
                return new FormSubmissionResult($result->values(), [
                    $key => ['admin.settings.form.errors.email_invalid'],
                ]);
            }
        }

        $identifier = $result->value(UserFlowConfig::DEFAULT_ACL_GROUP_KEY);

        if (null === $identifier) {
            return null;
        }

        if (!is_string($identifier)) {
            return new FormSubmissionResult($result->values(), [
                UserFlowConfig::DEFAULT_ACL_GROUP_KEY => ['admin.settings.form.errors.default_acl_group_unavailable'],
            ]);
        }

        $identifier = trim($identifier);

        if ('' === $identifier) {
            return null;
        }

        $group = $this->entityManager->getRepository(AclGroup::class)->findOneBy(['identifier' => $identifier]);

        if (!$group instanceof AclGroup || $group->minRole() > AccessLevel::USER) {
            return new FormSubmissionResult($result->values(), [
                UserFlowConfig::DEFAULT_ACL_GROUP_KEY => ['admin.settings.form.errors.default_acl_group_unavailable'],
            ]);
        }

        return null;
    }

    private function isValidOptionalEmail(mixed $email): bool
    {
        if (null === $email) {
            return true;
        }

        return is_string($email) && ('' === trim($email) || EmailAddress::isValid($email));
    }
}
