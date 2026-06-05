<?php

declare(strict_types=1);

namespace App\Tests\Localization;

use App\Content\Routing\ContentRouteLocalization;
use App\Core\Config\Config;
use App\Core\Config\ConfigValueType;
use App\Entity\UserAccount;
use App\Localization\LocalePreferenceResolver;
use App\Localization\TranslationLanguageCatalog;
use App\Tests\Support\FilesystemTestHelper;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;

final class LocalePreferenceResolverTest extends TestCase
{
    use FilesystemTestHelper;

    public function testItUsesDynamicallyAvailableLanguagesWithoutCodeChanges(): void
    {
        $root = $this->temporaryLanguageRoot(['en', 'de', 'fr']);
        $resolver = new LocalePreferenceResolver($this->localization($root, 'en'));
        $user = new UserAccount(
            '77777777-7777-7777-8777-777777777780',
            'dynamiclocale',
            'dynamic-locale@example.test',
            'hash',
            settings: ['language' => 'fr_FR'],
        );

        self::assertSame('fr', $resolver->resolveRequestLocale(null, $user, null));
        self::assertSame('fr', $resolver->resolveMailLocale($user));
        self::assertSame('fr', $resolver->resolveProfileLocale($user));
    }

    public function testItKeepsUrlLocalesStrictBeforeLenientUserFallback(): void
    {
        $root = $this->temporaryLanguageRoot(['en', 'de']);
        $resolver = new LocalePreferenceResolver($this->localization($root, 'en', routePrefixesEnabled: true));
        $user = new UserAccount(
            '77777777-7777-7777-8777-777777777781',
            'localeuser',
            'locale@example.test',
            'hash',
            settings: ['language' => 'de_DE'],
        );

        self::assertSame('de', $resolver->resolveRequestLocale('fr', $user, null));
        self::assertSame('de', $resolver->resolveRequestLocale('de', $user, null));
    }

    public function testItResolvesMailLocaleFromUserRequestAndDefaultCandidates(): void
    {
        $root = $this->temporaryLanguageRoot(['en', 'de']);
        $resolver = new LocalePreferenceResolver($this->localization($root, 'de'));
        $user = new UserAccount(
            '77777777-7777-7777-8777-777777777782',
            'mailuser',
            'mail@example.test',
            'hash',
            settings: ['language' => 'fr'],
        );

        self::assertSame('en', $resolver->resolveMailLocale($user, 'en_US'));
        self::assertSame('de', $resolver->resolveMailLocale($user));
    }

    /**
     * @param list<string> $languages
     */
    private function temporaryLanguageRoot(array $languages): string
    {
        $root = $this->createTemporaryDirectory('locale-preference');

        foreach ($languages as $language) {
            $directory = $root.'/translations/languages/'.$language;
            mkdir($directory, 0777, true);
            file_put_contents($directory.'/message.yaml', "message:\n  test: test\n");
        }

        return $root;
    }

    private function localization(string $projectDir, string $defaultLanguage, bool $routePrefixesEnabled = false): ContentRouteLocalization
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE config_entry (config_key VARCHAR(160) NOT NULL PRIMARY KEY, value CLOB NOT NULL, value_type VARCHAR(32) NOT NULL, sensitive BOOLEAN NOT NULL DEFAULT 0, modified_at DATETIME DEFAULT NULL, modified_by VARCHAR(180) DEFAULT NULL)');
        $config = new Config($connection);
        $config->set(ContentRouteLocalization::DEFAULT_LANGUAGE_KEY, $defaultLanguage, ConfigValueType::String);
        $config->set(ContentRouteLocalization::ENABLED_KEY, $routePrefixesEnabled, ConfigValueType::Boolean);

        return new ContentRouteLocalization($config, new TranslationLanguageCatalog($projectDir));
    }
}
