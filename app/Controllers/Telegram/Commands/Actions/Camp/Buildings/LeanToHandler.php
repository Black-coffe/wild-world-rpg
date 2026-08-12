<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Models\BuildingModel;
use App\Models\CharacterBuildingModel;
use App\Services\Notifications\MediaSender;
use Longman\TelegramBot\Entities\CallbackQuery;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

/**
 * Экран «⛺ Навес» (LeanTo) — первая постройка новичка (S5, ADR-142).
 *
 * Багрепорт из чата Bugs-info (12.08.2026, игрок Анжела): тап по «⛺ Навес L1»
 * на своей базе отвечал «Неизвестное строение, в классе: BuildingHandlerAction».
 * Причина: `BuildingHandlerAction::handle()` — switch по name_eng, и case для
 * `LeanTo` не завели, когда Навес добавляли (S5). Здание падало в `default`.
 *
 * Тот же класс, что ADR-041 ({@see DefensiveBuildingHandler}), но больнее:
 * Навес — ПЕРВАЯ постройка онбординга, дофаминовый момент «база ожила».
 * Игрок ставил её и первым же тапом получал ошибку. На проде 21 владелец
 * (первый 27.06, последний 11.08) — и каждый упирался в этот тупик.
 *
 * Экран намеренно НЕ предлагает «🆙 Поднять уровень»: Навес — one-shot стартовое
 * укрытие без уровневой прокачки и без постоянного эффекта. Предложить апгрейд
 * значило бы обещать то, чего нет (и завести новый тупик). Вместо этого экран
 * честно говорит, что Навес уже отработал своё, и ведёт к следующему шагу —
 * ровно как обещает его собственный completion_text: «Дальше — больше: Склад,
 * Теплица и оборона ждут своей очереди».
 */
class LeanToHandler extends BaseAction
{
    private const NAME_ENG = 'LeanTo';
    private const IMAGE    = 'uploads/telegram/camp/lean_to.jpg';

    protected CharacterBuildingModel $characterBuildingModel;
    protected BuildingModel $buildingModel;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->characterBuildingModel = new CharacterBuildingModel();
        $this->buildingModel          = new BuildingModel();
    }

    public function handle(): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }

        $buildingInfo = $this->buildingModel->where('name_en', self::NAME_ENG)->first();
        if (!is_array($buildingInfo)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Информация о постройке не найдена.',
            ]);
        }

        $cb = $this->characterBuildingModel
            ->where('character_id', $character['id'])
            ->where('building_id', $this->asInt($buildingInfo['id'] ?? null))
            ->orderBy('hp', 'ASC')
            ->first();
        if (!is_array($cb)) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'У вас нет такой постройки на базе.',
            ]);
        }

        $nameRu = is_string($buildingInfo['name_ru'] ?? null) ? $buildingInfo['name_ru'] : 'Навес';
        $level  = max(1, $this->asInt($cb['level'] ?? null, 1));
        $maxHp  = $this->asInt($buildingInfo['hp'] ?? null);
        $curHp  = max(0, $this->asInt($cb['hp'] ?? null));

        $text = "⛺ *{$nameRu}*\n\n"
            . "🆙 Уровень: *{$level}*\n"
            . ($maxHp > 0 ? "❤️ Прочность: *{$curHp} / {$maxHp}* hp\n" : '')
            . "💰 Налог: *0* — Навес не облагается\n\n"
            . "Простое укрытие из жердей и шкур: твой первый угол на пустоши. "
            . "Он уже сделал главное — база перестала быть пустым местом.\n\n"
            . "_Постоянного эффекта Навес не даёт и не улучшается — это стартовая "
            . "постройка. Что действительно усилит базу дальше: *Склад* (место под "
            . "ресурсы), *Теплица* (свои посевы), *Мастерская* (быстрее крафт) и "
            . "оборона. Загляни в «🏗 Развитие базы» — там видно, что каждая постройка "
            . "даёт и что для неё нужно._";

        $keyboard = ['inline_keyboard' => [
            [
                ['text' => '🏗 Развитие базы', 'callback_data' => 'baseDevelopment'],
            ],
            [
                ['text' => '🏠 База', 'callback_data' => 'Base'],
                ['text' => '📖 Путь новичка', 'callback_data' => 'guide_base'],
            ],
        ]];

        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        // Файл проверяем ДО encodeFile: отсутствующий путь роняет fopen и убивает
        // экран целиком. Нет картинки → тот же смысл текстом (MEDIA-OFF, ADR-020).
        $imageAbs = FCPATH . str_replace('/', DIRECTORY_SEPARATOR, self::IMAGE);
        if (is_file($imageAbs)) {
            return MediaSender::editOrSend($this->navTarget() + [
                'photo'        => Request::encodeFile(base_url(self::IMAGE)),
                'caption'      => $text,
                'parse_mode'   => 'Markdown',
                'reply_markup' => json_encode($keyboard),
            ]);
        }

        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    private function asInt(mixed $v, int $default = 0): int
    {
        return is_numeric($v) ? (int) $v : $default;
    }
}
