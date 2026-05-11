<?php

declare(strict_types=1);

namespace App\Commands;

use App\Models\ActionLogModel;
use App\Models\CharacterModel;
use App\Models\CraftedItemsLogModel;
use App\Models\CraftedItemsModel;
use App\Models\TelegramUserModel;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
use Longman\TelegramBot\Request;
use Longman\TelegramBot\Telegram;

/**
 * ⚠️  ОДНОРАЗОВАЯ КОМАНДА — УДАЛИТЬ ПОСЛЕ ИСПОЛЬЗОВАНИЯ.
 *
 * Компенсация игрокам, пострадавшим от бага события «Эпидемия» (active_event #1033 —
 * наносило ~50 HP/тик вместо 1–5 HP; пофикшено v0.51.162). Начисляет золото + крафт-предметы
 * и шлёт каждому уведомление в бот.
 *
 * Кому: персонажи из `active_events.effect_log` события, у кого |health_delta| ≥ 300
 *       (т.е. их «закатило» в ~0 HP) И level ≥ --min-level (по умолчанию 4) —
 *       это горстка реально пострадавших; ~240 аккаунтов 1-2 lvl, что присоединились во время
 *       события и почти ничего не потеряли, не трогаем. SarCasM пропускается (восстановлен вручную).
 *
 * Сколько: gold = clamp(level × 3000, 5000, 1_500_000); предметы: 5×Bandage, 3×BasicMedKit, 3×Antiseptic.
 *
 * Запуск:
 *   php spark compensate:epidemic            — DRY-RUN (печатает, кому и сколько; НИЧЕГО не меняет)
 *   php spark compensate:epidemic --execute  — реально начисляет + рассылает DM
 *
 * Идемпотентно: помечает обработанных в `action_log` (action_name='epidemic_1033_compensation'),
 * повторный --execute их пропускает.
 */
class CompensateEpidemic extends BaseCommand
{
    protected $group       = 'Maintenance';
    protected $name        = 'compensate:epidemic';
    protected $description  = '[ONE-OFF] Компенсация пострадавшим от бага события «Эпидемия» #1033 (золото + крафт + DM).';
    protected $usage        = 'compensate:epidemic [--execute] [--active-event-id N] [--min-level N]';
    protected $options      = [
        '--execute'         => 'Реально начислить и разослать. Без флага — dry-run.',
        '--active-event-id' => 'ID строки active_events (по умолчанию 1033).',
        '--min-level'       => 'Минимальный уровень персонажа (по умолчанию 4).',
    ];

    /** Суммарная потеря HP, при которой считаем персонажа «пострадавшим катастрофически». */
    private const HEALTH_LOSS_THRESHOLD = 300.0;
    private const ACTION_NAME           = 'epidemic_1033_compensation';
    /** name_eng в crafted_items => кол-во. */
    private const ITEMS                 = ['Bandage' => 5, 'BasicMedKit' => 3, 'Antiseptic' => 3];
    /** Имена персонажей, которых пропускаем (уже компенсированы вручную). */
    private const SKIP_NAMES            = ['SarCasM'];

