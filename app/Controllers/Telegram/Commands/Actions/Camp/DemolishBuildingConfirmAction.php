<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\ActionLogModel;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Models\ClaimedCellModel;
use App\Services\GameSettings\GameSettingsReaderTrait;
use App\Services\Telegram\Request;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\I18n\Time;
use Config\Database;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * chat-requests-batch-07 — подтверждение и исполнение сноса ОДНОЙ постройки.
 * Образец потока — {@see \App\Controllers\Telegram\Commands\Actions\Camp\Buildings\TeleportBeaconRemoveAction}
 * (список → подтверждение → необратимое действие, три текстовых экрана).
 *
 * callback_data:
 *   - `demolishBuildingConfirm_<id>` — карточка подтверждения ОДНОЙ постройки
 *     (`id` — строка `character_buildings`).
 *   - `demolishBuildingGo_<id>`      — собственно снос (ровно ОДНОЙ штуки из стека).
 *
 * 🔴 Ревью 24.08.2026 (BLOCK) — три поправки к тому, что было:
 *
 *  1. **Стек, не строка.** `GenericBuildingCompletionHandler` не создаёт вторую строку
 *     для того же типа постройки на той же базе — инкрементит `amount` на СУЩЕСТВУЮЩЕЙ
 *     (`CampCreateConfirmAction` при переезде переносит `amount` как есть — строки с
 *     `amount > 1` в проде реальны). Снос теперь снимает РОВНО ОДНУ штуку: строка
 *     теряет 1 из `amount`, и только когда счётчик доходит до нуля — строка удаляется.
 *
 *  2. **Атомарность.** SELECT → проверки → DELETE раздельными запросами — гонка: два
 *     параллельных подтверждения (два тапа, два PHP-процесса вебхука) оба читают
 *     «кулдауна нет» / «построек ещё 2» и оба проходят, снося базу до нуля построек в
 *     обход гейта. Теперь весь путь `execute()` — одна транзакция с `SELECT ... FROM
 *     characters ... FOR UPDATE` на строке персонажа (тот же приём, что
 *     `CharacterStatsService`/`SellGearConfirmAction`): второй параллельный снос ждёт
 *     коммита первого и видит уже АКТУАЛЬНОЕ состояние — кулдаун и остаток построек не
 *     обойти гонкой.
 *
 *  3. **Налог не «за штуку».** `TaxCollectionHandler` считает `SUM(tax)` ПО СТРОКАМ
 *     `character_buildings`, `amount` формула не читает вовсе — стек из N одинаковых
 *     построек на одной базе платит ТУ ЖЕ ставку, что и одна. Снос лишней штуки из
 *     стека налог сам по себе не снижает; налог за тип исчезает, только когда стек
 *     снесён ДО НУЛЯ (строка удалена). Тексты ниже сформулированы честно к этому факту.
 *
 * Плюс два несогласованных места, приведённых к одному поведению:
 *  - Подтверждение и исполнение теперь ТРЕБУЮТ стоять на базе постройки (физически
 *    или под вышкой связи) — как и список. Раньше не требовали вовсе.
 *  - Кулдаун ОДИН на весь аккаунт (персональный, не по базе) — сказано игроку ДО
 *    необратимого клика «Снести», не только постфактум при попытке снести снова.
 */
final class DemolishBuildingConfirmAction extends BaseAction
{
    use GameSettingsReaderTrait;

    private const ACTION_NAME = 'DEMOLISH_BUILDING';

