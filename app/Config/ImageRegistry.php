<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Реестр изображений + параметры пайплайна генерации единого визуала.
 *
 * Канон визуала — `mmorpg-vault/reference/Image-Style-Bible.md` (ADR-022).
 * Архитектура промпта: STYLE CORE (неизменный префикс) + LEXICON-запись + конкретика сцены.
 * Полная инвентаризация 158 файлов — `mmorpg-vault/reference/image-inventory.md`.
 *
 * ⚠️ Это СКАФФОЛД: `$images` пока содержит только пилот-кандидатов (Image-Style-Bible §7);
 * провайдеры — стабы (нужен API-ключ). См. {@see \App\Commands\GenerateImages}.
 */
class ImageRegistry extends BaseConfig
{
    // ── Провайдер ──────────────────────────────────────────────────────────
    // Ресёрч 2026-05-11: Gemini 2.5 Flash Image («Nano Banana») ≈ $0.039/картинка
    // (~$15–16 за ~400 генераций; batch — вдвое дешевле), нативный 3:2, лучшая
    // итеративность (multi-turn + до 3 reference-картинок). gpt-image-2 (medium) ≈ та же
    // цена, но edits дороже. ⚠️ seed/детерминизм НЕ доступен ни у одного — воспроизводимость
    // только через reference-image, не через seed (см. ADR-022 / Image-Style-Bible §6 addendum).
    public string $provider  = 'gemini';                 // 'gemini' | 'openai' | 'null'
    public string $model     = 'gemini-2.5-flash-image';
    public string $apiKeyEnv = 'images.api_key';          // ключ читается из .env (НЕ в репо)

    // ── Файлы ──────────────────────────────────────────────────────────────
    public string $imagesDir = 'uploads/telegram';                  // относительно FCPATH (public/)
    public string $legacyDir = 'uploads/telegram/_legacy';          // бэкап оригиналов ДО перезаписи

    /** Текстовая карта мира — арт ей не нужен и не подпадает под ребренд (Image-Style-Bible §4 Q5/Q14). */
    public bool $skipWorldMap = true;

    // ── Формат вывода (Image-Style-Bible §4 Q9) ────────────────────────────
    public string $outputFormat = 'jpg';
    public int    $jpegQuality   = 84;
    public int    $maxLongSide   = 1536;                  // px
    public int    $maxBytes      = 300_000;               // ≈300 КБ; идеал — меньше
    public string $aspectRatio   = '3:2';                 // landscape, для всего (hero — тоже 3:2)

    // ── STYLE CORE — неизменный префикс ко всем промптам ───────────────────
    // Канон — Image-Style-Bible §1. ДЕРЖАТЬ В СИНХРОНЕ. `{SCENE}` = LEXICON-запись + конкретика.
    public string $styleCore = <<<'CORE'
        A recovered analog photograph from the world of Wild World — a survival story set on a remote island, the last pocket of relative calm after a global collapse that erased modern civilization. The photo looks like it was taken on a battered 35mm film camera with long-expired stock and then kept for years: visible film grain, slightly soft focus, mild halation around highlights, faint light leaks at one edge, dust specks, fine scratches and a couple of small emulsion blemishes. Naturalistic available light only — overcast skies, low sun, firelight or the glow of salvaged lamps; no studio lighting, no flash, no HDR. Slightly faded, low-saturation colours with a gentle warm yellow-green cast and softly lifted blacks, the way old colour film ages — weathered, NOT cosy-vintage, NOT an Instagram filter. Documentary, candid framing — a little crooked, a little off-centre, as if shot quickly while moving; shallow-ish depth of field. The MAIN SUBJECT is clearly recognisable, large in the frame and roughly centred, kept in focus; the film grain, scratches and light leaks sit on the background and at the edges and never obscure or smear the main subject — readability of the subject comes first. Everything in frame is scavenged, hand-repaired and improvised: patched cloth, taped and wired tools, rust, scrap-metal welds, weather-worn wood, faded generic military surplus and pre-collapse junk repurposed for survival; partial salvaged tech (boxy DIY robots welded from scrap, makeshift teleport beacons of bent pipe and salvaged electronics) cobbled together — never sleek sci-fi. Mood is tense and weary but grounded — survival, not horror, no gore, no bodies, no blood beyond a stained bandage. No people posing for the camera — people appear only candid, in action, faces turned, shadowed, masked or silhouetted, never as a posed portrait. Absolutely no text, letters, numbers, captions, timestamps, watermarks, logos, modern brand names, real national flags, real military insignia, recognisable real-world landmarks, UI elements, frames or borders. Aspect ratio 3:2, landscape. Subject of this photograph: {SCENE}
        CORE;

