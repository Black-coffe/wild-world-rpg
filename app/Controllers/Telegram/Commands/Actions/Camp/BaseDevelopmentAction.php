<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Robots\StartRobotGatheringAction;
use App\Services\Bases\BaseCallbackSuffix;
use App\Services\Bases\BaseScopeResolver;
use App\Services\BuildingEffects\BuildingEffectLines;
use App\Services\Notifications\MediaSender;
use Config\Database;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * E18 (ADR-118) — витрина «🏗 Развитие базы». Callback `baseDevelopment`.
 *
 * Легибельность апгрейдов: аудит (BUILDING_BUFFS_AUDIT) + данные (38 зданий на L1, крутой обрыв)
 * показали — игроки НЕ качают постройки, т.к. не видят ценность уровня. Плато L4-10 уже починено
 * (ADR-042 интерполяция), но эффект невидим. Этот экран показывает по каждой постройке: уровень +
 * ТЕКУЩИЙ эффект + что даёт СЛЕДУЮЩИЙ уровень (реюз BuildingEffectsService::effectAtLevel).
 *
 * Read-only, аддитивный (не трогает основной экран базы), edit-in-place. Media-off самодостаточен.
 *
 * story multibase-picker-06 — экран показывает постройки ВЫБРАННОЙ базы, не `MAX(level)`
 * по всем базам персонажа сразу. Суффикс `_b<id>` (см. {@see BaseCallbackSuffix}) в
 * `callback_data` → {@see BaseScopeResolver::resolveForBase()}, `unavailable` — честный
 * отказ вместо чужих уровней. Без суффикса — прежнее правило {@see BaseScopeResolver::resolve()}.
 *
 * w2-n4-tails-02: строки эффекта — {@see BuildingEffectLines} (общие с запросом апгрейда), текст экрана прежний.
 */
final class BaseDevelopmentAction extends BaseAction
{
    private BuildingEffectLines $lines;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->lines = new BuildingEffectLines();
    }

    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        if (! $user || ! $character) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }

        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;

        [, $baseId]  = BaseCallbackSuffix::split((string) $this->callbackQuery->getData());
        $currentCell = is_numeric($character['cell_number'] ?? null) ? (int) $character['cell_number'] : 0;
        $resolver    = new BaseScopeResolver();

        if ($baseId !== null) {
            $resolved = $resolver->resolveForBase($charId, $currentCell, $baseId);
            if ($resolved['cell'] === null) {
                return $this->refusal($chatId, $resolved['text'], $baseId);
            }
            $cell = $resolved['cell'];
        } else {
            $legacy = $resolver->resolve($charId, $currentCell);
            if ($legacy['cell'] === null) {
                return $this->refusal($chatId, (string) $legacy['text'], null);
            }
            $cell = $legacy['cell'];
        }

        // Активный уровень здания для эффектов = MAX(level) в пределах ВЫБРАННОЙ базы
        // (раньше — по всем базам персонажа сразу, story multibase-picker-06).
        $rows = Database::connect()->table('character_buildings cb')
            ->select('b.name_en AS name_en, b.name_ru AS name_ru, MAX(cb.level) AS lvl')
            ->join('buildings b', 'b.id = cb.building_id', 'inner')
            ->where('cb.character_id', $charId)
            ->where('cb.map_cell_id', $cell)
            ->groupBy('b.id')
            ->get();
        $built = $rows === false ? [] : $rows->getResultArray();

        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'text'         => $this->buildText($built, StartRobotGatheringAction::baseLabel($charId, $cell)),
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => [
                [['text' => '🏗 К базе', 'callback_data' => $this->toBaseCallback($baseId)]],
                [['text' => '◀️ Я', 'callback_data' => 'character']],
            ]]) ?: '{}',
        ]);
    }

    /** multibase-picker-11: `construction_b<id>`, если экран открыт с суффиксом; иначе голый `construction`. */
    private function toBaseCallback(?int $baseId): string
    {
        return $baseId === null ? 'construction' : BaseCallbackSuffix::append('construction', $baseId);
    }

    /**
     * story multibase-picker-06 — честный отказ (база чужая/неактивна/вне сигнала, либо ≥2 баз без выбора).
     * multibase-picker-11: «🏗 К базе» несёт суффикс, если экран открыт с ним.
     */
    private function refusal(int $chatId, string $text, ?int $baseId): ServerResponse
    {
        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'text'         => $text,
            'reply_markup' => json_encode(['inline_keyboard' => [
                [['text' => '🏗 К базе', 'callback_data' => $this->toBaseCallback($baseId)]],
                [['text' => '◀️ Я', 'callback_data' => 'character']],
            ]]) ?: '{}',
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $built
     * @param string $baseLabel «Имя (x, y)» показанной базы (multibase-picker-11), уже markdown-safe
     */
    private function buildText(array $built, string $baseLabel): string
    {
        $text = "🏗 *Развитие базы*\n🏠 База: {$baseLabel}\n\n";
        if ($built === []) {
            return $text . "У тебя пока нет построек.\n\n_Разбей лагерь («База» → «🏕 Разбить лагерь») и строй: каждый уровень постройки усиливает её эффект._";
        }

        $sum = 0;
        $cnt = 0;
        foreach ($built as $b) {
            $lvl = is_numeric($b['lvl'] ?? null) ? (int) $b['lvl'] : 1;
            $sum += $lvl;
            $cnt++;
        }
        $avg = round($sum / $cnt, 1); // $cnt ≥ 1: пустой $built отсечён выше
        $text .= "Средний уровень построек: *{$avg}/10* ({$cnt} шт.)\n\n";

        foreach ($built as $b) {
            $nameEn = is_string($b['name_en'] ?? null) ? $b['name_en'] : '';
            $nameRu = is_string($b['name_ru'] ?? null) ? $b['name_ru'] : $nameEn;
            $lvl    = is_numeric($b['lvl'] ?? null) ? (int) $b['lvl'] : 1;
            $icon   = BuildingEffectLines::icon($nameEn);

            $text .= "{$icon} *{$nameRu}* — ур. *{$lvl}/10*\n";

            // w2-n4-tails-02: строка эффекта — общий расчёт с запросом апгрейда (бот и веб).
            $line = $this->lines->developmentLine($nameEn, $lvl);
            if ($line !== null) {
                $text .= "    {$line}\n";
            }
        }

        $text .= "\n_💡 Каждый уровень постройки усиливает её эффект (плавно до ур.10). Прокачка — на экране базы → постройка → «Улучшить»._";
        return $text;
    }
}
