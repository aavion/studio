<?php

declare(strict_types=1);

namespace App\Core\Access;

use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Message\MessageReporterInterface;

final class AccessResolver
{
    public function __construct(private MessageReporterInterface $messageReporter)
    {
    }

    public function decide(AccessActor $actor, AccessCapability $capability, AccessRule ...$rules): AccessDecision
    {
        [$rule, $source] = $this->effectiveRule($capability, $rules);
        $granted = $rule->allows($actor);

        return new AccessDecision(
            $granted,
            $capability,
            $rule,
            $source,
            $this->message($actor, $capability, $rule, $source, $granted),
        );
    }

    /**
     * @param list<AccessRule> $rules
     *
     * @return array{AccessRule, string}
     */
    private function effectiveRule(AccessCapability $capability, array $rules): array
    {
        foreach ($rules as $index => $rule) {
            if (!$rule->isInherited()) {
                return [$rule, 'rule_'.$index];
            }
        }

        return [AccessRule::defaultFor($capability), 'default'];
    }

    private function message(
        AccessActor $actor,
        AccessCapability $capability,
        AccessRule $rule,
        string $source,
        bool $granted,
    ): Message {
        $context = [
            ...$actor->toContext(),
            'capability' => $capability->value,
            'rule_source' => $source,
            'rule' => $rule->toArray(),
        ];

        $parameters = [
            '%capability%' => $capability->value,
            '%required_level%' => $rule->minLevel() ?? 'group',
            '%actor_level%' => $actor->accessLevel(),
        ];

        if ($granted) {
            return $this->report(Message::debug(MessageCode::ACCESS_GRANTED, MessageKey::ACCESS_GRANTED, $parameters, $context));
        }

        return $this->report(Message::create(MessageCode::ACCESS_DENIED, MessageKey::ACCESS_DENIED, $parameters, $context, MessageLevel::Warning));
    }

    private function report(Message $message): Message
    {
        return $this->messageReporter->report($message, [
            'source' => 'access_resolver',
        ]);
    }
}
