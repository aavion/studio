<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Content\ContentVisibility;
use App\Core\Extension\ExtensionContentFacade;
use App\Core\Extension\ExtensionRuntime;
use App\Core\Extension\ExtensionRuntimeServices;
use App\Entity\ContentItem;
use App\Tests\Support\FilesystemTestHelper;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;

final class ExtensionRuntimeContentTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
        $this->removeDirectory($this->projectDir.'/extensions/content-facade');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir.'/extensions/content-facade');
        ExtensionRuntime::reset();
    }

    public function testItQueriesAndGetsPublishedPublicContentForCallingExtension(): void
    {
        $home = new ContentItem('75000000-0000-7000-8000-000000000001', 'home');
        $home->publish();
        $home->setAvailableLanguages(['en', 'de']);
        $private = new ContentItem('75000000-0000-7000-8000-000000000002', 'private-page');
        $private->publish();
        $private->setVisibility(ContentVisibility::Private);
        $draft = new ContentItem('75000000-0000-7000-8000-000000000003', 'draft-page');
        ExtensionRuntime::configure(new ExtensionRuntimeServices(
            $this->projectDir,
            content: new ExtensionContentFacade($this->entityManager([$home, $private, $draft])),
        ));
        $this->writeExtensionFile(<<<'PHP'
            <?php

            return [
                extension_content_query([], ['limit' => 10]),
                extension_content_get('home'),
                extension_content_get('75000000-0000-7000-8000-000000000001'),
                extension_content_get('private-page'),
                extension_content_get('draft-page'),
            ];
            PHP);

        [$query, $bySlug, $byUid, $privateResult, $draftResult] = require $this->projectDir.'/extensions/content-facade/extension.php';

        self::assertCount(1, $query);
        self::assertSame('content', $query[0]['type']);
        self::assertSame($home->uid(), $query[0]['uid']);
        self::assertSame('home', $query[0]['slug']);
        self::assertSame(['en', 'de'], $query[0]['available_languages']);
        self::assertSame($query[0], $bySlug);
        self::assertSame($query[0], $byUid);
        self::assertNull($privateResult);
        self::assertNull($draftResult);
        self::assertArrayNotHasKey('fields', $query[0]);
        self::assertArrayNotHasKey('metadata', $query[0]);
        self::assertArrayNotHasKey('active_revision', $query[0]);
    }

    public function testItAppliesCriteriaLimitAndSafeDefaults(): void
    {
        $a = new ContentItem('75000000-0000-7000-8000-000000000011', 'alpha');
        $a->publish();
        $b = new ContentItem('75000000-0000-7000-8000-000000000012', 'beta');
        $b->publish();
        ExtensionRuntime::configure(new ExtensionRuntimeServices(
            $this->projectDir,
            content: new ExtensionContentFacade($this->entityManager([$a, $b])),
        ));

        self::assertSame([], ExtensionRuntime::contentQuery());
        self::assertNull(ExtensionRuntime::contentGet('alpha'));

        $this->writeExtensionFile(<<<'PHP'
            <?php

            return [
                extension_content_query(['slug' => 'beta']),
                extension_content_query([], ['limit' => 1, 'sort' => 'slug', 'direction' => 'desc']),
            ];
            PHP);

        [$byCriteria, $limited] = require $this->projectDir.'/extensions/content-facade/extension.php';

        self::assertSame('beta', $byCriteria[0]['slug']);
        self::assertCount(1, $limited);
        self::assertSame('beta', $limited[0]['slug']);
    }

    private function writeExtensionFile(string $contents): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/content-facade/extension.php', $contents);
    }

    /**
     * @param list<ContentItem> $contents
     */
    private function entityManager(array $contents): EntityManagerInterface
    {
        $byUid = [];
        $bySlug = [];

        foreach ($contents as $content) {
            $byUid[$content->uid()] = $content;
            $bySlug[$content->slug()] = $content;
        }

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('find')->willReturnCallback(
            static fn (string $class, mixed $id): ?object => ContentItem::class === $class ? ($byUid[(string) $id] ?? null) : null,
        );
        $entityManager->method('getRepository')->willReturnCallback(
            function () use ($contents, $bySlug): EntityRepository {
                $repository = $this->createStub(EntityRepository::class);
                $repository->method('findOneBy')->willReturnCallback(
                    static function (array $criteria) use ($bySlug): ?ContentItem {
                        $content = is_string($criteria['slug'] ?? null) ? ($bySlug[$criteria['slug']] ?? null) : null;

                        return $content instanceof ContentItem && (!isset($criteria['status']) || $content->status() === $criteria['status'])
                            ? $content
                            : null;
                    },
                );
                $repository->method('findBy')->willReturnCallback(
                    static function (array $criteria, array $orderBy = [], ?int $limit = null) use ($contents): array {
                        $matches = array_values(array_filter(
                            $contents,
                            static fn (ContentItem $content): bool => self::contentMatches($content, $criteria),
                        ));

                        if (['slug' => 'DESC'] === $orderBy || ($orderBy['slug'] ?? null) === 'DESC') {
                            usort($matches, static fn (ContentItem $left, ContentItem $right): int => $right->slug() <=> $left->slug());
                        } else {
                            usort($matches, static fn (ContentItem $left, ContentItem $right): int => $left->slug() <=> $right->slug());
                        }

                        return null === $limit ? $matches : array_slice($matches, 0, $limit);
                    },
                );

                return $repository;
            },
        );

        return $entityManager;
    }

    /**
     * @param array<string, mixed> $criteria
     */
    private static function contentMatches(ContentItem $content, array $criteria): bool
    {
        foreach ($criteria as $field => $value) {
            $actual = match ($field) {
                'slug' => $content->slug(),
                'parentUid' => $content->parentUid(),
                'customUrl' => $content->customUrl(),
                'status' => $content->status(),
                'visibility' => $content->visibility(),
                default => null,
            };

            if ($actual !== $value) {
                return false;
            }
        }

        return true;
    }
}
