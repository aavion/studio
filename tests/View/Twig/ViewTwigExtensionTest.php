<?php

declare(strict_types=1);

namespace App\Tests\View\Twig;

use App\Core\Manifest\ManifestParser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class ViewTwigExtensionTest extends KernelTestCase
{
    public function testItExposesViewGlobalsFunctionsAndMarkdownFilter(): void
    {
        self::bootKernel();

        $twig = self::getContainer()->get(Environment::class);
        $systemVersion = $this->rootManifestVersion();
        $html = $twig->createTemplate(
            '{{ view_context().system_extension.name }}|{{ macro_template("core", "ui") }}|{{ event_hooks()|length }}|{{ navigation("main")|length }}|{{ debug_info().hooks is defined ? "debug" : "missing" }}|{{ extension_setting("demo-module", "missing.key", "fallback") }}|{{ footer_copyright("backend") }}|{{ "**ok**"|render_markdown }}',
        )->render();

        self::assertSame('Studio|@root/macros/core/ui.html.twig|11|4|debug|fallback|Powered by [Studio](https://www.aavion.media) '.$systemVersion.'|<p><strong>ok</strong></p>', $html);
    }

    public function testItRendersSafeHtmlAttributes(): void
    {
        self::bootKernel();

        $twig = self::getContainer()->get(Environment::class);
        $html = $twig->createTemplate(
            '{{ html_attributes({"class": "is-immutable", "data-action": "save", "aria-expanded": false, "title": "A & B", "maxlength": 120, "pattern": "^/.*$", "onclick": "alert(1)", "style": "display:none", "data-active": true}) }}',
        )->render();

        self::assertSame('class="is-immutable" data-action="save" title="A &amp; B" maxlength="120" pattern="^/.*$" data-active', $html);
    }

    public function testItRendersNativeProviderNamespaceFallbacks(): void
    {
        self::bootKernel();

        $twig = self::getContainer()->get(Environment::class);
        $html = $twig->createTemplate(
            '{% include "@frontend/partials/forms/fields/captcha.html.twig" with {form_id: "comment-form", name: "captcha"} only %}|{% include "@backend/editor/fields/richtext.html.twig" with {name: "body", value: "Hello"} only %}',
        )->render();

        self::assertStringContainsString('name="captcha[provider]" value="none"', $html);
        self::assertStringContainsString('name="captcha[fallback_rendered]" value="1"', $html);
        self::assertStringContainsString('name="captcha[form_id]" value="comment-form"', $html);
        self::assertStringNotContainsString('captcha[status]', $html);
        self::assertStringContainsString('|', $html);
        self::assertStringContainsString('name="body"', $html);
        self::assertStringContainsString('data-controller="code-editor"', $html);
        self::assertStringContainsString('data-code-editor-language-value="markdown"', $html);
        self::assertStringContainsString('Hello', $html);
    }

    public function testItRendersRootCaptchaFieldComponent(): void
    {
        self::bootKernel();

        $twig = self::getContainer()->get(Environment::class);
        $html = $twig->createTemplate(
            '<twig:root:CaptchaField form_id="registration-form" workflow="user.registration" name="captcha" />',
        )->render();

        self::assertStringContainsString('name="captcha[provider]" value="none"', $html);
        self::assertStringContainsString('name="captcha[fallback_rendered]" value="1"', $html);
        self::assertStringContainsString('name="captcha[form_id]" value="registration-form"', $html);
        self::assertStringNotContainsString('captcha[status]', $html);
    }

    public function testItRendersCodemirrorSyntaxProviderAliases(): void
    {
        self::bootKernel();

        $twig = self::getContainer()->get(Environment::class);
        $html = $twig->createTemplate(
            '{% include "@provider/editor/json.html.twig" with {name: "payload", value: "{}", attributes: {id: "json-editor"}} only %}',
        )->render();

        self::assertStringContainsString('name="payload"', $html);
        self::assertStringContainsString('id="json-editor"', $html);
        self::assertStringContainsString('data-code-editor-language-value="json"', $html);
        self::assertStringContainsString('data-code-editor-tab-size-value="2"', $html);
    }

    private function rootManifestVersion(): string
    {
        $projectDir = (string) self::getContainer()->getParameter('kernel.project_dir');
        $result = (new ManifestParser())->parse((string) file_get_contents($projectDir.'/.manifest'));

        self::assertTrue($result->isSuccess(), json_encode($result->toArray(), JSON_THROW_ON_ERROR));

        return (string) $result->value()->get('APP_VERSION');
    }

    public function testItRendersGranularFormAndActionPartials(): void
    {
        self::bootKernel();

        $twig = self::getContainer()->get(Environment::class);
        $html = $twig->createTemplate(
            implode('', [
                '{% include "@frontend/partials/forms/fields/email.html.twig" with {name: "email", label: "Email", value: "a@example.test"} only %}',
                '{% include "@frontend/partials/forms/fields/radio-group.html.twig" with {name: "mode", label: "Mode", value: "draft", options: {draft: "Draft", live: "Live"}} only %}',
                '{% include "@frontend/partials/actions/_button-group.html.twig" with {actions: [{label: "Save", variant: "primary"}, {label: "Cancel", href: "/"}]} only %}',
                '{% include "@backend/partials/forms/fields/select.html.twig" with {name: "status", label: "Status", value: "active", options: {active: "Active", inactive: "Inactive"}} only %}',
                '{% include "@backend/partials/forms/fields/code-editor.html.twig" with {name: "template", label: "Template", value: "{{ title }}", language: "html"} only %}',
            ]),
        )->render();

        self::assertStringContainsString('type="email"', $html);
        self::assertStringContainsString('type="radio"', $html);
        self::assertStringContainsString('system-button-group', $html);
        self::assertStringContainsString('system-backend-select', $html);
        self::assertStringContainsString('data-code-editor-language-value="html"', $html);
    }
}
