<?php

declare(strict_types=1);

namespace App\Tests\Content\Render;

use App\Content\ContentMessageCode;
use App\Content\ContentMessageKey;
use App\Content\Read\ContentReadContext;
use App\Content\Read\PublishedContentView;
use App\Content\Render\ContentFieldsetRenderer;
use App\Content\Schema\ContentSchemaSource;
use App\Core\Access\AccessCapability;
use App\Core\Access\AccessDecision;
use App\Core\Access\AccessRule;
use App\Core\Message\Message;
use App\Core\Message\MessageReporterInterface;
use App\Entity\ContentItem;
use App\Entity\ContentRevision;
use App\Entity\ContentSchema;
use App\Entity\ContentSchemaVersion;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ContentFieldsetRendererTest extends TestCase
{
    public function testItReportsInvalidCustomTwigAndFallsBackToGenericFieldset(): void
    {
        $reporter = new RecordingContentMessageReporter();
        $renderer = new ContentFieldsetRenderer(new Environment(new ArrayLoader([
            '@frontend/content/partials/_generic-fields.html.twig' => '<section data-generic-fieldset>{{ content_view.title }}</section>',
        ])), $reporter);

        $html = $renderer->render($this->viewWithCustomTwig('{% if broken %}'));

        self::assertSame('<section data-generic-fieldset>Title</section>', $html);
        self::assertCount(1, $reporter->messages);
        self::assertSame(ContentMessageCode::CONTENT_CUSTOM_TWIG_FAILED, $reporter->messages[0]->code());
        self::assertSame(ContentMessageKey::CONTENT_CUSTOM_TWIG_FAILED, $reporter->messages[0]->translationKey());
        self::assertSame('content.render.custom_twig', $reporter->contexts[0]['operation']);
    }

    private function viewWithCustomTwig(string $customTwig): PublishedContentView
    {
        $content = new ContentItem('11111111-1111-7111-8111-111111111111', 'article');
        $schema = new ContentSchema(
            'aaaaaaaa-aaaa-7aaa-aaaa-aaaaaaaaaaaa',
            'article',
            ContentSchemaSource::Custom,
            ['en' => 'Article'],
        );
        $schemaVersion = new ContentSchemaVersion(
            'bbbbbbbb-bbbb-7bbb-bbbb-bbbbbbbbbbbb',
            $schema,
            1,
            ['en' => 'Article schema'],
            [
                'fields' => [
                    ['identifier' => 'title', 'type' => 'text', 'required' => true],
                    ['identifier' => 'subtitle', 'type' => 'text', 'required' => true],
                ],
            ],
            customTwig: $customTwig,
        );
        $revision = new ContentRevision('33333333-3333-7333-8333-333333333333', $content, 1, $schemaVersion);
        $content->setSchema($schema);

        return new PublishedContentView(
            $content,
            $revision,
            new ContentReadContext('en', 'en', 'default', 'default'),
            ['title' => 'Title'],
            new AccessDecision(
                true,
                AccessCapability::View,
                AccessRule::defaultFor(AccessCapability::View),
                'test',
                Message::debug('access.granted', 'message.access.granted'),
            ),
        );
    }
}

final class RecordingContentMessageReporter implements MessageReporterInterface
{
    /**
     * @var list<Message>
     */
    public array $messages = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $contexts = [];

    public function report(Message $message, array $context = []): Message
    {
        $this->messages[] = $message;
        $this->contexts[] = $context;

        return $message;
    }

    public function reportBatch(iterable $records): array
    {
        $messages = [];

        foreach ($records as $record) {
            $messages[] = $this->report($record['message'], $record['context'] ?? []);
        }

        return $messages;
    }
}
