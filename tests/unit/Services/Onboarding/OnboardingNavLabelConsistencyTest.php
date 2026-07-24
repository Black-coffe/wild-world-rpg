<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Onboarding;

use App\Services\Onboarding\ColdOpenSignalService;
use App\Services\Onboarding\NewbieAtmosphereService;
use App\Services\Onboarding\NewbieGreeterService;
use App\Services\Player\TipService;
use App\Services\Telegram\BotMenuService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Анти-дрейф: онбординг-подсказки называют кнопки нижнего меню ТОЛЬКО из единого
 * источника ({@see BotMenuService::worldButtonLabel()}), не хардкодом.
 *
 * Триггерный инцидент 2026-07-24: три текста («Сигнал в эфире», «Ты здесь не один»,
 * «Выносливость на исходе») звали «Открой «🗺 Карту»», но после ADR-150 (final_grid
 * ON на проде) нижнее меню — `[🌍 Мир · 🧑 Я · 🔨 Крафт]`, и кнопки с таким названием
 * там НЕТ. Новичок читал инструкцию и искал кнопку, которой не существует — ровно тот
 * класс «tip обещает путь, которого нет», против которого заведено правило
 * UX-Discoverability. Хардкод метки в тексте = отложенная ложь при следующей смене
 * каркаса меню; поэтому запрещаем сам литерал, а не конкретную формулировку.
 *
 * @internal
 */
final class OnboardingNavLabelConsistencyTest extends CIUnitTestCase
{
    /**
     * Литералы меток, которые НЕЛЬЗЯ хардкодить в player-facing текстах: либо кнопка
     * так больше не подписана (ADR-150), либо подпись зависит от killswitch.
     */
    private const STALE_LABELS = ['«🗺 Карту»', '«🗺 Карта»', '«Перс»', '«Карта»', '«Крафт»', '«База»'];

    /** Группы меню, кнопки которых присутствуют в клавиатуре при любом каркасе. */
    private const ALWAYS_PRESENT_GROUPS = ['world', 'me', 'base', 'craft', 'more'];

    /** Кнопка, которую называем, обязана существовать в реальном нижнем меню. */
    public function testWorldLabelIsActuallyInTheReplyKeyboard(): void
    {
        $label = BotMenuService::worldButtonLabel();

        $this->assertNotSame('', trim($label));
        $this->assertStringContainsString($label, BotMenuService::replyButtonsLine());
    }

    /** То же для КАЖДОЙ группы: метка непустая и реально стоит на клавиатуре. */
    public function testEveryMenuLabelExistsOnTheKeyboard(): void
    {
        $line = BotMenuService::replyButtonsLine();

        foreach (self::ALWAYS_PRESENT_GROUPS as $group) {
            $label = BotMenuService::menuLabel($group);

            $this->assertNotSame('', trim($label), "пустая метка группы {$group}");
            $this->assertStringContainsString(
                $label,
                $line,
                "метка «{$label}» (группа {$group}) не найдена в нижнем меню: {$line}"
            );
        }
    }

    /** Неизвестная группа не выдумывает название кнопки. */
    public function testUnknownGroupYieldsEmptyLabel(): void
    {
        $this->assertSame('', BotMenuService::menuLabel('nope'));
    }

    /**
     * 🔴 Ни один онбординг-сервис не хардкодит устаревшую метку кнопки.
     * Скан исходников — потому что дрейф происходит именно в литералах копии.
     */
    public function testOnboardingTextsDoNotHardcodeStaleMenuLabel(): void
    {
        $dir   = APPPATH . 'Services/Onboarding';
        $files = glob($dir . '/*.php') ?: [];

        $this->assertNotEmpty($files, 'каталог онбординг-сервисов не найден');

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);
            // Комментарии описывают сам инцидент и могут упоминать метку — режем их.
            $code = (string) preg_replace('~^\s*(//|\*|/\*).*$~mu', '', $source);

