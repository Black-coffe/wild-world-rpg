<?php

declare(strict_types=1);

namespace App\Services\Player;

use App\Models\CharacterModel;
use App\Models\WhatsNewTopicModel;
use App\Services\GameSettings\GameSettingsService;

/**
 * W7b (ADR-065 Part 2) — логика каталога «📚 Что нового».
 *
 * Читает темы из таблицы whats_new_topics + трекинг прочитанного через
 * characters.whats_new_seen (JSON-массив topic_key). Используют WhatsNewCatalogAction
 * (список тем + маркер 🆕 непрочитанных) и WhatsNewTopicAction (рендер темы + mark seen).
 *
 * Killswitch: onboarding.whats_new.enabled (ADR-024, category=world).
 *
 * whats_new_seen читается/пишется через builder/Model::update (а не Entity) — избегаем
 * неоднозначности типизации под PHPStan L9 (паттерн TipService).
 */
final class WhatsNewService
{
    private WhatsNewTopicModel $topics;
    private CharacterModel $characters;
    private GameSettingsService $settings;

    public function __construct(
        ?WhatsNewTopicModel $topics = null,
        ?CharacterModel $characters = null,
        ?GameSettingsService $settings = null
    ) {
        $this->topics     = $topics ?? new WhatsNewTopicModel();
        $this->characters = $characters ?? new CharacterModel();
        $this->settings   = $settings ?? new GameSettingsService();
    }

    /**
     * Killswitch каталога. false → кнопка «📚 Что нового» скрыта, промо в онбординге
     * не показывается, оба handler'а отвергают вход.
     */
    public function isEnabled(): bool
    {
        $v = $this->settings->get('onboarding.whats_new.enabled', true);
        if (is_bool($v)) {
            return $v;
        }
        if (is_numeric($v)) {
            return (int) $v === 1;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Все темы каталога по возрастанию sort_order.
     *
     * @return list<array<int|string,mixed>>
     */
    public function allTopics(): array
    {
        $rows = $this->topics->orderBy('sort_order', 'ASC')->orderBy('id', 'ASC')->findAll();

        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Тема по ключу. null — если не найдена.
     *
     * @return array<int|string,mixed>|null
     */
    public function topicByKey(string $key): ?array
    {
        $row = $this->topics->where('topic_key', $key)->first();
        return is_array($row) ? $row : null;
    }

    /**
     * Прочитанные темы персонажа (список topic_key). Пустой массив при отсутствии/мусоре.
     *
     * @return list<string>
     */
    public function seenKeys(int $characterId): array
    {
        $result = $this->characters->builder()
            ->select('whats_new_seen')
            ->where('id', $characterId)
            ->get();
        $row = $result === false ? null : $result->getRowArray();
        if ($row === null) {
            return [];
        }

        $raw = $row['whats_new_seen'] ?? null;
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $keys = [];
        foreach ($decoded as $v) {
            if (is_string($v) && $v !== '') {
                $keys[] = $v;
            }
        }
        return $keys;
    }

    /** Прочитана ли тема персонажем. */
    public function isSeen(int $characterId, string $key): bool
    {
        return in_array($key, $this->seenKeys($characterId), true);
    }

    /**
     * Относительный путь к картинке темы/каталога с fallback на существующий
     * онбординг-ассет, если dedicated-арт ещё не сгенерён (status=pending до art-tail).
     * Картинка = enhancement (media-off правило): caption несёт весь смысл.
     */
    public static function imageRelOrFallback(string $rel): string
    {
        $fallback = 'uploads/telegram/character/final-step-image.jpg';
        if ($rel === '') {
            return $fallback;
        }
        if (defined('FCPATH') && is_file(FCPATH . $rel)) {
            return $rel;
        }
        return $fallback;
    }

    /**
     * Отметить тему прочитанной (идемпотентно). Хранит JSON-массив уникальных topic_key.
     */
    public function markSeen(int $characterId, string $key): void
    {
        if ($key === '') {
            return;
        }
        $keys = $this->seenKeys($characterId);
        if (in_array($key, $keys, true)) {
            return;
        }
        $keys[] = $key;

        $this->characters->update($characterId, [
            'whats_new_seen' => json_encode($keys, JSON_UNESCAPED_UNICODE),
        ]);
    }
}
