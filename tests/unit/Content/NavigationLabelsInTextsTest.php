<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Services\Onboarding\GuideCatalog;
use App\Services\Player\FactionProjectService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * Аудит путей 2026-09-05 — тексты не должны звать кнопки, которых в интерфейсе нет.
 *
 * Класс дефекта вскрыл вопрос игрока про специализацию: подсказка называла путь без хаба
 * «⚙️ Развитие», и фича читалась как несуществующая. Прогон всего контента против индекса
 * реальных кнопок нашёл ещё три подписи-призрака: «Стандартный верстак» (раздел подписан
 * «🔧 Стандартный крафт», а «верстак» — это предмет и тир крафта), «🚁/🏭 Ангар» (живая
 * кнопка — «🤖 Ангар») и «🛠 Крафт» (нижняя кнопка — «🔨 Крафт», и берётся она из
 * {@see \App\Services\Telegram\BotMenuService::menuLabel()}).
 *
 * Гейтим ТЕКСТ, а не поведение: здесь артефакт и есть текст, поэтому скан по нему —
 * настоящее покрытие, а не имитация. Содержимое `game_tips` живёт в БД и лечится
 * миграциями (`FixStaleHubPathsInTips`, `FixStaleCraftAndHangarPaths`) — тестовая база
 * контент-таблиц не содержит, поэтому здесь проверяются каталоги из кода.
 *
 * @internal
 */
final class NavigationLabelsInTextsTest extends CIUnitTestCase
{
    /** Подпись-призрак → чем она должна быть. */
    private const GHOST_LABELS = [
        'Стандартный верстак' => 'раздел подписан «🔧 Стандартный крафт»',
        '🚁 Ангар'            => 'живая кнопка — «🤖 Ангар»',
        '🏭 Ангар'            => 'живая кнопка — «🤖 Ангар»',
        '🛠 Крафт'            => 'нижняя кнопка — «🔨 Крафт» (брать из BotMenuService::menuLabel)',
        '💎 Проект фракции'   => 'канон — «🤝 Проект фракции» (FactionProjectService::BUTTON_LABEL)',
        '🏗 Проект фракции'   => 'канон — «🤝 Проект фракции» (FactionProjectService::BUTTON_LABEL)',
    ];

    /**
     * Одна дверь — одна подпись. У проекта фракции их было четыре («🤝» в хабе Развития и в
     * шапке экрана, «💎» в «⚙️ Ещё» и в оплоте, «🏗» в /guide): игрок ищет кнопку глазами по
     * эмодзи, поэтому разнобой читается как разные фичи. Гейтим то, что все живые точки входа
     * берут подпись из одной константы, а сама она совпадает с шапкой экрана за кнопкой.
     */
    public function testFactionProjectDoorHasOneLabelEverywhere(): void
    {
        $label = FactionProjectService::BUTTON_LABEL;
        $this->assertStringStartsWith('🤝', $label, 'Канон подписи — «🤝»: как у шапки экрана проекта.');

        $renderers = [
            APPPATH . 'Services/Player/ProfileHubService.php',
            APPPATH . 'Services/More/MoreSurfaceService.php',
            APPPATH . 'Controllers/Telegram/Commands/Actions/Settlement/SettlementHubAction.php',
        ];

        foreach ($renderers as $file) {
            $source = (string) file_get_contents($file);
            $this->assertStringContainsString(
                'FactionProjectService::BUTTON_LABEL',
                $source,
                basename($file) . ' обязан брать подпись проекта фракции из константы, а не хардкодить эмодзи.'
            );
            $this->assertStringNotContainsString(
                "'💎 Проект фракции'",
                $source,
                basename($file) . ' всё ещё несёт хардкод «💎 Проект фракции».'
            );
        }
    }

    public function testGuideCatalogNamesOnlyLivingButtons(): void
    {
        foreach (GuideCatalog::sections() as $section) {
            $haystack = $section['title'] . ' ' . $section['body'];
            foreach (self::GHOST_LABELS as $ghost => $truth) {
                $this->assertStringNotContainsString(
                    $ghost,
                    $haystack,
                    "Раздел /guide «{$section['key']}» зовёт кнопку «{$ghost}», которой нет: {$truth}."
                );
            }
        }
    }

