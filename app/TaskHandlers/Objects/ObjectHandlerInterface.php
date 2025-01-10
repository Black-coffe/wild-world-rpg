<?php

namespace App\TaskHandlers\Objects;

interface ObjectHandlerInterface {
    public function handle($object, $cell, $character);
}

