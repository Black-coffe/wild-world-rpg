<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Faction;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Player\FactionProjectService;
use Longman\TelegramBot\Entities\CallbackQuery;

/**
 * V20 (ADR-051) — общий рендер экрана фракц-проекта (view + deposit-confirm).
 */
abstract class FactionProjectBaseAction extends BaseAction
{
    protected FactionProjectService $projectService;

    /** Лейблы играбельных фракций (эмодзи как в ChooseFaction). */
    protected const FACTION_LABELS = [
        1 => '🛡️ Милитари',
        2 => '🌲 Партизаны',
        3 => '🛠️ Инженеры',
        4 => '🌾 Фермеры',
    ];

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->projectService = new FactionProjectService();
    }

    /**
     * Текст + клавиатура экрана фракц-проекта. $prefix — опц. строка-результат сверху.
     *
     * @return array{text:string, keyboard:array<string,mixed>}
     */
    protected function buildProjectScreen(int $factionId, string $prefix = ''): array
    {
        $label     = self::FACTION_LABELS[$factionId] ?? 'Фракция';
        $project   = $this->projectService->getOrCreateProject($factionId);
        $progress  = isset($project['progress']) && is_numeric($project['progress']) ? (int) $project['progress'] : 0;
        $threshold = $this->projectService->threshold();
        $deposit   = $this->projectService->depositGold();
        $buffTs    = $this->projectService->buffActiveTs($factionId);
        $pct       = (int) round((1.0 - $this->projectService->craftTimeMultiplier()) * 100);
        $hours     = $this->projectService->buffDurationHours();

        $text = ($prefix !== '' ? $prefix . "\n\n" : '')
            . "🤝 *Проект фракции: {$label}*\n\n"
            . "Фракция сообща копит на снабжение. При достижении цели *вся фракция* получает "
            . "ускорение крафта −{$pct}% на {$hours} ч.\n\n"
            . "📊 Прогресс: *{$progress}* / {$threshold} 🪙\n";

        if ($buffTs !== null) {
            $text .= "⚡ *Бафф активен:* −{$pct}% к времени крафта до " . date('d.m H:i', $buffTs) . "\n";
        } else {
            $text .= "⏳ Бафф неактивен — добей цель, чтобы включить.\n";
        }
        $text .= "\nОдин вклад: *{$deposit}* 🪙 (золото в общий пул).";

        $keyboard = ['inline_keyboard' => [[
            ['text' => "💰 Внести {$deposit}🪙", 'callback_data' => 'factionDeposit'],
            ['text' => '◀️ Назад',              'callback_data' => 'character'],
        ]]];

        return ['text' => $text, 'keyboard' => $keyboard];
    }
}