    public function handle(): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        $data   = (string) $this->callbackQuery->getData();

        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return $this->reply($chatId, '🤖 Пользователь или персонаж не найден.');
        }
        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $cellNr = is_numeric($character['cell_number'] ?? null) ? (int) $character['cell_number'] : 0;

        if (str_starts_with($data, 'demolishBuildingGo_')) {
            return $this->execute($chatId, $charId, $cellNr, $this->idFrom($data, 'demolishBuildingGo_'));
        }

        return $this->confirm($chatId, $charId, $cellNr, $this->idFrom($data, 'demolishBuildingConfirm_'));
    }

    private function confirm(int $chatId, int $charId, int $currentCellNumber, int $buildingRowId): ServerResponse
    {
        $row = $this->ownBuilding($charId, $buildingRowId);
        if ($row === null) {
            return $this->reply($chatId, '🤖 Постройка не найдена или она не твоя.', [[['text' => '🔨 К списку', 'callback_data' => 'demolishBuilding']]]);
        }

        if (! $this->isOnRowBase($charId, $currentCellNumber, $row['mapCellId'])) {
            return $this->reply($chatId, $this->notOnBaseMessage(), $this->notOnBaseButtons());
        }

        // Гейт 3: последняя постройка на базе — это снос базы, отдельный поток.
        if ($this->unitsOnBase($charId, $row['mapCellId']) <= 1) {
            return $this->reply(
                $chatId,
                "🤖 *{$row['name']}* — единственная постройка на этой базе.\n\n"
                    . "Снести последнюю постройку значит снести базу целиком — это другой поток "
                    . "(«⚠️ Снести / переехать»), он сохраняет постройки при плановом сносе/переезде "
                    . "и честно предупреждает о потерях при моментальном.",
                [[
                    ['text' => '⚠️ Снести / переехать', 'callback_data' => 'DeleteBase'],
                    ['text' => '🏠 База',                'callback_data' => 'Base'],
                ]],
            );
        }

        // Гейт 2: кулдаун повторного сноса.
        $remaining = $this->cooldownRemainingMinutes($charId);
        if ($remaining > 0) {
            return $this->reply(
                $chatId,
                "🤖 *{$row['name']}* можно снести, но ты недавно уже что-то сносил.\n\n"
                    . "⏳ Повторный снос доступен через {$this->humanMinutes($remaining)}.",
                [[['text' => '🔨 К списку', 'callback_data' => 'demolishBuilding'], ['text' => '🏠 База', 'callback_data' => 'Base']]],
            );
        }

        $amountLine = $row['amount'] > 1
            ? 'У тебя *' . $row['amount'] . "* шт. на этой базе — снос уберёт ОДНУ, останется *"
                . ($row['amount'] - 1) . "*. Налог считается за тип постройки целиком (одна ставка "
                . "на весь стек, не за штуку) — он исчезнет только когда снесёшь последнюю.\n\n"
            : '';

        $text = "🔨 *Снести постройку?*\n\n"
            . "*{$row['name']}* L{$row['level']} — налог *{$row['tax']}* ед. золота/сутки.\n\n"
            . $amountLine
            . "⚠️ Ресурсы, потраченные на постройку, *не возвращаются* — цена ошибки остаётся.\n\n"
            . '_После подтверждения следующий снос (любой постройки, на любой из твоих баз) '
            . 'станет доступен не сразу — кулдаун один на весь аккаунт._';

        return $this->reply($chatId, $text, [[
            ['text' => '🔨 Снести',   'callback_data' => "demolishBuildingGo_{$buildingRowId}"],
            ['text' => '🚫 Отменить', 'callback_data' => 'demolishBuilding'],
        ]]);
    }

    private function execute(int $chatId, int $charId, int $currentCellNumber, int $buildingRowId): ServerResponse
    {
        $db = Database::connect();
        $db->transStart();

        $row       = null;
        $committed = false;

        try {
            // Сериализуем ВСЕ попытки сноса этого персонажа одним локом на строке
            // `characters` — второй параллельный процесс ждёт коммита первого и видит
            // АКТУАЛЬНЫЕ кулдаун/остаток построек, а не устаревший снимок (см. класс-докблок).
            $lockResult = $db->query('SELECT id FROM characters WHERE id = ? FOR UPDATE', [$charId]);
            $locked     = $lockResult instanceof BaseResult ? $lockResult->getRowArray() : null;
            if ($locked === null) {
                $db->transRollback();

                return $this->reply($chatId, '🤖 Персонаж не найден.');
            }

            $rawRow = (new CharacterBuildingModel())
                ->where('id', $buildingRowId)
                ->where('character_id', $charId)
                ->first();

            if (! is_array($rawRow)) {
                $db->transRollback();

                return $this->reply(
                    $chatId,
                    '🤖 Постройка не найдена, она не твоя, или уже снесена.',
                    [[['text' => '🔨 К списку', 'callback_data' => 'demolishBuilding']]],
                );
            }

            $row = $this->shapeRow($rawRow);

            if (! $this->isOnRowBase($charId, $currentCellNumber, $row['mapCellId'])) {
                $db->transRollback();

                return $this->reply($chatId, $this->notOnBaseMessage(), $this->notOnBaseButtons());
            }

            // Race-safety: те же гейты, что на подтверждении, теперь под локом персонажа.
            if ($this->unitsOnBase($charId, $row['mapCellId']) <= 1) {
                $db->transRollback();

                return $this->reply(
                    $chatId,
                    '🤖 Это уже последняя постройка на этой базе — сноси базу целиком через «⚠️ Снести / переехать».',
                    [[['text' => '⚠️ Снести / переехать', 'callback_data' => 'DeleteBase'], ['text' => '🏠 База', 'callback_data' => 'Base']]],
                );
            }

            $remaining = $this->cooldownRemainingMinutes($charId);
            if ($remaining > 0) {
                $db->transRollback();

                return $this->reply(
                    $chatId,
                    "🤖 ⏳ Повторный снос доступен через {$this->humanMinutes($remaining)}.",
                    [[['text' => '🏠 База', 'callback_data' => 'Base']]],
                );
            }

            $newAmount = $row['amount'] - 1;
            // 🔴 Найдено при добавлении теста на арифметику стека (не ревью — сам поймал):
            // `CharacterBuildingModel::update()` валидирует ВЕСЬ payload против
            // `$validationRules` (`character_id`/`building_id`/`built_at`/... все `required`).
            // Частичный `update($id, ['amount' => N])` не проходит валидацию, `update()`
            // молча возвращает false — БЕЗ исключения, БЕЗ ошибки транзакции — и строка
            // остаётся нетронутой, а игрок видит «✅ снесена» на пустом месте. Raw SQL
            // в той же транзакции (что и лок персонажа) — валидацию не трогает и пишет
            // реально.
            if ($newAmount > 0) {
                $db->query(
                    'UPDATE character_buildings SET amount = ?, updated_at = ? WHERE id = ?',
                    [$newAmount, date('Y-m-d H:i:s'), $buildingRowId],
                );
            } else {
                $db->query('DELETE FROM character_buildings WHERE id = ?', [$buildingRowId]);
            }

            $this->logDemolish($charId, $chatId, $row);

            // 🔴 Второй самонайденный баг: `strictOn=false` (`Config\Database`) заставляет
            // `transComplete()` СБРАСЫВАТЬ `transStatus` обратно в `true` после отката
            // (см. `BaseConnection::transComplete()` — нестрогий режим прощает неудачную
            // транзакцию для СЛЕДУЮЩЕЙ группы запросов). Проверка `transStatus()` ПОСЛЕ
            // `transComplete()` в этом проекте поэтому ничего не доказывает — нужен
            // булев РЕЗУЛЬТАТ самого `transComplete()` (true = закоммичено).
            $committed = $db->transComplete();
        } catch (\Throwable $e) {
            $db->transRollback();
            log_message('error', '[DemolishBuildingConfirmAction] execute failed: ' . $e->getMessage());

            return $this->reply($chatId, '🤖 Не получилось снести — попробуй ещё раз.');
        }

        if (! $committed) {
            return $this->reply($chatId, '🤖 Не получилось снести — попробуй ещё раз.');
        }

        $remainingAfter = max(0, $row['amount'] - 1);
        $tail           = $remainingAfter > 0
            ? "Осталось *{$remainingAfter}* шт. — налог за тип не изменился (он общий на весь "
                . 'стек), исчезнет только когда снесёшь последнюю.'
            : "Это была последняя штука этого типа на базе — налог *{$row['tax']}* ед. золота/сутки "
                . 'за неё больше не берётся.';

        $text = "✅ *{$row['name']}* снесена (1 шт.).\n\n"
            . "Ресурсы за неё не возвращены (цена ошибки остаётся). {$tail}";

        return $this->reply($chatId, $text, [[
            ['text' => '🔨 Снести ещё', 'callback_data' => 'demolishBuilding'],
            ['text' => '🏠 База',       'callback_data' => 'Base'],
        ]]);
    }

    /**
     * Кулдаун читается из `action_log` (последняя `DEMOLISH_BUILDING`/`Completed`
     * этого персонажа) — НЕ заводит новую колонку/таблицу (WipeManifest не трогаем).
     * Персональный (по `character_id`), не по базе — снос на ОДНОЙ базе блокирует
     * снос на ЛЮБОЙ другой на тот же срок.
     */
    private function cooldownRemainingMinutes(int $charId): int
    {
        $cooldownMin = $this->gsInt('buildings.demolish.cooldown_minutes', 1440);
        if ($cooldownMin <= 0) {
            return 0;
        }

        $last = (new ActionLogModel())
            ->where('character_id', $charId)
            ->where('action_name', self::ACTION_NAME)
            ->where('action_status', 'Completed')
            ->orderBy('created_at', 'DESC')
            ->first();

        $lastCreatedAt = is_array($last) ? ($last['created_at'] ?? null) : null;
        if (! is_string($lastCreatedAt) || $lastCreatedAt === '') {
            return 0;
        }

        $lastTs = strtotime($lastCreatedAt);
        if ($lastTs === false) {
            return 0;
        }

        $elapsedMin = (int) floor((Time::now()->getTimestamp() - $lastTs) / 60);
        $remaining  = $cooldownMin - $elapsedMin;

        return max(0, $remaining);
    }

    /** @param array{name:string, level:int, tax:int, mapCellId:int, amount:int} $row */
    private function logDemolish(int $charId, int $chatId, array $row): void
    {
        try {
            (new ActionLogModel())->save([
                'character_id'  => $charId,
                'chat_id'       => $chatId,
                'action_name'   => self::ACTION_NAME,
                'action_status' => 'Completed',
                'description'   => mb_substr(
                    "building={$row['name']} tax={$row['tax']} level={$row['level']} "
                        . "map_cell_id={$row['mapCellId']} amount_before={$row['amount']}",
                    0,
                    500,
                ),
            ]);
        } catch (\Throwable $e) {
            // Форензика не имеет права уронить сам снос — тот же принцип, что
            // у BaseAction::logActivity()/logRejected().
            log_message('error', '[DemolishBuildingConfirmAction] action_log insert failed: ' . $e->getMessage());
        }
    }

    /**
     * Строка `character_buildings`, принадлежащая ИМЕННО этому персонажу —
     * без этой проверки чужой `id` в callback_data сносил бы постройку соседа.
     *
     * @return array{name:string, level:int, tax:int, mapCellId:int, amount:int}|null
     */
    private function ownBuilding(int $charId, int $buildingRowId): ?array
    {
        if ($buildingRowId <= 0) {
            return null;
        }

        $row = (new CharacterBuildingModel())
            ->where('id', $buildingRowId)
            ->where('character_id', $charId)
            ->first();

        if (! is_array($row)) {
            return null;
        }

        return $this->shapeRow($row);
    }

    /**
     * @param  array<int|string,mixed>                                          $row строка `character_buildings`
     * @return array{name:string, level:int, tax:int, mapCellId:int, amount:int}
     */
    private function shapeRow(array $row): array
    {
        $bldRaw = (new BuildingModel())->where('id', $row['building_id'] ?? 0)->first();
        /** @var array<string,mixed> $bld */
        $bld    = is_array($bldRaw) ? $bldRaw : ($bldRaw !== null ? (array) $bldRaw : []);
        $nameEn = isset($bld['name_en']) && is_string($bld['name_en']) ? $bld['name_en'] : '';
        // Правило проекта (db-schema.md): имя постройки — только через rusName(),
        // не напрямую name_ru.
        $name = BuildingModel::rusName($bld, $nameEn !== '' ? $nameEn : 'Неизвестное строение');

        return [
            'name'      => $name,
            'level'     => is_numeric($row['level'] ?? null) ? max(1, (int) $row['level']) : 1,
            'tax'       => is_numeric($row['tax'] ?? null) ? (int) $row['tax'] : 0,
            'mapCellId' => is_numeric($row['map_cell_id'] ?? null) ? (int) $row['map_cell_id'] : 0,
            'amount'    => is_numeric($row['amount'] ?? null) ? max(1, (int) $row['amount']) : 1,
        ];
    }

    /** Сумма `amount` по всем строкам этой базы — реальное число построек, не строк. */
    private function unitsOnBase(int $charId, int $mapCellId): int
    {
        $rows = (new CharacterBuildingModel())
            ->select('amount')
            ->where('character_id', $charId)
            ->where('map_cell_id', $mapCellId)
            ->findAll();

        $total = 0;
        foreach ($rows as $r) {
            $amt    = is_array($r) ? ($r['amount'] ?? null) : null;
            $total += is_numeric($amt) ? max(1, (int) $amt) : 1;
        }

        return $total;
    }

    /**
     * Постройка привязана к базе `$rowMapCellId` — персонаж обязан стоять именно на
     * ней (физически или под вышкой связи), как и список ({@see DemolishBuildingAction}).
     */
    private function isOnRowBase(int $charId, int $currentCellNumber, int $rowMapCellId): bool
    {
        $resolved = (new ClaimedCellModel())->resolveTargetBaseCell($charId, $currentCellNumber);

        return $resolved !== null && $resolved === $rowMapCellId;
    }

    private function notOnBaseMessage(): string
    {
        return '🤖 Нужно быть на этой базе (физически или в радиусе вышки связи), чтобы '
            . 'управлять её постройками. Вернись и попробуй снова.';
    }

    /** @return list<list<array{text:string, callback_data:string}>> */
    private function notOnBaseButtons(): array
    {
        return [[
            ['text' => '🏠 База',      'callback_data' => 'Base'],
            ['text' => '🔨 К списку',  'callback_data' => 'demolishBuilding'],
        ]];
    }

    private function idFrom(string $data, string $prefix): int
    {
        $raw = substr($data, strlen($prefix));

        return is_numeric($raw) ? (int) $raw : 0;
    }

    /** «42 мин.» / «2 ч 05 мин.» — то же человекочитаемое форматирование, что у ADR-143 (ActionScopeService). */
    private function humanMinutes(int $minutes): string
    {
        if ($minutes < 60) {
            return "{$minutes} мин.";
        }
        $hours = intdiv($minutes, 60);
        $rest  = $minutes % 60;

        return $rest > 0 ? "{$hours} ч {$rest} мин." : "{$hours} ч";
    }

    /**
     * @param list<list<array{text:string, callback_data:string}>> $rows
     */
    private function reply(int $chatId, string $text, array $rows = []): ServerResponse
    {
        $payload = [
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ];

        if ($rows !== []) {
            $payload['reply_markup'] = json_encode(['inline_keyboard' => $rows]);
        }

        return Request::sendMessage($payload);
    }
}
