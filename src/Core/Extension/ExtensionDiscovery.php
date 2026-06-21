<?php

declare(strict_types=1);

namespace App\Core\Extension;

use App\Core\Manifest\ManifestParser;
use App\Core\Manifest\ManifestSpec;
use App\Core\Manifest\ManifestValidator;
use App\Core\Message\Message;
use App\Core\Message\MessageLevel;
use App\Core\Extension\ExtensionMessageCode;
use App\Core\Extension\ExtensionMessageKey;
use App\Core\Workflow\WorkflowResult;

final readonly class ExtensionDiscovery
{
    public function __construct(
        private ManifestParser $parser = new ManifestParser(),
        private ManifestValidator $validator = new ManifestValidator(),
    ) {
    }

    /**
     * @return WorkflowResult<list<ExtensionCandidate>>
     */
    public function discover(string $projectDir, string $environment): WorkflowResult
    {
        return $this->discoverSources($projectDir, $this->defaultSources($environment));
    }

    /**
     * @param list<ExtensionSource> $sources
     *
     * @return WorkflowResult<list<ExtensionCandidate>>
     */
    public function discoverSources(string $projectDir, array $sources): WorkflowResult
    {
        $projectDir = rtrim($projectDir, DIRECTORY_SEPARATOR);
        $candidates = [];
        $issues = [];
        $messages = [];

        foreach ($sources as $source) {
            foreach ($source->candidateDirectories($projectDir) as $directory) {
                $manifestPath = $directory.DIRECTORY_SEPARATOR.'.manifest';

                if (!is_file($manifestPath)) {
                    continue;
                }

                $contents = file_get_contents($manifestPath);
                if (false === $contents) {
                    $issues[] = Message::create(
                        ExtensionMessageCode::EXTENSION_MANIFEST_UNREADABLE,
                        ExtensionMessageKey::EXTENSION_MANIFEST_UNREADABLE,
                        ['%path%' => $manifestPath],
                        context: ['path' => $manifestPath, 'source' => $source->name()],
                        level: MessageLevel::Error,
                    );

                    continue;
                }

                $parseResult = $this->parser->parse($contents);
                if (!$parseResult->isSuccess()) {
                    foreach ($parseResult->issues() as $issue) {
                        $issues[] = Message::create(
                            $issue->code(),
                            $issue->translationKey(),
                            $issue->parameters(),
                            ['path' => $manifestPath, 'source' => $source->name()] + $issue->context(),
                            $issue->level(),
                        );
                    }

                    continue;
                }

                $manifest = $parseResult->value();
                foreach ($parseResult->messages() as $message) {
                    $messages[] = $message->withContext(['path' => $manifestPath, 'source' => $source->name()]);
                }

                $spec = $source->spec();

                if (null !== $spec) {
                    $validationResult = $this->validator->validate($manifest, $spec);
                    if (!$validationResult->isSuccess()) {
                        foreach ($validationResult->issues() as $issue) {
                            $issues[] = Message::create(
                                $issue->code(),
                                $issue->translationKey(),
                                $issue->parameters(),
                                ['path' => $manifestPath, 'source' => $source->name()] + $issue->context(),
                                $issue->level(),
                            );
                        }

                        continue;
                    }

                    foreach ($validationResult->messages() as $message) {
                        $messages[] = $message->withContext(['path' => $manifestPath, 'source' => $source->name()]);
                    }
                }

                if ('extension' === $source->name()) {
                    $scopeValue = $manifest->get('EXTENSION_SCOPE', '');

                    try {
                        ExtensionScope::fromManifestValue($scopeValue);
                    } catch (\InvalidArgumentException $exception) {
                        $issues[] = Message::create(
                            ExtensionMessageCode::EXTENSION_SCOPE_INVALID,
                            ExtensionMessageKey::EXTENSION_SCOPE_INVALID,
                            ['%scope%' => $scopeValue],
                            ['path' => $manifestPath, 'source' => $source->name(), 'scope' => $scopeValue],
                            MessageLevel::Error,
                        );

                        continue;
                    }
                }

                $candidates[] = new ExtensionCandidate($source, $directory, $manifestPath, $manifest);
            }
        }

        if ([] !== $issues) {
            return WorkflowResult::invalid($issues, ['candidates' => $candidates], $messages);
        }

        return WorkflowResult::success($candidates, [
            'candidate_count' => count($candidates),
        ], [
            ...$messages,
            Message::create(ExtensionMessageCode::EXTENSION_DISCOVERY_COMPLETED, ExtensionMessageKey::EXTENSION_DISCOVERY_COMPLETED, [
                '%count%' => count($candidates),
            ], [
                'candidate_count' => count($candidates),
            ], MessageLevel::Success),
        ]);
    }

    /**
     * @return list<ExtensionSource>
     */
    public function defaultSources(string $environment): array
    {
        return [
            ExtensionSource::single('app', '.', ManifestSpec::forNamespace(
                'APP',
                ['VERSION', 'DATE', 'NAME', 'AUTHOR', 'DESCRIPTION', 'CHANNEL', 'SOURCE', 'LICENSE', 'HOMEPAGE', 'IMAGE'],
                ['VERSION'],
            )),
            ExtensionSource::children('extension', 'extensions', ExtensionManifestSpec::create()),
            ExtensionSource::children('import', 'var/cache/'.$environment.'/imports'),
        ];
    }
}
