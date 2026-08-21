<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Models\CraftedItemsLogModel;
use App\Services\Craft\ConsumableExpiryService;
use Config\Consumables;
use Config\Debuffs;

/**
 * Полка расходника и её отрисовка — вынесены из `PharmacyAction` в чистый слой
 * (docs/specs/pharmacy-split/pharmacy-split-01.md). Telegram и `Request`-слой не трогает:
 * на вход — уже выбранные строки `crafted_items_log`+`crafted_items`, на выход — готовый
 * текст и кнопки. Настройки годности расходников всё же читает — через
 * {@see ConsumableExpiryService}, который принимает параметром конструктора (и который
 * сам ходит в `game_settings` через `GameSettingsService`). Story 02 подключает это
 * к экранам, ничего здесь не меняя.
 */
final class ConsumableShelfService
{
    public const SHELF_MEDICINE  = Consumables::SHELF_MEDICINE;
    public const SHELF_PROVISION = Consumables::SHELF_PROVISION;

    private ConsumableExpiryService $expiry;

    public function __construct(?ConsumableExpiryService $expiry = null)
    {
        $this->expiry = $expiry ?? new ConsumableExpiryService();
    }

    /**
     * Раскладывает строки выборки по полкам — БД и Telegram не трогает.
     *
     * @param list<array<string, mixed>> $rows
     * @return array{medicine: list<array<string, mixed>>, provision: list<array<string, mixed>>}
     */
    public function split(array $rows): array
    {
        $medicine  = [];
        $provision = [];

        foreach ($rows as $row) {
            $nameEng = is_string($row['name_eng'] ?? null) ? $row['name_eng'] : '';
            if (Consumables::shelfOf($nameEng) === self::SHELF_MEDICINE) {
                $medicine[] = $row;
            } else {
                $provision[] = $row;
            }
        }

        return [self::SHELF_MEDICINE => $medicine, self::SHELF_PROVISION => $provision];
    }

    /**
     * Готовый экран полки: заголовок, список предметов, кнопки применения.
     *
     * @param list<array<string, mixed>> $rows строки, УЖЕ отфильтрованные под $shelf (см. split())
     * @param list<string> $activeDebuffLines уже отрисованные строки активных ран (пусто = раздела нет)
     * @return array{text: string, buttons: list<array{text: string, callback_data: string}>}
     */
    public function screen(string $shelf, array $rows, array $activeDebuffLines = []): array
    {
        $isMedicine = $shelf === self::SHELF_MEDICINE;

        $text = $isMedicine
            ? "🔥 *Исцели свои раны и зарядись силой в этом безумном мире!* 🔥\n\n"
            : "🍲 *Подкрепись — сытость и силы для похода.* 🍲\n\n";

        // Раздел активных ран показываем только на полке лекарств — провизия их не лечит
        // (Config\Debuffs: 🔴 еда никогда не снимает состояние).
        if ($isMedicine && $activeDebuffLines !== []) {
            $text .= "🩺 *Сейчас на тебе:*\n" . implode("\n", $activeDebuffLines) . "\n\n";
        }

        $text .= "*У тебя в наличии:*\n\n";

        $buttons = [];
        foreach ($rows as $item) {
            $text .= $this->itemLine($item, $isMedicine);

            $nameEng   = is_string($item['name_eng'] ?? null) ? $item['name_eng'] : '';
            $nameRus   = is_string($item['name_rus'] ?? null) ? $item['name_rus'] : $nameEng;
            $buttons[] = ['text' => $nameRus, 'callback_data' => 'usePharmacy_' . $nameEng];
        }

        return ['text' => $text, 'buttons' => $buttons];
    }

    /**
     * Строка одного предмета — формат сохранён дословно из `PharmacyAction`: название,
     * количество, дозы в начатой упаковке, срок годности, «Баф», плюс (только у лекарств)
     * какие раны предмет снимает — всегда, а не только при активной ране (требование
     * брифа: игрок должен узнать назначение бинта до того, как обгорит).
     *
     * @param array<string, mixed> $item
     */
    private function itemLine(array $item, bool $isMedicine): string
    {
        $nameRus  = is_string($item['name_rus'] ?? null) ? $item['name_rus'] : '';
        $nameEng  = is_string($item['name_eng'] ?? null) ? $item['name_eng'] : '';
        $quantity = is_numeric($item['quantity'] ?? null) ? (int) $item['quantity'] : 0;

        // character_boost — JSON-строка вида {"heal": {"hp": 40, "stamina": 20}}.
        $cleanedBoost = is_string($item['character_boost'] ?? null)
            ? preg_replace('/[[:cntrl:]]/', '', $item['character_boost'])
            : null;
        // Неразрывный пробел (правится вручную через админку) внутри JSON ломает
        // json_decode() молча — предмет остался бы с пустым «Баф:» без ошибки в логе.
        $cleanedBoost = is_string($cleanedBoost) ? str_replace("\xC2\xA0", ' ', $cleanedBoost) : $cleanedBoost;
        $boost        = is_string($cleanedBoost) ? json_decode($cleanedBoost, true) : null;
        $boostText = '';
        if (is_array($boost) && $boost !== []) {
            foreach ($boost as $effects) {
                if (!is_array($effects)) {
                    continue;
                }
                foreach ($effects as $effectName => $effectValue) {
                    $valueText = is_scalar($effectValue) ? (string) $effectValue : '';
                    $boostText .= "{$effectName}: {$valueText}, ";
                }
            }
            $boostText = rtrim($boostText, ', ');
        }

        // ADR-094: строка годности (только если механика включена и срок задан).
        $freshLine = '';
        if ($this->expiry->enabled()) {
            $durTime = $item['durability_time'] ?? null;
            if ($this->expiry->isExpired($durTime)) {
                $lostPct   = 100 - $this->expiry->stalePercent();
                $freshLine = " 🕒 *просрочен* (эффект −{$lostPct}%)\n";
            } elseif (is_string($durTime) && $durTime !== '') {
                $freshLine = " ✅ годен до " . substr($durTime, 0, 10) . "\n";
            }
        }

        // Многодозовые препараты (Антисептик — 5 применений в упаковке и т.п.).
        $baseCharges = CraftedItemsLogModel::baseCharges($item['base_charges'] ?? null);
        $dosesLine   = '';
        if ($baseCharges > 1) {
            $left      = CraftedItemsLogModel::effectiveCharges($item['log_charges'] ?? null, $baseCharges);
            $dosesLine = " 💊 доз в начатой упаковке: {$left} из {$baseCharges}\n";
        }

        // Требование брифа: лекарство называет снимаемую рану всегда, не только при
        // активном дебаффе — иначе игрок не узнает назначение бинта, пока не обгорит.
        $curedLine = '';
        if ($isMedicine) {
            $cured = Debuffs::curedByItem($nameEng);
            if ($cured !== []) {
                $names = [];
                foreach ($cured as $key) {
                    $meta = Debuffs::get($key);
                    if ($meta !== null) {
                        $names[] = "{$meta['emoji']} {$meta['name']}";
                    }
                }
                if ($names !== []) {
                    $curedLine = " 🩺 *Снимает:* " . implode(', ', $names) . "\n";
                }
            }
        }

        return "📋 *{$nameRus}* | {$quantity} шт.\n"
            . $dosesLine
            . $freshLine
            . " *Баф:* {$boostText}\n"
            . $curedLine
            . "\n";
    }
}
