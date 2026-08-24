<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Services\Display\MarkdownSafe;
use App\Services\GameSettings\GameSettingsService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\ResultInterface;
use Config\Database;

/**
 * Story chat-requests-batch-06 — экран «🧾 Куда ушло» (Ivan Divan «лога движения средств
 * тоже нету и не понятно нихера»; Max Syskov «У меня исчезло 50% ресурсов…»).
 *
 * Сшивает ДВА уже существующих источника форензики в одну ленту, отсортированную по
 * времени (свежее сверху):
 *  - `action_log` — только economy-коды (story 05: `TAX_BUILDINGS`, `TAX_BEACONS`,
 *    `DEATH_RESPAWN`; story 11: `DEATH_LOSS` с составом потерь; плюс уже жившие
 *    `BUY_RESOURCE`/`SELL_RESOURCE`/`BULK_SELL`). `description` показывается КАК ЕСТЬ —
 *    её не парсим: у налога/смерти он уже человекочитаем, будущие story обогащают его
 *    без правок здесь.
 *  - `event_effects_log` — мировые события (`effect_details` JSON: `gold_delta`,
 *    `log_summary`, `magnitude`), джойн на `events.name` для читаемого имени.
 *
 * Тест-сим: 2-й/3-й аргумент конструктора — оверрайды GameSettings и/или готовое
 * DB-соединение — тот же паттерн, что `VehicleEffectsService` (unit-тест без БД для
 * `depth()`, с БД — для `entries()`).
 */
final class LedgerService
{
    /**
     * Лента показывает только эти коды `action_log.action_name` — экономика персонажа.
     *
     * Ревью-находка (после первого прохода): whitelist покрывал 5 из 7 категорий Goal
     * («налог, мировое событие, продажа, покупка, крафт, склад, смерть»). Добавлены
     * все РЕАЛЬНО пишущиеся `action_status='Completed'` коды, меняющие золото/ресурсы:
     * снос здания за неуплату, склад базы (сдал/забрал), докупка нехватки под крафт,
     * азартные игры, откуп, дрон-ремонт, карго-дрон, продажа снаряжения. Полный список
     * Descoped-исключений и почему — `## Descoped` в story chat-requests-batch-06.
     */
    private const ICONS = [
        'TAX_BUILDINGS'            => '🏛️',
        'TAX_BEACONS'              => '🏛️',
        'TAX_BUILDING_DESTROYED'   => '🏚️',
        'DEATH_RESPAWN'            => '💀',
        'DEATH_LOSS'               => '💀',
        'BUY_RESOURCE'             => '🛍️',
        'SELL_RESOURCE'            => '💰',
        'BULK_SELL'                => '💰',
        'SELL_GEAR'                => '🗡️',
        'BASE_STORAGE_DEPOSIT'     => '📦',
        'BASE_STORAGE_DEPOSIT_ALL' => '📦',
        'BASE_STORAGE_RETRIEVE_ONE' => '📤',
        'CRAFT_SHORTFALL_BUY'      => '🔨',
        'SHUFFLE_RESOURCES'        => '🔀',
        'GAMBLE_WHEEL'             => '🎡',
        'GAMBLE_GUESS'             => '🎲',
        'ORACLE_BET'               => '🔮',
        'TRIBUTE_BUYOUT'           => '🎗️',
        'DRONE_REPAIR_RUN'         => '🔧',
        'DRONE_CARGO_SEND'         => '🚚',
    ];

    /**
     * У этих кодов `description` — служебная строка (`res=12 qty=3 gold=-45` или
     * `rarity=3 -10 Дерево +7 Вода burned=3`), не фраза. Метка перед ней хотя бы
     * называет категорию — контент внутри всё равно не трогаем (не парсим, не
     * переписываем; полная человекочитаемость этих кодов — кандидат на отдельную
     * story, по образцу chat-requests-batch-12). У кодов БЕЗ записи здесь
     * (налог/смерть/продажа снаряжения/снос здания) `description` уже полная фраза,
     * метка перед ней была бы дублем («Налог: Налог за 3 зданий…»).
     */
    private const RAW_LABELS = [
        'BUY_RESOURCE'              => 'Покупка',
        'SELL_RESOURCE'             => 'Продажа',
        'BULK_SELL'                 => 'Продажа (опт)',
        'BASE_STORAGE_DEPOSIT'      => 'Сдал на склад',
        'BASE_STORAGE_DEPOSIT_ALL'  => 'Сдал всё на склад',
        'BASE_STORAGE_RETRIEVE_ONE' => 'Забрал со склада',
        'CRAFT_SHORTFALL_BUY'       => 'Докупка под крафт',
        'SHUFFLE_RESOURCES'         => 'Пересыпка',
        'GAMBLE_WHEEL'              => 'Колесо фортуны',
        'GAMBLE_GUESS'              => 'Угадай число',
        'ORACLE_BET'                => 'Ставка у Оракула',
        'TRIBUTE_BUYOUT'            => 'Откуп',
        'DRONE_REPAIR_RUN'          => 'Ремонт дронов',
        'DRONE_CARGO_SEND'          => 'Карго-дрон',
    ];

