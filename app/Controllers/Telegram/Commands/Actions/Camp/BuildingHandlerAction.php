<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Bases\BaseCallbackSuffix;
use App\Services\Bases\BuildingCardService;
use App\Services\Notifications\MediaSender;
use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;

/**
 * Карточка постройки на базе — `building_<id>_<Key>[_b<baseId>]`.
 *
 * W2.N4b (ADR-190): единственный отрисовщик бота. Данные, проверки (переезд, выбор базы) и различия
 * зданий — в {@see BuildingCardService}; здесь только прежний Markdown каждого шаблона, фото и кнопки.
 * Раньше это был диспетчер на 14 классов `Camp/Buildings/*Handler.php` (удалены): текст и кнопки
 * сохранены байт-в-байт (снимки `tests/unit/Camp/BuildingCardSnapshots.json`).
 */
class BuildingHandlerAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        // Отправляем ответ на CallbackQuery сразу
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        // story multibase-picker-03: суффикс `_b<baseId>` не участвует в маршрутизации по имени
        // постройки — его перепроверяет ядро.
        [$callbackData, $baseId] = BaseCallbackSuffix::split($this->callbackQuery->getData());
        $parts = explode('_', $callbackData);
        [$prefix, $buildingId, $key] = [$parts[0], $parts[1] ?? '', $parts[2] ?? ''];

        if ($prefix !== 'building' || !is_numeric($buildingId) || $key === '') {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Некорректные данные для обработки запроса.',
            ]);
        }

        if (!BuildingCardService::has($key)) {
            return $this->unknown($chatId);
        }

        [$user, $character] = $this->getUserAndCharacter();
        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => BuildingCardService::noUserText($key),
            ]);
        }

        $currentCell = is_numeric($character['cell_number'] ?? null) ? (int) $character['cell_number'] : 0;
        $card        = (new BuildingCardService())->card((int) $character['id'], $currentCell, $key, $baseId);

        if ($card['state'] !== BuildingCardService::STATE_OK) {
            return match ($card['refusal']) {
                BuildingCardService::REFUSAL_RELOCATION => $this->relocation((int) $chatId, $card['text']),
                BuildingCardService::REFUSAL_UNKNOWN    => $this->unknown($chatId),
                default                                 => Request::sendMessage(['chat_id' => $chatId, 'text' => $card['text']]),
            };
        }

        $keyboard = ['inline_keyboard' => array_map(
            static fn (array $row): array => array_map(
                static fn (array $b): array => ['text' => $b['text'], 'callback_data' => $b['data']],
                $row
            ),
            $card['rows']
        )];
        $text = self::text($card);

        // Файл проверяем ДО encodeFile: отсутствующий путь роняет fopen и убивает экран целиком.
        // Нет картинки → тот же смысл текстом (MEDIA-OFF, ADR-020).
        $imageAbs = FCPATH . str_replace('/', DIRECTORY_SEPARATOR, $card['image']);
        if (is_file($imageAbs)) {
            return MediaSender::editOrSend($this->navTarget() + [
                'photo'        => Request::encodeFile(base_url($card['image'])),
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

    /**
     * Прежний Markdown карточки по шаблону ядра.
     *
     * @param array<string,mixed> $c модель {@see BuildingCardService::card()}
     */
    public static function text(array $c): string
    {
        $str = static fn (mixed $v): string => is_scalar($v) ? (string) $v : '';
        $int = static fn (mixed $v): int => is_numeric($v) ? (int) $v : 0;

        switch ($c['template']) {
            case 'defensive':
                $amount = $int($c['amount']);
                $text   = "{$str($c['emoji'])} *{$str($c['name'])}*\n\n"
                    . "🆙 Уровень: *{$int($c['level'])}*\n"
                    . (($c['stack_on'] && $amount > 1) ? "🏗 Построено на базе: *×{$amount}*\n" : '')
                    . "❤️ Прочность: *{$int($c['hp_cur'])} / {$int($c['hp_max'])}* hp\n"
                    . "⚔️ Эффект: {$str($c['effect'])}\n"
                    . "💰 Налог: {$int($c['tax'])}/день\n\n"
                    . ($int($c['hp_cur']) < $int($c['hp_max'])
                        ? "_Структура повреждена. Почини её — иначе сломается в бою и перестанет защищать._\n\n"
                        : "_Структура в полном порядке._\n\n")
                    . ($c['stack_on']
                        ? ($c['stackable']
                            ? '_💡 Построй ещё такую же на этой базе — защита усилится (каждая следующая слабее, до общего потолка)._'
                            : '_💡 Вторая вышка не усилит — она работает от наличия._')
                        : '');

                return $text;

            case 'leanto':
                return "⛺ *{$str($c['name'])}*\n\n"
                    . "🆙 Уровень: *{$int($c['level'])}*\n"
                    . ($int($c['hp_max']) > 0 ? "❤️ Прочность: *{$int($c['hp_cur'])} / {$int($c['hp_max'])}* hp\n" : '')
                    . "💰 Налог: *0* — Навес не облагается\n\n"
                    . 'Простое укрытие из жердей и шкур: твой первый угол на пустоши. '
                    . "Он уже сделал главное — база перестала быть пустым местом.\n\n"
                    . '_Постоянного эффекта Навес не даёт и не улучшается — это стартовая '
                    . 'постройка. Что действительно усилит базу дальше: *Склад* (место под '
                    . 'ресурсы), *Теплица* (свои посевы), *Мастерская* (быстрее крафт) и '
                    . 'оборона. Загляни в «🏗 Развитие базы» — там видно, что каждая постройка '
                    . 'даёт и что для неё нужно._';
        }

        $tower  = $c['template'] === 'tower';
        $format = ($tower ? "📡 *%s*\n\n" : "🏗️ *%s*\n\n")
            . "📅 *Дата постройки:* %s\n"
            . "⏳ *Дата исчезновения:* %s\n"
            . "🔄 *Использований:* %d всего / %s осталось\n"
            . ($tower ? "💰 *Налог:* %d$\n" : "💰 *Налог за постройку:* %d$\n")
            . ($tower ? "🆙 *Уровень:* %d lvl\n" : "🆙 *Уровень постройки:* %d lvl\n")
            . "🔒 *Доступность:* %s\n"
            . ($tower ? "📜 *Описание:* %s\n\n_Каждый уровень увеличивает радиус связи +100 клеток._" : '📜 *Описание:* %s');

        $text = sprintf(
            $format,
            $str($c['name']),
            date('d.m.Y', (int) strtotime($str($c['built_at']))),
            $str($c['disappears_at']) !== '' ? date('d.m.Y', (int) strtotime($str($c['disappears_at']))) : 'Без ограничений',
            $int($c['uses_used']),
            $c['uses_left'] !== null ? $str($c['uses_left']) : 'Без ограничений',
            $int($c['tax']),
            $int($c['level']),
            $str($c['usage_label']),
            $str($c['description'])
        );

        if ($c['effect'] !== null) {
            $text .= "\n" . $str($c['effect']);
        }
        if (is_array($c['farming'])) {
            $text .= "\n\n🌱 *Грядки (посадка):* растёт {$int($c['farming']['used'])}/{$int($c['farming']['max'])}."
                . "\n_Жми «Грядки», чтобы посадить семена и собрать урожай._";
        }

        return $text;
    }

    /** Переезд базы: как прежний `ActiveTasksService::checkRelocationAndBlock()` — Markdown-отказ, без карточки. */
    private function relocation(int $chatId, string $text): ServerResponse
    {
        Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
        ]);

        return Request::emptyResponse();
    }

    private function unknown(int|string $chatId): ServerResponse
    {
        // Сюда попадать не должно: гейт tests/unit/Camp/BuildingHandlerCoverageTest требует запись
        // в BuildingCardService::CARDS на каждый ключ Config\Buildings. Но если постройка всё же не
        // опознана — не бросаем игрока в тупик, а возвращаем на базу.
        return Request::sendMessage([
            'chat_id'      => $chatId,
            'text'         => BuildingCardService::TEXT_UNKNOWN,
            'reply_markup' => json_encode(['inline_keyboard' => [[
                ['text' => '🏠 База', 'callback_data' => 'Base'],
            ]]]),
        ]);
    }
}
