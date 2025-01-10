<?php

namespace App\TaskHandlers\Events;

class BerryBoomHandler implements EventHandlerInterface {
    /**
     * Ночные нападения
     */
    public function handle($event) {
        // Логика обработки события "hurricane"
        $logFilePath = WRITEPATH . 'logs/gatherAction_log.txt';
        $currentCellJson = json_encode($event, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $logMessage = "BerryBoomHandler__handle__event: {$currentCellJson}: " . date('Y-m-d H:i:s') . "\n";
        file_put_contents($logFilePath, $logMessage, FILE_APPEND);

    }
}