    private const DEFAULT_DEPTH = 15;

    private ?GameSettingsService $settings;

    /** @var array<string, mixed>|null тест-оверрайды GameSettings (минуя БД). */
    private ?array $overrides;

    /** @var BaseConnection<object, object>|null */
    private ?BaseConnection $db;

    /**
     * @param array<string, mixed>|null $overrides
     * @param BaseConnection<object, object>|null $db
     */
    public function __construct(?GameSettingsService $settings = null, ?array $overrides = null, ?BaseConnection $db = null)
    {
        $this->overrides = $overrides;
        $this->settings  = $overrides === null ? ($settings ?? new GameSettingsService()) : null;
        $this->db        = $db;
    }

    /** Глубина ленты — сколько последних записей показываем. */
    public function depth(): int
    {
        if ($this->overrides !== null) {
            $raw = $this->overrides['economy.ledger.depth'] ?? self::DEFAULT_DEPTH;

            return is_numeric($raw) ? max(1, (int) $raw) : self::DEFAULT_DEPTH;
        }

        $raw = ($this->settings ?? new GameSettingsService())->get('economy.ledger.depth', self::DEFAULT_DEPTH);

        return is_numeric($raw) ? max(1, (int) $raw) : self::DEFAULT_DEPTH;
    }

    /**
     * Ревью-находка: были ли ОБА источника реально прочитаны последним `entries()`.
     * `false` — не «ничего не менялось», а «не смогли проверить»; `renderScreen()`
     * обязан различать эти два случая, иначе тихий отказ (пропавшая таблица/обрыв
     * соединения) маскируется под честный «пусто».
     */
    private bool $sourcesComplete = true;

    public function sourcesComplete(): bool
    {
        return $this->sourcesComplete;
    }

