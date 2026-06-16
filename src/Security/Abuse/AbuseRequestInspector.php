<?php

declare(strict_types=1);

namespace App\Security\Abuse;

use Symfony\Component\HttpFoundation\Request;

final readonly class AbuseRequestInspector
{
    public function __construct(
        private AbuseSubjectResolver $subjectResolver,
        private RequestIntentClassifier $intentClassifier,
        private ActionCostCatalogue $costCatalogue,
    ) {
    }

    /**
     * @return array{profile: AbuseRequestProfile, subjects: AbuseSubjectResolution, cost: ActionCost}
     */
    public function inspect(Request $request): array
    {
        $profile = $this->intentClassifier->classify($request);

        return [
            'profile' => $profile,
            'subjects' => $this->subjectResolver->resolve($request),
            'cost' => $this->costCatalogue->costFor($profile),
        ];
    }
}
