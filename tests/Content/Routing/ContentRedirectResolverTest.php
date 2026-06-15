<?php

declare(strict_types=1);

namespace App\Tests\Content\Routing;

use App\Content\Routing\ContentRedirectResolveStatus;
use App\Content\Routing\ContentPathLookup;
use App\Content\Routing\ContentRedirectResolver;
use App\Content\Routing\ContentRedirectTargetType;
use App\Repository\ContentItemRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ContentRedirectResolverTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private ContentRedirectResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->resolver = new ContentRedirectResolver(
            new ContentPathLookup($container->get(ContentItemRepository::class)),
        );
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testItResolvesContentRedirectRoutes(): void
    {
        $this->connection->update('content_item', ['redirect_target' => '/news/first-update'], ['slug' => 'about']);
        $this->entityManager->clear();

        $result = $this->resolver->resolveByPath('/about');

        self::assertSame(ContentRedirectResolveStatus::Resolved, $result->status());
        self::assertTrue($result->isResolved());
        self::assertSame('/news/first-update', $result->redirectRoute());
        self::assertSame(['/about', '/news/first-update'], $result->routeChain());
    }

    public function testItFollowsRedirectChainsToTheFinalRoute(): void
    {
        $this->connection->update('content_item', ['redirect_target' => '/home'], ['slug' => 'about']);
        $this->connection->update('content_item', ['redirect_target' => '/news/first-update'], ['slug' => 'home']);
        $this->entityManager->clear();

        $result = $this->resolver->resolveByPath('/about');

        self::assertSame(ContentRedirectResolveStatus::Resolved, $result->status());
        self::assertSame('/news/first-update', $result->redirectRoute());
        self::assertSame(['/about', '/home', '/news/first-update'], $result->routeChain());
    }

    public function testItResolvesExternalRedirectTargets(): void
    {
        $this->connection->update('content_item', ['redirect_target' => 'https://example.test/target'], ['slug' => 'about']);
        $this->entityManager->clear();

        $result = $this->resolver->resolveByPath('/about');

        self::assertSame(ContentRedirectResolveStatus::Resolved, $result->status());
        self::assertSame(ContentRedirectTargetType::ExternalUrl, $result->targetType());
        self::assertSame('https://example.test/target', $result->redirectRoute());
    }

    public function testItRejectsUnsupportedRedirectSchemes(): void
    {
        $this->connection->update('content_item', ['redirect_target' => 'javascript:alert(1)'], ['slug' => 'about']);
        $this->entityManager->clear();

        $result = $this->resolver->resolveByPath('/about');

        self::assertSame(ContentRedirectResolveStatus::InvalidTarget, $result->status());
        self::assertSame('javascript:alert(1)', $result->redirectRoute());
    }

    #[DataProvider('unsafeExternalRedirectTargets')]
    public function testItRejectsUnsafeExternalRedirectTargets(string $target): void
    {
        $this->connection->update('content_item', ['redirect_target' => $target], ['slug' => 'about']);
        $this->entityManager->clear();

        $result = $this->resolver->resolveByPath('/about');

        self::assertSame(ContentRedirectResolveStatus::InvalidTarget, $result->status());
        self::assertSame($target, $result->redirectRoute());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeExternalRedirectTargets(): iterable
    {
        yield 'missing host' => ['https:///target'];
        yield 'backslash' => ['https://example.test\\@evil.example.test/target'];
        yield 'control character' => ["https://example.test/target\nLocation: https://evil.example.test"];
    }

    public function testItDetectsRedirectLoops(): void
    {
        $this->connection->update('content_item', ['redirect_target' => '/home'], ['slug' => 'about']);
        $this->connection->update('content_item', ['redirect_target' => '/about'], ['slug' => 'home']);
        $this->entityManager->clear();

        $result = $this->resolver->resolveByPath('/about');

        self::assertSame(ContentRedirectResolveStatus::LoopDetected, $result->status());
        self::assertSame('/about', $result->redirectRoute());
        self::assertSame(['/about', '/home', '/about'], $result->routeChain());
    }
}