    /**
     * Лента персонажа: свежее сверху, обрезано до `depth()`. Только СВОЙ характер —
     * запрос всегда по `character_id = $characterId`, чужих данных не касается.
     *
     * @return list<array{time: string, text: string}>
     */
    public function entries(int $characterId): array
    {
        $depth = $this->depth();
        $db    = $this->db ?? Database::connect();

        $rows = [];

        $actionLogOk   = $db->tableExists('action_log');
        $eventsLogOk   = $db->tableExists('event_effects_log');
        $this->sourcesComplete = $actionLogOk && $eventsLogOk;

        if ($actionLogOk) {
            $actionRows = self::resultArray(
                $db->table('action_log')
                    ->select('created_at, action_name, description')
                    ->where('character_id', $characterId)
                    // Ревью-находка: некоторые коды (SELL_GEAR, ORACLE_BET, ...) пишут
                    // ТОЙ ЖЕ строкой `action_name` как удачную сделку (Completed), так и
                    // отклонённую попытку (REJECTED, через logRejected) — без этого
                    // фильтра неудавшаяся покупка выглядела бы как реальная потеря.
                    ->where('action_status', 'Completed')
                    ->whereIn('action_name', array_keys(self::ICONS))
                    ->orderBy('created_at', 'DESC')
                    ->limit($depth)
                    ->get()
            );

            foreach ($actionRows as $row) {
                $rows[] = [
                    'time' => self::str($row['created_at'] ?? null),
                    'text' => self::actionLine(self::str($row['action_name'] ?? null), self::str($row['description'] ?? null)),
                ];
            }
        }

        if ($eventsLogOk) {
            $builder = $db->table('event_effects_log ee')
                ->select('ee.event_time, ee.effect_details' . ($db->tableExists('events') ? ', e.name as event_name' : ''))
                ->where('ee.character_id', $characterId)
                // Ревью-находка: `EventDispatcher::logToDb()` пишет КАЖДЫЙ применённый
                // эффект (включая чисто-HP/атрибутные) — без фильтра 15 событий с уроном
                // по здоровью подряд вытесняли из ленты налог и продажи. В ленту ПРО
                // ЗАПАС попадают только эффекты, реально менявшие золото (`gold_delta`)
                // или ресурсы (`magnitude.resource_loss_percent`, DamageResourcesEffect).
                // `gold_delta` в JSON есть ВСЕГДА (дефолт 0 у EffectResultFactory), путь
                // `magnitude.resource_loss_percent` отсутствует у эффектов без него —
                // JSON_EXTRACT тогда даёт NULL, сравнение ложно, строка отсеивается.
                ->where(
                    "(JSON_EXTRACT(ee.effect_details, '$.gold_delta') != 0"
                        . " OR JSON_EXTRACT(ee.effect_details, '$.magnitude.resource_loss_percent') > 0)",
                    null,
                    false
                )
                ->orderBy('ee.event_time', 'DESC')
                ->limit($depth);

            if ($db->tableExists('events')) {
                $builder->join('events e', 'e.event_id = ee.event_id', 'left');
            }

            $eventRows = self::resultArray($builder->get());

            foreach ($eventRows as $row) {
                $rows[] = [
                    'time' => self::str($row['event_time'] ?? null),
                    'text' => self::eventLine($row),
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($b['time'], $a['time']));

        return array_slice($rows, 0, $depth);
    }

    /**
     * Сужает `ResultInterface|false` → массив строк (CI4 `get()` возвращает `false`
     * только при обрыве соединения на уровне драйвера — трактуем как пустой результат,
     * тот же паттерн, что `CraftTreeService::fetchAll()`).
     *
     * @param  ResultInterface<object, object>|false $result
     * @return list<array<string, mixed>>
     */
    private static function resultArray(ResultInterface|false $result): array
    {
        if (! $result instanceof ResultInterface) {
            return [];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = $result->getResultArray();

        return $rows;
    }

    /** Узкий `mixed → string` для значений из строки БД (PHPStan L9 запрещает `(string)$mixed`). */
    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * Строка события `action_log`. `description` не парсится — идёт целиком, как её
     * написал источник, но обезврежена от Markdown ({@see MarkdownSafe::text()}):
     * с 2026 в него подставляются имена ресурсов/крафта из БД (`resources.name`,
     * `crafted_items.name_rus`), и непарный `_`/`*` в имени валит рендер ВСЕГО экрана
     * в тихий Telegram 400 (легаси-Markdown не поддерживает бэкслеш-эскейп).
     */
    public static function actionLine(string $actionName, string $description): string
    {
        $icon        = self::ICONS[$actionName] ?? '📄';
        $label       = self::RAW_LABELS[$actionName] ?? null;
        $safeDescription = MarkdownSafe::text($description);

        return $label !== null ? "{$icon} {$label}: {$safeDescription}" : "{$icon} {$safeDescription}";
    }

    /**
     * Строка мирового события. `log_summary`/`gold_delta` уже посчитаны диспетчером
     * событий (`EventDispatcher::logToDb`) — здесь их только читаем и склеиваем.
     * Имя события и `log_summary` — из БД/контента, обезврежены тем же
     * {@see MarkdownSafe::text()}, что и `description` в {@see self::actionLine()}.
     *
     * @param array<string, mixed> $row
     */
    public static function eventLine(array $row): string
    {
        $eventName = $row['event_name'] ?? null;
        $name      = is_string($eventName) && $eventName !== '' ? MarkdownSafe::text($eventName) : 'Мировое событие';

        $decoded = json_decode(self::str($row['effect_details'] ?? null), true);
        $details = is_array($decoded) ? $decoded : [];

        $rawSummary = $details['log_summary'] ?? null;
        $summary    = is_string($rawSummary) ? MarkdownSafe::text(trim($rawSummary)) : '';
        $gold       = is_numeric($details['gold_delta'] ?? null) ? (int) $details['gold_delta'] : 0;

        $parts = [];
        if ($summary !== '' && $summary !== 'thematic event') {
            $parts[] = $summary;
        }
        if ($gold !== 0 && !str_contains($summary, (string) $gold)) {
            $parts[] = ($gold > 0 ? '+' : '') . "{$gold} золота";
        }
        $tail = $parts !== [] ? implode(', ', $parts) : 'эффект применён';

        return "🌪️ «{$name}»: {$tail}";
    }

    /**
     * Текстовое сообщение Telegram (`sendMessage`/`editMessageText`) держит до 4096
     * символов — НЕ 1024 (это лимит подписи к ФОТО, `sendPhoto.caption`). Экран «Куда
     * ушло» — чистый текст без media ({@see \App\Services\Notifications\MediaSender::sendTextFallback()}),
     * поэтому режем по правильному лимиту. Ревью-находка: старый код резал лентy
     * вчетверо раньше нужного.
     */
    private const TEXT_MESSAGE_BUDGET = 4096;

    /**
     * Чистый рендер экрана (без БД) — текст + ряды кнопок. Пустая лента объясняет
     * себя, а не заводит игрока в тупик (UX-DISCOVERABILITY), но НЕ утверждает фактов
     * о мире шире собственной области видимости (ревью-находка: старый текст «не было
     * налога, торговли, мирового события или смерти» звучал как приговор всему запасу
     * персонажа, хотя экран знает только про свой whitelist кодов — сформулировано
     * обобщённо, без перечисления категорий, чтобы не устаревать при следующем
     * добавлении кода). `$sourcesComplete=false` — отдельная, честная ветка: не
     * «ничего не менялось», а «не смогли проверить» (пропавшая таблица — тихий сбой,
     * а не подтверждённый факт).
     *
     * @param list<array{time: string, text: string}> $entries уже отсортированы новыми сверху, обрезаны до depth
     * @return array{text: string, buttons: list<list<array{text: string, callback_data: string}>>}
     */
    public static function renderScreen(array $entries, int $depth, bool $sourcesComplete = true): array
    {
        $header = "🧾 *Куда ушло*\n\n";
        // Честный Descoped-дисклеймер (story chat-requests-batch-06 `## Descoped`):
        // сам крафт (расход ресурсов на постройку/предмет) вообще не пишет след в
        // `action_log` — не «забыли добавить в whitelist», а «этому коду физически
        // нечего показать». Называем это игроку прямо, а не молчим (та же логика,
        // что не даёт эмпти-стейту врать о категориях: лучше явный «не видно», чем
        // тихая дыра, которую примут за «крафт ничего не стоит»).
        $footer = "\n\n_Глубина ленты — {$depth} записей. Крафт (расход на постройку/предмет) "
            . 'в ленте пока не виден — сам процесс крафта не оставляет следа в логе._';

        if (! $sourcesComplete) {
            $text = $header
                . '⚠️ Не получилось прочитать часть источников ленты — технический сбой, '
                . 'а не подтверждение того, что твой запас не менялся. Попробуй заглянуть позже.';

            return ['text' => $text, 'buttons' => self::footerButtons()];
        }

        if ($entries === []) {
            $text = $header
                . 'Пока ни одна отслеженная запись не меняла твоё золото или ресурсы. Как '
                . 'только что-то из этого случится, здесь появится строка.';

            return ['text' => $text, 'buttons' => self::footerButtons()];
        }

        $intro = "Последние события, менявшие твой запас — от новых к старым:\n\n";
        $lines = array_map(static fn (array $e): string => self::formatLine($e), $entries);

        $budget   = self::TEXT_MESSAGE_BUDGET;
        $fixedLen = mb_strlen($header . $intro . $footer);

        // Первый проход — БЕЗ резерва под пометку об обрезке: возможно, обрезка вообще
        // не понадобится. Ревью-находка: резервировать всегда — значит терять
        // последнюю строку на ровном месте, когда всё и так помещалось.
        [$shown, $dropped] = self::packLines($lines, $budget - $fixedLen);

        if ($dropped > 0) {
            // Обрезка нужна — теперь пакуем ещё раз, уже с резервом под саму пометку
            // «…и ещё N», иначе она сама вытолкнула бы текст за бюджет.
            $noticeBudget       = mb_strlen(self::truncationNotice(count($lines)));
            [$shown, $dropped]  = self::packLines($lines, $budget - $fixedLen - $noticeBudget);
        }

        $text = $header . $intro . implode("\n", $shown) . $footer;
        if ($dropped > 0) {
            $text .= self::truncationNotice($dropped);
        }

        return ['text' => $text, 'buttons' => self::footerButtons()];
    }

    /**
     * Жадно набирает строки, пока влезают в `$available` символов; неограниченный
     * бюджет ($available может быть отрицательным на вырожденных входах — тогда
     * влезает только первая строка, чтобы лента никогда не была пустой при наличии
     * записей).
     *
     * @param  list<string> $lines
     * @return array{0: list<string>, 1: int} [показанные строки, сколько отброшено]
     */
    private static function packLines(array $lines, int $available): array
    {
        $used    = 0;
        $shown   = [];
        $dropped = 0;
        foreach ($lines as $line) {
            $lineLen = mb_strlen($line) + 1;
            if ($used + $lineLen > $available && $shown !== []) {
                $dropped++;
                continue;
            }
            $shown[] = $line;
            $used   += $lineLen;
        }

        return [$shown, $dropped];
    }

    private static function truncationNotice(int $dropped): string
    {
        return "\n_…и ещё {$dropped}: не влезли в одно сообщение, загляни попозже._";
    }

    /** @param array{time: string, text: string} $entry */
    private static function formatLine(array $entry): string
    {
        return self::formatStamp((string) $entry['time']) . ' — ' . $entry['text'];
    }

    private static function formatStamp(string $ts): string
    {
        $dt = \DateTime::createFromFormat('Y-m-d H:i:s', $ts);
        if ($dt === false) {
            return $ts !== '' ? $ts : '—';
        }

        return $dt->format('d.m H:i');
    }

    /** @return list<list<array{text: string, callback_data: string}>> */
    private static function footerButtons(): array
    {
        return [
            [
                ['text' => '↩️ Назад', 'callback_data' => 'inventory'],
                ['text' => '🛒 Магазин', 'callback_data' => 'shop'],
            ],
        ];
    }
}
