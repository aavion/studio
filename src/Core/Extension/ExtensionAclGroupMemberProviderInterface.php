<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Entity\AclGroup;
use App\Entity\UserAccount;

interface ExtensionAclGroupMemberProviderInterface
{
    /**
     * @return list<UserAccount>
     */
    public function members(AclGroup $group): array;
}
