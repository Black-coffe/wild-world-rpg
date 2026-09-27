<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Helpers\ResourceIconHelper;
use App\Services\Bases\BaseCallbackSuffix;
use App\Services\Buildings\BuildOrderService;
use App\Services\Notifications\MediaSender;
use App\Services\Tasks\ActionScopeService;
use App\Services\Tasks\ActiveTasksService;
use Config\Buildings;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * S1 (v0.51.182+) — generic preview-handler для всех зданий (`genericBuildInfo_<Key>`[`_b<id>`]).
 *
 * w2-n4-base-02 (ADR-190): гейты и данные карточки — ядро {@see BuildOrderService::preview()}, общее с
 * вебом; здесь прежние тексты и кнопки. Суффикс базы — подсказка, ядро перепроверяет, что игрок на ней;
 * кнопки карточки («Строить», «🏗 Строить», «🏠 База») несут его дальше. Обещание «займёт ~N минут» — из
 * ядра, тем же `BuildDurationService`, что и сама стройка (ADR-160): экран времени не считает.
 *
 * Контракт preview (1:1 с legacy):
 *   - Нет user/character → ошибка.
 *   - Активный переезд → блок сервисом `ActiveTasksService`.
 *   - Нет лагеря → buttons «Разбить лагерь / Действия».
 *   - Не на базе → buttons «Телепорт / Двигаться».
 *   - Низкий уровень → buttons «Телепорт / Двигаться» + сообщение про уровень.
 *   - Список ресурсов + крафтовых компонентов (с иконками + в наличии).
 *   - Список зависимостей (зданий, которые должны быть построены).
 *   - Если всего хватает → кнопка «🛠️ Строить» → `genericStartBuild_<Key>` → {@see GenericBuildingAction}.
 *   - Если не хватает → buttons «Добыть / Купить / Действия / Инвентарь».
 *   - Edit-in-place через `MediaSender::editOrSend()` (ADR-018).
 */
class GenericBuildingInfoAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // 1. Parse building key from callback_data: genericBuildInfo_HandPump[_b<id>]
        [$callbackData, $baseId] = BaseCallbackSuffix::split((string) $this->callbackQuery->getData());
        $parts       = explode('_', $callbackData, 2);
        $buildingKey = $parts[1] ?? '';
        if ($buildingKey === '') {
            return $this->sendError('Не указан тип здания.');
        }

        /** @var Buildings $cfg */
        $cfg    = config('Buildings');
        $recipe = $cfg->get($buildingKey);
        if ($recipe === null) {
            return $this->sendError("Неизвестное здание: {$buildingKey}");
        }

        // 2. user/character
        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return $this->sendError('Пользователь не найден в базе данных или персонаж не определён.');
        }

        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // 3. Active relocation block
        if ((new ActiveTasksService())->checkRelocationAndBlock(
            $character['id'],
            $this->callbackQuery->getId(),
            $chatId
        )) {
            return Request::emptyResponse();
        }

        $emoji     = $recipe['emoji'];
        $nameRus   = $recipe['name_rus'];
        $infoText  = $recipe['info_text'];
        $minLevel  = $recipe['level_required'];
        $imagePath = base_url($recipe['image_in_progress']);
        $suffix    = static fn (string $cb): string => $baseId !== null ? BaseCallbackSuffix::append($cb, $baseId) : $cb;

        $p = (new BuildOrderService())->preview((int) $character['id'], $baseId, $buildingKey);

        switch ($p['code']) {
            case BuildOrderService::LEANTO_GATED:
                // Причина отказа разная (уже поставил / перерос уровень / killswitch) — говорим правду.
                return $this->card($imagePath, \App\Services\Buildings\BuildingCopyNotice::leanToGateExplanation($p['reason']), [[
                    ['text' => '🏗 Строить', 'callback_data' => $suffix('Build')],
                    ['text' => '🏠 База', 'callback_data' => $suffix('Base')],
                ]]);
            case BuildOrderService::NO_CAMP:
                return $this->card($imagePath, "У вас нет лагеря. Разбейте лагерь, чтобы продолжить.", [[
                    ['text' => '🏕 Разбить лагерь', 'callback_data' => 'Camp'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                ]]);
            case BuildOrderService::NOT_ON_BASE:
                return $this->card($imagePath, "Вы находитесь не в лагере. Переместитесь в лагерь, чтобы продолжить строительство.", [[
                    ['text' => '📡 Телепорт', 'callback_data' => 'TeleportToCamp'],
                    ['text' => '🧭 Двигаться', 'callback_data' => 'move'],
                ]]);
            case BuildOrderService::LOW_LEVEL:
                return $this->card($imagePath, "Ваш уровень слишком низкий для строительства *{$emoji} {$nameRus}*. Нужно хотя бы уровень: *{$minLevel}*.", [[
                    ['text' => '📡 Телепорт', 'callback_data' => 'TeleportToCamp'],
                    ['text' => '🧭 Двигаться', 'callback_data' => 'move'],
                ]]);
            case BuildOrderService::PREVIEW:
                break;
            default:
                return $this->sendError('Пользователь не найден в базе данных или персонаж не определён.');
        }

        // ADR-143: легенда области действия — стройка «🏠 Только на базе» + занятость (фоновая).
        $scope   = new ActionScopeService();
        $minutes = $p['minutes'];
        $caption = "*{$emoji} {$nameRus}!*\n"
            . $scope->scopeLine(ActionScopeService::KIND_BUILD, $p['background']) . "\n\n"
            . "Для строительства тебе нужны:\n\n"
            . $this->materialsText($p['resources'])
            . $this->materialsText($p['items'])
            . ($minutes !== null
                ? "\n*Строительство займёт:* ~{$minutes} минут"
                    . $this->durationBonusNote($minutes, $p['max_minutes']) . "\n"
                : '')
            . ($infoText !== '' ? "\n*Описание:* {$infoText}\n\n" : "\n");

        if ($p['missing_deps'] !== []) {
            $caption .= "\n*Сначала постройте:* " . implode(', ', $p['missing_deps']) . "\n";
        }
        if ($p['missing_resources'] !== []) {
            $caption .= "\nНедостающие ресурсы:\n" . $this->formatMissing($p['missing_resources']);
        }
        if ($p['missing_items'] !== []) {
            $caption .= "\nНедостающие предметы:\n" . $this->formatMissing($p['missing_items']);
        }

        // ADR-156: честная оговорка про материалы выше собственного уровня — только при нехватке и только
        // то, что вне досягаемости. Ресурсы продаются в магазине, предметы — верстак или закрытый рынок (Склад).
        if ($p['out_of_reach'] !== []) {
            $parts    = [];
            $hasRes   = false;
            $hasCraft = false;
            foreach (array_slice($p['out_of_reach'], 0, 3) as $row) {
                $parts[] = "{$row['name']} ({$row['kind']} с {$row['level']} ур.)";
                $row['kind'] === 'крафт' ? $hasCraft = true : $hasRes = true;
            }
            $ways = [];
            if ($hasRes) {
                $ways[] = 'ресурсы продаются в магазине';
            }
            if ($hasCraft) {
                $ways[] = 'предметы делают на верстаке или берут на закрытом рынке (нужен Склад)';
            }
            $caption .= "\n⚠️ *На своём уровне добудешь не всё:* " . implode(', ', $parts)
                . '. ' . ucfirst(implode('; ', $ways)) . ".\n";
        }

        // building-bonus-absorption story-02: здание этого типа уже есть — оговорка про поглощение бонуса,
        // налог за каждое и оборону. Кнопку «Строить» не убираем; fitting-версия не выталкивает caption за 1024.
        if ($p['duplicate']['owned']) {
            $caption .= \App\Services\Buildings\BuildingCopyNotice::duplicateWarningFitting(
                $caption,
                $emoji,
                $nameRus,
                $p['duplicate']['stacks_defense']
            );
        }

        // Дефицит-ссылки: каждый недостающий ресурс — своя кнопка сразу на нужное количество. Первые четыре,
        // по две в ряд; остальное добирается общей кнопкой.
        $needBtns = [];
        foreach (array_slice($p['missing_resources'], 0, 4) as $info) {
            $lack = max(0, $info['need'] - $info['have']);
            if ($info['id'] <= 0 || $lack <= 0) {
                continue;
            }
            $needBtns[] = [
                'text'          => "🛒 {$info['name']} ×{$lack}",
                'callback_data' => "buy_need_{$info['id']}_{$lack}",
            ];
        }

        $rows = array_chunk($needBtns, 2);
        // ADR-168 — метка источника «экран постройки».
        $rows[] = [
            ['text' => '⛏️ Добыть ресурсы', 'callback_data' => \App\Services\Logging\ActionOrigin::tag('gather', \App\Services\Logging\ActionOrigin::FROM_BUILDING)],
            ['text' => '🛍️ Купить', 'callback_data' => 'buy'],
        ];
        $rows[] = [
            ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
            ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
        ];
        if ($p['can_start']) {
            $rows[] = [[
                'text'          => "🛠️ Строить {$emoji} {$nameRus}",
                'callback_data' => $suffix("genericStartBuild_{$buildingKey}"),
            ]];
        }

        return $this->card($imagePath, $caption, $rows);
    }

    /**
     * @param list<array<int, array{text: string, callback_data: string}>> $rows
     */
    private function card(string $imagePath, string $caption, array $rows): ServerResponse
    {
        return MediaSender::editOrSend($this->navTarget() + [
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $caption,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);
    }

    /**
     * @param list<array{key: string, name: string, id: int, need: int, have: int, in_db: bool}> $rows
     */
    private function materialsText(array $rows): string
    {
        $out = '';
        foreach ($rows as $r) {
            if (! $r['in_db']) {
                $out .= "- {$r['key']} (нет в DB) - {$r['need']}\n";
                continue;
            }
            $icon = ResourceIconHelper::for($r['name']);
            $out .= "{$icon} {$r['name']} - {$r['need']} ед. (в наличии {$r['have']} ед.)\n";
        }

        return $out;
    }

    /**
     * @param list<array{key: string, name: string, id: int, need: int, have: int, in_db: bool}> $missing
     */
    private function formatMissing(array $missing): string
    {
        $lines = [];
        foreach ($missing as $info) {
            $name    = $info['in_db'] ? $info['name'] : $info['key'] . ' (нет в DB)';
            $lines[] = "- {$name}: требуется {$info['need']}, в наличии {$info['have']}";
        }
        return implode("\n", $lines) . "\n";
    }

    /**
     * ADR-162: строка правды о выигрыше от статов — « (новичку — 74 мин)». Пусто, когда выигрыша нет или
     * у задачи нет верхней границы. «−0%» не пишем (ADR-158).
     */
    private function durationBonusNote(int $minutes, int $max): string
    {
        if ($max <= 0 || $minutes >= $max) {
            return '';
        }

        return " _(новичку — {$max} мин; ускоряют опыт, ловкость и интеллект)_";
    }

    private function sendError(string $text): ServerResponse
    {
        return Request::sendMessage([
            'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);
    }
}
