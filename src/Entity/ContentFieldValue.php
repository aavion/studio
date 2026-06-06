<?php

declare(strict_types=1);

namespace App\Entity;

use App\Content\ContentMessageKey;
use App\Content\Routing\ContentSlug;
use App\Core\Message\MessageException;
use App\Core\Validation\Uid;
use App\Repository\ContentFieldValueRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ContentFieldValueRepository::class)]
#[ORM\Table(name: 'content_field_value')]
#[ORM\Index(name: 'idx_content_field_lookup', columns: ['revision_uid', 'language', 'variant'])]
#[ORM\Index(name: 'idx_content_field_identifier', columns: ['field_identifier'])]
#[ORM\Index(name: 'idx_content_field_identifier_variant', columns: ['field_identifier', 'language', 'variant'])]
#[ORM\UniqueConstraint(name: 'uniq_content_field_context_identifier', columns: ['revision_uid', 'language', 'variant', 'field_identifier'])]
class ContentFieldValue
{
    #[ORM\Id]
    #[ORM\Column(length: 36)]
    private string $uid;

    #[ORM\ManyToOne(targetEntity: ContentRevision::class, inversedBy: 'fieldValues')]
    #[ORM\JoinColumn(name: 'revision_uid', referencedColumnName: 'uid', nullable: false, onDelete: 'CASCADE')]
    private ContentRevision $revision;

    #[ORM\Column(length: 16)]
    private string $language;

    #[ORM\Column(length: 80)]
    private string $variant;

    #[ORM\Column(length: 160)]
    private string $fieldIdentifier;

    /**
     * @var array<string, mixed>|list<mixed>|string|int|float|bool|null
     */
    #[ORM\Column(type: 'json')]
    private array|string|int|float|bool|null $fieldContent;

    /**
     * @param array<string, mixed>|list<mixed>|string|int|float|bool|null $fieldContent
     */
    public function __construct(
        string $uid,
        ContentRevision $revision,
        string $language,
        string $variant,
        string $fieldIdentifier,
        array|string|int|float|bool|null $fieldContent,
    ) {
        $this->uid = self::assertUid($uid, 'Content field value UID');
        $this->revision = $revision;
        $this->language = self::assertToken($language, 'Field language');
        $this->variant = ContentSlug::fromString($variant)->value();
        $this->fieldIdentifier = self::assertFieldIdentifier($fieldIdentifier);
        $this->fieldContent = $fieldContent;
    }

    public function uid(): string
    {
        return $this->uid;
    }

    public function revision(): ContentRevision
    {
        return $this->revision;
    }

    public function content(): ContentItem
    {
        return $this->revision->content();
    }

    public function attachTo(ContentRevision $revision): void
    {
        $this->revision = $revision;
    }

    public function version(): int
    {
        return $this->revision->version();
    }

    public function language(): string
    {
        return $this->language;
    }

    public function variant(): string
    {
        return $this->variant;
    }

    public function fieldIdentifier(): string
    {
        return $this->fieldIdentifier;
    }

    /**
     * @return array<string, mixed>|list<mixed>|string|int|float|bool|null
     */
    public function fieldContent(): array|string|int|float|bool|null
    {
        return $this->fieldContent;
    }

    /**
     * @param array<string, mixed>|list<mixed>|string|int|float|bool|null $fieldContent
     */
    public function replaceFieldContent(array|string|int|float|bool|null $fieldContent): void
    {
        $this->fieldContent = $fieldContent;
    }

    private static function assertUid(string $uid, string $label): string
    {
        return Uid::assert($uid, $label);
    }

    private static function assertToken(string $token, string $label): string
    {
        if (1 !== preg_match('/^[a-z]{2}(?:-[a-z0-9]+)?$/', $token)) {
            throw MessageException::invalidArgument(ContentMessageKey::CONTENT_LOCALE_TOKEN_INVALID, [
                '%label%' => $label,
                '%token%' => $token,
            ]);
        }

        return $token;
    }

    private static function assertFieldIdentifier(string $fieldIdentifier): string
    {
        if (1 !== preg_match('/^[a-z][a-z0-9_]*$/', $fieldIdentifier)) {
            throw MessageException::invalidArgument(ContentMessageKey::CONTENT_FIELD_IDENTIFIER_INVALID, [
                '%field_identifier%' => $fieldIdentifier,
            ]);
        }

        return $fieldIdentifier;
    }
}
