<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Message\Message;
use App\Security\PasswordPolicy;
use App\Setup\SetupMessageCode;
use App\Setup\SetupMessageKey;

final readonly class SetupRunInputValidator
{
    public function __construct(
        private SetupPasswordPolicy $passwordPolicy = new SetupPasswordPolicy(),
    ) {
    }

    /**
     * @return list<Message>
     */
    public function validate(SetupInput $input): array
    {
        $issues = array_map(
            fn (string $violation): Message => $this->adminPasswordMessage($violation),
            $this->passwordPolicy->violationCodes($input->adminPassword(), $input->adminUsername(), $input->adminEmail()),
        );

        if (null !== $input->appSecret() && strlen($input->appSecret()) < SetupInputValidator::MIN_APP_SECRET_LENGTH) {
            $issues[] = Message::error(
                SetupMessageCode::SETUP_APP_SECRET_TOO_SHORT,
                SetupMessageKey::SETUP_APP_SECRET_TOO_SHORT,
                ['%min_length%' => SetupInputValidator::MIN_APP_SECRET_LENGTH],
                ['field' => 'app_secret', 'min_length' => SetupInputValidator::MIN_APP_SECRET_LENGTH],
            );
        }

        return $issues;
    }

    private function adminPasswordMessage(string $violation): Message
    {
        [$code, $key] = match ($violation) {
            PasswordPolicy::VIOLATION_COMPLEXITY => [SetupMessageCode::SETUP_ADMIN_PASSWORD_COMPLEXITY, SetupMessageKey::SETUP_ADMIN_PASSWORD_COMPLEXITY],
            PasswordPolicy::VIOLATION_REPEATED => [SetupMessageCode::SETUP_ADMIN_PASSWORD_REPEATED, SetupMessageKey::SETUP_ADMIN_PASSWORD_REPEATED],
            PasswordPolicy::VIOLATION_PERSONAL => [SetupMessageCode::SETUP_ADMIN_PASSWORD_PERSONAL, SetupMessageKey::SETUP_ADMIN_PASSWORD_PERSONAL],
            default => [SetupMessageCode::SETUP_ADMIN_PASSWORD_TOO_SHORT, SetupMessageKey::SETUP_ADMIN_PASSWORD_TOO_SHORT],
        };

        return Message::error(
            $code,
            $key,
            ['%min_length%' => SetupPasswordPolicy::MIN_ADMIN_PASSWORD_LENGTH],
            ['field' => 'admin_password', 'min_length' => SetupPasswordPolicy::MIN_ADMIN_PASSWORD_LENGTH, 'violation' => $violation],
        );
    }
}
