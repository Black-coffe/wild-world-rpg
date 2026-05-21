<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Greenhouse;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Farming\FarmingService;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * V6 (ADR-033) — превью/подтверждение посадки (callback `plantSeedPreview_<crop>`).
 *
 * Показывает точный исход: культура → время роста → ожидаемый урожай (+севооборот),
 * сколько семян спишется, занятость слотов. Кнопка «✅ Посадить» → plantSeedStart_<crop>.
 * Caption самодостаточен (media-off).
 */
class SeedPlantPreviewAction extends BaseAction
{
    use FarmingActionTrait;

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // plantSeedPreview_<crop>
        $parts = explode('_', (string) $this->callbackQuery->getData());
        $crop  = $parts[1] ?? '';

        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->errorCard($chatId, 'Персонаж не найден. Попробуй /start.');
        }
        $charId  = (int) $character['id'];
        $farming = new FarmingService();

        if (!$farming->isEnabled()) {
            return $this->errorCard($chatId, '🌱 Земледелие сейчас недоступно.');
        }
        $meta = $farming->cropMeta($crop);
        if ($meta === null) {
            return $this->errorCard($chatId, 'Неизвестная культура.');
        }
        if (!$this->hasGreenhouse($charId)) {
            return $this->errorCard($chatId, 'Для посадки нужна *Теплица* на базе.');
        }

        $owned = $this->ownedSeedQty($charId, $meta['seed_en']);
        if ($owned <= 0) {
            return $this->errorCard($chatId, "У тебя нет семян «{$meta['crop_ru']}». Сначала заготовь их.");
        }

        $maxSlots  = $farming->maxConcurrentPlantings();
        $plantTask = $this->plantTaskRow();
        $used      = $plantTask !== null && isset($plantTask['id']) && is_numeric($plantTask['id'])
            ? count($this->activePlantings($charId, (int) $plantTask['id']))
            : 0;
        $slotsFull = $used >= $maxSlots;

        $lastCropRaw = $character['last_planted_crop'] ?? null;
        $lastCrop    = is_string($lastCropRaw) && $lastCropRaw !== '' ? $lastCropRaw : null;
        $willRot     = $farming->shouldRotate($lastCrop, $crop);
        // V7: greenhouse-level множители (урожай/время роста).
        $effects     = $this->buildingEffects();
        $ghGrowMult  = $effects->getGreenhouseGrowTimeMultiplier($charId);
        $ghYieldMult = $effects->getGreenhouseYieldMultiplier($charId);
        $grow        = $farming->growMinutes($crop, $ghGrowMult);
        $yield       = $farming->harvestYield($crop, $willRot, $ghYieldMult);

        $rotLine = $willRot
            ? "🔄 Севооборот: *+{$farming->rotationBonusPercent()}%* (культура отличается от прошлой)\n"
            : "🔁 Севооборот: нет (та же культура или первая посадка)\n";
        $ghLine = ($ghYieldMult > 1.0 || $ghGrowMult < 1.0)
            ? "🏗 Бонус теплицы по уровню учтён в цифрах выше\n"
            : '';

        $text = "🌱 *Посадка: {$meta['icon']} {$meta['crop_ru']}*\n\n"
            . "Спишется: *1× {$meta['seed_ru']}* (в запасе: {$owned})\n"
            . "⏱ Время роста: *{$grow} мин*\n"
            . "🌾 Ожидаемый урожай: *{$meta['icon']} {$meta['crop_ru']} ×{$yield}*\n"
            . $rotLine
            . $ghLine
            . "🌿 Слоты посадок: *{$used}/{$maxSlots}*\n\n";

        $rows = [];
        if ($slotsFull) {
            $text .= "❗Все слоты заняты ({$maxSlots}). Дождись урожая или собери готовое.";
        } else {
            $text .= "_Подтверди посадку — семя будет списано._";
            $rows[] = [['text' => "✅ Посадить {$meta['icon']}", 'callback_data' => "plantSeedStart_{$crop}"]];
        }
        $rows[] = [
            ['text' => '⬅️ К грядкам', 'callback_data' => 'plantSeedMenu'],
        ];

        $imagePath = base_url('uploads/telegram/camp/Greenhouse_craft.png');

        return \App\Services\Notifications\MediaSender::editOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);
    }

    private function errorCard(int $chatId, string $message): ServerResponse
    {
        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $message,
            'parse_mode' => 'Markdown',
        ]);
    }
}
