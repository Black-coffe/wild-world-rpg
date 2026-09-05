<?php

declare(strict_types=1);

namespace App\Services\More;

use App\Entities\CharacterEntity;
use App\Models\CharacterFactionModel;
use App\Services\GameSettings\GameSettingsService;
use App\Services\PVE\DuelService;
use App\Services\PVE\TributeService;
use App\Services\Player\FactionProjectService;
use App\Services\Player\ReferralService;
use App\Services\Player\WhatsNewService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * ADR-150 Слайс 4 — единый рендер поверхности «⚙️ Ещё».
 *
 * Дом для всего, что не «место» и не «дело»: торговля, состязания, фракционная политика,
 * развлечения, помощь, настройки. До Слайса 4 отдельного дома НЕ существовало — эти фичи
 * висели на карточке Перса вперемешку с личными, прятались в под-хабах или (худший случай)
 * были достижимы только из локации.
 *
 * 🔴 Триггерная находка аудита (firehose, 2026-07-09): **«🏟 Арена» включена на проде
 * (`pvp.duel.enabled=1`), но за всё время НОЛЬ тапов.** Её единственные входы — хаб поселения
 * (куда надо физически дойти) и экран «🏆 Рейтинг», который сам открывается только из Арены →
 * замкнутый круг. Классический BUILT-BUT-INVISIBLE (правило UX-DISCOVERABILITY). Оракул (3 тапа
 * за всё время) жил на дне цепочки «Окопаться → Развлечения → Оракул».
 *
 * Поверхность — ТЕКСТ (media-off-нейтрально, ADR-020). Read-only витрина: НИКАКИХ мутаций.
 *
 * **Один канон на фичу (ADR-150):** Оракул остаётся внутри «🎮 Развлечения» (его канонический
 * дом) — сюда он попадает транзитивно, дубль-кнопки нет. Арена получает канон ЗДЕСЬ, а вход из
 * хаба поселения остаётся допустимым контекстным входом.
 *
 * Killswitch `navigation.more_hub.enabled` (default OFF → dormant, всё byte-identical).
 */
class MoreSurfaceService
{
    /**
     * Killswitch ADR-150 Слайс 4. false (default) — DORMANT: нижняя кнопка «Настройки» как
     * раньше, экрана «Ещё» нет. true — «⚙️ Ещё» становится домом группы.
     */
    public function enabled(): bool
    {
        $raw = (new GameSettingsService())->get('navigation.more_hub.enabled', false);
        if (is_bool($raw)) {
            return $raw;
        }

        return is_numeric($raw) ? (int) $raw === 1 : false;
    }

    /**
     * Отправляет НОВОЕ сообщение с поверхностью «Ещё» (нижняя кнопка / текст «ещё»).
     *
     * @param array<string, mixed>|CharacterEntity $character
     */
    public function show(int $chatId, array|CharacterEntity $character): ServerResponse
    {
        $screen = $this->buildScreen($character);

        return Request::sendMessage([
            'chat_id'                  => $chatId,
            'text'                     => $screen['text'],
            'parse_mode'               => 'Markdown',
            'disable_web_page_preview' => true,
            'reply_markup'             => json_encode($screen['keyboard']),
        ]);
    }

    /**
     * Полный экран «⚙️ Ещё»: текст + inline-клавиатура. Чистый рендер поверх сеймов —
     * переиспользуется callback-хабом (edit-in-place) и нижней кнопкой (новое сообщение).
     *
     * @param array<string, mixed>|CharacterEntity $character
     * @return array{text: string, keyboard: array{inline_keyboard: list<list<array{text:string, callback_data:string}>>}}
     */
    public function buildScreen(array|CharacterEntity $character): array
    {
        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $level  = is_numeric($character['level'] ?? null) ? (int) $character['level'] : 1;

        $state = $this->gates($charId, $level);

        return [
            'text'     => $this->buildText($state),
            'keyboard' => $this->buildKeyboard($state),
        ];
    }

    /**
     * Состояние гейтов группы. Каждая фича уважает СВОЙ killswitch (мы его не дублируем).
     *
     * @return array{arena:bool, oracle:bool, referral:bool, tribute:bool, whatsnew:bool, factionChosen:bool, factionProject:bool, level:int}
     */
    protected function gates(int $charId, int $level): array
    {
        $tribute = new TributeService();

        return [
            'arena'          => (new DuelService())->enabled(),
            'oracle'         => $this->oracleEnabled(),
            'referral'       => (new ReferralService())->enabled(),
            'tribute'        => $charId > 0 && $tribute->enabled() && $tribute->hasAnyTributeRelation($charId),
            'whatsnew'       => (new WhatsNewService())->isEnabled(),
            'factionChosen'  => $this->hasChosenFaction($charId),
            'factionProject' => (new FactionProjectService())->enabled(),
            'level'          => $level,
        ];
    }

    /** Оракул (ADR-133) — свой killswitch; здесь только для честного текста-тизера. */
    protected function oracleEnabled(): bool
    {
        $raw = (new GameSettingsService())->get('oracle.enabled', false);
        if (is_bool($raw)) {
            return $raw;
        }

        return is_numeric($raw) ? (int) $raw === 1 : false;
    }

