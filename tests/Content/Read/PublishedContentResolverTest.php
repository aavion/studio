<?php

declare(strict_types=1);

namespace App\Tests\Content\Read;

use App\Content\Read\PublishedContentResolver;
use App\Content\Read\PublishedContentResolveStatus;
use App\Core\Access\AccessActor;
use App\Core\Access\AccessLevel;
use App\Repository\ContentFieldValueRepository;
use App\Repository\ContentItemRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PublishedContentResolverTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $entityManager;
    private PublishedContentResolver $resolver;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->resolver = new PublishedContentResolver(
            $container->get(ContentItemRepository::class),
            $container->get(ContentFieldValueRepository::class),
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

    public function testItResolvesSeededPublishedContentByCustomPath(): void
    {
        $view = $this->resolver->findByPath('/', AccessActor::anonymous(), 'de');

        self::assertNotNull($view);
        self::assertSame('home', $view->content()->slug());
        self::assertSame('de', $view->context()->language());
        self::assertSame('default', $view->context()->variant());
        self::assertSame('Willkommen in Studio', $view->title());
        self::assertSame('Ein flexibler Content-Seed fuer Tests.', $view->subtitle());
        self::assertSame(['html' => '<p>Diese Seite wird vom PHPUnit-Bootstrap erzeugt.</p>'], $view->field('body'));
        self::assertTrue($view->accessDecision()->isGranted());
    }

    public function testItFallsBackToDefaultLanguageWhenRequestedLanguageIsMissing(): void
    {
        $view = $this->resolver->findBySlug('about', AccessActor::anonymous(), 'fr');

        self::assertNotNull($view);
        self::assertSame('fr', $view->context()->requestedLanguage());
        self::assertSame('en', $view->context()->language());
        self::assertTrue($view->context()->languageFallbackUsed());
        self::assertSame('About Studio', $view->title());
    }

    public function testItReturnsNullWhenRequestedVariantIsMissing(): void
    {
        self::assertNull($this->resolver->findBySlug('home', AccessActor::anonymous(), variant: 'compact'));
        self::assertSame(
            PublishedContentResolveStatus::ContextUnavailable,
            $this->resolver->resolveBySlug('home', AccessActor::anonymous(), variant: 'compact')->status(),
        );
    }

    public function testItResolvesPublishedContentByHierarchyPath(): void
    {
        $homeUid = (string) $this->connection->fetchOne("SELECT uid FROM content_item WHERE slug = 'home'");
        $this->connection->update('content_item', ['parent_uid' => $homeUid, 'custom_url' => null], ['slug' => 'about']);
        $this->entityManager->clear();

        self::assertNull($this->resolver->findByPath('/about', AccessActor::anonymous()));

        $view = $this->resolver->findByPath('/home/about', AccessActor::anonymous());

        self::assertNotNull($view);
        self::assertSame('about', $view->content()->slug());
        self::assertSame('About Studio', $view->title());
    }

    public function testItAppliesViewAclRules(): void
    {
        $this->connection->update('content_item', ['view_min_level' => AccessLevel::EDITOR], ['slug' => 'home']);
        $this->entityManager->clear();

        self::assertNull($this->resolver->findBySlug('home', AccessActor::anonymous()));
        self::assertSame(
            PublishedContentResolveStatus::Denied,
            $this->resolver->resolveBySlug('home', AccessActor::anonymous())->status(),
        );

        $view = $this->resolver->findBySlug('home', AccessActor::fromAccess(AccessLevel::EDITOR, ['editor']));

        self::assertNotNull($view);
        self::assertSame(AccessLevel::EDITOR, $view->accessDecision()->rule()->minLevel());
    }

    public function testItDoesNotResolvePrivateOrRevisionlessContentForPublicReads(): void
    {
        $this->connection->update('content_item', ['visibility' => 'private'], ['slug' => 'home']);
        $this->entityManager->clear();

        self::assertNull($this->resolver->findBySlug('home', AccessActor::fromAccess(AccessLevel::ADMIN, ['admin'])));
        self::assertSame(
            PublishedContentResolveStatus::NotPublic,
            $this->resolver->resolveBySlug('home', AccessActor::fromAccess(AccessLevel::ADMIN, ['admin']))->status(),
        );

        $this->connection->update('content_item', ['visibility' => 'public', 'active_revision_uid' => null], ['slug' => 'home']);
        $this->entityManager->clear();

        self::assertNull($this->resolver->findBySlug('home', AccessActor::fromAccess(AccessLevel::ADMIN, ['admin'])));
        self::assertSame(
            PublishedContentResolveStatus::ContextUnavailable,
            $this->resolver->resolveBySlug('home', AccessActor::fromAccess(AccessLevel::ADMIN, ['admin']))->status(),
        );
    }

    public function testItTreatsUnpublishedContentAsNotPublished(): void
    {
        $this->connection->update('content_item', ['status' => 'draft'], ['slug' => 'home']);
        $this->entityManager->clear();

        self::assertNull($this->resolver->findBySlug('home', AccessActor::fromAccess(AccessLevel::ADMIN, ['admin'])));
        self::assertSame(
            PublishedContentResolveStatus::NotPublished,
            $this->resolver->resolveBySlug('home', AccessActor::fromAccess(AccessLevel::ADMIN, ['admin']))->status(),
        );
    }

    public function testItAppliesLegacyAclRestrictionWhitelist(): void
    {
        $this->connection->update('content_item', ['acl_restrictions' => json_encode(['project_team'], JSON_THROW_ON_ERROR)], ['slug' => 'home']);
        $this->entityManager->clear();

        self::assertNull($this->resolver->findBySlug('home', AccessActor::anonymous()));
        self::assertSame(
            PublishedContentResolveStatus::Denied,
            $this->resolver->resolveBySlug('home', AccessActor::anonymous())->status(),
        );
        self::assertNotNull($this->resolver->findBySlug('home', AccessActor::fromAccess(AccessLevel::PUBLIC, ['project_team'])));
    }
}
