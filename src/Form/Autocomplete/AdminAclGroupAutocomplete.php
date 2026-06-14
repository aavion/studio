<?php

declare(strict_types=1);

namespace App\Form\Autocomplete;

use App\Entity\AclGroup;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\UX\Autocomplete\Form\AsEntityAutocompleteField;
use Symfony\UX\Autocomplete\Form\BaseEntityAutocompleteType;

#[AsEntityAutocompleteField]
final class AdminAclGroupAutocomplete extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'class' => AclGroup::class,
            'choice_label' => 'name',
            'placeholder' => 'admin.autocomplete.acl_group.placeholder',
            'searchable_fields' => ['identifier', 'name'],
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
