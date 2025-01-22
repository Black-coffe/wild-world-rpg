<?php

namespace App\Controllers\Telegram\Commands\Actions\Camp\Buildings\Upgrades;

use Longman\TelegramBot\Request;

/**
 * Пример: чтобы апгрейдить Мастерскую робототехники,
 * нужно n золота +, возможно, x металла.
 */
class RoboticsWorkshopUpgradeAction extends BaseBuildingUpgradeAction
{
    protected function checkUpgradeConditions()
    {
        $currentLevel = $this->characterBuilding['level'];

        // Допустим, хотим запретить поднимать выше 10 уровня:
        if ($currentLevel >= 10) {
            return "Мастерскую робототехники нельзя улучшать выше 10 уровня!";
        }

        // Любые другие проверки, например, сперва построить Лабораторию...
        // if (!$this->someCheckLabExists()) {
        //    return "Нужно сначала построить и улучшить Лабораторию!";
        // }

        // Если всё ок, возвращаем true
        return true;
    }

    protected function applyUpgradeCost()
    {
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $currentLevel = $this->characterBuilding['level'];

        // Пример расчёта стоимости: 1000$ * (currentLevel + 1)
        // + 50 металла, условно
        $goldNeeded   = 1000 * ($currentLevel + 1);
        $metalNeeded  = 50 * ($currentLevel + 1);

        // Проверяем золото у персонажа
        if ($this->character['gold'] < $goldNeeded) {
            return "Недостаточно золота: нужно {$goldNeeded}, а у вас {$this->character['gold']}!";
        }

        // Проверяем металл
        // Предположим, у нас есть CharacterResourceModel,
        // и ID = 10 это "Металл". (Просто пример)
        // Или ищем по названию "metal" - зависит от вашей БД.
        $resourceModel = new \App\Models\CharacterResourceModel();
        $metalRow = $resourceModel
            ->where('character_id', $this->character['id'])
            ->where('resource_id', 10) // допустим металл
            ->first();

        $currentMetal = $metalRow ? $metalRow['quantity'] : 0;
        if ($currentMetal < $metalNeeded) {
            return "Недостаточно металла: нужно {$metalNeeded}, а у вас {$currentMetal}!";
        }

        // Если всего хватает, списываем
        // 1) Списать золото
        $newGold = $this->character['gold'] - $goldNeeded;
        // В CharacterModel (запись персонажа) уменьшаем gold:
        $charModel = new \App\Models\CharacterModel();
        $charModel->update($this->character['id'], [
            'gold' => $newGold,
        ]);

        // 2) Списать металл
        $newMetal = $currentMetal - $metalNeeded;
        $resourceModel->update($metalRow['id'], [
            'quantity' => $newMetal,
        ]);

        // Возвращаем true, значит всё успешно списалось
        return true;
    }

    protected function onUpgradeSuccess($newLevel)
    {
        // Допустим, хотим в чате дополнительно сообщить,
        // что теперь роботы смогут работать дольше
        $chatId = $this->callbackQuery->getMessage()->getChat()->getId();

        $extraMessage = "Ваши роботы теперь могут работать ещё дольше! (+24 часа за каждый уровень)";
        Request::sendMessage([
            'chat_id' => $chatId,
            'text'    => $extraMessage,
        ]);
    }
}