    /**
     * Фракция выбрана? Зеркалит логику карточки Перса: запись есть, faction_id ≠ 5 («без
     * фракции»). Единственный смысл — выбрать правильную кнопку (выбор / проект / lock).
     */
    protected function hasChosenFaction(int $charId): bool
    {
        if ($charId <= 0) {
            return false;
        }
        $factionId = (new CharacterFactionModel())->getFactionId($charId);

        return $factionId > 0 && $factionId !== 5;
    }

    /**
     * Текст экрана. Самодостаточен в media-off (ADR-020): объясняет, что внутри, а не «см. кнопки».
     *
     * @param array{arena:bool, oracle:bool, referral:bool, tribute:bool, whatsnew:bool, factionChosen:bool, factionProject:bool, level:int} $s
     */
    protected function buildText(array $s): string
    {
        $text = "⚙️ *Ещё* — всё остальное, что есть на острове.\n\n";

        $text .= "🛒 *Магазин* — продать добычу и крафт, купить недостающее.\n";

        if ($s['arena']) {
            $text .= "🏟 *Арена* — дуэли с другими выжившими и таблица рейтинга.\n";
        }

        $text .= "🎮 *Развлечения* — колесо, угадайка, камень-ножницы"
            . ($s['oracle'] ? ', ставки у *Оракула острова*' : '') . ".\n";

        if ($s['factionChosen']) {
            $text .= "⚑ *Фракция* — твоя сторона и её общий проект.\n";
        } elseif ($s['level'] >= 10) {
            $text .= "⚑ *Фракция* — пора выбрать сторону.\n";
        } else {
            $text .= "🔒 *Фракция* — откроется на 10 уровне.\n";
        }

        if ($s['referral']) {
            $text .= "👥 *Позови выжившего* — личная ссылка и титул «Зовущий».\n";
        }
        if ($s['tribute']) {
            $text .= "⚖️ *Трофейная подать* — у тебя есть активная подать.\n";
        }

        $text .= "\n❓ *Помощь:* «Путь новичка» — справочник по всем механикам, «Совет» — короткая "
            . "подсказка" . ($s['whatsnew'] ? ", «Что нового» — свежие изменения" : '') . ".\n";
        // Не перечисляем конкретные тумблеры: часть из них гейтится своими killswitch
        // (дуэли/язык/звук) и в экране Настроек может отсутствовать → текст бы врал.
        $text .= "⚙️ *Настройки* — картинки, советы и остальные переключатели.\n";

        return $text;
    }

    /**
     * Клавиатура. Cap 7±2 (ADR-150): пакуем сиблингов по 2 в ряд
     * (memory `feedback_inline_keyboard_pack_sibling_buttons` — НЕ соло-ряды).
     *
     * 🔴 Фракция — lock-кнопка при <L10 вместо скрытия (UX-DISCOVERABILITY): клик объяснит
     * prerequisite. Арена показывается только при своём killswitch — dormant-фичу не обещаем.
     *
     * @param array{arena:bool, oracle:bool, referral:bool, tribute:bool, whatsnew:bool, factionChosen:bool, factionProject:bool, level:int} $s
     * @return array{inline_keyboard: list<list<array{text:string, callback_data:string}>>}
     */
    protected function buildKeyboard(array $s): array
    {
        $buttons = [];

        $buttons[] = ['text' => '🛒 Магазин', 'callback_data' => 'shop'];

        // Канон Арены — здесь. Раньше вход был только из хаба поселения (0 тапов за всё время).
        if ($s['arena']) {
            $buttons[] = ['text' => '🏟 Арена', 'callback_data' => 'arena'];
        }

        // Оракул НЕ дублируем: его канонический дом — внутри «Развлечений».
        $buttons[] = ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'];

        if ($s['factionChosen']) {
            $buttons[] = $s['factionProject']
                ? ['text' => \App\Services\Player\FactionProjectService::BUTTON_LABEL, 'callback_data' => 'factionProject']
                : ['text' => '⚑ Моя фракция', 'callback_data' => 'chooseFaction_info'];
        } elseif ($s['level'] >= 10) {
            $buttons[] = ['text' => '⚑ Выбрать фракцию', 'callback_data' => 'chooseFaction_info'];
        } else {
            $buttons[] = ['text' => '🔒 ⚑ Фракция (с lvl 10)', 'callback_data' => 'chooseFactionLocked'];
        }

        if ($s['referral']) {
            $buttons[] = ['text' => '👥 Позови выжившего', 'callback_data' => 'referral'];
        }
        if ($s['tribute']) {
            $buttons[] = ['text' => '⚖️ Трофейная подать', 'callback_data' => 'tributeStatus'];
        }

        $buttons[] = ['text' => '📖 Путь новичка', 'callback_data' => 'guide'];
        if ($s['whatsnew']) {
            $buttons[] = ['text' => '📚 Что нового', 'callback_data' => 'whatsNewCatalog'];
        }
        $buttons[] = ['text' => '⚙️ Настройки', 'callback_data' => 'settings'];

        $rows = array_chunk($buttons, 2);

        // Экран не тупик: возврат в мир.
        $rows[] = [['text' => '🧭 Идти', 'callback_data' => 'move']];

        return ['inline_keyboard' => $rows];
    }
}
