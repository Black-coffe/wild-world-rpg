<?php

declare(strict_types=1);

namespace App\Services\Player\Relocation;

use App\Entities\CharacterEntity;
use App\Models\CharacterModel;
use App\Models\MapModel;
use App\Models\TelegramUserModel;
use App\Services\GameSettings\GameSettingsReaderTrait;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * Единый пайплайн запроса «Полноценного переезда» базы: координаты → валидация → подтверждение.
 *
 * 🔴 Зачем вынесен. Раньше все восемь шагов (парсинг, поиск персонажа, диапазон, кулдаун, карта,
 * занятость клетки, изученность, предложение подтвердить) жили внутри `BaseShiftingCommand::execute`
 * и были доступны ТОЛЬКО игроку, который вручную наберёт `/base_shifting X=123Y=456`. Кнопка
 * «🚚 Полноценный переезд» на экране базы лишь показывала инструкцию, как эту команду напечатать —
 * единственное место в игре, где от игрока требовалось запомнить синтаксис. Прод (10 дней):
 * 7 тапов по кнопке → 6 набранных команд → 4 переезда.
 *
 * Теперь пайплайн общий: его зовёт и slash-команда, и forceReply-ответ на кнопку. Второй копии
 * валидаций не существует — расхождение путей невозможно.
 *
 * Дальше по флоу ничего не меняется: игрок получает то же подтверждение
 * (`StartRelocationConfirm_X_Y_cell`), которое создаёт 24-часовую задачу.
 */
class RelocationRequestService
{
    use GameSettingsReaderTrait;

    /**
     * Маркер в тексте промпта, по которому `GenericmessageCommand` узнаёт ответ игрока.
     * По образцу «✍ NAME» (ввод имени) и «SELL:/BUY:» (торговля).
     */
    public const PROMPT_MARKER = '🚚 ПЕРЕЕЗД';

    private ?RelocationValidator $validator = null;
    private ?ClosestCellFinder $closestFinder = null;
    private ?RelocationMessageFormatter $formatter = null;

    /**
     * Killswitch UX-переезда через forceReply (`buildings.relocation.force_reply`).
     * false (default) — DORMANT: кнопка показывает инструкцию про `/base_shifting`, byte-identical.
     * true — кнопка сразу спрашивает координаты, команда печатать не нужно (она остаётся рабочей).
     */
    public function forceReplyEnabled(): bool
    {
        return $this->gsBool('buildings.relocation.force_reply', false);
    }

