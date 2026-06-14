<?php

declare(strict_types=1);

namespace App\Tests\Form\Autocomplete;

use App\Entity\AclGroup;
use App\Entity\UserAccount;
use App\Form\Autocomplete\AdminAclGroupAutocomplete;
use App\Form\Autocomplete\AdminUserAutocomplete;
use PHPUnit\Framework\TestCase;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

final class AdminAutocompleteTest extends TestCase
{
    public function testAdminUserAutocompleteDefinesSecureSearchDefaults(): void
    {
        $type = new AdminUserAutocomplete();
        $options = $this->defaults($type);

        self::assertSame(BaseEntityAutocompleteType::class, $type->getParent());
        self::assertSame(UserAccount::class, $options['class']);
        self::assertSame('username', $options['choice_label']);
        self::assertSame(['username', 'email'], $options['searchable_fields']);
        self::assertSame('ROLE_ADMIN', $options['security']);
    }

    public function testAdminAclGroupAutocompleteDefinesSecureSearchDefaults(): void
    {
        $type = new AdminAclGroupAutocomplete();
        $options = $this->defaults($type);

        self::assertSame(BaseEntityAutocompleteType::class, $type->getParent());
        self::assertSame(AclGroup::class, $options['class']);
        self::assertSame('name', $options['choice_label']);
        self::assertSame(['identifier', 'name'], $options['searchable_fields']);
        self::assertSame('ROLE_ADMIN', $options['security']);
    }

    /**
     * @return array<string, mixed>
     */
    private function defaults(object $type): array
    {
        $resolver = new \Symfony\Component\OptionsResolver\OptionsResolver();
        $type->configureOptions($resolver);

        return $resolver->resolve();
    }
}
