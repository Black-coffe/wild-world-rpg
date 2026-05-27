<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\BiomeModel;

/**
 * Публичная карта мира 1000×1000 (MVP, ADR-N/A — 🟢 wiki-style расширение).
 *
 * MVP scope: статическая PNG-подложка (попиксельная биом-карта или художественная),
 * координатная сетка, tooltip с координатами при hover. Без overlay-слоёв
 * (базы / караваны / события / биомы-tint — следующая итерация).
 *
 * Видимость публичных слоёв (будущая итерация): караваны, биомы, события мира.
 * Скрыто от паблика: игроки, базы, схроны/world_objects.
 */
class Map extends BaseController
{
    public function index(): string
    {
        $biomes = (new BiomeModel())->orderBy('id')->findAll();

        $canonical = rtrim(base_url('map'), '/');
        $title     = 'Карта мира — Wild World';
        $descr     = 'Интерактивная карта Wild World 1000×1000: переключение между попиксельной биом-картой и художественной подложкой, координатная сетка, легенда биомов.';

        $meta = [
            'title'       => $title,
            'description' => $descr,
            'canonical'   => $canonical,
            'ogImage'     => null,
            'robots'      => 'index,follow',
            'ogType'      => 'website',
            'keywords'    => 'карта Wild World, биомы, мир игры, текстовая MMORPG, Telegram',
            'breadcrumbs' => [
                ['name' => 'Главная', 'url' => rtrim(base_url(), '/') . '/'],
                ['name' => 'Карта мира', 'url' => $canonical],
            ],
        ];

        return view('site/map', [
            'biomes' => $biomes,
            'meta'   => $meta,
        ]);
    }
}
