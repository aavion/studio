<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Manifest\ManifestParser;
use App\Core\Manifest\ManifestSpec;
use App\Core\Manifest\ManifestValidator;
use App\Core\Message\Message;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
use App\Core\Message\MessageLevel;
use App\Core\Workflow\OperationIssue;
use App\Core\Workflow\OperationResult;

final readonly class PackageDiscovery
{
    public function __construct(
        private ManifestParser $parser = new ManifestParser(),
        private ManifestValidator $validator = new ManifestValidator(),
    ) {
    }

    /**
     * @return OperationResult<list<PackageCandidate>>
     */
    public function discover(string $projectDir, string $environment): OperationResult
    {
        return $this->discoverSources($projectDir, $this->defaultSources($environment));
    }

    /**
     * @param list<PackageSource> $sources
     *
     * @return OperationResult<list<PackageCandidate>>
     */
    public function discoverSources(string $projectDir, array $sources): OperationResult
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
                    $issues[] = OperationIssue::create(
                        MessageCode::PACKAGE_MANIFEST_UNREADABLE,
                        MessageKey::PACKAGE_MANIFEST_UNREADABLE,
                        ['%path%' => $manifestPath],
                        context: ['path' => $manifestPath, 'source' => $source->name()],
                        level: MessageLevel::Error,
                    );

                    continue;
                }

                $parseResult = $this->parser->parse($contents);
                if (!$parseResult->isSuccess()) {
                    foreach ($parseResult->issues() as $issue) {
                        $issues[] = OperationIssue::create(
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
                            $issues[] = OperationIssue::create(
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

                if ('package' === $source->name()) {
                    $scopeValue = $manifest->get('PACKAGE_SCOPE', '');

                    try {
                        PackageScope::fromManifestValue($scopeValue);
                    } catch (\InvalidArgumentException $exception) {
                        $issues[] = OperationIssue::create(
                            MessageCode::PACKAGE_SCOPE_INVALID,
                            MessageKey::PACKAGE_SCOPE_INVALID,
                            ['%scope%' => $scopeValue],
                            ['path' => $manifestPath, 'source' => $source->name(), 'scope' => $scopeValue],
                            MessageLevel::Warning,
                        );

                        continue;
                    }
                }

                $candidates[] = new PackageCandidate($source, $directory, $manifestPath, $manifest);
            }
        }

        if ([] !== $issues) {
            return OperationResult::invalid($issues, ['candidates' => $candidates], $messages);
        }

        return OperationResult::success($candidates, [
            'candidate_count' => count($candidates),
        ], [
            ...$messages,
            Message::info(MessageCode::PACKAGE_DISCOVERY_COMPLETED, MessageKey::PACKAGE_DISCOVERY_COMPLETED, [
                '%count%' => count($candidates),
            ], [
                'candidate_count' => count($candidates),
            ]),
        ]);
    }

    /**
     * @return list<PackageSource>
     */
    public function defaultSources(string $environment): array
    {
        return [
            PackageSource::single('app', '.', ManifestSpec::forNamespace(
                'APP',
                ['VERSION', 'DATE', 'CHANNEL', 'SOURCE'],
                ['VERSION'],
            )),
            PackageSource::children('package', 'packages', ManifestSpec::create()
                ->allowOnly(
                    'PACKAGE_AUTHOR',
                    'PACKAGE_NAME',
                    'PACKAGE_VERSION',
                    'PACKAGE_SCOPE',
                    'PACKAGE_DEPENDENCIES',
                    'PACKAGE_SOURCE',
                    'PACKAGE_CHANNEL',
                    'PACKAGE_IMAGE',
                    'PACKAGE_NAMESPACE',
                    'PACKAGE_DESCRIPTION',
                    'PACKAGE_LICENSE',
                    'PACKAGE_HOMEPAGE',
                )
                ->require('PACKAGE_AUTHOR')
                ->require('PACKAGE_NAME')
                ->require('PACKAGE_VERSION')
                ->require('PACKAGE_SCOPE')
                ->require('PACKAGE_DEPENDENCIES')),
            PackageSource::children('import', 'var/cache/'.$environment.'/imports'),
        ];
    }
}