    /**
     * Реестр картинок. `key` = относительный путь файла внутри `imagesDir` БЕЗ расширения.
     *
     * ⚠️ TODO: заполнить все ~140–150 реально используемых картинок из `image-inventory.md`
     * (после grep'а «used_in» по app/ + сверки `events.image` в проде, разбора orphan'ов/дублей).
     * Сейчас — пилот-кандидаты (Image-Style-Bible §7 «Пилот ~10–15»: разнородная выборка
     * для калибровки STYLE CORE/LEXICON до массовой генерации).
     *
     * `mode` — V1/V2/V3/V4 (Image-Style-Bible §1.1). `status` — pending|generated|approved|rejected.
     *
     * @var list<array{key:string, file:string, lexicon:string, scene:string, mode:string, status:string, used_in?:string, ref?:string, notes?:string}>
     */
    public array $images = [
        [
            'key'     => 'craft/standard/wired_bat',
            'file'    => 'uploads/telegram/craft/standard/wired_bat.jpg',
            'lexicon' => 'loot.common-weapon',
            'scene'   => 'a baseball bat tightly wrapped in barbed wire — the Common-rarity benchmark — rusty, with a taped grip, leaning against an improvised workbench on a piece of sacking; the bat large, centred and sharply in focus',
            'mode'    => 'V4',
            'status'  => 'pending',
            'used_in' => 'Craft/standard recipe (CraftRecipes.php)',
        ],
        [
            'key'     => 'craft/standard/robot_explorer',
            'file'    => 'uploads/telegram/craft/standard/robot_explorer.jpg',
            'lexicon' => 'robot.explorer',
            'scene'   => 'a small mobile DIY scout robot — boxy scrap body on welded treads, a tall antenna, a single camera-eye lens, a hand-painted number on the chassis — mid-roll surveying open terrain at dusk; clearly lighter and nimbler than a gatherer',
            'mode'    => 'V4',
            'status'  => 'pending',
            'used_in' => 'Craft/standard, robotics',
        ],
        [
            'key'     => 'camp/blast_furnace',
            'file'    => 'uploads/telegram/camp/blast_furnace.png',
            'lexicon' => 'building.blast-furnace',
            'scene'   => 'a crude brick-and-clay smelting furnace at a survivor camp, actively smoking with an orange glow at its mouth, hand bellows, slag heaps, soot-blackened ground — afternoon overcast light, the furnace filling the centre of the frame; no people',
            'mode'    => 'V1',
            'status'  => 'pending',
            'used_in' => 'Camp/Buildings (Buildings.php, BlastFurnace handlers)',
        ],
        [
            'key'     => 'camp/laboratory',
            'file'    => 'uploads/telegram/camp/laboratory.jpg',
            'lexicon' => 'building.laboratory',
            'scene'   => 'a tarp-walled lab corner of a camp: a stained bench with retorts and cloudy glass jars, a makeshift burner with a faint flame, a hand-cranked still, scribbled charts pinned up — overcast daylight, the bench centred and in focus; no people',
            'mode'    => 'V1',
            'status'  => 'pending',
            'used_in' => 'Camp/Buildings (Laboratory handlers)',
        ],
        [
            'key'     => 'craft/bandage_that_is_made_in_the_wild',
            'file'    => 'uploads/telegram/craft/bandage_that_is_made_in_the_wild.jpg',
            'lexicon' => 'loot.first-aid',
            'scene'   => 'a tin first-aid box with a hand-stitched cross on a cloth strip (no lettering), rolled improvised bandages and a glass vial of cloudy medicine, on a workbench — the box large, centred and in focus, the background mutely out of focus',
            'mode'    => 'V4',
            'status'  => 'pending',
            'used_in' => 'Craft general-tier (Bandage recipe)',
        ],
        [
            'key'     => 'epidemic',
            'file'    => 'uploads/telegram/epidemic.png',
            'lexicon' => 'event.epidemic',
            'scene'   => 'a quarantined camp — a sheet draped over a doorway, bowls left at the threshold, scattered medicine, no one in sight; grim but not graphic, no body — overcast, strong grain and a faded look',
            'mode'    => 'V2',
            'status'  => 'pending',
            'used_in' => 'WorldEvents (Epidemic event; events.image in DB)',
        ],
        [
            'key'     => 'volcanic_eruption',
            'file'    => 'uploads/telegram/volcanic_eruption.png',
            'lexicon' => 'event.volcanic-eruption',
            'scene'   => 'an ash plume blasting from a brooding volcano cone, glowing ejecta, the land lit hellish orange, raining grit, a scorched ridge in the foreground — heat shimmer, sulphur haze, strong grain',
            'mode'    => 'V3',
            'status'  => 'pending',
            'used_in' => 'WorldEvents (VolcanicEruption; events.image in DB)',
            'notes'   => 'дубль: см. также volcanic_eruption_image.png — определить используемый',
        ],
        [
            'key'     => 'vendor_kiosk_in_the_game_world',
            'file'    => 'uploads/telegram/vendor_kiosk_in_the_game_world.png',
            'lexicon' => 'economy.vendor-stall',
            'scene'   => 'an improvised barter stall of a wandering trader — a handcart and a tarp spread on the ground, a balance scale, mismatched crates, goods mixed together (resource heaps next to a few crafted scraps), a battered tin for coins, a stick-and-plastic awning — overcast daylight; NOT a shop window',
            'mode'    => 'V1',
            'status'  => 'pending',
            'used_in' => 'Sell/Buy actions (vendor kiosk background)',
            'notes'   => 'дубль: см. также craft/vendor_kiosk_in_the_game_world.jpg — определить используемый',
        ],
        [
            'key'     => 'wheel_fortune_game',
            'file'    => 'uploads/telegram/wheel_fortune_game.png',
            'lexicon' => 'event.wheel-of-fortune',
            'scene'   => 'a crooked wooden wheel of fortune knocked together by survivors from a cart rim, spokes and tin-can lids, a bent-nail pointer, peeling paint, set up by a fire — a fairground/yard toy, NOT a casino slot, no neon, no jackpot glow, no falling coins',
            'mode'    => 'V4',
            'status'  => 'pending',
            'used_in' => 'EntertaimentAction / WheelOfFortune mini-game',
        ],
        [
            'key'     => 'character/ready-for-adventure',
            'file'    => 'uploads/telegram/character/ready-for-adventure.jpg',
            'lexicon' => 'survivor.onboarding-hero',
            'scene'   => 'a lone survivor and a friendly little dented scrap-built helper robot (warm soft lamp-glow eye, not menacing, no weapons) standing on a calm safe shore at dawn, soft warm light, an inviting open composition — the final reassuring image of the onboarding; minimal grain, subjects centred and in focus',
            'mode'    => 'V4',
            'status'  => 'pending',
            'used_in' => 'StartGame onboarding (final step / ready-for-adventure)',
            'notes'   => 'Роби — дружелюбный (П10); первая/финальная картинка онбординга — приглашающая, не депрессивная',
        ],
    ];
}
