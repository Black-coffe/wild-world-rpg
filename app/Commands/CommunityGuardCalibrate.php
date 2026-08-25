<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\GameTipsModel;
use App\Services\Community\CommunityGuard;
use App\Services\Onboarding\GuideCatalog;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\CLI\Commands;
use Psr\Log\LoggerInterface;

/**
 * ADR-177 §5, story 66 — замер рубежей `CommunityGuard` на живом корпусе прода, а не
 * на зафиксированном эталоне (это делает `CommunityGuardTest::testCalibrationMeasures…`,
 * story 65). Живой корпус растёт с каждым разделом `/guide` и каждым `game_tips` — эта
 * команда отвечает на вопрос «что рубежи делают с составом корпуса СЕГОДНЯ», владелец
 * запускает её вручную по SSH после правки контента.
 *
 * 🔴 Читать вместе `ADR-177` и `ADR-178` (обе поправки в конце ADR-178). После ADR-178
 * провенанс (рубеж 1) лишён права вето — он собирает пометки (`advisories`), а не
 * отклоняет. «Ложный отказ по провенансу» — величина, которая перестала существовать.
 * Команда меряет ДВЕ другие вещи вместо неё:
 *  - работу рубежей формы и лексики (рубеж 1 §2 `comparative_claim`, рубеж 3
 *    `lexical_stoplist`, рубеж 4 стоп-темы, рубеж 5 килсвитчи) — каждый фрагмент
 *    живого корпуса прогоняется через `CommunityGuard::verdict()` как если бы это был
 *    предлагаемый ответ, и тальируется причина отказа;
 *  - шумность пометки рубежа 1 — доля НЕ отклонённых фрагментов, получивших `advisory`
 *    (стоимость внимания владельца, не ошибка), и отдельно — сколько фрагментов не
 *    получили пометки вовсе (по инварианту ADR-178: пометка есть → сомнительно,
 *    пометки нет → не значит ничего — это надо доказывать числом на каждом запуске,
 *    а не декларировать).
 *
 * Команда не дублирует логику гварда — она зовёт `CommunityGuard::verdict()` (сервис)
 * на каждом фрагменте живого корпуса `GuideCatalog::sections()` + `GameTipsModel`
 * (те же два обязательных источника, что `CommunityGuard::defaultCorpus()` собирает
 * сама — читаются здесь отдельно, чтобы посчитать состав корпуса ДО прогона и иметь
 * право отказаться считать).
 *
 * 🔴 Единица замера — ПРЕДЛОЖЕНИЕ, не документ (правка после первого прогона — целый
 * раздел `/guide` почти всегда содержит хоть одно число, лексический рубеж 3 резал
 * 63% документов, и эта цифра отвечала на вопрос, которого никто не задавал: «можно
 * ли пересказать бота целым разделом одним ответом»). Единица провенанса после
 * ADR-177 — предложение источника, PHPUnit-калибровка (story 65) на добросовестной
 * стороне судит именно предложения-кандидаты — этот замер обязан считать той же
 * единицей, иначе числа двух замеров несравнимы. Документы (разделы/советы) режутся
 * на предложения простым текстовым разбиением (не вызовом приватного метода гварда —
 * сегментация текста не бизнес-логика рубежей), каждое предложение прогоняется через
 * `verdict()` по отдельности.
 *
 * 🔴 Отказ считать (главное требование story): если обязательный источник пуст (ни
 * одного раздела `/guide` ИЛИ ни одного `game_tips`), команда печатает «замер
 * невозможен» с именем источника и возвращает ненулевой код — молчаливый замер на
 * суженном корпусе дважды пропустил BLOCK (ADR-177 §5, история story 65).
 *
 * Только читает — ни `GuideCatalog`, ни `game_tips`, ни `CommunityGuard::verdict()`
 * ничего не пишут в БД (провенанс на самом гварде тоже read-only).
 *
 * Запуск: php spark --no-header community:guard-calibrate (`--no-header` — канонично,
 * команда идёт по SSH на preprod/прод, framework-баннер CI4 ломает разбор на другом
 * конце).
 */
final class CommunityGuardCalibrate extends BaseCommand
{
    protected $group       = 'Community';
    protected $name        = 'community:guard-calibrate';
    protected $description = 'Замер CommunityGuard на живом корпусе прода: разбивка по причинам отказа + шумность пометки провенанса (ADR-177 §5, ADR-178).';
    protected $usage       = 'community:guard-calibrate';

    private GuideSectionsReader $guideSections;