    public function run(array $params)
    {
        $execute       = (bool) CLI::getOption('execute');
        $activeEventId  = (int) (CLI::getOption('active-event-id') ?: 1033);
        $minLevel       = (int) (CLI::getOption('min-level') ?: 4);

        $db = Database::connect();

        // 1) active_event → effect_log
        $aeRes = $db->table('active_events')->where('id', $activeEventId)->get();
        $ae    = $aeRes === false ? null : $aeRes->getRowArray();
        if ($ae === null) {
            CLI::error("active_events #{$activeEventId} не найдено.");
            return EXIT_ERROR;
        }
        $log = json_decode(is_scalar($ae['effect_log'] ?? null) ? (string) $ae['effect_log'] : '', true);
        if (!is_array($log) || $log === []) {
            CLI::error('effect_log пуст / не парсится.');
            return EXIT_ERROR;
        }

        // 2) кандидаты по порогу потери HP
        $lossByChar = [];
        foreach ($log as $charKey => $entry) {
            $cid = (int) $charKey;
            if ($cid <= 0 || !is_array($entry)) {
                continue;
            }
            $hd   = isset($entry['health_delta']) && is_numeric($entry['health_delta']) ? (float) $entry['health_delta'] : 0.0;
            $loss = abs($hd);
            if ($loss < self::HEALTH_LOSS_THRESHOLD) {
                continue;
            }
            $lossByChar[$cid] = $loss;
        }
        if ($lossByChar === []) {
            CLI::write('Нет кандидатов по порогу потери HP — нечего делать.', 'yellow');
            return EXIT_SUCCESS;
        }

        // 3) characters: level >= minLevel
        $charsRes = $db->table('characters')
            ->whereIn('id', array_keys($lossByChar))
            ->where('level >=', $minLevel)
            ->get();
        $chars = $charsRes === false ? [] : $charsRes->getResultArray();
        if ($chars === []) {
            CLI::write("Среди кандидатов нет персонажей level >= {$minLevel}.", 'yellow');
            return EXIT_SUCCESS;
        }

        // 4) crafted_items по name_eng
        $craftedItemsModel = new CraftedItemsModel();
        $itemDefs = [];
        foreach (array_keys(self::ITEMS) as $nameEng) {
            $row = $craftedItemsModel->where('name_eng', $nameEng)->first();
            if (!is_array($row) || !isset($row['id'])) {
                CLI::error("crafted_items name_eng='{$nameEng}' не найден — прерываю (предметы не начислены ни одному).");
                return EXIT_ERROR;
            }
            $itemDefs[$nameEng] = $row;
        }

        // 5) кого уже компенсировали (идемпотентность)
        $already = [];
        $alRes   = $db->table('action_log')->where('action_name', self::ACTION_NAME)->select('character_id')->get();
        foreach (($alRes === false ? [] : $alRes->getResultArray()) as $r) {
            $already[self::asInt($r['character_id'] ?? null)] = true;
        }

        $craftedItemsLogModel = new CraftedItemsLogModel();
        $charModel            = new CharacterModel();
        $tgUserModel          = new TelegramUserModel();
        $actionLogModel       = new ActionLogModel();
        $itemsStr             = $this->itemsString();

        CLI::write($execute ? '=== EXECUTE — начисляю и рассылаю ===' : '=== DRY-RUN (без --execute ничего не меняется) ===', $execute ? 'red' : 'cyan');
        $done = 0;
        $skipped = 0;

        if ($execute) {
            $this->initTelegram();
        }

        foreach ($chars as $ch) {
            $cid   = self::asInt($ch['id'] ?? null);
            $name  = is_scalar($ch['name'] ?? null) ? (string) $ch['name'] : '?';
            $level = self::asInt($ch['level'] ?? null, 1);
            $loss  = $lossByChar[$cid] ?? 0.0;
            if ($cid <= 0) {
                continue;
            }

            if (in_array($name, self::SKIP_NAMES, true)) {
                CLI::write("  skip {$name} (#{$cid}) — в SKIP_NAMES (восстановлен вручную)", 'yellow');
                $skipped++;
                continue;
            }
            if (isset($already[$cid])) {
                CLI::write("  skip {$name} (#{$cid}) — уже компенсирован ранее", 'yellow');
                $skipped++;
                continue;
            }

            $gold = $this->goldFor($level);
            CLI::write(sprintf('  #%d %s (lvl %d, потеря HP ≈ %.0f) → +%s золота, %s', $cid, $name, $level, $loss, number_format($gold, 0, '.', ' '), $itemsStr));

            if (!$execute) {
                $done++;
                continue;
            }

            // gold
            $charModel->update($cid, ['gold' => self::asFloat($ch['gold'] ?? null) + $gold]);

            // crafted items
            foreach (self::ITEMS as $nameEng => $qty) {
                $def      = $itemDefs[$nameEng];
                $defId    = self::asInt($def['id']);
                $existing = $craftedItemsLogModel->where(['character_id' => $cid, 'crafted_item_id' => $defId])->first();
                if (is_array($existing) && isset($existing['id'])) {
                    $craftedItemsLogModel->update(self::asInt($existing['id']), ['quantity' => self::asInt($existing['quantity'] ?? null) + $qty]);
                } else {
                    $craftedItemsLogModel->insert([
                        'character_id'      => $cid,
                        'task_id'           => null,
                        'crafted_item_id'   => $defId,
                        'type'              => $def['type'] ?? null,
                        'direction_craft'   => $def['direction_craft'] ?? null,
                        'crafting_location' => $def['crafting_location'] ?? null,
                        'durability_count'  => $def['durability_count'] ?? null,
                        'durability_time'   => null,
                        'quantity'          => $qty,
                    ]);
                }
            }

            // mark (idempotency)
            $actionLogModel->insert([
                'character_id'  => $cid,
                'chat_id'       => null,
                'action_name'   => self::ACTION_NAME,
                'action_status' => 'Completed',
                'description'   => "Компенсация бага «Эпидемия» #{$activeEventId}: +{$gold} золота, {$itemsStr}",
            ]);

            // DM
            $this->sendDm($tgUserModel, self::asInt($ch['telegram_user_id'] ?? null), $cid, $gold);

            $done++;
        }

        CLI::write('');
        CLI::write(
            $execute
                ? "Готово: компенсировано {$done}, пропущено {$skipped}."
                : "DRY-RUN: компенсировал бы {$done}, пропустил бы {$skipped}. Перепроверь список и запусти с --execute.",
            'green'
        );

        return EXIT_SUCCESS;
    }