    /**
     * Разбор координат из СВОБОДНОГО ответа игрока. Принимаем всё, что человек реально напишет:
     * «357 391», «357,391», «357:391», «x=357 y=391», «X=357Y=391».
     * Возвращает [x, y] либо null, если чисел не два.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function parseCoords(string $text): ?array
    {
        if (preg_match('/X\s*=\s*(\d+)\s*[,;: ]*\s*Y\s*=\s*(\d+)/i', $text, $m) === 1) {
            return [(int) $m[1], (int) $m[2]];
        }

        // Иначе берём первые два целых числа в строке. `preg_match_all` возвращает ЧИСЛО
        // совпадений (не 1/0, как preg_match) — сравнивать надо с ожидаемым количеством.
        // Длину ограничиваем: координаты 1..1000, «12345678901234567890» — не координата.
        if (preg_match_all('/\d{1,4}/', $text, $all) >= 2) {
            return [(int) $all[0][0], (int) $all[0][1]];
        }

        return null;
    }

    /** Промпт с forceReply: игрок отвечает координатами прямо в поле ввода. */
    public function askCoords(int $chatId): ServerResponse
    {
        $text = self::PROMPT_MARKER . " базы\n\n"
            . "Куда перевозим? Пришли ответом *две координаты* — например `357 391`.\n\n"
            . "📝 *Важно:*\n"
            . "1) X и Y — от 1 до 1000.\n"
            . "2) Клетка должна быть тобой *изучена* (или разведана роботом) и свободна. "
            . "Если нет — предложу ближайшую подходящую.\n"
            . "3) Переезд длится *24 часа*, постройки и ресурсы сохраняются полностью.\n"
            . "4) ⏳ Переезжать можно *раз в 10 дней*.";

        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode([
                'force_reply'             => true,
                'input_field_placeholder' => '357 391',
                'selective'               => true,
            ]),
        ]);
    }

    /** Ответ на неразобранные координаты — без упоминания синтаксиса команды. */
    public function coordsNotUnderstood(int $chatId): ServerResponse
    {
        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => "⚠️ Не понял координаты. Пришли два числа — например `357 391`.",
            'parse_mode' => 'Markdown',
        ]);
    }

    /**
     * Общий пайплайн: от координат до предложения подтвердить переезд.
     * Один и тот же для slash-команды и для ответа на forceReply.
     */
    public function handleCoords(int $chatId, int $telegramId, int $x, int $y): ServerResponse
    {
        $userRow = (new TelegramUserModel())->where('telegram_id', $telegramId)->first();
        if (! is_array($userRow)) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Ошибка: телеграм-профиль не найден.']);
        }
        $rawUserId = $userRow['id'] ?? null;
        $userId    = is_numeric($rawUserId) ? (int) $rawUserId : 0;

        $character = (new CharacterModel())->where('telegram_user_id', $userId)->first();
        if (! $character instanceof CharacterEntity) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'У вас нет персонажа! Сначала создайте персонажа.']);
        }

        if ($x < 1 || $x > 1000 || $y < 1 || $y > 1000) {
            return $this->send($chatId, $this->formatter()->coordsOutOfRange($x, $y));
        }

        $canProceed = $this->validator()->checkPreconditions($character);
        if ($canProceed['ok'] !== true) {
            return Request::sendMessage([
                'chat_id'    => $chatId,
                'text'       => $canProceed['error'] ?? 'Сейчас переехать нельзя.',
                'parse_mode' => 'Markdown',
            ]);
        }

        $mapRow = (new MapModel())->where('coordinate_x', $x)->where('coordinate_y', $y)->first();
        if (! is_array($mapRow)) {
            return $this->send($chatId, $this->formatter()->cellNotFound($x, $y));
        }
        $rawCell   = $mapRow['id'] ?? null;
        $mapCellId = is_numeric($rawCell) ? (int) $rawCell : 0;

        $rawCharId = $character['id'] ?? null;
        $charId    = is_numeric($rawCharId) ? (int) $rawCharId : 0;

        $reason = '';
        if (! $this->validator()->isCellAvailableForRelocation($charId, $mapCellId, $reason)) {
            $closestInfo = $this->closestFinder()->findClosest($charId, $x, $y);
            if (! $closestInfo) {
                return $this->send($chatId, $this->formatter()->cellUnavailableNoAlt($x, $y, $reason));
            }

            return $this->send($chatId, $this->formatter()->cellUnavailableWithAlt(
                $x,
                $y,
                $reason,
                $closestInfo['x'],
                $closestInfo['y'],
                $this->biomeName($closestInfo['map_id']),
                $this->altCellId($closestInfo['map_id'])
            ));
        }

        if (! $this->validator()->isCellExploredBy($charId, $mapCellId)) {
            $closestInfo = $this->closestFinder()->findClosest($charId, $x, $y);
            if (! $closestInfo) {
                return $this->send($chatId, $this->formatter()->cellNotExploredNoAlt($x, $y));
            }

            return $this->send($chatId, $this->formatter()->cellNotExploredWithAlt(
                $x,
                $y,
                $closestInfo['x'],
                $closestInfo['y'],
                $this->biomeName($closestInfo['map_id']),
                $this->altCellId($closestInfo['map_id'])
            ));
        }

        return $this->send($chatId, $this->formatter()->confirmPrompt($x, $y, $this->biomeName($mapCellId), $mapCellId));
    }

    /**
     * id альтернативной клетки для кнопки «Перенести туда» — ТОЛЬКО при включённом forceReply.
     * При OFF возвращаем null → формат сообщения остаётся историческим (byte-identical).
     */
    private function altCellId(int $mapId): ?int
    {
        return $this->forceReplyEnabled() ? $mapId : null;
    }

    /** @param array<string,mixed> $payload */
    private function send(int $chatId, array $payload): ServerResponse
    {
        return Request::sendMessage(array_merge(['chat_id' => $chatId], $payload));
    }

    /** Биом по map.id (для человекочитаемого текста альтернативной клетки). */
    protected function biomeName(int $mapId): string
    {
        $query = \Config\Database::connect()->table('map')
            ->select('biomes.name AS biome_name')
            ->join('biomes', 'biomes.id=map.biome_id', 'left')
            ->where('map.id', $mapId)
            ->get();

        if ($query === false) {
            return 'Неизвестный биом';
        }

        $row  = $query->getRowArray();
        $name = $row['biome_name'] ?? null;

        return is_string($name) && $name !== '' ? $name : 'Неизвестный биом';
    }

    protected function validator(): RelocationValidator
    {
        return $this->validator ??= new RelocationValidator();
    }

    protected function closestFinder(): ClosestCellFinder
    {
        return $this->closestFinder ??= new ClosestCellFinder($this->validator());
    }

    protected function formatter(): RelocationMessageFormatter
    {
        return $this->formatter ??= new RelocationMessageFormatter();
    }
}
