<?php

declare(strict_types=1);

namespace App\Setup;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Security\PasswordPolicy;

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
                MessageCode::SETUP_APP_SECRET_TOO_SHORT,
                MessageKey::SETUP_APP_SECRET_TOO_SHORT,
                ['%min_length%' => SetupInputValidator::MIN_APP_SECRET_LENGTH],
                ['field' => 'app_secret', 'min_length' => SetupInputValidator::MIN_APP_SECRET_LENGTH],
            );
        }

        return $issues;
    }

    private function adminPasswordMessage(string $violation): Message
    {
        [$code, $key] = match ($violation) {
            PasswordPolicy::VIOLATION_COMPLEXITY => [MessageCode::SETUP_ADMIN_PASSWORD_COMPLEXITY, MessageKey::SETUP_ADMIN_PASSWORD_COMPLEXITY],
            PasswordPolicy::VIOLATION_REPEATED => [MessageCode::SETUP_ADMIN_PASSWORD_REPEATED, MessageKey::SETUP_ADMIN_PASSWORD_REPEATED],
            PasswordPolicy::VIOLATION_PERSONAL => [MessageCode::SETUP_ADMIN_PASSWORD_PERSONAL, MessageKey::SETUP_ADMIN_PASSWORD_PERSONAL],
            default => [MessageCode::SETUP_ADMIN_PASSWORD_TOO_SHORT, MessageKey::SETUP_ADMIN_PASSWORD_TOO_SHORT],
        };

        return Message::error(
            $code,
            $key,
            ['%min_length%' => SetupPasswordPolicy::MIN_ADMIN_PASSWORD_LENGTH],
            ['field' => 'admin_password', 'min_length' => SetupPasswordPolicy::MIN_ADMIN_PASSWORD_LENGTH, 'violation' => $violation],
        );
    }
}
