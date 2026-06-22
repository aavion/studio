<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Extension\ExtensionClassAutoloader;
use App\Core\Extension\ExtensionScope;
use App\Core\Extension\ExtensionStatus;
use App\Core\Message\MessageException;
use App\Entity\Extension;
use App\Tests\Support\FilesystemTestHelper;
use PHPUnit\Framework\TestCase;

final class ExtensionClassAutoloaderTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    private ?ExtensionClassAutoloader $loader = null;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
        $this->removeDirectory($this->projectDir.'/extensions/autoload-parent');
        $this->removeDirectory($this->projectDir.'/extensions/autoload-child');
    }

    protected function tearDown(): void
    {
        $this->loader?->reset();
        $this->removeDirectory($this->projectDir.'/extensions/autoload-parent');
        $this->removeDirectory($this->projectDir.'/extensions/autoload-child');
    }

    public function testItRejectsOverlappingActiveExtensionNamespaces(): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/autoload-parent/src/Marker.php', '<?php');
        $this->writeTestFile($this->projectDir, 'extensions/autoload-child/src/Marker.php', '<?php');
        $this->loader = new ExtensionClassAutoloader($this->projectDir);
        $this->loader->register($this->extension('autoload-parent', 'SharedExtension'));

        $this->expectException(MessageException::class);

        $this->loader->register($this->extension('autoload-child', 'SharedExtension\\Feature'));
    }

    private function extension(string $name, string $namespace): Extension
    {
        return new Extension(
            match ($name) {
                'autoload-child' => '10000000-0000-7000-8000-000000000714',
                default => '10000000-0000-7000-8000-000000000713',
            },
            [ExtensionScope::Module],
            $name,
            'extensions/'.$name,
            ExtensionStatus::Active,
            ['manifest' => ['EXTENSION_NAMESPACE' => $namespace]],
        );
    }
}
