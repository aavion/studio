<?php

declare(strict_types=1);

namespace App\Core\Log;

final readonly class MonologLineParser
{
    /**
     * @return array<string, mixed>
     */
    public function parse(string $line, string $file = ''): array
    {
        $entry = [
            'timestamp' => '',
            'channel' => '',
            'level' => '',
            'message' => $line,
            'context' => [],
            'context_json' => '',
            'raw_context' => '',
            'file' => '' === $file ? '' : basename($file),
            'raw' => $line,
        ];

        if (1 !== preg_match('/^\[(?<timestamp>[^\]]+)\] (?<channel>[^.]+)\.(?<level>[A-Z]+): (?<body>.*)$/', $line, $matches)) {
            return $entry;
        }

        $entry['timestamp'] = $matches['timestamp'];
        $entry['channel'] = $matches['channel'];
        $entry['level'] = $matches['level'];
        $body = $matches['body'];

        if (1 === preg_match('/^(?<message>.*?) (?<context>\{.*\}|\[.*\]) (?<extra>\{.*\}|\[.*\])$/', $body, $bodyMatches)) {
            $entry['message'] = $bodyMatches['message'];
            $entry['raw_context'] = $bodyMatches['context'];
            $decoded = json_decode($bodyMatches['context'], true);
            $entry['context'] = is_array($decoded) ? $decoded : [];
            $entry['context_json'] = [] === $entry['context'] ? '' : (json_encode($entry['context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

            return $entry;
        }

        $entry['message'] = $body;

        return $entry;
    }
}
