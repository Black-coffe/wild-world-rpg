<?php

namespace App\TaskHandlers\Objects;

use App\Attributes\HandlerKey;
use App\TaskHandlers\Objects\ObjectHandlerInterface;

#[HandlerKey(
    key: 'world_object_abandoned_truck',
    displayName: 'World-object: Заброшенный грузовик',
    description: 'Discovery handler для world_objects.name_en="Abandoned truck". Сейчас лишь пишет лог (заглушка для будущей механики).',
)]
class AbandonedTruckHandler implements ObjectHandlerInterface {
    public function handle($object, $cell, $character) {
        $logFilePath = WRITEPATH . 'logs/gatherAction_log.txt';
        $currentCellJson = json_encode($object, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $logMessage = "AbandonedTruckHandler__object: {$currentCellJson}: " . date('Y-m-d H:i:s') . "\n";
        file_put_contents($logFilePath, $logMessage, FILE_APPEND);
    }
}