    /**
     * `$logger`/`$commands` — стандартная DI-пара `BaseCommand`, позиционно (см.
     * `CommunityExport` — то же обоснование порядка, `Commands::discoverCommands()`
     * инстанцирует ровно двумя аргументами). `$guideSections` — тестовая точка
     * подмены `GuideCatalog::sections()`, чтобы юнит-тест не зависел от живого
     * `BotMenuService`/`GameSettingsService` внутри каталога.
     */
    public function __construct(
        ?LoggerInterface $logger = null,
        ?Commands $commands = null,
        ?GuideSectionsReader $guideSections = null,
    ) {
        parent::__construct($logger ?? \Config\Services::logger(), $commands ?? \Config\Services::commands());
        $this->guideSections = $guideSections ?? new class implements GuideSectionsReader {
            public function sections(): array
            {
                return GuideCatalog::sections();
            }
        };
    }

    public function run(array $params): int
    {
        // Правка после ревью team-lead: замер единицей-предложением гоняет `verdict()` ~800+ раз
        // подряд на одном и том же `GameSettingsModel`-инстансе внутри `GameSettingsService`.
        // Локализованный механизм (не `BaseConnection::$saveQueries` — такого свойства в этой
        // версии фреймворка нет, первая версия правки ошибочно назвала не то): если `get()`
        // словит исключение ДО того, как `Model::first()` штатно сбросит состояние построителя
        // запроса (напр. таблица `game_settings` недоступна/не мигрирована — так на этой машине
        // локально), builder следующего вызова накапливает `where()` поверх непойманного
        // предыдущего — задокументированный CI4-квирк, memory `feedback_ci4_model_builder_state_quirk`
        // (обычно ловится на `foreach` с переиспользуемым Model-инстансом). Дефолтный `php.ini`
        // (512M) на этом упирается около 700-й итерации. НЕ трогаем ни `CommunityGuard.php`, ни
        // `GameSettingsService`/`GameSettingsModel` (оба вне `## Files` этой story) — поднимаем
        // лимит локально для этой команды и печатаем это явно (SSH-инструмент на чужой машине не
        // должен молча трогать чужой лимит): если унаследованного `php.ini`-лимита не хватит на
        // прод/preprod (там `game_settings` мигрирована, механизм может не воспроизвестись вовсе
        // — см. `## Findings` story), явная строка в stderr — единственная зацепка для диагностики.
        $inheritedLimit = ini_get('memory_limit');
        $inheritedMb    = (int) $inheritedLimit;
        if ($inheritedMb > 0 && $inheritedMb < 1024) {
            ini_set('memory_limit', '1024M');
            fwrite(STDERR, sprintf(
                "community:guard-calibrate: memory_limit поднят с %s до 1024M (замер прогоняет verdict() на каждом предложении живого корпуса — см. tech-writing ноту).\n",
                $inheritedLimit,
            ));
        }

        try {
            $guideSections = $this->guideSections->sections();
        } catch (\Throwable $e) {
            CLI::error('community:guard-calibrate: замер невозможен — источник «/guide» недоступен (' . $e->getMessage() . ').');

            return EXIT_ERROR;
        }

        if ($guideSections === []) {
            CLI::error('community:guard-calibrate: замер невозможен — источник «/guide» пуст (0 разделов GuideCatalog::sections()).');

            return EXIT_ERROR;
        }

        try {
            $tips = (new GameTipsModel())->findAll();
        } catch (\Throwable $e) {
            CLI::error('community:guard-calibrate: замер невозможен — источник «game_tips» недоступен (' . $e->getMessage() . ').');

            return EXIT_ERROR;
        }

        if ($tips === []) {
            CLI::error('community:guard-calibrate: замер невозможен — источник «game_tips» пуст (0 строк).');

            return EXIT_ERROR;
        }

        $documents = $this->buildDocuments($guideSections, $tips);
        $units     = $this->sentenceUnitsOf($documents);

        CLI::write('=== Состав корпуса ===');
        CLI::write(sprintf('/guide разделов: %d', count($guideSections)));
        CLI::write(sprintf('game_tips советов: %d', count($tips)));
        CLI::write(sprintf('документов всего: %d', count($documents)));
        CLI::write(sprintf('предложений (юниты замера): %d', count($units)));
        CLI::newLine();

        $guard = new CommunityGuard();

        /** @var array<string, int> $reasonCounts */
        $reasonCounts = [];
        $allowCount   = 0;
        $advisoryHits = 0;
        $clearHits    = 0;

        foreach ($units as $unit) {
            $verdict = $guard->verdict($unit['text'], '', null);

            if (! $verdict->isAllow()) {
                $reasonCounts[$verdict->reason] = ($reasonCounts[$verdict->reason] ?? 0) + 1;
                continue;
            }

            $allowCount++;
            if ($verdict->advisories !== []) {
                $advisoryHits++;
            } else {
                $clearHits++;
            }
        }

        $total = count($units);

        CLI::write('=== Разбивка по причинам отказа (рубежи формы и лексики) ===');
        CLI::write('Единица замера — предложение источника. Вопрос к каждому: мог ли бот произнести');
        CLI::write('его как готовый ответ? Это НЕ доля брака корпуса.');
        if ($reasonCounts === []) {
            CLI::write('(ни одно предложение корпуса не было отклонено)');
        } else {
            ksort($reasonCounts);
            foreach ($reasonCounts as $reason => $count) {
                $note = match (true) {
                    $reason === 'lexical_stoplist' => ' — рубеж 3 работает как задуман: предложение с числом/лексикой баланса ОБЯЗАНО отклоняться, это не брак.',
                    $reason === 'comparative_claim' => ' — тревожный сигнал: рост означает сравнительное обещание в справочнике, лечится правкой источника, не правила.',
                    default => '',
                };
                CLI::write(sprintf('%s: %d (%.1f%%)%s', $reason, $count, self::percentOf($count, $total), $note));
            }
        }
        CLI::newLine();

        CLI::write('=== Шумность пометки провенанса (рубеж 1, только среди пропущенных) ===');
        CLI::write(sprintf('пропущено (allow): %d из %d (%.1f%%)', $allowCount, $total, self::percentOf($allowCount, $total)));
        CLI::write(sprintf('  с пометкой advisory: %d (%.1f%% от пропущенных)', $advisoryHits, self::percentOf($advisoryHits, $allowCount)));
        CLI::write(sprintf('  без пометки вовсе: %d (%.1f%% от пропущенных)', $clearHits, self::percentOf($clearHits, $allowCount)));
        CLI::newLine();
        CLI::write('Инвариант ADR-178: пометка есть — сомнительно; пометки нет — НЕ значит ничего (провенанс не имеет права вето).');

        return EXIT_SUCCESS;
    }

