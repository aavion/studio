<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Log\MessageLoggerInterface;
use App\Core\Message\Message;
use App\Core\Message\MessageException;
use App\Core\Validation\EmailAddress;
use App\Entity\UserAccount;
use App\Security\SecurityMessageCode;
use App\Security\SecurityMessageKey;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

#[AsDoctrineListener(event: Events::onFlush)]
final readonly class UserAccountUniquenessGuard
{
    public function __construct(private MessageLoggerInterface $messageLogger)
    {
    }

    public function onFlush(OnFlushEventArgs $event): void
    {
        $entityManager = $event->getObjectManager();
        $unitOfWork = $entityManager->getUnitOfWork();
        $users = [];

        foreach ([...$unitOfWork->getScheduledEntityInsertions(), ...$unitOfWork->getScheduledEntityUpdates()] as $entity) {
            if ($entity instanceof UserAccount) {
                $users[$entity->uid()] = $entity;
            }
        }

        if ([] === $users) {
            return;
        }

        $seenEmails = [];
        $seenUsernames = [];

        foreach ($users as $user) {
            $email = EmailAddress::normalize($user->email());
            $username = $user->username();

            if (isset($seenEmails[$email]) && $seenEmails[$email] !== $user->uid()) {
                $this->rejectDuplicate(SecurityMessageCode::USER_EMAIL_DUPLICATE, SecurityMessageKey::USER_EMAIL_DUPLICATE, 'email', $email);
            }

            if (isset($seenUsernames[$username]) && $seenUsernames[$username] !== $user->uid()) {
                $this->rejectDuplicate(SecurityMessageCode::USER_USERNAME_DUPLICATE, SecurityMessageKey::USER_USERNAME_DUPLICATE, 'username', $username);
            }

            $seenEmails[$email] = $user->uid();
            $seenUsernames[$username] = $user->uid();
        }

        $connection = $entityManager->getConnection();

        foreach ($users as $user) {
            if (false !== $connection->fetchOne(
                'SELECT uid FROM user_account WHERE LOWER(email) = ? AND uid <> ? LIMIT 1',
                [EmailAddress::normalize($user->email()), $user->uid()],
            )) {
                $this->rejectDuplicate(SecurityMessageCode::USER_EMAIL_DUPLICATE, SecurityMessageKey::USER_EMAIL_DUPLICATE, 'email', $user->email());
            }

            if (false !== $connection->fetchOne(
                'SELECT uid FROM user_account WHERE username = ? AND uid <> ? LIMIT 1',
                [$user->username(), $user->uid()],
            )) {
                $this->rejectDuplicate(SecurityMessageCode::USER_USERNAME_DUPLICATE, SecurityMessageKey::USER_USERNAME_DUPLICATE, 'username', $user->username());
            }
        }
    }

    private function rejectDuplicate(string $code, string $translationKey, string $field, string $value): never
    {
        $message = Message::warning($code, $translationKey, [
            '%value%' => $value,
        ], [
            'entity' => UserAccount::class,
            'field' => $field,
        ]);
        $this->messageLogger->log($message);

        throw MessageException::fromMessage($message);
    }
}
