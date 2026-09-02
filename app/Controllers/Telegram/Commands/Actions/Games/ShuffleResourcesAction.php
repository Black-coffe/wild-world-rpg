<?php

namespace App\Controllers\Telegram\Commands\Actions\Games;

use App\Services\Telegram\Request;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Models\ResourceModel;
use App\Models\CharacterResourceModel;
use App\Services\Db\WriteOutcome;
use App\Services\GameSettings\GameSettingsReaderTrait;
use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use Longman\TelegramBot\Entities\CallbackQuery;

/**
 * 🔀 Перемешать ресурсы — RNG-конвертер внутри одной редкости (Asana «Перемешать ресурсы»,
 * ADR-093).
 *
 * Игрок выбирает РЕДКОСТЬ (1–10) и КОЛИЧЕСТВО (tunable CSV, дефолт 5/10/25/50). Система
 * случайно берёт один из ЕГО ресурсов этой редкости, которого хватает, списывает выбранное
 * число — и зачисляет его (за вычетом потери при пересыпке, economy.shuffle.loss_pct)
 * СЛУЧАЙНОМУ другому ресурсу ТОЙ ЖЕ редкости (любому в игре, не обязательно из инвентаря —
 * можно вытрясти то, чего ещё не было). Редкость сохраняется → ценность не ломается, прогрессия
 * цела (ось ценности = редкость, не биом).
 *
 * Весь баланс live-tunable через GameSettings (economy.shuffle.*): killswitch, % потерь,
 * варианты количеств. Caption печатает реальные числа → текст не расходится с механикой.
 * Текстовые экраны (editTextOrSend) — полноценны в media-off (весь смысл в тексте). Discoverability:
 * кнопка в хабе «🎮 Развлечения» (EntertaimentAction), всегда видна.
 */
class ShuffleResourcesAction extends BaseAction
{
    use GameSettingsReaderTrait;

    /** @var ResourceModel (untyped: BaseAction уже объявляет $resourceModel без типа) */
    protected $resourceModel;
    protected CharacterResourceModel $characterResourceModel;

    /** @var array<string,mixed>|\App\Entities\CharacterEntity|null */
    protected $character;

    public function __construct(CallbackQuery $callbackQuery)
    {
        parent::__construct($callbackQuery);
        $this->resourceModel          = new ResourceModel();
        $this->characterResourceModel = new CharacterResourceModel();
    }

