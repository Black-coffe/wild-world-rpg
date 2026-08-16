<?php

declare(strict_types=1);

namespace App\Controllers\Telegram\Commands\Actions\Tribute;

use App\Controllers\Telegram\Commands\Actions\BaseAction;
use App\Services\Notifications\MediaSender;
use App\Services\PVE\TributeService;
use Longman\TelegramBot\Entities\ServerResponse;
use App\Services\Telegram\Request;

/**
 * ADR-135 Ф4 — экран «⚖️ Трофейная подать» (callback `tributeStatus`).
 *
 * Показывает положение игрока с ЖИВЫМИ числами (из строки подати + GameSettings, не drift-prone):
 * под чьей податью он (вассал: ставка / собрано / срок / стоимость выкупа + кнопка «Выкупиться»)
 * и кто платит ему (хозяин: список данников). Текстовый (media-off, ADR-020), edit-in-place
 * (ADR-018). Killswitch tribute.enabled → при dormant сообщает «недоступно» (вход и так скрыт).
 */
final class TributeStatusAction extends BaseAction
{
    public function handle(): ServerResponse
    {
        $chatId = (int) $this->callbackQuery->getMessage()->getChat()->getId();
        [$user, $character] = $this->getUserAndCharacter();
        Request::answerCallbackQuery(['callback_query_id' => $this->callbackQuery->getId()]);

        if (! $user || ! $character) {
            return Request::sendMessage(['chat_id' => $chatId, 'text' => 'Персонаж не найден.']);
        }

        $svc = new TributeService();
        if (! $svc->enabled()) {
            return MediaSender::editTextOrSend($this->navTarget() + [
                'text'       => '⚖️ *Трофейная подать* временно недоступна.',
                'parse_mode' => 'Markdown',
            ]);
        }

        $charId = is_numeric($character['id'] ?? null) ? (int) $character['id'] : 0;
        [$text, $keyboard] = $this->build($svc, $charId);

        $params = $this->navTarget() + ['text' => $text, 'parse_mode' => 'Markdown'];
        if ($keyboard !== null) {
            $params['reply_markup'] = json_encode($keyboard);
        }

        return MediaSender::editTextOrSend($params);
    }

    /**
     * @return array{0:string, 1:?array<string,mixed>}
     */
    private function build(TributeService $svc, int $charId): array
    {
        $vassalOf  = $svc->getActiveTribute($charId); // я-вассал (под чьей податью)
        $myVassals = $svc->activeAsMaster($charId);    // мои данники

        $text     = "⚖️ *Трофейная подать*\n\n";
        $keyboard = null;

        if ($vassalOf !== null) {
            $masterId   = is_numeric($vassalOf['master_id'] ?? null) ? (int) $vassalOf['master_id'] : 0;
            $masterName = $this->nameOf($masterId);
            $rate       = is_numeric($vassalOf['rate'] ?? null) ? (float) $vassalOf['rate'] : $svc->rate();
            $pct        = (int) round($rate * 100);
            $collected  = is_numeric($vassalOf['total_collected'] ?? null) ? (int) $vassalOf['total_collected'] : 0;
            $expires    = is_string($vassalOf['expires_at'] ?? null) ? $vassalOf['expires_at'] : null;
            $ransom     = $svc->ransomCost($vassalOf);

            $text .= "🩸 Ты под податью у *{$masterName}*.\n";
            $text .= "С каждой добычи *{$pct}%* уходит ему (всего отдано: *{$collected}* ед.).\n";
            if ($expires !== null) {
                $text .= 'Срок: до *' . $this->fmtDate($expires) . "*.\n";
            }
            $text .= "\nКак освободиться:\n";
            $text .= "🗡 Победи *{$masterName}* в полевом бою — подать снимется сразу.\n";
            $text .= "💰 Выкупись за *{$ransom}* 🪙 (золото сгорает).\n";
            $text .= "⏳ Или дождись истечения срока.";

            $keyboard = ['inline_keyboard' => [[
                ['text' => "💰 Выкупиться ({$ransom} 🪙)", 'callback_data' => 'tributeBuyout'],
            ]]];
        }

        if ($myVassals !== []) {
            $text .= $vassalOf !== null ? "\n\n———\n" : '';
            $text .= '👑 *Твои данники* (' . count($myVassals) . "):\n";
            foreach ($myVassals as $t) {
                $vid = is_numeric($t['vassal_id'] ?? null) ? (int) $t['vassal_id'] : 0;
                $vn  = $this->nameOf($vid);
                $col = is_numeric($t['total_collected'] ?? null) ? (int) $t['total_collected'] : 0;
                $exp = is_string($t['expires_at'] ?? null) ? $this->fmtDate($t['expires_at']) : '—';
                $text .= "• *{$vn}* — собрано {$col} ед., до {$exp}\n";
            }
            $cap   = $svc->dailyCapPerMaster();
            $text .= "_С их добычи тебе идёт доля (дневной лимит {$cap} ед.)._";
        }

        if ($vassalOf === null && $myVassals === []) {
            $text .= "Сейчас ты свободен и никого не обложил.\n\n";
            $text .= '_Раз за разом громи одного игрока в полевом PvP (и сам не проигрывай ему) — '
                . 'он начнёт отдавать тебе долю с добычи. Под подать попадают только опытные бойцы._';
        }

        return [$text, $keyboard];
    }

    private function nameOf(int $id): string
    {
        if ($id <= 0) {
            return 'неизвестный';
        }
        $res = \Config\Database::connect()->table('characters')->select('name')->where('id', $id)->get();
        $row = $res === false ? null : $res->getRowArray();
        $n   = is_array($row) ? ($row['name'] ?? null) : null;
        return is_string($n) && $n !== '' ? $n : "#{$id}";
    }

    private function fmtDate(string $dt): string
    {
        $ts = strtotime($dt);
        return $ts !== false ? date('d.m H:i', $ts) : $dt;
    }
}
