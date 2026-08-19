<?php

declare(strict_types=1);

namespace App\Services\Player\Trade;

/**
 * Человекочитаемые названия категорий крафта (`crafted_items.type`) для экранов лавки.
 *
 * Повод (2026-07-31): экраны «💰 Продать крафт» / «🛍️ Купить крафт» 2.5 месяца были мертвы
 * из-за тихой потери photo-сообщений (см. {@see \App\Services\Notifications\MediaSender}).
 * Как только они ожили, стало видно: словарь категорий жил в ЧЕТЫРЁХ копиях
 * (Buy/SellCraftAction + Buy/SellCraftItemListAction) и покрывал 5–7 типов из 18 реальных.
 * Игрок видел кнопки `drones`, `clothing`, `❓utility` — сырые технические ключи.
 *
 * Отсюда единый источник истины: новая категория в БД → одна правка здесь, а не четыре.
 * Неизвестный тип не прячем и не роняем — показываем сам ключ с «❓», чтобы пробел был
 * заметен, а экран остался рабочим.
 */
final class CraftTypeLabels
{
    /** @var array<string,string> */
    private const LABELS = [
        'accessory'     => '💍 Украшения',
        'armor'         => '🛡 Броня-самоделки',
        'building'      => '🧱 Стройматериалы',
        'clothing'      => '🧥 Одежда',
        'component'     => '📐 Компоненты',
        'decorative'    => '🎏 Декор',
        'defense'       => '🏯 Укрепления',
        'drones'        => '🚁 Дроны',
        'drug'          => '💊 Лекарства',
        'food'          => '🍖 Еда',
        'magical item'  => '✨ Диковины',
        'military'      => '🏰 Осадное',
        'robots'        => '🤖 Роботы',
        // 2026-08-06: в категории уже не только маяки (рюкзак-телепорт, портативный
        // телепорт), поэтому подпись общая — «Телепорт-маяки» вводило бы в заблуждение.
        'teleport'      => '🌀 Телепорты',
        'tool'          => '🛠️ Инструменты',
        // transport-07: 🛴 сведён к 🚚 — тот же эмодзи, что и заголовок «🚚 Транспорт» в
        // CraftedResourcesAction (грамматика предмета, два эмодзи одной категории читались
        // как разные категории).
        'transport'     => '🚚 Транспорт',
        'utility'       => '🧰 Снаряжение',
        'weapon'        => '🗡 Оружие-самоделки',
        'workbench'     => '🔬 Верстаки',

        // ADR-165 — ВИРТУАЛЬНЫЕ категории экрана продажи: настоящая экипировка, которая
        // лежит в `characters_weapons` / `characters_outfits`, а не в `crafted_items_log`.
        // Легаси-типы `weapon` / `armor` из `crafted_items` — это НЕ она (там семь грубых
        // поделок ценой 15–50 вроде «Деревянного меча»), поэтому им дана своя подпись:
        // две одинаковые кнопки «⚔️ Оружие» на одном экране означали бы для игрока лотерею.
        'gearWeapon'    => '⚔️ Оружие',
        'gearArmor'     => '🛡 Броня',
    ];

    public static function rus(string $type): string
    {
        return self::LABELS[$type] ?? ('❓' . $type);
    }

    /** @return array<string,string> */
    public static function all(): array
    {
        return self::LABELS;
    }
}
