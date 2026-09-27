<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Bases\BaseCallbackSuffix;
use App\Services\Bases\BaseScopeResolver;
use App\Services\Bases\BaseScreenService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * «🏘 Постройки» (`construction`[`_b<id>`]) — кнопки построек базы.
 *
 * w2-n4-base-01 (ADR-190): какую базу показать и что на ней стоит — ядро
 * {@see BaseScreenService::resolveConstruction()}/{@see BaseScreenService::overview()}, общее с вебом;
 * здесь прежние тексты и кнопки.
 *
 * @phpstan-import-type Overview from BaseScreenService
 */
class DetailedBaseInfoAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        // Сразу отвечаем на CallbackQuery (убираем «часики» в Telegram)
        Request::answerCallbackQuery([
            'callback_query_id' => $this->callbackQuery->getId()
        ]);

        // Достаём данные о пользователе / персонаже
        [$user, $character] = $this->getUserAndCharacter();

        if (!$user || !$character) {
            return Request::sendMessage([
                'chat_id'    => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'       => "🤖 Это снова я – *Роби*!\n\nПользователь не найден в базе данных или персонаж не определён.",
                'parse_mode' => 'Markdown'
            ]);
        }

        // `construction_b<id>` — база выбрана явно (с экрана пикера/базы), ядро перепроверяет доступность.
        [, $suffixBaseId] = BaseCallbackSuffix::split((string) $this->callbackQuery->getData());
        $characterId = (int) $character['id'];
        $screen      = new BaseScreenService();
        $resolved    = $screen->resolveConstruction($characterId, $suffixBaseId);

        switch ($resolved['state']) {
            case BaseScreenService::STATE_UNAVAILABLE:
                return Request::sendMessage([
                    'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                    'text'    => $resolved['text'],
                ]);
            case BaseScreenService::STATE_NO_BASE:
                return $this->handleNoBase($character);
            case BaseScreenService::STATE_FAR:
                return $this->handleNotOnBasePhysically($screen->overview($characterId, $resolved['base_id']));
        }

        return $this->showBuildings($screen->overview($characterId, $resolved['base_id'], $resolved['coverage']));
    }

    /**
     * Случай: у персонажа нет базы вообще.
     */
    protected function handleNoBase(array|\App\Entities\CharacterEntity $character): ServerResponse
    {
        $text = "🤖 Это снова я – *Роби*!\n\n"
            . "У тебя нет ещё разбитого лагеря, а значит и нет базы. "
            . "Для разбивки лагеря используй кнопки ниже.";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🏕 Разбить лагерь', 'callback_data' => 'Camp'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions']
                ],
            ]
        ];

        return Request::sendMessage([
            'chat_id'      => $this->callbackQuery->getMessage()->getChat()->getId(),
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Случай: у персонажа есть база, но он НЕ на ней и нет покрытия вышки.
     *
     * @param Overview|null $overview {@see BaseScreenService::overview()}
     */
    protected function handleNotOnBasePhysically(?array $overview): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        if ($overview === null || $overview['base']['x'] === null || $overview['base']['y'] === null) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Ошибка: не удалось найти карту для базы.',
            ]);
        }
        $base = $overview['base'];

        $text = "🤖 Это снова я – *Роби*!\n\n"
            . "Твоя база находится в другой игровой ячейке, ты не дома! "
            . "Чтобы начать строительство или изучить сооружения, вернись на базу:\n"
            . "1️⃣ пешком\n"
            . "2️⃣ телепорт.\n\n"
            . "📍 *Координаты базы*: x={$base['x']} y={$base['y']}\n"
            . "🌍 *Биом*: {$base['biome']}";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '📡 Телепорт', 'callback_data' => 'TeleportToCamp'],
                    ['text' => '🧭 Двигаться', 'callback_data' => 'move'],
                ],
            ]
        ];
        $imagePath = base_url('uploads/telegram/camp/an_empty_area.jpg');

        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'      => $chatId,
            'photo'        => Request::encodeFile($imagePath),
            'caption'      => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode($keyboard),
        ]);
    }

    /**
     * Случай: игрок физически на базе ИЛИ покрывает вышка связи.
     * Покрытие в модели → пометка «дистанционно» в тексте.
     *
     * @param Overview|null $overview {@see BaseScreenService::overview()}
     */
    protected function showBuildings(?array $overview): ServerResponse
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();
        if ($overview === null) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => BaseScopeResolver::TEXT_UNAVAILABLE]);
        }
        $base      = $overview['base'];
        $buildings = $overview['buildings'];

        if ($buildings === []) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'На вашей базе нет построек.',
                'parse_mode' => 'Markdown'
            ]);
        }

        if ($base['x'] === null || $base['y'] === null) {
            return Request::sendMessage([
                'chat_id' => $chatId,
                'text'    => 'Ошибка: не удалось найти карту для базы.',
            ]);
        }

        $introText = "Перед тобой территория твоей базы. Здесь можно подробнее изучить каждое сооружение!\n\n"
            . "*Координаты базы*: x={$base['x']}, y={$base['y']}\n"
            . "*Биом*: {$base['biome']}\n";

        // Покрытие есть → дистанционно
        $coverage = $overview['coverage'];
        if (is_array($coverage) && $coverage['covered']) {
            $introText  = "_Вы не на базе физически,_ но сигнал *Вышки связи* (ур. {$coverage['tower_level']}) "
                . "покрывает расстояние {$coverage['distance']}/{$coverage['max']}. "
                . "**Можно управлять сооружениями удалённо!**\n\n"
                . $introText;
        }

        // S3 (v0.51.185+): suffix L<level> на кнопке — уровень каждой постройки виден без открытия карточки.
        $keyboardButtons = [];
        foreach ($buildings as $building) {
            $keyboardButtons[] = [
                'text'          => "{$building['icon']} {$building['name']} L{$building['level']}",
                'callback_data' => $building['bridge_callback'],
            ];
        }

        // Организуем кнопки по 2 в ряд
        $keyboard = array_chunk($keyboardButtons, 2);

        // E18 (ADR-118) — вход в витрину «🏗 Развитие базы» (легибельность эффектов уровней построек).
        $keyboard[] = [['text' => '🏗 Развитие базы', 'callback_data' => BaseCallbackSuffix::append('baseDevelopment', $base['id'])]];

        $imagePath = base_url('uploads/telegram/camp/base_with_its_buildings.jpg');

        return \App\Services\Notifications\MediaSender::sendPhotoOrText([
            'chat_id'    => $chatId,
            'photo'      => Request::encodeFile($imagePath),
            'caption'    => $introText,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard]),
        ]);
    }
}
