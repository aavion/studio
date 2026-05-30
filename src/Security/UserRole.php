<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Access\AccessLevel;

enum UserRole: string
{
    case Public = 'public';
    case User = 'user';
    case Moderator = 'moderator';
    case Author = 'author';
    case Publisher = 'publisher';
    case Curator = 'curator';
    case Manager = 'manager';
    case Director = 'director';
    case Admin = 'admin';
    case Owner = 'owner';

    public function accessLevel(): int
    {
        return match ($this) {
            self::Public => AccessLevel::PUBLIC,
            self::User => AccessLevel::USER,
            self::Moderator => AccessLevel::MODERATOR,
            self::Author => AccessLevel::AUTHOR,
            self::Publisher => AccessLevel::PUBLISHER,
            self::Curator => AccessLevel::CURATOR,
            self::Manager => AccessLevel::MANAGER,
            self::Director => AccessLevel::DIRECTOR,
            self::Admin => AccessLevel::ADMIN,
            self::Owner => AccessLevel::OWNER,
        };
    }

    /**
     * @return list<string>
     */
    public function symfonyRoles(): array
    {
        $roles = [];

        foreach (self::cases() as $role) {
            if ($role->accessLevel() > $this->accessLevel()) {
                continue;
            }

            $roles[] = 'ROLE_'.strtoupper($role->value);
        }

        return array_values(array_unique($roles));
    }

    /**
     * @return list<self>
     */
    public static function assignable(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $role): bool => self::Public !== $role,
        ));
    }

    public static function fromAccessLevel(int $accessLevel): self
    {
        return match (true) {
            $accessLevel >= AccessLevel::OWNER => self::Owner,
            $accessLevel >= AccessLevel::ADMIN => self::Admin,
            $accessLevel >= AccessLevel::DIRECTOR => self::Director,
            $accessLevel >= AccessLevel::MANAGER => self::Manager,
            $accessLevel >= AccessLevel::CURATOR => self::Curator,
            $accessLevel >= AccessLevel::PUBLISHER => self::Publisher,
            $accessLevel >= AccessLevel::AUTHOR => self::Author,
            $accessLevel >= AccessLevel::MODERATOR => self::Moderator,
            $accessLevel >= AccessLevel::USER => self::User,
            default => self::Public,
        };
    }
}
