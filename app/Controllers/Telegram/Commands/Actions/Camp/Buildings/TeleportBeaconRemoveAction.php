<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings;

use App\Models\BiomeModel;
use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Models\TeleportBeaconModel;
use App\Services\Telegram\ButtonPacker;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * Снятие своего маяка.
 *
 * Зачем. Лимит маяков считался по ВСЕМ строкам игрока
 * ({@see \App\Services\Player\TeleportBeacon\BeaconPlacementValidator}), а выработанный
 * маяк (`remaining_uses = 0`) не удалялся ниоткуда: перемещение на него отвергается,
 * налог с него не берётся, но место в лимите он занимает навсегда. Отказ при этом
 * советовал «сначала удали старый маяк» — механики удаления в игре не существовало
 * вовсе, то есть игрок с забитым лимитом упирался в тупик с инструкцией, которую
 * невозможно выполнить. Найдено 18.08.2026 при работе над видимостью заряда: экран
 * маяков стал честно показывать «⚡ 0 из 100», и выхода из этого состояния не было.
 *
 * Callback'и (хвост разбирает сам action, первый сегмент — ключ роута):
 *   - `teleportBeaconRemove`             — список своих маяков с остатком заряда
 *   - `teleportBeaconRemove_id=<id>`     — подтверждение по конкретному маяку
 *   - `teleportBeaconRemoveGo_id=<id>`   — собственно снятие
 *
 * Снятие безвозвратно: маяк не возвращается в инвентарь предметом (он остался стоять
 * в мире и разбирается на месте). Поэтому шаг подтверждения обязателен — и тем более
 * для маяка с живым зарядом.
 *
 * Media-off safe: чистый текст. Markdown — только парные `*`.
 */
final class TeleportBeaconRemoveAction
{
    private CallbackQuery $callbackQuery;
    private TeleportBeaconModel $beaconModel;
    private CharacterModel $characterModel;
    private MapModel $mapModel;
    private BiomeModel $biomeModel;

    public function __construct(CallbackQuery $callbackQuery)
    {
        $this->callbackQuery  = $callbackQuery;
        $this->beaconModel    = new TeleportBeaconModel();
        $this->characterModel = new CharacterModel();
        $this->mapModel       = new MapModel();
        $this->biomeModel     = new BiomeModel();
    }

    public function handle(): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $chatId         = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        $telegramUserId = (int) $this->callbackQuery->getFrom()->getId();

        $characterId = $this->characterModel->getCharacterIdByTelegramId($telegramUserId);
        if (! $characterId) {
            return $this->reply($chatId, 'Ошибка: персонаж не найден.');
        }

        $data = (string) $this->callbackQuery->getData();

        if (str_starts_with($data, 'teleportBeaconRemoveGo_id=')) {
            return $this->remove($chatId, (int) $characterId, $this->idFrom($data, 'teleportBeaconRemoveGo_id='));
        }

        if (str_starts_with($data, 'teleportBeaconRemove_id=')) {
            return $this->confirm($chatId, (int) $characterId, $this->idFrom($data, 'teleportBeaconRemove_id='));
        }

