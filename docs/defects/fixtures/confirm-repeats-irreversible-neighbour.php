<?php

// Соседняя форма: второй клиент (веб `/play`, нейтральная модель магазина) зовёт ядро опта
// без отпечатка подтверждения — повтор формы исполняет продажу снова, хотя бот уже защищён.

final class ShopScreenNeighbour
{
    public function bulkSell(int $characterId, ?int $rarity, int $percent): array
    {
        $r = $this->trade->bulkSellResources(
            ['id' => $characterId],
            $percent,
            $rarity
        );

        return $r;
    }
}