            foreach (self::STALE_LABELS as $stale) {
                $this->assertStringNotContainsString(
                    $stale,
                    $code,
                    basename($file) . ": метка {$stale} захардкожена — после ADR-150 такой кнопки"
                        . ' в нижнем меню нет. Используй BotMenuService::worldButtonLabel().'
                );
            }
        }
    }

    /** Навигационный хвост сигнала называет живую кнопку меню. */
    public function testSignalNavHintUsesLiveMenuLabel(): void
    {
        $text = (new ColdOpenSignalService())->buildSignalNarrative('к северу', '⬆️');

        $this->assertStringContainsString(BotMenuService::worldButtonLabel(), $text);
        $this->assertStringContainsString('нижнем меню', $text);
        $this->assertSame(0, substr_count($text, '*') % 2, 'непарные *');
        $this->assertSame(0, substr_count($text, '_') % 2, 'непарные _');
    }

    /** Тот же инвариант у встречающего. */
    public function testGreeterNavHintUsesLiveMenuLabel(): void
    {
        $text = (new NewbieGreeterService())->buildGreeterNarrative('к северу', '⬆️');

        $this->assertStringContainsString(BotMenuService::worldButtonLabel(), $text);
        $this->assertStringContainsString('Незнакомец', $text);
        $this->assertSame(0, substr_count($text, '_') % 2, 'непарные _');
    }

    /** И у JIT-подсказки про воду. */
    public function testThirstHintUsesLiveMenuLabel(): void
    {
        $text = NewbieAtmosphereService::thirstText();

        $this->assertStringContainsString(BotMenuService::worldButtonLabel(), $text);
        $this->assertSame(0, substr_count($text, '_') % 2, 'непарные _');
    }

    /**
     * В режиме одного окна навигационный хвост выключается: кнопка входа в мир стоит
     * рядом, вторая инструкция иначе спорит с ней названием.
     */
    public function testNavHintIsOmittedForSingleScreenSections(): void
    {
        $signal  = (new ColdOpenSignalService())->buildSignalNarrative('к северу', '⬆️', false);
        $greeter = (new NewbieGreeterService())->buildGreeterNarrative('к северу', '⬆️', false);

        $this->assertStringNotContainsString('нижнем меню', $signal);
        $this->assertStringNotContainsString('нижнем меню', $greeter);

        // Смысл секции при этом не теряется (media-off: текст самодостаточен).
        $this->assertStringContainsString('🎯', $signal);
        $this->assertStringContainsString('Незнакомец', $greeter);
        $this->assertSame(0, substr_count($signal, '_') % 2, 'непарные _ в сигнале');
        $this->assertSame(0, substr_count($greeter, '_') % 2, 'непарные _ у встречающего');
    }

    /**
     * Советы лежат строками в БД и кодом не лечатся — поэтому они называют кнопки
     * плейсхолдером `{{menu:<группа>}}`, который подставляется в момент показа.
     */
    public function testTipPlaceholdersResolveToLiveLabels(): void
    {
        $rendered = TipService::applyMenuLabels('Ищи в «{{menu:me}}» → «⚔️ Экип», а карта — «{{menu:world}}».');

        $this->assertStringContainsString(BotMenuService::menuLabel('me'), $rendered);
        $this->assertStringContainsString(BotMenuService::menuLabel('world'), $rendered);
        $this->assertStringNotContainsString('{{menu:', $rendered);
    }

    /** Чужой плейсхолдер остаётся видимым, а не превращается в пустое место в тексте игроку. */
    public function testUnknownPlaceholderIsLeftIntact(): void
    {
        $this->assertSame('жми {{menu:nope}}', TipService::applyMenuLabels('жми {{menu:nope}}'));
    }

    /** Миграция-починка данных использует ТОЛЬКО известные плейсхолдеры. */
    public function testDataFixMigrationUsesKnownPlaceholdersOnly(): void
    {
        $file = APPPATH . 'Database/Migrations/2026-10-27-100000_FixStaleMenuLabelsInPlayerTexts.php';
        $this->assertFileExists($file);

        preg_match_all('/\{\{menu:([a-z]+)\}\}/', (string) file_get_contents($file), $m);
        $this->assertNotEmpty($m[1], 'миграция обязана переводить советы на плейсхолдеры');

        foreach (array_unique($m[1]) as $group) {
            $this->assertNotSame('', BotMenuService::menuLabel($group), "неизвестная группа {$group}");
        }
    }

    /** StartCommand обязан гасить хвосты именно в режиме одного окна. */
    public function testStartCommandPassesSingleScreenContextToSections(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Controllers/Telegram/Commands/StartCommand.php');

        $this->assertMatchesRegularExpression(
            '/placeBaitForNewChar\(.*?!\s*\$singleScreen/s',
            $source,
            'приманка должна получать контекст одного окна'
        );
        $this->assertMatchesRegularExpression(
            '/placeGreeterForNewChar\(.*?!\s*\$singleScreen/s',
            $source,
            'встречающий должен получать контекст одного окна'
        );
    }
}
