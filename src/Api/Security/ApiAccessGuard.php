<?php

declare(strict_types=1);

namespace App\Api\Security;

use App\Api\Http\ApiRequestContext;
use App\Api\Http\ApiResponder;
use App\Core\Access\AccessActor;
use App\Core\Access\AccessLevel;
use App\Core\Message\Message;
use App\Security\SecurityMessageCode;
use App\Security\SecurityMessageKey;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ApiAccessGuard
{
    public function __construct(private ApiResponder $responder)
    {
    }

    public function denyUnlessAccessLevel(Request $request, int $minimumAccessLevel): ?Response
    {
        AccessLevel::assert($minimumAccessLevel);
        $context = ApiRequestContext::fromRequest($request);
        $actor = $context?->actor() ?? AccessActor::anonymous();

        if ($actor->accessLevel() >= $minimumAccessLevel) {
            return null;
        }

        return $this->responder->error(
            Message::warning(
                SecurityMessageCode::API_KEY_PERMISSION_DENIED,
                SecurityMessageKey::API_KEY_PERMISSION_DENIED,
                context: [
                    'required_access_level' => $minimumAccessLevel,
                    'actor_access_level' => $actor->accessLevel(),
                    'authenticated' => $context?->isAuthenticated() ?? false,
                ],
            ),
            Response::HTTP_FORBIDDEN,
            $request,
        );
    }
}
