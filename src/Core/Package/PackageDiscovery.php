<?php

declare(strict_types=1);

namespace App\Core\Package;

use App\Core\Manifest\ManifestParser;
use App\Core\Manifest\ManifestSpec;
use App\Core\Manifest\ManifestValidator;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageKey;
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
                        context: ['path' => $manifestPath, 'source' => $source->name()],
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
                        );
                    }

                    continue;
                }

                $manifest = $parseResult->value();
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
                            );
                        }

                        continue;
                    }
                }

                $candidates[] = new PackageCandidate($source, $directory, $manifestPath, $manifest);
            }
        }

        if ([] !== $issues) {
            return OperationResult::invalid($issues, ['candidates' => $candidates]);
        }

        return OperationResult::success($candidates);
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
            PackageSource::children('theme', 'themes', ManifestSpec::forNamespace(
                'THEME',
                ['VERSION', 'AUTHOR', 'NAME'],
                ['NAME', 'VERSION'],
            )),
            PackageSource::children('module', 'modules', ManifestSpec::forNamespace(
                'MODULE',
                ['VERSION', 'AUTHOR', 'NAME'],
                ['NAME', 'VERSION'],
            )),
            PackageSource::children('import', 'var/cache/'.$environment.'/imports'),
        ];
    }
}
