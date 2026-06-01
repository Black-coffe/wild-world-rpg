<?php

namespace App\Controllers\Admin;

use App\Models\CharacterModel;
use App\Models\TelegramUserModel;
use App\Models\GeneralModel;
use App\Services\Admin\WipeService;
use CodeIgniter\HTTP\RedirectResponse;
use App\Controllers\Telegram\BotController;
use Longman\TelegramBot\Exception\TelegramException;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

class CharacterResetController extends BaseAdminController
{
    protected CharacterModel $characterModel;
    protected TelegramUserModel $telegramUserModel;
    protected GeneralModel $generalModel;
    private ?Telegram $telegram = null;

    public function __construct()
    {
        $this->characterModel = new CharacterModel();
        $this->telegramUserModel = new TelegramUserModel();
        $this->generalModel = new GeneralModel();

        $API_KEY = (string) getenv('telegram.API_KEY');
        $BOT_USERNAME = (string) getenv('telegram.BOT_USERNAME');

        try {
            $this->telegram = new Telegram($API_KEY, $BOT_USERNAME);
            Request::initialize($this->telegram);
        } catch (TelegramException $e) {
            log_message('error', $e->getMessage());
        }
    }

    public function index(): string
    {
        $telegramUserId = 1; // Здесь нужно указать ID телеграм пользователя
        $characterId = null;

        $character = $this->characterModel->where('telegram_user_id', $telegramUserId)->first();
        if ($character !== null) {
            $characterId = $character['id'];
        }

        return view('admin/character_reset_index', [
            'title' => 'Сброс персонажа в игре, его обнуление',
            'characterId' => $characterId
        ]);
    }

    public function check(): string|RedirectResponse
    {
        $telegramId = $this->request->getPost('id_telegram');
        $characterId = $this->request->getPost('characterId');

        // Проверяем существование пользователя и персонажа
        $user = $this->telegramUserModel->where('telegram_id', $telegramId)->first();
        $character = $this->characterModel->find($characterId);

        if (!$user || !$character || $user['id'] != $character['telegram_user_id']) {
            return redirect()->back()->with('errors', ['Неверные данные пользователя или персонажа.']);
        }

        // Получение информации о затрагиваемых таблицах
        $affectedRows = $this->getAffectedTablesData($characterId);

        return view('admin/character_reset_index', [
            'affectedRows' => $affectedRows,
            'characterId' => $characterId,
            'telegramId' => $telegramId,
            'title' => 'Подтверждение сброса персонажа'
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function getAffectedTablesData(int|string $characterId): array
    {
        return (new WipeService())->previewCharacter((int) $characterId);
    }

    public function confirmReset(): RedirectResponse
    {
        $rawCid = $this->request->getPost('characterId');
        $characterId = (int) (is_scalar($rawCid) ? $rawCid : 0);
        $telegramId = $this->request->getPost('telegramId');

        // ADR-087: единый манифест (WipeService.resetCharacter) — сбрасывает строку
        // персонажа к стартовым + удаляет ВСЕ его player_data (без сирот, в отличие
        // от прежнего 15-таблиц ResetTables) + респавн на юг.
        $report        = (new WipeService())->resetCharacter($characterId);
        $deletedTotals = $report['deleted'];

        $this->audit('CHARACTER_RESET', 'character', $characterId, [
            'telegram_id'     => $telegramId,
            'tables_affected' => $deletedTotals,
            'respawned'       => $report['respawned'],
        ]);

        if ($telegramId) {
            $botController = new BotController();
            $message = "🔥 *Внимание!* Твой персонаж был сброшен (обнулен) до стартовых показателей. 🎲\n" .
                "🧹 Все, что было до этого, удалено и забыто. Даже исторические кнопки, которые у тебя сохранились в ленте чата, не будут работать. 🚫 Советую:\n\n" .
                "1⃣ Почистить всю переписку (историю чата).\n" .
                "2⃣ Написать мне в сообщении: `/start`.\n\n" .
                "🚀 *Готов начать новое приключение? Напиши `/start` и вперёд к новым горизонтам!* 🌌";

            $imagePath = base_url('uploads/telegram/character/reset_character.jpg'); // Assuming the image path is correct

            $botController->sendMessage($telegramId, $message, $imagePath);
        }

        return redirect()->to('/admin/character-reset')->with('message', 'Персонаж успешно обнулён.');
    }

    public function resetAllCharacters(): RedirectResponse
    {
        return redirect()->to('/admin/character-reset')
            ->with('errors', ['Обнуление всех персонажей отключено.']);
    }

}
