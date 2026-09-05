<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions;

use App\Services\Notifications\MediaSender;
use App\Services\Telegram\Request;
use App\Services\World\ExploredMapService;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * «🔍 Что я открыл» — личная карта исследованного.
 *
 * Вопрос игрока (Анжела, 18.08.2026): «нет ли ресурса, показывающего открытую карту?»
 * До этого — не было. `explored_cells` копится с первого шага, но игроку не
 * показывался нигде: «🗺 Обзор» рисует весь мир 1000×1000 с точкой игрока (туман войны
 * там не отражён), публичная `/map` личные слои намеренно скрывает, а единственный
 * код, умевший рисовать исследованное, лежал без вызывающих.
 *
 * Media-off (ADR-020): caption самодостаточен — сколько клеток открыто, в каких
 * границах, какие биомы попались. Картинка только показывает форму открытого пятна;
 * при `disable_media=1` MediaSender отправит тот же текст без фото, и смысл не теряется.
 */
final class ExploredMapAction extends BaseAction
{
    /** Сколько биомов перечисляем в caption (лимит подписи фото — 1024). */
    private const BIOME_LINES = 5;

    private ExploredMapService $service;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->service = new ExploredMapService();
    }

    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }

        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $summary     = $this->service->summary($characterId);

        if ($summary['explored'] <= 0) {
            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => "🔍 *Что я открыл*\n\n"
                    . "Пока ничего: карта открывается сама, когда ходишь — каждый шаг снимает туман "
                    . "с соседних клеток. Сделай первый шаг, и здесь появится твоё пятно на карте.",
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode(['inline_keyboard' => [[
                    ['text' => '🧭 Идти',  'callback_data' => 'move'],
                    ['text' => '🗺 Обзор', 'callback_data' => 'mapOverview'],
                ]]]),
            ]);
        }

        [$playerX, $playerY] = $this->playerPosition($character);

        $text = $this->caption($summary, $playerX, $playerY);

        $keyboard = json_encode(['inline_keyboard' => [
            [
                ['text' => '🧭 Идти',  'callback_data' => 'move'],
                ['text' => '🗺 Обзор', 'callback_data' => 'mapOverview'],
            ],
            [
                ['text' => '🚁 Дроны', 'callback_data' => 'droneScoutList'],
            ],
        ]]);

        $file = $this->service->renderPng($characterId, $playerX, $playerY);

        if ($file === null) {
            // Картинка не собралась (нет GD / не записался файл) — экран всё равно
            // полноценен: весь смысл в тексте.
            return Request::sendMessage([
                'chat_id'      => $chatId,
                'text'         => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => $keyboard,
            ]);
        }

        return MediaSender::sendPhotoOrText([
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($file),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => $keyboard,
        ]);
    }

    /**
     * @param array{explored:int, percent:float, min_x:int, max_x:int, min_y:int, max_y:int, width:int, height:int, biomes:list<array{name:string, cells:int}>} $s
     */
    private function caption(array $s, ?int $playerX, ?int $playerY): string
    {
        $total = ExploredMapService::WORLD_SIDE * ExploredMapService::WORLD_SIDE;

        $text = "🔍 *Что я открыл*\n\n"
            . "Открыто клеток: *" . $this->fmt($s['explored']) . "* из " . $this->fmt($total)
            . " (" . $this->percent($s['percent']) . "% острова)\n"
            . "Твои границы: `X {$s['min_x']}–{$s['max_x']}`, `Y {$s['min_y']}–{$s['max_y']}` "
            . "— прямоугольник {$s['width']}×{$s['height']}\n\n";

        if ($s['biomes'] !== []) {
            $text .= "Где успел побывать:\n";
            foreach (array_slice($s['biomes'], 0, self::BIOME_LINES) as $b) {
                $text .= "• {$b['name']} — " . $this->fmt($b['cells']) . " кл.\n";
            }
            if (count($s['biomes']) > self::BIOME_LINES) {
                $text .= "• …и ещё " . (count($s['biomes']) - self::BIOME_LINES) . " биомов\n";
            }
            $text .= "\n";
        }

        if ($playerX !== null && $playerY !== null) {
            $text .= "📍 Ты сейчас: `X={$playerX} Y={$playerY}` — на картинке отмечен красным\n\n";
        }

        $text .= "_Цвет клетки — её биом; тёмное поле вокруг — то, куда ты ещё не заходил. "
            . "Карта открывается шагами, а разом большой кусок снимает дрон-разведчик._";

        return $text;
    }

    /**
     * @param  array<string,mixed>|\App\Entities\CharacterEntity $character
     * @return array{0: int|null, 1: int|null}
     */
    private function playerPosition(array|\App\Entities\CharacterEntity $character): array
    {
        $cellNumber = is_numeric($character['cell_number'] ?? null) ? (int) $character['cell_number'] : 0;
        if ($cellNumber <= 0) {
            return [null, null];
        }

        $row = (new \App\Models\MapModel())->where('cell_number', $cellNumber)->first();
        if (! is_array($row)) {
            return [null, null];
        }

        $x = is_numeric($row['coordinate_x'] ?? null) ? (int) $row['coordinate_x'] : null;
        $y = is_numeric($row['coordinate_y'] ?? null) ? (int) $row['coordinate_y'] : null;

        return [$x, $y];
    }

    private function fmt(int $n): string
    {
        return number_format($n, 0, '.', ' ');
    }

    /**
     * Проценты у новичка — сотые доли: округление до целого показало бы «0%»
     * там, где человек только что прошёл сотню клеток.
     */
    private function percent(float $percent): string
    {
        if ($percent >= 1) {
            return number_format($percent, 1, '.', ' ');
        }

        return number_format($percent, 3, '.', ' ');
    }
}
