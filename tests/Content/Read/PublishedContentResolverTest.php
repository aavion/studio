<?php

declare(strict_types=1);

namespace App\Tests\Content\Read;

use App\Content\Read\PublishedContentResolver;
use App\Content\Read\PublishedContentResolveStatus;
use App\Core\Access\AccessActor;
use App\Core\Access\AccessLevel;
use App\Core\Message\MessageCode;
use App\Core\Message\MessageLevel;
use App\Core\Message\MessageKey;
use App\Repository\ContentFieldValueRepository;
use App\Repository\ContentItemRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use App\Tests\Support\NullMessageReporter;
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
            new NullMessageReporter(),
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

    public function testItResolvesSeededHomeContentByHierarchyPath(): void
    {
        $view = $this->resolver->findByPath('/home', AccessActor::anonymous(), 'de');

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
        self::assertSame(MessageCode::CONTENT_LANGUAGE_FALLBACK, $this->resolver->resolveBySlug('about', AccessActor::anonymous(), 'fr')->messages()[0]->code());
        self::assertSame(MessageKey::CONTENT_LANGUAGE_FALLBACK, $this->resolver->resolveBySlug('about', AccessActor::anonymous(), 'fr')->messages()[0]->translationKey());
    }

    public function testItFallsBackToDefaultVariantWhenRequestedVariantIsMissing(): void
    {
        $result = $this->resolver->resolveBySlug('home', AccessActor::anonymous(), variant: 'compact');
        $view = $result->view();

        self::assertSame(PublishedContentResolveStatus::Resolved, $result->status());
        self::assertNotNull($view);
        self::assertSame('compact', $view->context()->requestedVariant());
        self::assertSame('default', $view->context()->variant());
        self::assertTrue($view->context()->variantFallbackUsed());
        self::assertSame(MessageCode::CONTENT_VARIANT_FALLBACK, $result->messages()[0]->code());
        self::assertSame(MessageLevel::Warning, $result->messages()[0]->level());
    }

    public function testItResolvesPublishedContentByHierarchyPath(): void
    {
        $homeUid = (string) $this->connection->fetchOne("SELECT uid FROM content_item WHERE slug = 'home'");
        $this->connection->update('content_item', ['parent_uid' => $homeUid, 'custom_url' => null], ['slug' => 'about']);
        $this->entityManager->clear();

        self::assertNull($this->resolver->findBySlug('about', AccessActor::anonymous()));
        self::assertNull($this->resolver->findByPath('/about', AccessActor::anonymous()));

        $view = $this->resolver->findByPath('/home/about', AccessActor::anonymous());

        self::assertNotNull($view);
        self::assertSame('about', $view->content()->slug());
        self::assertSame('About Studio', $view->title());
    }

    public function testItResolvesInternalSystemHierarchyWhenCalledDirectly(): void
    {
        $this->connection->update('content_item', [
            'slug' => 'footer',
            'parent_uid' => 'system',
            'custom_url' => null,
        ], ['slug' => 'about']);
        $this->entityManager->clear();

        $view = $this->resolver->findByPath('/system/footer', AccessActor::anonymous());

        self::assertNotNull($view);
        self::assertSame('footer', $view->content()->slug());
        self::assertSame('About Studio', $view->title());
    }

    public function testItResolvesRouteVariantSuffix(): void
    {
        $revisionUid = (string) $this->connection->fetchOne("SELECT active_revision_uid FROM content_item WHERE slug = 'home'");
        $this->connection->update('content_item', [
            'available_variants' => json_encode(['default', 'compact'], JSON_THROW_ON_ERROR),
        ], ['slug' => 'home']);
        $this->connection->insert('content_field_value', [
            'uid' => '40000000-0000-0000-0000-000000000401',
            'revision_uid' => $revisionUid,
            'language' => 'en',
            'variant' => 'compact',
            'field_identifier' => 'title',
            'field_content' => json_encode('Compact Home', JSON_THROW_ON_ERROR),
        ]);
        $this->entityManager->clear();

        $view = $this->resolver->findByPath('/home/~compact', AccessActor::anonymous());

        self::assertNotNull($view);
        self::assertSame('compact', $view->context()->requestedVariant());
        self::assertSame('compact', $view->context()->variant());
        self::assertSame('Compact Home', $view->title());
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
