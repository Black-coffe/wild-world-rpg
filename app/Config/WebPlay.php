<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * web-bridge-p1 (ADR-189) — инфраструктурные числа игры на сайте (plan A8).
 *
 * Не баланс (ADR-024 не применяется): размеры истории/ящика, частота опроса, лимиты запросов и
 * длины ввода — защита сервера и удобство экрана, игровая экономика от них не зависит.
 * Включение самой игры — флаг `web.play_enabled` в GameSettings.
 */
class WebPlay extends BaseConfig
{
    /** Сколько прошлых экранов держит история `/play`. */
    public int $historySize = 10;

    /** Сколько последних входящих хранится на персонажа (prune on append, A7). */
    public int $inboxKeep = 200;

    /** Период опроса колокольчика, секунды. */
    public int $inboxPollSeconds = 30;

    /** Серверный минимум периода опроса, секунды. */
    public int $inboxPollMinSeconds = 10;

    /** Действий `/play/act` в минуту на аккаунт. */
    public int $actsPerMinute = 60;

    /** Чтений входящих в минуту на аккаунт. */
    public int $inboxReadsPerMinute = 12;

    /** Максимальная длина введённого текста (как у Telegram-сообщения). */
    public int $textMaxLength = 4096;

    /** Первый синтетический `message_id` экрана (default `web_play_state.next_message_id`). */
    public int $firstMessageId = 1000000000;

    /** Окно дедупа `web_play_intents`, часы: старше — удаляется при записи намерения (plan A17). */
    public int $intentRetentionHours = 24;

    /**
     * Каталоги под `public/`, чьи файлы отправитель удаляет сразу после отправки (карта —
     * `MapService`). Фото оттуда копируется в {@see $photoDir} в момент записи (plan A18).
     *
     * @var list<string>
     */
    public array $transientPhotoPrefixes = ['uploads/tmp/'];

    /** Каталог копий фото для `/play` под `public/` (имя — sha1 содержимого). */
    public string $photoDir = 'uploads/web/';

    /** Сколько часов живёт копия фото: старше — удаляется при следующей копии (plan A18). */
    public int $photoKeepHours = 168;
}