        return $this->listBeacons($chatId, (int) $characterId);
    }

    private function listBeacons(int $chatId, int $characterId): ServerResponse
    {
        $beacons = $this->ownBeacons($characterId);

        if ($beacons === []) {
            return $this->reply(
                $chatId,
                "📡 *Снять маяк*\n\nУ тебя нет установленных маяков — снимать нечего.",
                [[['text' => '📡 Маяки', 'callback_data' => 'teleportBeacon'], ['text' => '🏠 База', 'callback_data' => 'Base']]]
            );
        }

        $text = "📡 *Снять маяк*\n\n"
            . "Снятый маяк исчезает насовсем: предметом он не возвращается, зато освобождает место в лимите.\n"
            . "Выработанный маяк (⚡ 0) не принимает телепорты, но место занимает — его снимать и стоит первым.\n\n";

        $buttons = [];
        foreach ($beacons as $b) {
            $text .= "• `X={$b['x']} Y={$b['y']}` — ⚡ *{$b['uses']}* телепортов · {$b['biome']}\n";
            $buttons[] = [
                'text'          => "🗑 X={$b['x']} Y={$b['y']} (⚡{$b['uses']})",
                'callback_data' => "teleportBeaconRemove_id={$b['id']}",
            ];
        }

        $rows   = ButtonPacker::pack($buttons);
        $rows[] = [
            ['text' => '📡 Маяки', 'callback_data' => 'teleportBeacon'],
            ['text' => '🏠 База',  'callback_data' => 'Base'],
        ];

        return $this->reply($chatId, $text, $rows);
    }

    private function confirm(int $chatId, int $characterId, int $beaconId): ServerResponse
    {
        $beacon = $this->ownBeacon($characterId, $beaconId);
        if ($beacon === null) {
            return $this->reply($chatId, 'Маяк не найден или он не твой.');
        }

        $text = "🗑 *Снять маяк?*\n\n"
            . "Точка: `X={$beacon['x']} Y={$beacon['y']}` · {$beacon['biome']}\n"
            . "Остаток: ⚡ *{$beacon['uses']}* телепортов\n\n"
            . ($beacon['uses'] > 0
                ? "⚠️ Заряд у маяка ещё есть — после снятия он пропадёт вместе с маяком, вернуть нельзя.\n\n"
                : "Заряд выработан: маяк всё равно не принимает телепорты, а место в лимите держит.\n\n")
            . "Налог за него платить перестанешь.";

        return $this->reply($chatId, $text, [[
            ['text' => '🗑 Снять',    'callback_data' => "teleportBeaconRemoveGo_id={$beaconId}"],
            ['text' => '🚫 Отменить', 'callback_data' => 'teleportBeacon'],
        ]]);
    }

    private function remove(int $chatId, int $characterId, int $beaconId): ServerResponse
    {
        $beacon = $this->ownBeacon($characterId, $beaconId);
        if ($beacon === null) {
            return $this->reply($chatId, 'Маяк не найден или он не твой.');
        }

        $this->beaconModel->delete($beaconId);

        $text = "✅ *Маяк снят*\n\n"
            . "Точка `X={$beacon['x']} Y={$beacon['y']}` больше не твоя — место в лимите свободно, "
            . "налог за этот маяк больше не берётся.";

        return $this->reply($chatId, $text, [[
            ['text' => '📡 Маяки', 'callback_data' => 'teleportBeacon'],
            ['text' => '🏠 База',  'callback_data' => 'Base'],
        ]]);
    }

    /**
     * @return list<array{id:int, x:int, y:int, uses:int, biome:string}>
     */
    private function ownBeacons(int $characterId): array
    {
        $rows = $this->beaconModel->where('character_id', $characterId)->findAll();

        // Свежие модели под цикл не нужны: find() не копит условия, а where() выше
        // применился к beaconModel, не к map/biome.
        $out = [];
        foreach ($rows as $raw) {
            $out[] = $this->shape(is_array($raw) ? $raw : (array) $raw);
        }

        return $out;
    }

    /**
     * @return array{id:int, x:int, y:int, uses:int, biome:string}|null
     */
    private function ownBeacon(int $characterId, int $beaconId): ?array
    {
        if ($beaconId <= 0) {
            return null;
        }

        $row = $this->beaconModel
            ->where('id', $beaconId)
            ->where('character_id', $characterId)
            ->first();

        if (! is_array($row)) {
            return null;
        }

        return $this->shape($row);
    }

    /**
     * @param  array<int|string,mixed> $row
     * @return array{id:int, x:int, y:int, uses:int, biome:string}
     */
    private function shape(array $row): array
    {
        $biome  = '???';
        $cellId = $this->asInt($row['map_cell_id'] ?? null);
        if ($cellId > 0) {
            $mapFound = $this->mapModel->find($cellId);
            $mapRow   = is_array($mapFound) ? $mapFound : [];
            $biomeId  = $this->asInt($mapRow['biome_id'] ?? null);
            if ($biomeId > 0) {
                // BiomeModel отдаёт BiomeEntity — конвертируем в массив отдельной
                // переменной (урок entity_strict_array_typehint_trap).
                $biomeFound = $this->biomeModel->find($biomeId);
                $biomeRow   = $biomeFound !== null ? $biomeFound->toArray() : [];
                $name       = $biomeRow['name'] ?? null;
                if (is_string($name) && $name !== '') {
                    $biome = $name;
                }
            }
        }

        return [
            'id'    => $this->asInt($row['id'] ?? null),
            'x'     => $this->asInt($row['coordinate_x'] ?? null),
            'y'     => $this->asInt($row['coordinate_y'] ?? null),
            'uses'  => $this->asInt($row['remaining_uses'] ?? null),
            'biome' => $biome,
        ];
    }

    private function idFrom(string $data, string $prefix): int
    {
        return $this->asInt(substr($data, strlen($prefix)));
    }

    private function asInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
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
