<?php

declare(strict_types=1);

namespace App\Tests\Backend;

use App\Backend\ExtensionAdminLinkResolver;
use PHPUnit\Framework\TestCase;

final class ExtensionAdminLinkResolverTest extends TestCase
{
    public function testItAcceptsOnlyHttpUrlsWithHosts(): void
    {
        $resolver = new ExtensionAdminLinkResolver();

        self::assertSame('https://example.test/extension', $resolver->safeExternalUrl(' https://example.test/extension '));
        self::assertNull($resolver->safeExternalUrl('javascript:alert(1)'));
        self::assertNull($resolver->safeExternalUrl('https:///missing-host'));
        self::assertNull($resolver->safeExternalUrl('https://example.test\\@evil.example.test/extension'));
        self::assertNull($resolver->safeExternalUrl("https://example.test/\nheader"));
    }

    public function testItBuildsGithubSourceChannelUrls(): void
    {
        $resolver = new ExtensionAdminLinkResolver();

        self::assertSame(
            'https://github.com/aavion/demo/tree/release%2F1.0',
            $resolver->sourceUrl('https://github.com/aavion/demo.git', 'release/1.0'),
        );
        self::assertSame(
            'https://git.example.test/aavion/demo',
            $resolver->sourceUrl('https://git.example.test/aavion/demo', 'release/1.0'),
        );
    }
}
