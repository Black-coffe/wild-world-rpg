<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use App\Controllers\Front;
use CodeIgniter\Test\CIUnitTestCase;
use ReflectionMethod;

/**
 * SEO-инварианты публичного сайта (аудит 2026-07-24, прогон 139 страниц).
 *
 * Три дефекта, найденные прогоном, чинятся приватными хелперами `Front`, и каждый из них
 * уже однажды выстрелил в проде — поэтому закрепляем поведение тестом, а не надеждой:
 *  - сниппет обрывался посреди слова («…проекты без покупок вовсе. Как за»);
 *  - <title> перерастал 65 символов и обрезался в выдаче;
 *  - картинки шли без width/height (CLS), а добавление height к вёрстке из WordPress
 *    растянуло их — это поймал visual-смок на 768px, и повтор ловим здесь.
 *
 * @internal
 */
final class FrontSeoMetaTest extends CIUnitTestCase
{
    private function call(string $method, mixed ...$args): mixed
    {
        $m = new ReflectionMethod(Front::class, $method);
        $m->setAccessible(true);

        return $m->invokeArgs(new Front(), $args);
    }

    /** Описание длиннее лимита обрывается на конце предложения, а не на середине слова. */
    public function testDescriptionCutsAtSentenceEnd(): void
    {
        $raw = 'Разбираем, что скрывается за словом «бесплатно» в играх на выживание — '
            . 'условно-бесплатные с магазином, платные эталоны жанра и проекты без покупок вовсе. '
            . 'Как заработать и не платить.';

        $out = (string) $this->call('metaDescription', $raw);

        $this->assertLessThanOrEqual(160, mb_strlen($out));
        $this->assertStringEndsWith('вовсе.', $out, 'обрыв должен приходиться на конец предложения');
        $this->assertStringNotContainsString('Как за', $out, 'вернулся обрыв посреди фразы');
    }

    /** Нет конца предложения — режем по границе слова и честно ставим многоточие. */
    public function testDescriptionFallsBackToWordBoundary(): void
    {
        $raw = str_repeat('слово ', 60);

        $out = (string) $this->call('metaDescription', $raw);

        $this->assertLessThanOrEqual(160, mb_strlen($out));
        $this->assertStringEndsWith('…', $out);
        $this->assertStringNotContainsString('сло…', $out, 'многоточие не должно резать слово');
    }

    /** Короткий текст не трогаем: многоточие соврало бы о продолжении. */
    public function testShortDescriptionUntouched(): void
    {
        $this->assertSame('Короткое описание.', $this->call('metaDescription', 'Короткое описание.'));
    }

    /** Бренд-суффикс добавляется только когда итог влезает в лимит выдачи. */
    public function testBrandSuffixOnlyWhenItFits(): void
    {
        $short = (string) $this->call('pageTitle', '', 'Игры на выживание на острове');
        $this->assertStringEndsWith(' — Wild World', $short);
        $this->assertLessThanOrEqual(65, mb_strlen($short));

        $long = (string) $this->call('pageTitle', '', str_repeat('длинный ', 10));
        $this->assertStringNotContainsString('Wild World', $long, 'бренд не должен выталкивать за лимит');
    }

    /** Бренд внутри заголовка не задваивается суффиксом. */
    public function testBrandNotDuplicated(): void
    {
        $out = (string) $this->call('pageTitle', 'Крафт в Wild World: рецепты и материалы', 'Крафт');

        $this->assertSame(1, substr_count($out, 'Wild World'), 'бренд задвоился в <title>');
    }

    /** Короткий seo_title вытесняет обычный заголовок. */
    public function testSeoTitleWins(): void
    {
        $out = (string) $this->call('pageTitle', 'Короткий', 'Очень длинный заголовок поста, который не влезает');

        $this->assertStringStartsWith('Короткий', $out);
    }

    /**
     * 🔴 Регресс, пойманный visual-смоком: атрибут height у картинки с инлайновым
     * `style="width:100%"` без `height:auto` растягивает её (681×766 вместо 681×340).
     */
    public function testContentImageGetsHeightAutoAlongsideDimensions(): void
    {
        $file = FCPATH . 'uploads/site/probe-seo-test.png';
        @mkdir(dirname($file), 0777, true);
        $im = imagecreatetruecolor(40, 20);
        imagepng($im, $file);
        imagedestroy($im);

        try {
            $html = '<p>текст</p><img src="/uploads/site/probe-seo-test.png" loading="lazy" style="width:100%">';
            $out  = (string) $this->call('enrichContentImages', $html);

            $this->assertStringContainsString('width="40"', $out);
            $this->assertStringContainsString('height="20"', $out);
            $this->assertStringContainsString('height:auto', $out);
            $this->assertSame(1, substr_count($out, 'style='), 'атрибут style задвоился');
        } finally {
            @unlink($file);
        }
    }

    /** Чужие и уже размеченные картинки не переписываем. */
    public function testForeignAndAlreadySizedImagesUntouched(): void
    {
        $html = '<img src="https://example.com/a.jpg"><img src="/uploads/site/x.jpg" width="10" height="10">';

        $this->assertSame($html, (string) $this->call('enrichContentImages', $html));
    }
}
