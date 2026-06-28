<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Greenhouse;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Farming\FarmingService;
use DateInterval;
use DateTime;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * V6 (ADR-033) — старт посадки (callback `plantSeedStart_<crop>`).
 *
 * Зеркало GenericCraftActionStart: гейт (farming.enabled + Greenhouse + slot-кап +
 * наличие семени) → транзакция (списать 1 семя + insert character_tasks с
 * task_settings={crop, rotation}, end_time=now+grow). Rotation-флаг замораживается
 * здесь (детерминир. урожай в completion). last_planted_crop обновляется в completion.
 */
class PlantCropActionStart extends BaseAction
{
    use FarmingActionTrait;

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // plantSeedStart_<crop>
        $parts = explode('_', (string) $this->callbackQuery->getData());
        $crop  = $parts[1] ?? '';

        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError($chatId, 'Персонаж не найден. Попробуй /start.');
        }
        $charId  = (int) $character['id'];
        $farming = new FarmingService();

        if (!$farming->isEnabled()) {
            return $this->sendError($chatId, '🌱 Земледелие сейчас недоступно.');
        }
        $meta = $farming->cropMeta($crop);
        if ($meta === null) {
            return $this->sendError($chatId, 'Неизвестная культура.');
        }
        if (!$this->hasGreenhouse($charId)) {
            $this->logRejected($charId, 'PLANT_' . $crop, 'no_greenhouse');
            return $this->sendError($chatId, 'Для посадки нужна *Теплица* на базе.');
        }

        $plantTask = $this->plantTaskRow();
        if ($plantTask === null || !isset($plantTask['id']) || !is_numeric($plantTask['id'])) {
            log_message('error', '[PlantCropActionStart] tasks-row plantCrop не найдена в БД');
            return $this->sendError($chatId, 'Конфигурационная ошибка посадки. Сообщи администратору.');
        }
        $plantTaskId = (int) $plantTask['id'];

        // Slot-кап.
        $maxSlots = $farming->maxConcurrentPlantings();
        $used     = count($this->activePlantings($charId, $plantTaskId));
        if ($used >= $maxSlots) {
            $this->logRejected($charId, 'PLANT_' . $crop, 'slots_full', ['max' => $maxSlots]);
            return $this->sendError($chatId, "Все слоты посадок заняты (*{$maxSlots}*). Дождись урожая.");
        }

        // Наличие семени + его resource_id.
        $db     = \Config\Database::connect();
        $resRow = $db->table('resources')->select('id')->where('name_en', $meta['seed_en'])->get();
        $resArr = $resRow !== false ? $resRow->getRowArray() : null;
        $seedResourceId = is_array($resArr) && isset($resArr['id']) && is_numeric($resArr['id']) ? (int) $resArr['id'] : 0;
        if ($seedResourceId === 0) {
            log_message('error', "[PlantCropActionStart] seed resource '{$meta['seed_en']}' не найден в БД");
            return $this->sendError($chatId, 'Конфигурационная ошибка семени. Сообщи администратору.');
        }
        $owned = $this->ownedSeedQty($charId, $meta['seed_en']);
        if ($owned <= 0) {
            $this->logRejected($charId, 'PLANT_' . $crop, 'no_seed');
            return $this->sendError($chatId, "У тебя нет семян «{$meta['crop_ru']}». Сначала заготовь их.");
        }

        // Rotation-решение замораживается на старте.
        $lastCropRaw = $character['last_planted_crop'] ?? null;
        $lastCrop    = is_string($lastCropRaw) && $lastCropRaw !== '' ? $lastCropRaw : null;
        $willRot     = $farming->shouldRotate($lastCrop, $crop);

        // V7: greenhouse-level множители (время роста — при посадке, замораживается).
        $effects   = $this->buildingEffects();
        $growMult  = $effects->getGreenhouseGrowTimeMultiplier($charId);
        $yieldMult = $effects->getGreenhouseYieldMultiplier($charId);
        $grow      = $farming->growMinutes($crop, $growMult);
        $startTime = new DateTime();
        $endTime   = (clone $startTime)->add(new DateInterval('PT' . $grow . 'M'));

        // Транзакция: списать 1 семя + insert task.
        $db->transStart();

        $db->table('character_resources')
            ->where('id_characters', $charId)
            ->where('id_resources', $seedResourceId)
            ->set('quantity', 'quantity - 1', false)
            ->update();

        $this->characterTaskModel->insert([
            'character_id'     => $charId,
            'telegram_user_id' => $user['id'],
            'task_id'          => $plantTaskId,
            'start_time'       => $startTime->format('Y-m-d H:i:s'),
            'end_time'         => $endTime->format('Y-m-d H:i:s'),
            'status'           => 'in_work',
            'task_settings'    => json_encode([
                'crop'     => $crop,
                'rotation' => $willRot,
            ]),
        ]);

        $db->transComplete();
        if ($db->transStatus() === false) {
            log_message('error', "[PlantCropActionStart] транзакция упала для character {$charId}, crop {$crop}");
            return $this->sendError($chatId, 'Ошибка при посадке. Попробуй ещё раз.');
        }

        $yield   = $farming->harvestYield($crop, $willRot, $yieldMult);
        $rotLine = $willRot ? "\n🔄 Севооборот активен: *+{$farming->rotationBonusPercent()}%*." : '';
        $ghLine  = ($yieldMult > 1.0 || $growMult < 1.0)
            ? "\n🏗 Бонус теплицы применён (урожай/скорость по уровню)."
            : '';
        $endStr  = $endTime->format('H:i');

        $scope      = new \App\Services\Tasks\ActionScopeService();
        $background  = $scope->isBackground($plantTask['parallel_execution_allowed'] ?? 1);

        $text = "🌱 *Посажено: {$meta['icon']} {$meta['crop_ru']}*\n\n"
            . $scope->startedBlock(\App\Services\Tasks\ActionScopeService::KIND_CRAFT, $background) . "\n\n"
            . "⏱ Поспеет через *{$grow} мин* (≈ {$endStr}).\n"
            . "🌾 Ожидаемый урожай: *{$meta['icon']} {$meta['crop_ru']} ×{$yield}*.{$rotLine}{$ghLine}\n\n"
            . "Слоты посадок: *" . ($used + 1) . "/{$maxSlots}*.\n"
            . "_О готовности узнаешь в сообщении._ 🎁";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🌿 К грядкам', 'callback_data' => 'plantSeedMenu'],
                    ['text' => '🏗️ К теплице', 'callback_data' => 'building_5_Greenhouse'],
                ],
            ],
        ];

        $imagePath = base_url('uploads/telegram/camp/Greenhouse_craft.png');

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function sendError(int $chatId, string $message): ServerResponse
    {
        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $message,
            'parse_mode' => 'Markdown',
        ]);
    }
}