    /**
     * Одна дверь — одно имя (аудит подписей 2026-09-05).
     *
     * Обратный индекс `callback_data → подписи` по всему `app/` вскрыл 52 двери с разнобоем.
     * Часть из него законна: «💰 Продать ещё», «🔄 Крафтить ещё», «🧭 Идти дальше» — это одно
     * действие в разном контексте. Незаконна вторая часть: у ДВЕРИ (кнопки, ведущей на экран
     * фичи) имя и эмодзи обязаны совпадать везде, иначе игрок ищет глазами и не узнаёт.
     *
     * Гейтим поимённо те двери, что уже сведены, — регресс вернёт разнобой молча.
     *
     * @return list<array{0:string,1:string}>
     */
    public static function unifiedDoors(): array
    {
        return [
            ['events', '🎉 События'],
            ['baseStorageList', '📦 Склад базы'],
            ['cargoDroneList', '🚚 Карго-дрон'],
            ['combatDroneList', '🛡 Боевой дрон'],
            ['droneScoutList', '🚁 Мои дроны'],
            ['pvpLadder', '🏆 Рейтинг PvP'],
            ['repairToolsList', '🪛 Ремонт инструментов'],
            ['sellCraft', '💰 Продать крафт'],
            ['PersonalInsurance', '🧍 Страховка'],
            ['craftInsuranceList', '📦 Крафт-страховка'],
            ['character', '◀️ Я'],
        ];
    }

    /**
     * @dataProvider unifiedDoors
     */
    public function testDoorWearsOneNameEverywhere(string $callback, string $canonical): void
    {
        $labels = [];
        foreach ($this->buttonPairs() as [$label, $cb]) {
            if ($cb !== $callback) {
                continue;
            }
            // Контекстные возвраты («◀️ Назад», «🔄 Обновить») дверью не считаются.
            if (preg_match('~(Назад|назад|К списку|⬅️|🔙|Отмена|Обновить|ещё|Ещё|Завершить игру|Пройти мимо)~u', $label) === 1) {
                continue;
            }
            $labels[$label] = true;
        }

        $this->assertNotSame([], $labels, "Дверь «{$callback}» не нашлась ни на одной кнопке — тест устарел.");
        $this->assertSame(
            [$canonical],
            array_keys($labels),
            "Дверь «{$callback}» носит разные имена. Канон — «{$canonical}» (имя экрана за кнопкой)."
        );
    }

    /**
     * Все пары «подпись → callback» из `app/`: то же извлечение, что дало индекс живых
     * кнопок при аудите. Читаем исходники, а не рантайм: кнопки собираются в 300+ местах.
     *
     * @return list<array{0:string,1:string}>
     */
    private function buttonPairs(): array
    {
        $pairs    = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(APPPATH));
        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            foreach (["'", '"'] as $q) {
                $re = '~' . $q . 'text' . $q . '\s*=>\s*' . $q . '([^' . $q . ']{1,80})' . $q
                    . '\s*,\s*' . $q . 'callback_data' . $q . '\s*=>\s*' . $q . '([^' . $q . ']{1,80})' . $q . '~u';
                if (preg_match_all($re, $source, $m, PREG_SET_ORDER) > 0) {
                    foreach ($m as $one) {
                        $pairs[] = [$one[1], $one[2]];
                    }
                }
            }
        }

        return $pairs;
    }

    /**
     * Каталоги подсказок и экраны читаем как файлы: подсказка собирается из кусков и
     * плейсхолдеров, поэтому строковый скан ловит призрак там, где его не видно в рантайме.
     */
    public function testPlayerFacingCatalogsNameOnlyLivingButtons(): void
    {
        $files = [
            APPPATH . 'Services/Onboarding/OnboardingHintCatalog.php',
            APPPATH . 'Services/Onboarding/GuideCatalog.php',
            APPPATH . 'Controllers/Telegram/Commands/Actions/Camp/HangarAction.php',
            APPPATH . 'Controllers/Telegram/Commands/Actions/Drone/CargoDroneLockedAction.php',
        ];

        foreach ($files as $file) {
            $this->assertFileExists($file);
            $source = (string) file_get_contents($file);
            // Комментарии-пояснения про сам дефект законны — режем их, оставляя код и строки.
            $source = (string) preg_replace('~^\s*(//|\*|/\*).*$~mu', '', $source);

            foreach (self::GHOST_LABELS as $ghost => $truth) {
                $this->assertStringNotContainsString(
                    $ghost,
                    $source,
                    basename($file) . " зовёт кнопку «{$ghost}», которой нет: {$truth}."
                );
            }
        }
    }
}