    private function goldFor(int $level): int
    {
        return (int) max(5000, min(1_500_000, $level * 3000));
    }

    private function itemsString(): string
    {
        $names = ['Bandage' => 'Бинт', 'BasicMedKit' => 'Базовая аптечка', 'Antiseptic' => 'Антисептик'];
        $parts = [];
        foreach (self::ITEMS as $eng => $qty) {
            $parts[] = "{$qty}× " . $names[$eng];
        }
        return implode(', ', $parts);
    }

    private static function asInt(mixed $v, int $default = 0): int
    {
        return is_numeric($v) ? (int) $v : $default;
    }

    private static function asFloat(mixed $v, float $default = 0.0): float
    {
        return is_numeric($v) ? (float) $v : $default;
    }

    private function initTelegram(): void
    {
        $apiKey   = (string) getenv('telegram.API_KEY');
        $username = (string) getenv('telegram.BOT_USERNAME');
        try {
            $tg = new Telegram($apiKey, $username);
            Request::initialize($tg);
        } catch (\Throwable $e) {
            CLI::write('⚠️ Telegram init не удался — DM рассылаться не будут: ' . $e->getMessage(), 'yellow');
        }
    }

    private function sendDm(TelegramUserModel $tgUserModel, int $telegramUserId, int $charId, int $gold): void
    {
        if ($telegramUserId <= 0) {
            CLI::write("    ⚠️ нет telegram_user_id у #{$charId} — DM пропущен", 'yellow');
            return;
        }
        $tgUser = $tgUserModel->find($telegramUserId);
        $chatId = is_array($tgUser) ? ($tgUser['telegram_id'] ?? null) : null;
        if (empty($chatId)) {
            CLI::write("    ⚠️ нет telegram_id у tg_user {$telegramUserId} (char #{$charId}) — DM пропущен", 'yellow');
            return;
        }
        $b = self::ITEMS['Bandage'];
        $m = self::ITEMS['BasicMedKit'];
        $a = self::ITEMS['Antiseptic'];
        $text = "🤖 Привет, выживальщик! Это система Wild World.\n\n"
            . "Из-за бага в событии «Эпидемия» твой персонаж недавно получал намного больше урона, чем задумано, и мог несправедливо погибнуть — этот баг мы уже устранили. "
            . "В благодарность за то, что ты играешь с нами, и в качестве компенсации начисляем тебе:\n"
            . '• 💰 *' . number_format($gold, 0, '.', ' ') . "* золота\n"
            . "• 🩹 *{$b}×* Бинт, 💊 *{$m}×* Базовая аптечка, 🧴 *{$a}×* Антисептик\n\n"
            . "Спасибо, что ты часть Wild World! 🌍";
        try {
            Request::sendMessage(['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'Markdown']);
        } catch (\Throwable $e) {
            log_message('error', "[CompensateEpidemic] DM fail char #{$charId}: " . $e->getMessage());
            CLI::write("    ⚠️ DM не отправлен (#{$charId}): " . $e->getMessage(), 'yellow');
        }
    }
}
