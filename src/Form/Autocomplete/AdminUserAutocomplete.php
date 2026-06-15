<?php

declare(strict_types=1);

namespace App\Form\Autocomplete;

use App\Entity\UserAccount;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField]
final class AdminUserAutocomplete extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => UserAccount::class,
            'choice_label' => 'username',
            'placeholder' => 'admin.autocomplete.user.placeholder',
            'searchable_fields' => ['username', 'email'],
            'security' => 'ROLE_ADMIN',
            'max_results' => 20,
            'min_characters' => 2,
        ]);
    }

    public function getParent(): string
    {
        return BaseEntityAutocompleteType::class;
    }
}
