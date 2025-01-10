<?php

namespace App\TaskHandlers\Objects;

use App\TaskHandlers\Objects\ObjectHandlerInterface;

class AbandonedTruckHandler implements ObjectHandlerInterface {
    public function handle($object, $cell, $character) {
        $logFilePath = WRITEPATH . 'logs/gatherAction_log.txt';
        $currentCellJson = json_encode($object, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $logMessage = "AbandonedTruckHandler__object: {$currentCellJson}: " . date('Y-m-d H:i:s') . "\n";
        file_put_contents($logFilePath, $logMessage, FILE_APPEND);
    }
}
