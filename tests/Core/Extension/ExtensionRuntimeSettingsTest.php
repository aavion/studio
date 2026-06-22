<?php

declare(strict_types=1);

namespace App\Tests\Core\Extension;

use App\Core\Config\ConfigValueType;
use App\Core\Extension\ExtensionRuntime;
use App\Core\Extension\ExtensionRuntimeServices;
use App\Core\Extension\Settings\ExtensionSettings;
use App\Tests\Support\FilesystemTestHelper;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class ExtensionRuntimeSettingsTest extends TestCase
{
    use FilesystemTestHelper;

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = dirname(__DIR__, 3);
        $this->removeDirectory($this->projectDir.'/extensions/settings-facade-a');
        $this->removeDirectory($this->projectDir.'/extensions/settings-facade-b');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->projectDir.'/extensions/settings-facade-a');
        $this->removeDirectory($this->projectDir.'/extensions/settings-facade-b');
        ExtensionRuntime::reset();
    }

    public function testItReadsOnlyCallingExtensionSettings(): void
    {
        $settings = new ExtensionSettings($this->connection());
        $settings->set('settings-facade-a', 'display.mode', 'compact', ConfigValueType::String);
        $settings->set('settings-facade-b', 'display.mode', 'comfortable', ConfigValueType::String);
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, settings: $settings));

        $this->writeExtensionFile('settings-facade-a', <<<'PHP'
            <?php

            return extension_settings_get('display.mode', 'missing');
            PHP);
        $this->writeExtensionFile('settings-facade-b', <<<'PHP'
            <?php

            return extension_settings_get('display.mode', 'missing');
            PHP);

        self::assertSame('compact', require $this->projectDir.'/extensions/settings-facade-a/extension.php');
        self::assertSame('comfortable', require $this->projectDir.'/extensions/settings-facade-b/extension.php');
    }

    public function testItReturnsDefaultsForInvalidKeysAndNonExtensionCallers(): void
    {
        ExtensionRuntime::configure(new ExtensionRuntimeServices($this->projectDir, settings: new ExtensionSettings($this->connection())));

        self::assertSame('default', ExtensionRuntime::settingsGet('display.mode', 'default'));

        $this->writeExtensionFile('settings-facade-a', <<<'PHP'
            <?php

            return extension_settings_get('../escape', 'default');
            PHP);

        self::assertSame('default', require $this->projectDir.'/extensions/settings-facade-a/extension.php');
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE extension_setting_entry (extension_name VARCHAR(120) NOT NULL, setting_key VARCHAR(160) NOT NULL, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, metadata CLOB NOT NULL, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL, PRIMARY KEY (extension_name, setting_key))');

        return $connection;
    }

    private function writeExtensionFile(string $extension, string $contents): void
    {
        $this->writeTestFile($this->projectDir, 'extensions/'.$extension.'/extension.php', $contents);
    }
}