    public function handle(): ServerResponse
    {
        [$user, $character] = $this->getUserAndCharacter();
        if (! $user || ! $character) {
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Пользователь не найден в базе данных или персонаж не определён.',
            ]);
        }
        $this->character = $character;

        if (! $this->gsBool('economy.shuffle.enabled', true)) {
            Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
            return Request::sendMessage([
                'chat_id' => $this->callbackQuery->getMessage()->getChat()->getId(),
                'text'    => 'Игра «Перемешать ресурсы» временно недоступна.',
            ]);
        }

        $characterId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        $params      = explode('_', (string) $this->callbackQuery->getData());

        // ShuffleResources
        //  └── rarity_{r}          → выбор количества
        //  └── confirm_{r}_{count} → экран подтверждения (честное предупреждение о потере)
        //  └── go_{r}_{count}      → выполнить перемешивание
        //  └── restart             → назад на выбор редкости
        if (count($params) === 3 && $params[1] === 'rarity') {
            return $this->showCountMenu($characterId, (int) $params[2]);
        }
        if (count($params) === 4 && $params[1] === 'confirm') {
            return $this->showConfirm($characterId, (int) $params[2], (int) $params[3]);
        }
        if (count($params) === 4 && $params[1] === 'go') {
            return $this->doShuffle($characterId, (int) $params[2], (int) $params[3]);
        }

        return $this->showRarityMenu($characterId);
    }

    /** Шаг 1 — выбор редкости (показываем только те, где есть чем играть). */
    protected function showRarityMenu(int $characterId): ServerResponse
    {
        $minCount    = $this->minCount();
        $catalogByR  = $this->catalogCountByRarity();
        $maxQtyByR   = $this->ownedMaxQtyByRarity($characterId);

        $available = [];
        for ($r = 1; $r <= 10; $r++) {
            // Источник: у игрока есть ≥ минимального количества хотя бы одного ресурса редкости r.
            // Получатель: в игре существует ≥2 ресурса этой редкости (иначе некуда перемешивать).
            if (($maxQtyByR[$r] ?? 0) >= $minCount && ($catalogByR[$r] ?? 0) >= 2) {
                $available[] = $r;
            }
        }

        if ($available === []) {
            return $this->screen(
                "🔀 *Перемешать ресурсы*\n\n"
                . "Сейчас перемешивать нечего. Нужно накопить хотя бы *{$minCount}* единиц "
                . "какого-нибудь ресурса одной редкости — тогда сможешь рискнуть и обменять его "
                . "на случайный другой той же ценности.",
                [[['text' => '🎮 Развлечения', 'callback_data' => 'entertainment']]]
            );
        }

        $loss = $this->lossPct();
        $text = "🔀 *Перемешать ресурсы*\n\n"
            . "Сваливаешь груду в общий бак — на выходе случайный *другой* ресурс той же редкости. "
            . "Сколько отдашь, столько и получишь, *минус {$loss}%* — просыпется при пересыпке.\n\n"
            . "_Что именно уйдёт и что выпадет — решает случай. Редкость сохраняется._\n\n"
            . "Выбери *редкость* ресурсов для перемешивания 👇";

        $rows    = [];
        $rowBuf  = [];
        foreach ($available as $r) {
            $rowBuf[] = ['text' => $this->rarityLabel($r), 'callback_data' => "ShuffleResources_rarity_{$r}"];
            if (count($rowBuf) === 3) {
                $rows[]  = $rowBuf;
                $rowBuf  = [];
            }
        }
        if ($rowBuf !== []) {
            $rows[] = $rowBuf;
        }
        $rows[] = [['text' => '🎮 Развлечения', 'callback_data' => 'entertainment']];

        return $this->screen($text, $rows);
    }

    /** Шаг 2 — выбор количества (только то, что игрок может позволить). */
    protected function showCountMenu(int $characterId, int $rarity): ServerResponse
    {
        if ($rarity < 1 || $rarity > 10 || ($this->catalogCountByRarity()[$rarity] ?? 0) < 2) {
            return $this->showRarityMenu($characterId);
        }

        $maxQty = $this->ownedMaxQtyByRarity($characterId)[$rarity] ?? 0;
        $loss   = $this->lossPct();

        $affordable = array_values(array_filter(
            $this->countOptions(),
            static fn (int $c): bool => $c <= $maxQty
        ));

        if ($affordable === []) {
            return $this->screen(
                "🔀 *Перемешать ресурсы* — {$this->rarityLabel($rarity)}\n\n"
                . "Для перемешивания не хватает ни одного ресурса этой редкости в нужном объёме. "
                . "Накопи побольше и возвращайся.",
                [[['text' => '⬅️ Назад', 'callback_data' => 'ShuffleResources_restart']]]
            );
        }

        $text = "🔀 *Перемешать ресурсы* — {$this->rarityLabel($rarity)}\n\n"
            . "Сколько единиц бросить в перемешивание? Возьму случайный твой ресурс этой редкости, "
            . "которого хватает, и спишу выбранное число. Взамен случайный *другой* ресурс той же "
            . "редкости получит столько же *за вычетом {$loss}%* потерь.\n\n"
            . "👇 Выбери количество (в скобках — сколько выпадет):";

        $rows   = [];
        $rowBuf = [];
        foreach ($affordable as $c) {
            $received = $this->received($c);
            $rowBuf[] = [
                'text'          => "{$c} → ~{$received}",
                'callback_data' => "ShuffleResources_confirm_{$rarity}_{$c}",
            ];
            if (count($rowBuf) === 2) {
                $rows[] = $rowBuf;
                $rowBuf = [];
            }
        }
        if ($rowBuf !== []) {
            $rows[] = $rowBuf;
        }
        $rows[] = [['text' => '⬅️ Назад', 'callback_data' => 'ShuffleResources_restart']];

        return $this->screen($text, $rows);
    }

    /**
     * Шаг подтверждения — ЧЕСТНОЕ предупреждение, что это не добыча, а обмен с
     * безвозвратной потерей (инцидент 2026-06-09: новичок принимал «Перемешать» за
     * полезную функцию и сжигал ресурсы, думая, что «добывает»).
     */
    protected function showConfirm(int $characterId, int $rarity, int $count): ServerResponse
    {
        if ($rarity < 1 || $rarity > 10 || ! in_array($count, $this->countOptions(), true)) {
            return $this->showRarityMenu($characterId);
        }
        // Источник всё ещё есть в нужном объёме?
        if (($this->ownedMaxQtyByRarity($characterId)[$rarity] ?? 0) < $count) {
            return $this->showCountMenu($characterId, $rarity);
        }

        $received = $this->received($count);
        $lost     = $count - $received;

        $text = "⚠️ *Подтверди перемешивание* — {$this->rarityLabel($rarity)}\n\n"
            . "Это *НЕ добыча*. Ты *безвозвратно отдаёшь* *{$count}* ед. случайного своего ресурса этой "
            . "редкости, а взамен случайный *другой* ресурс той же редкости получит лишь *{$received}* ед.\n\n"
            . "🔥 *Сгорит насовсем: {$lost} ед.* (потеря *{$this->lossPct()}%* при пересыпке).\n"
            . "_Что именно уйдёт и что выпадет — решает случай._\n\n"
            . "Точно перемешать?";

        return $this->screen($text, [
            [['text' => "✅ Да, перемешать ({$count} → ~{$received})", 'callback_data' => "ShuffleResources_go_{$rarity}_{$count}"]],
            [['text' => '⬅️ Назад', 'callback_data' => "ShuffleResources_rarity_{$rarity}"]],
        ]);
    }

    /** Резолв перемешивания: случайный источник → списать, случайный получатель → зачислить. */
    protected function doShuffle(int $characterId, int $rarity, int $count): ServerResponse
    {
        if ($characterId <= 0 || ! in_array($count, $this->countOptions(), true)) {
            return $this->showRarityMenu($characterId);
        }

        // Полный каталог ресурсов этой редкости (получатель — любой из них, не только из инвентаря).
        $pool = [];
        foreach ($this->resourceModel->findAllCached() as $res) {
            if ((is_numeric($res['rarity'] ?? null) ? (int) $res['rarity'] : 0) === $rarity) {
                $pool[] = $this->resInfo($res);
            }
        }
        if (count($pool) < 2) {
            return $this->showRarityMenu($characterId);
        }

        // Источники — ресурсы редкости, которых у игрока ≥ count (актуально на момент клика).
        $sources = [];
        foreach ($this->resourceModel->getCharacterResources($characterId) as $row) {
            $info = $this->resInfo($row);
            if ($info['rarity'] === $rarity && $info['qty'] >= $count) {
                $sources[] = $info;
            }
        }
        if ($sources === []) {
            return $this->screen(
                "🔀 Уже не хватает ресурсов этой редкости в нужном объёме — кто-то успел потратиться.",
                [[['text' => '⬅️ Назад', 'callback_data' => 'ShuffleResources_restart']]]
            );
        }

        $source   = $sources[array_rand($sources)];
        $received = $this->received($count);

        // Получатель — случайный другой ресурс той же редкости (исключаем источник).
        $targets = array_values(array_filter($pool, static fn (array $p): bool => $p['id'] !== $source['id']));
        if ($targets === []) {
            return $this->showRarityMenu($characterId);
        }
        $target = $targets[array_rand($targets)];

        // Списание и зачисление — в одной транзакции (найдено разведкой: раньше списание шло
        // вне какой-либо транзакции вовсе). `increaseResources()` вне scope этой story (F0
        // backlog), остаётся как есть — здесь меняется только форма списания.
        $db = \Config\Database::connect();
        $db->transStart();
        $outcome = $this->characterResourceModel->decrementIfAtLeast($characterId, $source['id'], $count);
        if ($outcome === WriteOutcome::Applied && $received > 0) {
            $this->characterResourceModel->increaseResources($characterId, $target['id'], $received);
        }
        $db->transComplete();

        if ($outcome !== WriteOutcome::Applied) {
            return $this->screen(
                "🔀 Уже не хватает ресурсов этой редкости в нужном объёме — кто-то успел потратиться.",
                [[['text' => '⬅️ Назад', 'callback_data' => 'ShuffleResources_restart']]]
            );
        }

        $lost = $count - $received;

        // Логируем расход сырья в action_log (форензика «куда делись ресурсы?»).
        $this->logActivity(
            $characterId,
            'SHUFFLE_RESOURCES',
            "rarity={$rarity} -{$count} {$source['name']} +{$received} {$target['name']} burned={$lost}"
        );
        $text = "🔀 *Перемешано!*\n\n"
            . "Ушло из кучи: *−{$count}* " . $this->label($source) . "\n"
            . "Выпало на выходе: *+{$received}* " . $this->label($target) . "\n"
            . ($lost > 0 ? "Просыпалось при пересыпке: *{$lost}* (−{$this->lossPct()}%)\n" : '')
            . "\n_Редкость {$rarity} сохранена._";

        return $this->screen($text, [
            [
                ['text' => '🔀 Ещё раз', 'callback_data' => 'ShuffleResources_restart'],
                ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
            ],
            [['text' => '🎮 Развлечения', 'callback_data' => 'entertainment']],
        ]);
    }

    // ───────────────────────── helpers ─────────────────────────

    /**
     * Сколько выпадет на выходе из $count при текущей потере (floor, ≥0).
     */
    protected function received(int $count): int
    {
        $received = (int) floor($count * (100 - $this->lossPct()) / 100);
        return max(0, $received);
    }

    /** Процент потерь при пересыпке (0..90). */
    protected function lossPct(): int
    {
        return max(0, min(90, $this->gsInt('economy.shuffle.loss_pct', 10)));
    }

    /** Минимальное из вариантов количеств (порог доступности редкости). */
    protected function minCount(): int
    {
        $opts = $this->countOptions();
        return $opts === [] ? 5 : min($opts);
    }

    /**
     * Варианты количеств (CSV из GameSettings → отсортированный уникальный список int).
     *
     * @return list<int>
     */
    protected function countOptions(): array
    {
        $raw  = $this->gsString('economy.shuffle.count_options', '5,10,25,50');
        $vals = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part) && (int) $part > 0) {
                $vals[(int) $part] = true;
            }
        }
        $list = array_keys($vals);
        sort($list);
        return $list !== [] ? $list : [5, 10, 25, 50];
    }

    /**
     * Каталог: редкость → число различных ресурсов этой редкости в игре.
     *
     * @return array<int,int>
     */
    protected function catalogCountByRarity(): array
    {
        $by = [];
        foreach ($this->resourceModel->findAllCached() as $res) {
            $r      = is_numeric($res['rarity'] ?? null) ? (int) $res['rarity'] : 0;
            $by[$r] = ($by[$r] ?? 0) + 1;
        }
        return $by;
    }

    /**
     * Инвентарь игрока: редкость → максимальное количество среди его ресурсов этой редкости.
     *
     * @return array<int,int>
     */
    protected function ownedMaxQtyByRarity(int $characterId): array
    {
        $by = [];
        foreach ($this->resourceModel->getCharacterResources($characterId) as $row) {
            $info = $this->resInfo($row);
            if ($info['qty'] > ($by[$info['rarity']] ?? 0)) {
                $by[$info['rarity']] = $info['qty'];
            }
        }
        return $by;
    }

    /**
     * @param array<string,mixed>|\App\Entities\ResourceEntity $row
     * @return array{id:int,rarity:int,name:string,icon:string,qty:int}
     */
    protected function resInfo($row): array
    {
        return [
            'id'     => is_numeric($row['id'] ?? null) ? (int) $row['id'] : 0,
            'rarity' => is_numeric($row['rarity'] ?? null) ? (int) $row['rarity'] : 0,
            'name'   => is_scalar($row['name'] ?? null) ? (string) $row['name'] : '?',
            'icon'   => is_scalar($row['icon_text'] ?? null) ? (string) $row['icon_text'] : '',
            'qty'    => is_numeric($row['quantity'] ?? null) ? (int) $row['quantity'] : 0,
        ];
    }

    /**
     * @param array{id:int,rarity:int,name:string,icon:string,qty:int} $info
     */
    protected function label(array $info): string
    {
        $icon = $info['icon'] !== '' ? $info['icon'] . ' ' : '';
        return $icon . $info['name'];
    }

    protected function rarityLabel(int $r): string
    {
        $emoji = [
            1 => '1️⃣', 2 => '2️⃣', 3 => '3️⃣', 4 => '4️⃣', 5 => '5️⃣',
            6 => '6️⃣', 7 => '7️⃣', 8 => '8️⃣', 9 => '9️⃣', 10 => '🔟',
        ];
        return ($emoji[$r] ?? (string) $r) . ' редкость';
    }

    /**
     * Единый рендер экрана (edit-in-place, ADR-018; текст полноценен в media-off).
     *
     * @param list<list<array<string,string>>> $rows
     */
    protected function screen(string $text, array $rows): ServerResponse
    {
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);
        return MediaSender::editTextOrSend($this->navTarget() + [
            'text'         => $text,
            'parse_mode'   => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $rows]),
        ]);
    }
}
