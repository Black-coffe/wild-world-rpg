<?php

namespace App\Services\Player;

use App\Models\BiomeModel;
use App\Models\CharacterFactionModel;
use App\Models\CharacterModel;
use App\Models\CharacterResourceModel;
use App\Models\ExploredCellsModel;
use App\Models\FactionModel;
use App\Models\MapModel;
use App\Models\ResourceModel;
use App\Models\CharactersWeaponsModel;
use App\Models\WeaponModel;
use App\Models\CharactersOutfitsModel;
use App\Models\OutfitModel;
use DateTime;
use Longman\TelegramBot\Entities\ServerResponse;
use Longman\TelegramBot\Request;

class CharacterService
{
    protected $characterModel;
    protected $exploredCellsModel;
    protected $mapModel;
    protected $biomeModel;
    protected $resourceModel;
    protected $characterResourceModel;
    protected $characterFactionModel;
    protected $factionModel;
    protected $charactersWeaponsModel;
    protected $weaponsModel;
    protected $charactersOutfitsModel;
    protected $outfitsModel;

    public function __construct()
    {
        $this->characterModel         = new CharacterModel();
        $this->exploredCellsModel     = new ExploredCellsModel();
        $this->mapModel               = new MapModel();
        $this->biomeModel             = new BiomeModel();
        $this->characterResourceModel = new CharacterResourceModel();
        $this->resourceModel          = new ResourceModel();
        $this->characterFactionModel  = new CharacterFactionModel();
        $this->factionModel           = new FactionModel();
        $this->charactersWeaponsModel = new CharactersWeaponsModel();
        $this->weaponsModel           = new WeaponModel();
        $this->charactersOutfitsModel = new CharactersOutfitsModel();
        $this->outfitsModel           = new OutfitModel();
    }

