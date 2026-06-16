<?php

namespace Tests\Unit\Views;

use CodeIgniter\Test\CIUnitTestCase;
use Config\Services;

/**
 * F2.12 — smoke test что admin partials рендерятся без exception.
 *
 * Проверяет partial'ы в изоляции (они не используют renderSection,
 * только статичный HTML + базовые base_url() / переменные). Тест
 * full-layout рендеринга невозможен через render() напрямую, потому
 * что extend()/renderSection() требуют extend-вызов снаружи.
 *
 * @internal
 */
final class AdminPartialsTest extends CIUnitTestCase
{
    public function testHeadCommonRendersWithTitle(): void
    {
        $view = Services::renderer();
        $view->setData(['title' => 'Test Title']);
        $html = $view->render('admin/partials/_head_common', [], false);

        $this->assertStringContainsString('<title>Test Title</title>', $html);
        $this->assertStringContainsString('app-saas.min.css', $html);
        $this->assertStringContainsString('icons.min.css', $html);
        $this->assertStringContainsString('my.css', $html);
        $this->assertStringContainsString('hyper-config.js', $html);
        // ADR-128: редизайн «Quiet Premium» заменил favicon.ico на компас-фавикон.
        $this->assertStringContainsString('favicon.svg', $html);
    }

    public function testFooterRendersCopyrightBlock(): void
    {
        $view = Services::renderer();
        $html = $view->render('admin/partials/_footer', [], false);

        $this->assertStringContainsString('class="footer"', $html);
        $this->assertStringContainsString('document.write(new Date().getFullYear', $html);
        $this->assertStringContainsString('Andrievskii', $html);
    }

    public function testScriptsCommonRendersBaseScripts(): void
    {
        $view = Services::renderer();
        $html = $view->render('admin/partials/_scripts_common', [], false);

        $this->assertStringContainsString('vendor.min.js', $html);
        $this->assertStringContainsString('app.min.js', $html);
        $this->assertStringContainsString('my.js', $html);
    }

    public function testHeadCommonContainsNoUnresolvedTemplate(): void
    {
        // Если какая-то base_url() выпала или $title undefined,
        // в HTML появятся PHP error / blank slot.
        $view = Services::renderer();
        $view->setData(['title' => 'X']);
        $html = $view->render('admin/partials/_head_common', [], false);

        $this->assertStringNotContainsString('Undefined variable', $html);
        $this->assertStringNotContainsString('<?', $html); // нет unrendered php тэгов
    }
}