    /**
     * @param list<array{key:string, group:string, button:string, title:string, body:string}> $guideSections
     * @param list<mixed>                                                                      $tips
     *
     * @return list<array{source: string, text: string}>
     */
    private function buildDocuments(array $guideSections, array $tips): array
    {
        $documents = [];
        foreach ($guideSections as $section) {
            $documents[] = ['source' => 'guide:' . $section['key'], 'text' => $section['title'] . ' ' . $section['body']];
        }

        foreach ($tips as $tip) {
            if (! is_array($tip)) {
                continue;
            }
            $titleEn = is_string($tip['title_en'] ?? null) ? $tip['title_en'] : '?';
            $content = $tip['content'] ?? '';
            $documents[] = ['source' => 'tip:' . $titleEn, 'text' => is_string($content) ? $content : ''];
        }

        return $documents;
    }

    /**
     * Режет каждый документ на предложения — та же единица, что провенанс ADR-177 и
     * добросовестная сторона PHPUnit-калибровки (story 65) судят как «юнит». Простое
     * текстовое разбиение (markdown-декорации сняты, граница — `.!?;:` или перенос
     * строки), не вызов приватного метода `CommunityGuard::sentencesOf()` — сегментация
     * текста не бизнес-логика рубежей, а инвариант «не дублировать логику гварда»
     * (story) относится к решению allow/deny, которое остаётся целиком в `verdict()`.
     *
     * @param list<array{source: string, text: string}> $documents
     *
     * @return list<array{source: string, text: string}>
     */
    private function sentenceUnitsOf(array $documents): array
    {
        $units = [];
        foreach ($documents as $document) {
            $plain = str_replace(['*', '_', '«', '»'], '', $document['text']);
            $parts = preg_split('/(?<=[.!?;:])\s+|\n+/u', $plain) ?: [$plain];

            foreach ($parts as $part) {
                $sentence = trim($part, " \t\n\r\0\x0B.!?;:—-");
                if ($sentence !== '') {
                    $units[] = ['source' => $document['source'], 'text' => $sentence];
                }
            }
        }

        return $units;
    }

    private static function percentOf(int $part, int $total): float
    {
        return $total > 0 ? ($part / $total) * 100.0 : 0.0;
    }
}

/**
 * Тестовая точка подмены источника `/guide` — `GuideCatalog::sections()` статический
 * (читает `BotMenuService`/`GameSettingsService`/`LevelProgressService` внутри), юнит-тест
 * команды не поднимает эти зависимости, поэтому реализация по умолчанию (анонимный класс
 * в конструкторе выше) — единственный живой путь в проде, а тест подставляет свой двойник.
 */
interface GuideSectionsReader
{
    /**
     * @return list<array{key:string, group:string, button:string, title:string, body:string}>
     */
    public function sections(): array;
}