    /**
     * Показ информации о персонаже + установка клавиатуры.
     */
    public function showCharacterInfo(int $chatId, array|\App\Entities\CharacterEntity $characterRow): ServerResponse
    {
        // Reply-keyboard (нижнее меню Перс/База/Крафт/Карта/Настройки) ставит /start
        // (StartCommand — теперь ВСЕГДА, и новым, и существующим игрокам; см. правку
        // 2026-06-01 после ADR-087) и НИГДЕ не снимается → персистентна на клиенте.
        // showCharacterInfo НЕ шлёт её сама: сюда попадают либо через /start (только что
        // переотправил), либо по нажатию reply-кнопки «Перс» (клавиатура уже на экране).
        // Карточке нужен inline-keyboard, а reply+inline на одном сообщении Telegram не
        // совмещает → отдельное «привязочное» сообщение тут было бы мусором.

        // Собираем сведения о персонаже
        $exploredCount = $this->exploredCellsModel->where('character_id', $characterRow['id'])->countAllResults();
        $totalResources = $this->characterResourceModel->where('id_characters', $characterRow['id'])->countAllResults();

        $cell  = $this->mapModel->where('cell_number', $characterRow['cell_number'])->first();
        $biome = ($cell) ? $this->biomeModel->find($cell['biome_id']) : null;

        // v0.51.121 hotfix: cast Time|null|string → string. CI4 Entity wraps
        // `created_at` як Time object (per F1.4.4-B v0.48.0 dates array).
        $createdAtRaw = $characterRow['created_at'] ?? null;
        $createdAtStr = $createdAtRaw instanceof \DateTimeInterface
            ? $createdAtRaw->format('Y-m-d H:i:s')
            : (string) ($createdAtRaw ?? '1970-01-01');
        $createdDate = new DateTime($createdAtStr);
        $interval   = $createdDate->diff(new DateTime());
        $timeInGame = $interval->format('%m мес. %d дн. %h чс.');

        $gold = $characterRow['gold'] ?? 0;
        $goldText = ($gold > 0)
            ? "🧰 Есть 💰*" . number_format($gold) . "* золота"
            : "🧰 Золото отсутствует!";

        // Фракция персонажа
        $factionName = '';
        $charFaction = $this->characterFactionModel->where('character_id', $characterRow['id'])->first();
        if ($charFaction) {
            $faction = $this->factionModel->find($charFaction['faction_id']);
            if ($faction) {
                $factionName = $faction['name'];
            }
        }

        // Получаем экипировку (броня и оружие)
        $equippedWeapon = $this->getEquippedWeapon($characterRow['id']);
        $equippedArmor  = $this->getEquippedArmor($characterRow['id']);

        // Итоговый текст
        $cleanName  = $this->sanitizeName($characterRow['name'] ?? '');
        $biomeName  = $biome['name'] ?? '???';
        $text = "🤖 *Персонаж {$cleanName}*\n";
        if ($factionName) {
            $text .= "🏳️ *Фракция:* {$factionName}\n";
        }
        if ($cell) {
            $text .= "🧭 *Координаты:* X={$cell['coordinate_x']} Y={$cell['coordinate_y']} | 🌄 {$biomeName}\n";
        }

        // S4 (ROADMAP-RETENTION-10) — «полярная звезда»: текущая цель онбординг-цепочки
        // (OnbStep*) видна ВВЕРХУ карточки, чтобы растерявшийся новичок всегда знал «что
        // дальше». gated onboarding.cold_open_v2.enabled → null = строки нет (byte-identical).
        $polarLine = (new \App\Services\Onboarding\PolarStarService())->line((int) ($characterRow['id'] ?? 0));
        if ($polarLine !== null) {
            $text .= $polarLine . "\n";
        }

        $text .= "🎢 *Изучено ячеек:* {$exploredCount}\n"
            . "💼 *Всего видов ресурсов:* {$totalResources}\n"
            . "⏳ *В игре:* {$timeInGame}\n"
            . "📈 *Уровень:* {$characterRow['level']}\n"
            . "🌟 *Опыт:* {$characterRow['experience']}\n"
            . "🤸‍♂️ *Ловкость:* {$characterRow['agility']}\n"
            . "🧠 *Интеллект:* {$characterRow['intellect']}\n"
            . "💪 *Сила:* {$characterRow['strength']}\n\n"
            . "💖 *Здоровье:* {$characterRow['health']}\n"
            . "🥱 *Выносливость:* {$characterRow['tired']}\n\n"
            . "💹 *Карма торговли:* {$characterRow['trading_karma']}\n"
            . $goldText . "\n";

        // E6 (ADR-108) Ф3 — серия входов (discoverability: видна всем при killswitch ON).
        $streakLine = (new \App\Services\Player\LoginStreakService())->streakLine($characterRow);
        $text .= ($streakLine !== null ? $streakLine . "\n" : '');

        // ADR-132 Ф2 — следующая веха серии (discoverability лестницы; gated milestones_enabled).
        $streakRaw = $characterRow['login_streak'] ?? 0;
        $curStreak = is_numeric($streakRaw) ? (int) $streakRaw : 0;
        $msLine    = (new \App\Services\Player\StreakMilestoneService())->cardProgressLine((int) $characterRow['id'], $curStreak);
        $text .= ($msLine !== null ? $msLine . "\n" : '');

        // E11 (ADR-112) — активный титул (видимая идентичность; строка есть при killswitch ON И наличии титула).
        $titleSvc = new \App\Services\Player\TitleService();
        if ($titleSvc->enabled()) {
            $titleLabel = $titleSvc->activeTitleLabel((int) $characterRow['id']);
            if ($titleLabel !== null) {
                $text .= "🎖 *Титул:* {$titleLabel}\n";
            }
        }
        $text .= "\n";

        // Добавляем броню и оружие
        $text .= "🛡 *Броня:* " . ($equippedArmor ?: "❌ Нет") . "\n";
        $text .= "⚔️ *Оружие:* " . ($equippedWeapon ?: "❌ Нет") . "\n";

        // V16 (ADR-047): крафт-специализация.
        $specSvc = new \App\Services\Player\SpecializationService();
        if ($specSvc->isEnabled()) {
            $specRaw = $characterRow['specialization'] ?? null;
            $text .= "🎓 *Специализация:* " . $specSvc->labelFor(is_string($specRaw) ? $specRaw : null) . "\n";
        }

        // W5 (ADR-064): combat-drone active status line (только если активен).
        $droneSvc = new \App\Services\Player\DroneService();
        if ($droneSvc->combatIsEnabled()) {
            $activeUntilRaw = $characterRow['combat_drone_active_until'] ?? null;
            $activeUntilTs  = is_string($activeUntilRaw) && $activeUntilRaw !== '' ? strtotime($activeUntilRaw) : 0;
            if ($activeUntilTs !== false && $activeUntilTs > time()) {
                $minsLeft = max(1, (int) ceil(($activeUntilTs - time()) / 60));
                $bonus    = $droneSvc->combatInitiativeBonusPercent();
                $text .= "🛡 *Боевой дрон:* активен `{$minsLeft}` мин (+{$bonus}% инициативы)\n";
            }
        }

        // Инлайн-кнопки. ADR-150 Слайс 2: при me_hub ON персональный блок «Я»
        // (🎒 Инвентарь / ⚔️ Экип / 💊 Аптечка / 🧍 Страховка) собран В ЕДИНЫЙ блок сверху,
        // а чужегрупповые кнопки (Действия/Маяки/Магазин/Развлечения/События — мигрируют
        // в свои группы на финале ADR-150) идут ниже. OFF — исходные 3 ряда byte-identical.
        if (\App\Services\Telegram\BotMenuService::meHubEnabled()) {
            $inlineRows = [
                // Личный блок «Я»
                [
                    ['text' => '🎒 Инвентарь', 'callback_data' => 'inventory'],
                    ['text' => '⚔️ Экип',      'callback_data' => 'equipMenu'],
                ],
                [
                    ['text' => '💊 Аптечка',   'callback_data' => 'pharmacy'],
                    ['text' => '🧍 Страховка', 'callback_data' => 'PersonalInsurance'],
                ],
                // Прочее (кросс-групповое — до постройки своих групп остаётся здесь)
                [
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                    ['text' => '📡 Маяки',          'callback_data' => 'teleportBeacon'],
                ],
                [
                    ['text' => '🛒 Магазин',     'callback_data' => 'shop'],
                    ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'],
                    ['text' => '🎉 События',     'callback_data' => 'events'],
                ],
            ];
        } else {
            $inlineRows = [
                [
                    ['text' => '🎮 Развлечения', 'callback_data' => 'entertainment'],
                    ['text' => '🎉 События',     'callback_data' => 'events'],
                    ['text' => '🧑‍🌾 Действия 🛠️', 'callback_data' => 'characterActions'],
                ],
                [
                    ['text' => '📡 Маяки',       'callback_data' => 'teleportBeacon'],
                    ['text' => '🎒 Инвентарь',   'callback_data' => 'inventory'],
                    ['text' => '🛒 Магазин',     'callback_data' => 'shop'],
                ],
                [
                    ['text' => '🧍 Страховка',      'callback_data' => 'PersonalInsurance'],
                    ['text' => '💊 Аптечка',        'callback_data' => 'pharmacy'],
                    ['text' => '⚔️ Экип',           'callback_data' => 'equipMenu'],
                ],
            ];
        }

        // N4 (ADR-039): on-demand вход к выбору фракции — кнопка появляется только
        // когда lvl≥10 и фракция ещё не выбрана (faction_id=5/нет записи, joined_at пуст).
        // Раньше попасть на выбор можно было лишь по крон-пингу (FactionNotificationHandler,
        // повтор раз в 24ч). После выбора кнопка исчезает.
        $level            = (int) ($characterRow['level'] ?? 0);
        $hasChosenFaction = $charFaction
            && (int) ($charFaction['faction_id'] ?? 0) !== 5
            && !empty($charFaction['joined_at']);

        // Фракция + «Задания дня» — В ОДНОМ ряду (фидбэк владельца 2026-06-11: две
        // полноширинные кнопки подряд раздували карточку). Каждая может отсутствовать
        // (фракция выбрана / killswitch дейликов) — тогда оставшаяся занимает ряд одна.
        $midRow = [];
        if ($level >= 10 && !$hasChosenFaction) {
            $midRow[] = ['text' => '⚑ Выбрать фракцию', 'callback_data' => 'chooseFaction_info'];
        } elseif ($level < 10 && !$hasChosenFaction) {
            // E7 (ROADMAP-100): lock-кнопка для <L10 — ранняя посадка цели. Срез показал:
            // 62% доросших до L10 выбирают фракцию, но до L10 цель была НЕВИДИМА. Клик →
            // alert с prerequisite + value-prop teaser (UX-DISCOVERABILITY, не скрываем молча).
            $midRow[] = ['text' => '🔒 ⚑ Фракция (с lvl 10)', 'callback_data' => 'chooseFactionLocked'];
        }

        // N-навигация (2026-06-11) — АНТИ button-soup (memory feedback_character_card_button_soup):
        // карточка Перса разрослась до ~19 кнопок. Свернули read-only виды и прогресс-фичи в ДВА
        // подменю-хаба (единый источник кнопок — ProfileHubService). Discoverability сохранена:
        // фичи находимы через хаб, а сам хаб виден только если внутри есть ≥1 включённая фича.
        //
        // E8 (ADR-109) «🗓 Задания дня» — daily-engagement, остаётся НА карточке (важна видимость).
        $charId       = (int) ($characterRow['id'] ?? 0);
        $dailyEnabled = (new \App\Services\Quest\DailyTaskService())->enabled();
        if ($dailyEnabled) {
            $midRow[] = ['text' => '🗓 Задания дня', 'callback_data' => 'dailyTasks'];
        }
        if ($midRow !== []) {
            $inlineRows[] = $midRow;
        }

        // ДВА хаба: «📊 Прогресс» (достижения/титулы/рейтинг/экономика/что нового) +
        // «⚙️ Развитие» (специализация/проект фракции/дрон/модернизация). Каждый — только если
        // в группе есть включённые фичи. Пакуем по 2 в строку.
        $hubButtons = [];
        if (\App\Services\Player\ProfileHubService::progressButtons() !== []) {
            $hubButtons[] = ['text' => '📊 Прогресс', 'callback_data' => 'progressHub'];
        }
        if (\App\Services\Player\ProfileHubService::developmentButtons($charId, $level) !== []) {
            $hubButtons[] = ['text' => '⚙️ Развитие', 'callback_data' => 'developmentHub'];
        }
        if ($hubButtons !== []) {
            $inlineRows[] = $hubButtons;
        }

        // ADR-135 — «⚖️ Трофейная подать»: вход виден ТОЛЬКО когда механика включена И у игрока
        // есть активная подать (как вассал ИЛИ хозяин). При dormant killswitch enabled()=false →
        // query не идёт, кнопка скрыта → не обещаем невидимую фичу (live-vs-dormant honesty, ADR-132).
        $tributeSvc = new \App\Services\PVE\TributeService();
        if ($tributeSvc->enabled() && $tributeSvc->hasAnyTributeRelation($charId)) {
            $inlineRows[] = [['text' => '⚖️ Трофейная подать', 'callback_data' => 'tributeStatus']];
        }

        // ADR-127 — «📖 Путь новичка»: вход в справочник-онбординг (`/guide`). ВСЕГДА виден
        // отдельной строкой — точка спасения для растерявшегося игрока (пропустил обучение /
        // забыл механику). Read-only, без наград (можно жать сколько угодно). Не раздувает
        // ряды действий (отдельная строка снизу).
        $inlineRows[] = [['text' => '📖 Путь новичка', 'callback_data' => 'guide']];

        // S8 (ADR-146) — «👥 Позови выжившего»: вход в реферальную петлю (личная ссылка +
        // honor-титул «Зовущий» за реального приглашённого). Виден ТОЛЬКО при killswitch
        // referral.enabled (dormant → скрыт, карточка byte-identical). Без player-prerequisite →
        // доступен сразу как кнопка (UX-DISCOVERABILITY). Отдельная строка (рядом с viral-петлёй).
        if ((new \App\Services\Player\ReferralService())->enabled()) {
            $inlineRows[] = [['text' => '👥 Позови выжившего', 'callback_data' => 'referral']];
        }

        // E30 (ROADMAP-100) — viral-петля: URL-кнопка на публичный веб-профиль (flat ADR-062).
        // Игроку есть что показать наружу (уровень/титулы/достижения/PvP, БЕЗ локации) → бесплатный
        // приток. URL-кнопка открывает /profile/{id} в браузере, откуда игрок делится ссылкой.
        $rawCharId = $characterRow['id'] ?? null;
        $profCharId = is_numeric($rawCharId) ? (int) $rawCharId : 0;
        if ($profCharId > 0) {
            $inlineRows[] = [['text' => '🔗 Мой профиль (поделиться)', 'url' => base_url('profile/' . $profCharId)]];
        }

        $inlineKeyboard = ['inline_keyboard' => $inlineRows];

        return Request::sendMessage([
            'chat_id'    => $chatId,
            'text'       => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode($inlineKeyboard),
        ]);
    }

    private function getEquippedWeapon(int $characterId): ?string
    {
        $row = $this->charactersWeaponsModel->where('character_id', $characterId)->where('equipped', 1)->first();
        return $row ? ($this->weaponsModel->find($row['weapon_id'])['name'] ?? null) : null;
    }

    private function getEquippedArmor(int $characterId): ?string
    {
        // Берём все экипированные предметы
        $equippedItems = $this->charactersOutfitsModel
            ->where('character_id', $characterId)
            ->where('equipped', 1)
            ->findAll();

        if (empty($equippedItems)) {
            return null;
        }

        $armorNames = [];
        foreach ($equippedItems as $item) {
            // Для каждого предмета достаём запись из outfits
            $outfitRow = $this->outfitsModel->find($item['outfit_id']);
            if ($outfitRow) {
                $armorNames[] = $outfitRow['name'];
            }
        }

        // Склеиваем все названия запятой или любым нужным разделителем
        return !empty($armorNames) ? implode(', ', $armorNames) : null;
    }

    private function sanitizeName(string $name): string
    {
        return preg_replace('/[^a-zA-Zа-яА-ЯёЁґҐєЄїЇ0-9 ]/u', '', str_replace(['_', '-'], ' ', $name)) ?? '';
    }
}
