<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Camp\Decor;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Housing\BaseCampDecorService;
use App\Services\Notifications\MediaSender;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * W21 (ADR-076) — Housing customisation: экран декора базы.
 *
 * Callbacks:
 *   campDecor          — обзорный экран (текущий декор + кнопки изменить)
 *   campDecorName      — палитра из 12 пресетных имён
 *   campDecorFlag      — палитра из 16 emoji-флагов
 *   campSetName_<idx>  — сохранить имя по индексу → вернуть обзор
 *   campSetFlag_<idx>  — сохранить флаг по индексу → вернуть обзор
 *
 * Killswitch housing.decoration.enabled. edit-in-place через MediaSender::editTextOrSend.
 * Caption самодостаточен (media-off safe: только текст, без фото).
 */
final class BaseCampDecorAction extends BaseAction
{
    private BaseCampDecorService $decor;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->decor = new BaseCampDecorService();
    }

    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }
        if (! $this->decor->enabled()) {
            return $this->editText($chatId, "🎨 *Декор базы временно недоступен*\n\n_Раздел отключён администрацией._", [
                [['text' => '◀️ База', 'callback_data' => 'Base']],
            ]);
        }

        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $data   = (string) $this->callbackQuery->getData();

        if (preg_match('/^campSetName_(\d+)$/', $data, $m) === 1) {
            $this->decor->setCampName($charId, (int) $m[1]);
            return $this->showOverview($chatId, $charId);
        }

        if (preg_match('/^campSetFlag_(\d+)$/', $data, $m) === 1) {
            $this->decor->setCampFlag($charId, (int) $m[1]);
            return $this->showOverview($chatId, $charId);
        }

        return match ($data) {
            'campDecorName' => $this->showNamePalette($chatId),
            'campDecorFlag' => $this->showFlagPalette($chatId),
            default         => $this->showOverview($chatId, $charId),
        };
    }

    private function showOverview(int $chatId, int $charId): ServerResponse
    {
        $d    = $this->decor->getCampDecor($charId);
        $name = $d['name'];
        $flag = $d['flag'];

        $current = ($name !== null || $flag !== null)
            ? trim(($flag !== null ? $flag . ' ' : '') . ($name ?? '(без имени)'))
            : '_не настроен_';

        $text = "🎨 *Декор базы*\n\n"
            . "Текущий вид: {$current}\n\n"
            . "Выбери, что изменить:";

        return $this->editText($chatId, $text, [
            [
                ['text' => '✏️ Имя лагеря', 'callback_data' => 'campDecorName'],
                ['text' => '🏴 Флаг',       'callback_data' => 'campDecorFlag'],
            ],
            [['text' => '◀️ База', 'callback_data' => 'Base']],
        ]);
    }

    private function showNamePalette(int $chatId): ServerResponse
    {
        $rows   = [];
        $groups = array_chunk(BaseCampDecorService::PRESET_NAMES, 3, true);
        foreach ($groups as $group) {
            $row = [];
            foreach ($group as $idx => $name) {
                $row[] = ['text' => $name, 'callback_data' => "campSetName_{$idx}"];
            }
            $rows[] = $row;
        }
        $rows[] = [['text' => '◀️ Назад', 'callback_data' => 'campDecor']];

        return $this->editText($chatId, "✏️ *Выбери имя лагеря:*", $rows);
    }

    private function showFlagPalette(int $chatId): ServerResponse
    {
        $rows   = [];
        $groups = array_chunk(BaseCampDecorService::PRESET_FLAGS, 4, true);
        foreach ($groups as $group) {
            $row = [];
            foreach ($group as $idx => $flag) {
                $row[] = ['text' => $flag, 'callback_data' => "campSetFlag_{$idx}"];
            }
            $rows[] = $row;
        }
        $rows[] = [['text' => '◀️ Назад', 'callback_data' => 'campDecor']];

        return $this->editText($chatId, "🏴 *Выбери флаг лагеря:*", $rows);
    }

    /**
     * @param list<list<array{text: string, callback_data: string}>> $rows
     */
    private function editText(int $chatId, string $text, array $rows): ServerResponse
    {
        return MediaSender::editTextOrSend($this->navTarget() + [
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]) ?: '{}',
        ]);
    }
}
