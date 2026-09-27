<?php

namespace App\Services\Player;

use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\ButtonPacker;
use App\Services\Telegram\Request;

/**
 * Карточка персонажа в боте — тонкий рендерер модели {@see CharacterSheetService} (W2.N1-01, ADR-190).
 *
 * @phpstan-import-type Sheet from CharacterSheetService
 * @phpstan-import-type Action from CharacterSheetService
 */
class CharacterService
{
    /**
     * Показ информации о персонаже — рендерер модели {@see CharacterSheetService} для бота
     * (W2.N1-01, ADR-190: веб рисует экран «Я» из той же модели).
     */
    public function showCharacterInfo(int $chatId, array|\App\Entities\CharacterEntity $characterRow): ServerResponse
    {
        // Reply-keyboard (нижнее меню Перс/База/Крафт/Карта/Настройки) ставит /start
        // (StartCommand — теперь ВСЕГДА, и новым, и существующим игрокам; см. правку
        // 2026-06-01 после ADR-087) и НИГДЕ не снимается → персистентна на клиенте.
        // showCharacterInfo НЕ шлёт её сама: сюда попадают либо через /start (только что
        // переотправил), либо по нажатию reply-кнопки «Перс» (клавиатура уже на экране).
        // Карточке нужен inline-keyboard, а reply+inline на одном сообщении Telegram не
        // совмещает → отдельное «привязочное» сообщение тут было бы мусором.
        $sheet = (new CharacterSheetService())->fromRow($characterRow);

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => self::cardText($sheet),
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => self::cardKeyboard($sheet)]),
        ]);
    }

    /**
     * Текст карточки бота (legacy-Markdown) из модели персонажа.
     *
     * @param Sheet $sheet модель {@see CharacterSheetService::fromRow()}
     */
    public static function cardText(array $sheet): string
    {
        $gold     = $sheet['gold'];
        $goldText = ($gold > 0)
            ? "🧰 Есть 💰*" . number_format($gold) . "* золота"
            : "🧰 Золото отсутствует!";

        $text = "🤖 *Персонаж {$sheet['name']}*\n";
        if ($sheet['faction'] !== null) {
            $text .= "🏳️ *Фракция:* {$sheet['faction']}\n";
        }
        if ($sheet['cell'] !== null) {
            $text .= "🧭 *Координаты:* X={$sheet['cell']['x']} Y={$sheet['cell']['y']} | 🌄 {$sheet['biome']}\n";
        }

        // S4 (ROADMAP-RETENTION-10) — «полярная звезда»: текущая цель онбординг-цепочки
        // видна ВВЕРХУ карточки (gated onboarding.cold_open_v2.enabled → null = строки нет).
        if ($sheet['polar_line'] !== null) {
            $text .= $sheet['polar_line'] . "\n";
        }

        $text .= "🎢 *Изучено ячеек:* {$sheet['explored']}\n"
            . "💼 *Всего видов ресурсов:* {$sheet['resource_kinds']}\n"
            . "⏳ *В игре:* {$sheet['time_in_game']}\n"
            . "📈 *Уровень:* {$sheet['level']}\n";

        // Слайс «Видимая лестница L1→L10» — прогресс к следующему уровню и что он откроет
        // (gated progression.ladder.enabled → null = строк нет, ADR-024).
        if ($sheet['ladder_line'] !== null) {
            $text .= $sheet['ladder_line'] . "\n";
            if ($sheet['unlock_line'] !== null) {
                $text .= $sheet['unlock_line'] . "\n";
            }
        }

        $text .= "🌟 *Опыт:* {$sheet['experience']}\n"
            . "🤸‍♂️ *Ловкость:* {$sheet['agility']}\n"
            . "🧠 *Интеллект:* {$sheet['intellect']}\n"
            . "💪 *Сила:* {$sheet['strength']}\n\n"
            . "💖 *Здоровье:* {$sheet['health']}\n"
            . "🥱 *Выносливость:* {$sheet['tired']}\n\n"
            . "💹 *Карма торговли:* {$sheet['trading_karma']}\n"
            . $goldText . "\n";

        // Раны, которые не лечатся едой: игрок видит их там же, где смотрит здоровье.
        if ($sheet['debuffs'] !== []) {
            $text .= "🩺 *Раны:*\n";
            foreach ($sheet['debuffs'] as $line) {
                $text .= $line . "\n";
            }
            $text .= "_Еда их не снимает — нужен предмет из «💊 Аптечки»._\n\n";
        }

        // E6 (ADR-108) серия входов; ADR-132 Ф2 следующая веха; E11 (ADR-112) титул.
        $text .= ($sheet['streak_line'] !== null ? $sheet['streak_line'] . "\n" : '');
        $text .= ($sheet['milestone_line'] !== null ? $sheet['milestone_line'] . "\n" : '');
        if ($sheet['title'] !== null) {
            $text .= "🎖 *Титул:* {$sheet['title']}\n";
        }
        $text .= "\n";

        $text .= "🛡 *Броня:* " . (($sheet['armor'] ?? '') !== '' ? $sheet['armor'] : "❌ Нет") . "\n";
        $text .= "⚔️ *Оружие:* " . (($sheet['weapon'] ?? '') !== '' ? $sheet['weapon'] : "❌ Нет") . "\n";

        // V16 (ADR-047): крафт-специализация (null — фича выключена).
        if ($sheet['specialization'] !== null) {
            $text .= "🎓 *Специализация:* {$sheet['specialization']}\n";
        }

        // W5 (ADR-064): активный боевой дрон.
        if ($sheet['drone'] !== null) {
            $text .= "🛡 *Боевой дрон:* активен `{$sheet['drone']['minutes']}` мин (+{$sheet['drone']['bonus']}% инициативы)\n";
        }

        return $text;
    }

    /**
     * Inline-клавиатура карточки. ADR-150 ФИНАЛ: личное + хвост одним списком по частоте, ряды
     * режет {@see ButtonPacker::packByCount()} (ни одной одиночки; tripleAt=2 — тройка на коротких
     * подписях Экип/Аптечка/Страховка). Legacy-сетки (killswitch OFF) — прежние ряды byte-identical,
     * хвост — тем же упаковщиком.
     *
     * @param Sheet $sheet
     *
     * @return array<int, array<int, array<string, string>>>
     */
    public static function cardKeyboard(array $sheet): array
    {
        $tailFlat = self::buttons($sheet['tail_actions']);

        if (\App\Services\Telegram\BotMenuService::finalGridEnabled()) {
            $personalFlat = self::buttons($sheet['personal_actions']);

            return ButtonPacker::packByCount(array_merge($personalFlat, $tailFlat), 2);
        }

        if (\App\Services\Telegram\BotMenuService::meHubEnabled()) {
            // ADR-150 Слайс 2: личный блок «Я» сверху, чужегрупповые кнопки ниже.
            $inlineRows = [
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '⚔️ Экип',      'callback_data' => 'equipMenu'],
                ],
                [
                    ['text' => '💊 Аптечка',   'callback_data' => 'pharmacy'],
                    ['text' => '🧍 Страховка', 'callback_data' => 'PersonalInsurance'],
                ],
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '📡 Маяки',          'callback_data' => 'teleportBeacon'],
                ],
                [
                    ['text' => '🛒 Магазин',     'callback_data' => 'shop'],
                    ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'],
                    ['text' => '🎉 События',     'callback_data' => 'events'],
                ],
            ];
        } else {
            $inlineRows = [
                [
                    ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'],
                    ['text' => '🎉 События',     'callback_data' => 'events'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                ],
                [
                    ['text' => '📡 Маяки',       'callback_data' => 'teleportBeacon'],
                    ['text' => '🎒 Инвентарь',   'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин',     'callback_data' => 'shop'],
                ],
                [
                    ['text' => '🧍 Страховка',      'callback_data' => 'PersonalInsurance'],
                    ['text' => '💊 Аптечка',        'callback_data' => 'pharmacy'],
                    ['text' => '⚔️ Экип',           'callback_data' => 'equipMenu'],
                ],
            ];
        }

        return array_merge($inlineRows, ButtonPacker::packByCount($tailFlat));
    }

    /**
     * Действия модели → кнопки Telegram.
     *
     * @param list<Action> $actions
     *
     * @return list<array<string, string>>
     */
    private static function buttons(array $actions): array
    {
        $out = [];
        foreach ($actions as $a) {
            if (isset($a['callback'])) {
                $out[] = ['text' => $a['label'], 'callback_data' => $a['callback']];
            } elseif (isset($a['url'])) {
                $out[] = ['text' => $a['label'], 'url' => $a['url']];
            }
        }

        return $out;
    }
}
