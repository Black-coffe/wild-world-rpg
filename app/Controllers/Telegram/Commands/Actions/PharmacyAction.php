<?php

namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;

class PharmacyAction extends BaseAction
{
    protected $craftedItemsLogModel;
    protected $craftedItemsModel;

    public function __construct($callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->craftedItemsLogModel = new CraftedItemsLogModel();
        $this->craftedItemsModel = new CraftedItemsModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        // Выбираем только те предметы, у которых quantity > 0
        $craftedItemsLogs = $this->craftedItemsLogModel
            ->select('crafted_items_log.quantity, crafted_items_log.durability_time, crafted_items_log.durability_count AS log_charges, crafted_items.durability_count AS base_charges, crafted_items.name_rus, crafted_items.name_eng, crafted_items.character_boost')
            ->join('crafted_items', 'crafted_items.id = crafted_items_log.crafted_item_id')
            ->where([
                'crafted_items_log.character_id' => $character['id'],
                'crafted_items.type' => 'drug'
            ])
            ->where('crafted_items_log.quantity >', 0)
            ->findAll();

        // ADR-094: статус годности медикамента (свежо / просрочен → эффект снижен).
        $expirySvc = new \App\Services\Craft\ConsumableExpiryService();

        // Если ничего нет, предлагаем перейти к крафту
        if (empty($craftedItemsLogs)) {
            $text = "К сожалению, у тебя нет медицинских предметов! Нужно их сначала скрафтить.";
            $inline_keyboard[] = [
                'text' => '🧑‍🌾 Действия 🛠️',
                'callback_data' => 'characterActions'
            ];
            // ADR-150 Слайс 2: возврат на карточку «Я» (чинит тупик Аптечки). Только при me_hub ON.
            if (\App\Services\Telegram\BotMenuService::meHubEnabled()) {
                $inline_keyboard[] = ['text' => '◀️ Я', 'callback_data' => 'character'];
            }
            $keyboard = ['inline_keyboard' => array_chunk($inline_keyboard, 2)];

            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text' => $text,
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        // Есть препараты > 0
        $text = "🔥 *Исцели свои раны и зарядись силой в этом безумном мире!* 🔥\n\n";

        // Раны, которые не лечатся едой: показываем прямо здесь, вместе с тем, какой
        // предмет их снимает. Это единственный экран, где решение «что применить»
        // принимается, — без списка ран игрок не поймёт, зачем ему лекарства.
        $charIdForDebuffs = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $debuffService    = new \App\Services\Player\DebuffService();
        $activeDebuffs    = $debuffService->active($charIdForDebuffs);
        if ($activeDebuffs !== []) {
            $text .= "🩺 *Сейчас на тебе:*\n";
            foreach ($activeDebuffs as $debuffRow) {
                $line = $debuffService->describe($debuffRow);
                if ($line !== '') {
                    $text .= $line . "\n";
                }
            }
            $text .= "\n";
        }

        $text .= "*У тебя в наличии:*\n\n";
        $inline_keyboard = [];

        foreach ($craftedItemsLogs as $item) {
            // Читаем "character_boost" (JSON), формируем понятный текст
            $cleanedBoost = preg_replace('/[[:cntrl:]]/', '', $item['character_boost']);
            $cleanedBoost = str_replace(' ', ' ', $cleanedBoost);
            $boost = json_decode($cleanedBoost, true);

            $boostText = '';
            if (is_array($boost) && !empty($boost)) {
                foreach ($boost as $effects) {
                    foreach ($effects as $effectName => $effectValue) {
                        $boostText .= "{$effectName}: {$effectValue}, ";
                    }
                }
                $boostText = rtrim($boostText, ', ');
            }

            // ADR-094: строка годности (только если механика включена и срок задан).
            $freshLine = '';
            if ($expirySvc->enabled()) {
                $durTime = $item['durability_time'] ?? null;
                if ($expirySvc->isExpired($durTime)) {
                    $lostPct = 100 - $expirySvc->stalePercent();
                    $freshLine = " 🕒 *просрочен* (эффект −{$lostPct}%)\n";
                } elseif (is_string($durTime) && $durTime !== '') {
                    $freshLine = " ✅ годен до " . substr($durTime, 0, 10) . "\n";
                }
            }

            // Многодозовые препараты (Антисептик — 5 применений в упаковке и т.п.)
            // раньше молчали о дозах: игрок применял «1 шт.» несколько раз подряд и
            // читал это как «предмет не заканчивается» (багрепорт 2026-08-09).
            $baseCharges = CraftedItemsLogModel::baseCharges($item['base_charges'] ?? null);
            $dosesLine   = '';
            if ($baseCharges > 1) {
                $left = CraftedItemsLogModel::effectiveCharges($item['log_charges'] ?? null, $baseCharges);
                $dosesLine = " 💊 доз в начатой упаковке: {$left} из {$baseCharges}\n";
            }

            $text .= "📋 *{$item['name_rus']}* | {$item['quantity']} шт.\n"
                . $dosesLine
                . $freshLine
                . " *Баф:* {$boostText}\n\n";

            // Добавляем кнопку для использования
            $inline_keyboard[] = [
                'text' => $item['name_rus'],
                'callback_data' => 'usePharmacy_' . $item['name_eng']
            ];
        }

        $text .= "\n_Выбери снизу, какой предмет ты будешь использовать:_ 👇";
        $inline_keyboard[] = [
            'text' => '🧑‍🌾 Действия 🛠️',
            'callback_data' => 'characterActions'
        ];
        // ADR-150 Слайс 2: возврат на карточку «Я» (чинит тупик Аптечки). Только при me_hub ON.
        if (\App\Services\Telegram\BotMenuService::meHubEnabled()) {
            $inline_keyboard[] = ['text' => '◀️ Я', 'callback_data' => 'character'];
        }

        $keyboard = ['inline_keyboard' => array_chunk($inline_keyboard, 2)];

        $imagePath = base_url('uploads/telegram/craft/many_medicinal_things.jpg');

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
            'photo' => Request::encodeFile($imagePath),
            'caption' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }
}
