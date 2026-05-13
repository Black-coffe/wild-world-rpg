<?php

declare(strict_types=1);

namespace Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Реестр изображений + параметры пайплайна генерации единого визуала
 * («Найденная фотоплёнка», ADR-022, `mmorpg-vault/reference/Image-Style-Bible.md`).
 *
 * Архитектура промпта: STYLE CORE (неизменный префикс) + LEXICON-запись + конкретика сцены.
 * Полный человекочитаемый список + маппинг — `mmorpg-vault/reference/image-inventory.md`.
 *
 * ⚠️ Скаффолд-стадия: реестр заполнен (~140 записей по факту инвентаризации репо +
 * прод-DB `events.img_path` + `Config\CraftRecipes` + `Config\Buildings`), но `scene`-хвосты
 * — короткие черновики (доводятся на пилоте); провайдер — стаб (нужен API-ключ). Orphan'ы
 * (см. `notes` в `image-inventory.md`: `dragon_heart`, 7 пар дублей и т.п.) сюда НЕ внесены —
 * они уходят в `_legacy/` и из игры. `faction/*` — НОВЫЕ картинки (фракционного арта пока нет).
 *
 * См. {@see \App\Commands\GenerateImages}.
 */
class ImageRegistry extends BaseConfig
{
    // ── Провайдер ──────────────────────────────────────────────────────────
    // Решение user'а 2026-05-11: использовать ПОСЛЕДНИЙ генератор — OpenAI **gpt-image-2**
    // (флагман, сильнейший по LM Arena среди image-моделей; «medium» ≈$0.04/картинка — паритет
    // с Gemini 2.5 Flash Image, но та — не последняя у Google, а Gemini 3 Pro/Flash вдвое дороже).
    // `moderation: low` у OpenAI чуть свободнее по постапок-контенту (самоделки/рейдеры).
    // Альтернатива (если по качеству не зайдёт / дешевле батчем) — переключить $provider на 'gemini'.
    // ⚠️ seed/детерминизм НЕ доступен ни у одного провайдера для image — воспроизводимость/итерации
    // через reference-image (поле `ref` в записи реестра), не через seed.
    public string $provider  = 'openai';                 // 'openai' | 'gemini' | 'null'
    public string $model      = 'gpt-image-2';            // openai: 'gpt-image-2' | 'gpt-image-1-mini'; gemini: 'gemini-2.5-flash-image'
    public string $quality    = 'medium';                 // openai quality tier: low|medium|high
    public string $apiKeyEnv  = 'images.api_key';         // ключ из .env (НЕ в репо). openai → sk-...; gemini → AI...

    // ── Файлы ──────────────────────────────────────────────────────────────
    public string $imagesDir = 'uploads/telegram';                  // относительно FCPATH (public/)
    public string $legacyDir = 'uploads/telegram/_legacy';          // бэкап оригиналов ДО перезаписи (откат)

    /** Текстовая карта мира — арт ей не нужен и не подпадает под ребренд (Image-Style-Bible §4 Q5/Q14). */
    public bool $skipWorldMap = true;

    // ── Формат вывода (Image-Style-Bible §4 Q9) ────────────────────────────
    public string $outputFormat = 'jpg';
    public int    $jpegQuality   = 84;
    public int    $maxLongSide   = 1536;                  // px
    public int    $maxBytes      = 300_000;               // ≈300 КБ; идеал — меньше
    public string $aspectRatio   = '3:2';                 // landscape, для всего (hero — тоже 3:2)

    // ── STYLE CORE — неизменный префикс ко всем промптам ───────────────────
    // Канон — Image-Style-Bible §1. ДЕРЖАТЬ В СИНХРОНЕ. `{SCENE}` = LEXICON-текст + scene-хвост.
    public string $styleCore = <<<'CORE'
        A recovered analog photograph from the world of Wild World — a survival story set on a remote island, the last pocket of relative calm after a global collapse that erased modern civilization. THE IMAGE CONTAINS ABSOLUTELY NO TEXT — no writing, no letters, no words, no numbers, no digits, no characters, no glyphs, no captions, no signage, no labels, no nameplates, no chalk writing, no stencilled markings that spell anything, nothing anywhere on any surface that reads as text or writing; every sign, board, crate, barrel, sack, wall, vehicle and piece of equipment is blank, worn smooth, rusted over or marked only with abstract smudges, scratches, stains or simple non-letter shapes. The photo looks like it was taken on a battered 35mm film camera with long-expired stock and then kept for years: visible film grain, slightly soft focus, mild halation around highlights, dust specks, fine scratches and a couple of small emulsion blemishes; at most a barely-there, low-saturation light leak softening just one corner — always subtle, never a bold or vividly coloured streak, and never on the main subject. Naturalistic available light only — overcast skies, low sun, firelight or the glow of salvaged lamps; no studio lighting, no flash, no HDR. Slightly faded, low-saturation colours with a gentle warm yellow-green cast and softly lifted blacks, the way old colour film ages — weathered, NOT cosy-vintage, NOT an Instagram filter. Documentary, candid framing — a little crooked, a little off-centre, as if shot quickly while moving; shallow-ish depth of field. The MAIN SUBJECT is clearly recognisable, large in the frame and roughly centred, kept in focus; the film grain, scratches and any faint light leak sit on the background and at the edges, stay subtle, and never obscure, tint or smear the main subject — readability of the subject comes first. Everything in frame is scavenged, hand-repaired and improvised: patched cloth, taped and wired tools, rust, scrap-metal welds, weather-worn wood, faded generic military surplus and pre-collapse junk repurposed for survival; partial salvaged tech (boxy DIY robots welded from scrap, makeshift teleport beacons of bent pipe and salvaged electronics) cobbled together — never sleek sci-fi. Mood is tense and weary but grounded — survival, not horror, no gore, no bodies, no blood beyond a stained bandage. No people posing for the camera — people appear only candid, in action, faces turned, shadowed, masked or silhouetted, never as a posed portrait. And once more, the single hardest rule: zero text or writing of any kind anywhere in the frame; also no real national flags, no real military insignia, no recognisable real-world landmarks, no UI elements, no on-image frames or borders. Aspect ratio 3:2, landscape. Subject of this photograph: {SCENE}
        CORE;

    // ── Режимы старения/палитры (Image-Style-Bible §1.1) ──────────────────
    // Дописываются в хвост {SCENE} (после LEXICON-текста и scene-хвоста). V1 = baseline STYLE CORE
    // (доп. фраза не нужна). V4 — минимум артефактов (hero/лут/оружие/роботы/Роби/UI/онбординг).
    // V2 — сильное старение (точечно: катаклизмы/руины/тревожные сцены). V3 — выгоревший тёплый
    // (точечно: пустыни/вулканы/Фермеры). ⚠️ Артефакты ВСЕГДА на фоне/по краям, не на главном объекте.
    /** @var array<string,string> */
    public array $modeModifiers = [
        'V1' => '',
        'V4' => 'Render with minimal film artefacts — only very fine grain, absolutely no light leaks, no coloured edge bloom, no visible scratches; clean clear edges; the main subject crisp, sharp and clearly readable even at thumbnail size.',
        'V2' => 'Render with heavier film ageing — coarser grain, stronger fade, a few more scratches, lower contrast, a faint cool edge haze — but keep all artefacts subtle and the main subject clearly readable; no bold or vividly coloured light streaks.',
        'V3' => 'Colour-grade warmer and drier — a faded amber-yellow cast and gentle edge vignetting (no bold or vividly coloured light streak), no green tint, as if shot in the low golden light of a desert or volcanic land.',
    ];

    // ── LEXICON — «как X выглядит в Wild World» (канон — Image-Style-Bible §2) ───────────────
    // Per-image промпт = styleCore с {SCENE} := lexicon[entry] . ' ' . scene-хвост записи.
    /** @var array<string,string> */
    public array $lexicon = [
        // — Выживший / персонаж —
        'survivor'                => 'a lone survivor — lean, sun- and wind-weathered, in layered patched clothing (worn jacket, scarf or improvised dust mask, fingerless gloves, scuffed boots), a hand-stitched backpack, salvaged tools clipped on with wire and tape; not a hero, slightly hunched, tired; face ordinary, shadowed, turned away or masked.',
        'survivor.equipped'       => 'a lone survivor wearing the gear they crafted — patchwork armour of sewn leather and bolted scrap plates, a corded crossbow or rebar spear slung, a loaded hand-stitched backpack; weather-worn, candid, face shadowed.',
        'survivor.action'         => 'a lone survivor mid-action in the wild — hauling, foraging, hurrying — gear on their back, face turned away, in available light; tense, working, not posing.',
        'survivor.onboarding-hero'=> 'a lone survivor and a friendly little dented scrap-built helper robot with a warm soft lamp-glow eye (not menacing, no weapons) standing together on a calm safe shore at dawn, soft warm light, an inviting open composition — the reassuring face of a new beginning.',
        // — Лагерь / стройка —
        'camp.neutral'            => "a modest 'undecided' survivor's camp on cleared ground: a single patched tarp tent, a fire pit, a couple of crates, mixed scavenged junk, no faction markings; fragile, lived-in.",
        'camp.overview'           => "a survivor's camp seen from a little way off — a patched tent or shack, drying racks, rusted blue water barrels, several rough buildings of corrugated sheet and scavenged timber, smoke from a fire; functional, fragile, lived-in.",
        'camp.empty-plot'         => 'a bare cleared patch of ground in the wild, ready to pitch a camp on — trampled grass, a few stones, the wider landscape around; nothing built yet.',
        'camp.fresh'              => 'a freshly pitched camp — a new patched tarp tent just raised, a small fire being lit, crates still being unpacked, the wider wild around.',
        'camp.relocation'         => "a survivor's camp being packed up and moved — tarps folded, gear loaded onto a handcart, the old fire pit cold, tracks leading away.",
        'camp.build-hub'          => "a camp's improvised construction yard — stacks of pallets, corrugated sheet, scavenged timber, a workbench, tools, half-finished frames; the place where buildings get put up.",
        'camp.fortify'            => 'a survivor camp being dug in — a shallow earthwork, sandbags, sharpened stakes, a salvaged sheet-metal barricade, churned mud.',
        'camp.tax-report'         => 'crates and sacks of supplies being counted out and carried off at a camp at dawn — the daily upkeep being paid; piles of resources, a board scuffed with chalk smudges (no writing), tired figures.',
        'building.in-progress'    => 'a camp building only half-built — a bare timber-and-scaffold frame, sheets of corrugated metal leaning, tools and offcuts on the ground, not yet roofed.',
        'building.workshop'       => 'a lean-to crafting shed at a camp: a heavy improvised workbench under a corrugated-sheet roof, a car-salvaged vice, hand-forged tools on a board, wood shavings and metal offcuts underfoot; the workbench centred and in focus.',
        'building.arsenal'        => 'a reinforced sandbagged armoury shack at a camp: a rack of improvised weapons (rebar spears, corded crossbows, barbed-wire bats), a workbench with a weapon held in a clamp, ammo tins, a daub of faded paint on a steel plate.',
        'building.laboratory'     => 'a tarp-walled lab corner of a camp: a stained bench with retorts and cloudy glass jars, a makeshift burner with a faint flame, a hand-cranked still, sketched diagrams pinned up — lines, dots and shapes only, no writing.',
        'building.greenhouse'     => 'a polytunnel at a camp: stretched cloudy plastic over bent pipe, rows of planters with green shoots actually growing, seed trays, condensation beading on the sheeting.',
        'building.warehouse'      => 'a big patched corrugated-sheet storage barn at a camp, shelves and stacks of crates, sacks and rusted barrels piled to the rafters — visibly full = wealth.',
        'building.hand-pump'      => 'a hand-cranked pump-column over a dug well at a camp, mid-stroke with water dripping into a bucket, churned mud around it, a coil of hose.',
        'building.solar-station'  => 'a tilted rack of mismatched scavenged solar panels at a camp, soldered onto a frame, cables running to a bank of salvaged car batteries and a junk fuse box.',
        'building.gym'            => 'an open-air workout rig at a camp: welded scaffold pipe, stacked tyres, a barbell of concrete-filled tins, a battered wooden board scuffed with rough scratch-marks.',
        'building.comm-tower'     => 'a tall lashed-together lattice antenna mast at a camp, bristling with cables and guy-wires, a wind-vane on top, a small junk-built shed at its base.',
        'building.blast-furnace'  => 'a crude brick-and-clay smelting furnace at a camp, actively smoking with an orange glow at its mouth, hand bellows, slag heaps, soot-blackened ground.',
        'building.teleport-center'=> 'a low platform at a camp ringed with salvaged electronics, hand-wound copper coils and a crooked pipe arch, faint unstable blue arc-light, ozone shimmer — improvised and fragile, NOT a stargate.',
        'building.robotics-workshop'=> 'a workbench at a camp strewn with robot limbs, circuit boards and wiring, a half-built boxy scrap-welded robot clamped upright on a stand, manipulator arms hanging.',
        // — Биомы —
        'biome.forests'           => 'dense temperate forest, tall mossy trees, fern undergrowth, soft grey overcast light through the canopy, mist between trunks; a rusted dead vehicle half-swallowed by roots in the background; calm.',
        'biome.mountains'         => 'steep grey rock and scree, thin cold air, patchy snow, a narrow precarious path, distant peaks lost in haze, a fallen pylon on a ridge; harsh, exposed.',
        'biome.tundra'            => 'a flat frozen plain, crusted snow and black ice, wind-scoured, skeletal dead shrubs, a low pale sun, near-whiteout, the ribs of a wreck poking through the snow; brutally cold.',
        'biome.rivers'            => 'a wide slow river or flooded lowland, reed banks, gravel bars, a half-sunk wreck, rusted blue barrels in the shallows, grey water mirroring the sky; damp.',
        'biome.jungles'           => 'thick humid jungle, oversized dripping leaves, vines and buttress roots, green gloom pierced by a few light shafts full of insects, a faded tarp tangled in the canopy; oppressive heat.',
        'biome.fields'            => 'open rolling grassland, tall dry grass and wildflowers, a lone wind-bent tree, a big soft sky — but a leaning power pole without wires and a rusting vehicle hull in the grass; the calmest land.',
        'biome.caves'             => 'a dark rock cavern or collapsed underground structure, stalactites, rubble, one distant light source, deep shadow, scattered scavenged gear at the edge of the light; claustrophobic, dangerous.',
        'biome.volcanic'          => 'black basalt and ash fields, ground cracked and glowing orange in fissures, smoke and heat shimmer, a brooding volcano cone, sulphur haze, freshly scorched dead vegetation; lethal.',
        'biome.deserts'           => 'endless rippled sand dunes, bleached bones, a battered hand-painted board half-buried with its paint peeled to abstract shapes, a dead vehicle stripped to the frame, blinding sun and heat haze; dry, deadly, silent.',
        'biome.collage'           => "a montage of the island's wild lands seen as one weathered photo-spread — forest, mountains, snow, river, jungle, grassland, dunes — varied but clearly one ruined world.",
        // — Лут / снаряжение —
        'loot.common-weapon'      => 'an improvised low-grade weapon — a barbed-wire-wrapped bat / a rebar spear / a leaf-spring knife / a crude zip-gun — rusty, taped, makeshift; laid on sacking or a workbench, large and in focus.',
        'loot.mid-weapon'         => 'a serviceably-made improvised weapon — cleaner welds, sound straps, less rust, a cord-bound wooden crossbow or a forged spear; laid out clearly, in focus.',
        'loot.epic-weapon'        => 'a finely built improvised weapon — clearly the work of skilled hands, a composite-limbed crossbow, blued steel, a real pre-collapse component repurposed; better made, NOT glowing, NO particles.',
        'loot.weapon-overview'    => 'a row of improvised weapons of different grades laid out on sacking — junk knives and zip-guns up to a composite crossbow — the spread of what a survivor can build.',
        'loot.first-aid'          => 'a tin first-aid box with a hand-stitched cross on a cloth strip (no lettering), rolled improvised bandages and a glass vial of cloudy medicine, on a workbench; the box centred and in focus, the background mutely soft.',
        'loot.medicine'          => 'improvised medicine — glass vials and jars of cloudy liquids and powders, a hand-cranked still in the background, a stained bench; field pharmacology, dirty and useful.',
        'loot.tool'               => 'a hand-forged improvised tool — a pick / axe / shovel / hoe / fishing rod with a taped or corded handle, weathered metal, on sacking or leaning against a workbench; the tool large and in focus.',
        'loot.armor'              => 'patchwork survivor armour — sewn leather panels with bolted scrap-metal plates, riveted straps, weather-worn; laid out on a workbench, in focus.',
        'loot.clothing'           => 'salvaged survivor clothing — a ragged shirt / a drifter\'s patched coat — faded, mended, layered; hung or laid out, in focus.',
        'loot.backpack'           => 'a hand-stitched survivor backpack of patched canvas and salvaged straps, bulging with gear, buckles and clips improvised; on a workbench, in focus.',
        'loot.protection-item'    => 'a capital, durable protective structure — a dug-in concrete-and-sheet-metal shelter, sloped roof, sandbags, heavy slabs — clearly NOT a flimsy umbrella-shield, NOT a one-time cape, NO force-field glow; it reads "permanent, won\'t wear out".',
        // — Ресурсы —
        'resource.junk'           => 'raw materials dumped in heaps and torn sacks — split firewood, piles of stone and ore chunks, jerrycans of water, baskets of berries and fruit, bundles of bark and moss, jars of dried insects; abundant, dirty, useful.',
        'resource.mid'            => 'a crafted component — fabric / fertiliser / charcoal briquettes / metal fragments / stone blocks / wiring / electronics / soil / glass — sorted, tied or in decent tins and crates on a workbench, in focus.',
        'resource.rare'           => 'a single precious component — wrapped in clean cloth, set in its own padded case, on a workbench, almost reverently; it reads "this is precious" without a price tag.',
        'resource.food'           => 'food and water stores — baskets of foraged berries and fruit, sealed ration tins, jerrycans and bottles of water, dried strips on a rack; sustenance, honest and dirty.',
        // — Роботы / техника —
        'robot.explorer'          => 'a small mobile DIY scout robot — boxy scrap body on welded treads or spindly legs, a tall antenna, a single camera-eye lens, a daub of bright hand-painted colour on the chassis — mid-roll surveying the terrain; lighter and nimbler than a gatherer.',
        'robot.gatherer'          => 'a squat, heavy DIY worker robot — scoops or claws, a resource sack slung on its back, low to the ground, a daub of bright hand-painted colour on its flank — mid-task digging or hauling; clearly heavier and bulkier than the scout.',
        'robot.overview'          => 'a scrap-built scout robot and a heavier scrap-built gatherer robot side by side in a robotics yard — clearly two different machines for two different jobs.',
        'robot.robi'              => 'a friendly little scrap-built helper robot — boxy, dented and a bit comical like an old domestic appliance, a warm soft lamp glow for an eye (not red, not menacing), no weapons, no jagged grilles, turned toward the viewer as if greeting; welded from junk but clearly kind.',
        'beacon'                  => 'a makeshift teleport beacon — a crooked tripod of bent pipe and rebar, car batteries and salvaged electronics duct-taped to a frame, hand-wound copper coils, a faint unstable blue glow — fragile, improvised, NOT a portal arch.',
        // — События —
        'event.berry-boom'        => 'bushes heavy with ripe berries, a sudden abundance, overflowing baskets, berry-stained hands — yes, abundance happens in this world too.',
        'event.dryness'           => 'a parched, cracked landscape under a harsh white sky — dried-up streambeds, withered plants, dust, an empty water barrel; the land gone dry.',
        'event.epidemic'          => 'a quarantined camp — a sheet draped over a doorway, bowls left at the threshold, scattered medicine, no one in sight; grim but NOT graphic, no body.',
        'event.exotic-flowering'  => 'an unexpected burst of strange wildflowers across the wild — vivid blooms among the rust and ruin, a survivor pausing to look; brief beauty.',
        'event.fever'             => 'a feverish survivor sweating by a low fire, a damp cloth on the brow, a faint heat-shimmer at the frame edge.',
        'event.fish-stock'        => 'a river or shore alive with fish — a survivor hauling a heavy net or full line, buckets brimming; a rare windfall of food.',
        'event.forest-fire'       => 'a wildfire crowning through the trees, orange glare, smoke blotting the sky, falling embers, a fleeing silhouette.',
        'event.geothermal'        => 'steam rising from a hot pool among rocks, mineral crusting, an inviting warmth, a survivor warming their hands at the edge.',
        'event.gold-mine'         => 'a glinting metallic seam exposed in cracked rock or a streambed, flecks catching the light, a pick leaning nearby, ingots of mismatched size set out.',
        'event.hurricane'         => 'violent wind bending trees, debris airborne, a tarp flying off a shack, sheeting rain, a barrel rolling.',
        'event.locust-exodus'     => 'a dense swarm of insects darkening the sky over the wild, stripped vegetation, a survivor covering their face; the land being eaten bare.',
        'event.meteor-impact'     => 'a single fireball slamming down at the horizon, a white flash and shockwave ring, dust and debris thrown up, a smoking crater forming; shot from a distance, the camera shaken.',
        'event.meteor-rain'       => 'streaks of fire raking the night sky, small impacts kicking up dust across a biome.',
        'event.mirage-oases'      => 'a shimmering false pool of water and palms wavering on the blinding desert horizon — a heat-haze illusion, not magic.',
        'event.mountain-echo'     => 'a lone figure on a high ridge, hand cupped, vast empty mountain space around, silence — the immensity of it.',
        'event.night-attacks'     => 'shapes moving at the edge of firelight, eyeshine in the dark, a survivor turning with a raised weapon; tension, NO gore, NO body.',
        'event.northern-lights'   => 'ribbons of green-violet light over a still snowbound landscape — an atmospheric phenomenon on film, not a fantasy effect.',
        'event.polar-night'       => 'endless dark twilight over a snowbound camp, deep blue shadow, a faint aurora, a single lamp burning.',
        'event.hidden-discovery'  => 'the earth gaped open revealing a dark stair or tunnel mouth, cold air breathing out, the unknown below, a survivor peering in with a lamp.',
        'event.sandstorm'         => 'a towering wall of ochre dust rolling over the dunes, the world dissolving into grit, a lone figure shielding their face, a tarp tearing loose.',
        'event.snowfall'          => 'thick heavy snow falling, everything muffled and white, footprints filling in, a half-buried camp.',
        'event.spring-flood'      => 'meltwater spreading over low ground from the hills, half-submerged paths, debris and a barrel floating, a flooded tent.',
        'event.starfall'          => 'a clear night sky streaked with shooting stars over a quiet camp.',
        'event.tremor'            => 'a camp shaken apart — a collapsed shack, a crack splitting the ground, dust hanging in the air, scattered crates; an aftershock.',
        'event.volcanic-eruption' => 'an ash plume blasting from a brooding volcano cone, glowing ejecta, the land lit hellish orange, raining grit, a scorched ridge in the foreground; heat shimmer, sulphur haze.',
        'event.sandy-wolves'      => 'lean dust-wrapped raiders on a dune ridge, scavenged weapons, faces hidden by goggles and scarves; menacing silhouettes against the sun.',
        'event.death-roulette'    => 'a stark ominous moment — a guttering candle, a long shadow, a scorched circle on the ground, fate hanging; abstract dread, NO violence, NO body. If respawn is shown instead: an empty camp at dawn, a cooled campfire, gear neatly stacked at the entrance — quietly saying someone left and someone will return, no maimed character.',
        'event.generic'           => 'a tense moment somewhere in the ruined wild — weather closing in or something disturbed in the distance; ominous, ordinary, postapocalyptic.',
        'event.collage'           => "a montage of the island's events seen as one weathered photo-spread — a dust wall, a wildfire, an aurora, a quarantined doorway, a meteor streak — varied dangers and wonders of one world.",
        // — Мини-игры —
        'minigame.wheel'          => 'a crooked wooden wheel of fortune knocked together by survivors from a cart rim, spokes and tin-can lids, a bent-nail pointer, peeling paint, set up by a fire — a fairground/yard toy, NOT a casino slot, no neon, no jackpot glow, no falling coins.',
        'minigame.guess-number'   => 'rough chalk scratch-marks and a row of pebble counters laid out on a sheet of rusty metal by a fire — a survivors\' guessing game, no writing.',
        'minigame.rps'            => 'two weathered hands at a fire making the rock-paper-scissors gestures — a survivors\' yard game.',
        'minigame.hub'            => 'a survivors\' makeshift games corner by a fire — a crooked wheel of fortune, a chalk number board, idle hands; rough, homemade fun amid the ruins.',
        // — Экономика / инвентарь —
        'economy.vendor-stall'    => 'an improvised barter stall of a wandering trader — a handcart and a tarp spread on the ground, a balance scale, mismatched crates, goods mixed together (resource heaps next to a few crafted scraps), a battered tin for coins, a stick-and-plastic awning, maybe a phlegmatic pack animal — a place where junk is swapped for junk, NOT a shop window.',
        'economy.inventory-warehouse'=> 'a survivor\'s store of supplies — a patched-sheet shed or a stash in the forest, shelves and stacks of crates, sacks, barrels, sorted gear; the loot you\'ve hauled in.',
        // — Объекты мира —
        'world.abandoned'         => 'a long-abandoned pre-collapse structure in the wild — a collapsing warehouse / a heap of rusted weathered tools and junk — overgrown, picked over, eerie.',
        'world.generic'          => 'a quiet, weather-worn corner of the ruined island — rust, scrub, grey light; nothing happening, just the world.',
        // — Квесты —
        'quests.journal'          => "a survivor's hand-bound quest journal open on a workbench — pages of pencil sketches and little drawings, pinned scraps, a stub of pencil, pictures only and no writing; the survivor's record of what's been done.",
        // — Карта (РЕШЕНИЕ: стилизованная картинка острова или skip) —
        'map.island'              => 'a hand-drawn, water-stained map of a remote island pinned to a board — pencil coastlines, a faint grid, marked spots, foxed paper; the survivor\'s mental picture of the land.',
        // — Фракции (НОВЫЕ картинки — фракционного арта пока нет) —
        'faction.military'        => 'a fortified compound — sandbags, razor wire, a salvaged armoured vehicle, ranked crates, a hand-stencilled emblem on steel; cold overcast noon, hard shadows, rigid geometry; disciplined, heavy.',
        'faction.military.endgame'=> "the Military faction's hand-stencilled mark raised over a captured strategic point, ordered patrols, the island under their control — domination.",
        'faction.partisans'       => 'a hidden bush camp — camouflaged lean-tos, snares, trophies, paint-marks, a watch perch up a tree, gear in dappled shade; dusk / pre-dawn murk, silhouettes, minimal light; sly, paranoid, mobile.',
        'faction.partisans.endgame'=> 'a rival installation burning and torn down, scattered chaos, no one in charge — anarchy.',
        'faction.engineers'       => 'a tech yard — half-built scrap robots, soldered solar arrays, cable runs, a humming makeshift beacon, tools and parts everywhere; the warm glow of the beacon, lamps and welding sparks as the only active light amid the junk; inventive, cluttered, brilliant.',
        'faction.engineers.endgame'=> 'a large jury-rigged machine or beacon roaring to full power, arcs of light — a technological breakthrough built from scrap.',
        'faction.farmers'         => 'a working homestead — cloudy polytunnels, tilled rows, water barrels, livestock pens, sacks of grain, washing on a line, a communal table, smoke from a chimney; low golden sun, a fire — the warmest, most human shot; calm, abundant, communal.',
        'faction.farmers.endgame' => 'a laden raft or boat pulling away from the island shore at dawn, people aboard, a peaceful collective exodus — evacuation.',
        'faction.reveal-hub'      => 'four weathered photographs laid side by side — a fortified compound, a hidden bush camp, a tech yard, a working homestead — the four paths a survivor can choose; one world, four ways to live in it.',
    ];

    /**
     * Реестр картинок. `key` = относительный путь файла внутри `imagesDir` БЕЗ расширения.
     * `scene` — короткий хвост (ракурс/конкретика) ИЛИ '' (тогда промпт = только LEXICON-текст).
     * `mode` — V1 (дефолт) / V2 (катаклизмы-руины) / V3 (пустыни-вулканы-Фермеры) / V4 (лут/оружие/роботы/Роби/UI-объекты/онбординг/hero).
     * `status` — pending|generated|approved|rejected. `used_in` — где показывается. `notes` — флаги/дубли/ref.
     * ⚠️ scene-хвосты — черновики, доводятся на пилоте. Orphan'ы (image-inventory.md) сюда НЕ внесены.
     *
     * @var list<array{key:string, file:string, lexicon:string, scene:string, mode:string, status:string, used_in?:string, ref?:string, notes?:string}>
     */
    public array $images = [
        // ─────────────── ВЫЖИВШИЙ / ПЕРСОНАЖ / ДЕЙСТВИЯ ───────────────
        ['key' => 'picture_of_the_playable_character', 'file' => 'uploads/telegram/picture_of_the_playable_character.png', 'lexicon' => 'survivor', 'scene' => 'a portrait-distance shot, face shadowed, the wider wild behind', 'mode' => 'V4', 'status' => 'generated', 'used_in' => 'character info / start screens'],
        ['key' => 'gear/equipped_hero', 'file' => 'uploads/telegram/gear/equipped_hero.png', 'lexicon' => 'survivor.equipped', 'scene' => 'standing ready, gear visible on the body', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'gear/equipment screen'],
        ['key' => 'character_ready_to_act', 'file' => 'uploads/telegram/character_ready_to_act.png', 'lexicon' => 'survivor.action', 'scene' => 'about to set off, pack shouldered, looking out over the land', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'character actions menu'],
        ['key' => 'character_rushes_back_to_his_base', 'file' => 'uploads/telegram/character_rushes_back_to_his_base.png', 'lexicon' => 'survivor.action', 'scene' => 'hurrying back toward a distant camp, weather closing in', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'return-to-base / compass'],
        ['key' => 'character_exhausted_need_treatment', 'file' => 'uploads/telegram/character_exhausted_need_treatment.png', 'lexicon' => 'event.fever', 'scene' => 'slumped, drained, in need of medicine', 'mode' => 'V2', 'status' => 'generated', 'used_in' => 'low health / treatment prompt', 'notes' => 'orphan: grep пуст вне реестра 2026-05-13; LowHealthWarningHandler использует character/low_health_warning.png. Кандидат на deprecated или подключить отдельно. Картинка сгенерирована, файл готов.'],
        ['key' => 'gather_food_and_water_to_survive', 'file' => 'uploads/telegram/gather_food_and_water_to_survive.png', 'lexicon' => 'resource.food', 'scene' => 'a survivor foraging berries and filling water containers in the wild', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'food/water consumption notice'],
        ['key' => 'is_sent_to_extract_the_resources', 'file' => 'uploads/telegram/is_sent_to_extract_the_resources.png', 'lexicon' => 'robot.gatherer', 'scene' => 'a gatherer robot trundling off into a biome to mine resources', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'robot gatherer dispatched'],
        ['key' => 'moves_to_another_territory', 'file' => 'uploads/telegram/moves_to_another_territory.png', 'lexicon' => 'survivor.action', 'scene' => 'on the march across open land toward new territory, the route behind', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'march / relocation'],
        ['key' => 'send_message_telegram_info_from_character_data', 'file' => 'uploads/telegram/send_message_telegram_info_from_character_data.png', 'lexicon' => 'survivor', 'scene' => 'a quiet moment, the survivor checking their kit, face shadowed', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'character data message', 'notes' => 'возможно служебная — проверить актуальность'],
        ['key' => 'character/reset_character', 'file' => 'uploads/telegram/character/reset_character.jpg', 'lexicon' => 'survivor', 'scene' => 'a survivor at a low ebb, starting over, gear stripped down', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'admin CharacterReset'],

        // ─────────────── ОНБОРДИНГ РОБИ (Волна 1, V4, UX-прогон новичком — П10) ───────────────
        ['key' => 'character/bioms-for-game-tips', 'file' => 'uploads/telegram/character/bioms-for-game-tips.jpg', 'lexicon' => 'biome.collage', 'scene' => 'a montage showing the island\'s biomes, each land clearly recognisable', 'mode' => 'V4', 'status' => 'generated', 'used_in' => 'onboarding step «biomes»'],
        ['key' => 'character/dynamic-collage-of-24-unique-events-from-the-game', 'file' => 'uploads/telegram/character/dynamic-collage-of-24-unique-events-from-the-game.jpg', 'lexicon' => 'event.collage', 'scene' => 'a montage showing the world\'s events, each one recognisable', 'mode' => 'V4', 'status' => 'generated', 'used_in' => 'onboarding step «events»'],
        ['key' => 'character/final-step-image', 'file' => 'uploads/telegram/character/final-step-image.jpg', 'lexicon' => 'survivor.onboarding-hero', 'scene' => 'a final reassuring image of a fresh start at dawn', 'mode' => 'V4', 'status' => 'generated', 'used_in' => 'onboarding final step'],
        ['key' => 'character/ready-for-adventure', 'file' => 'uploads/telegram/character/ready-for-adventure.jpg', 'lexicon' => 'survivor.onboarding-hero', 'scene' => 'about to step into the world, an inviting open dawn composition', 'mode' => 'V4', 'status' => 'generated', 'used_in' => 'onboarding «ready for adventure»'],
        ['key' => 'character/low_health_warning', 'file' => 'uploads/telegram/character/low_health_warning.png', 'lexicon' => 'event.fever', 'scene' => 'a stark warning moment — critical condition, act now; ominous but not gory', 'mode' => 'V2', 'status' => 'generated', 'used_in' => 'LowHealthWarningHandler'],

        // ─────────────── КАРТА (РЕШЕНИЕ: стилизованная картинка острова или SKIP) ───────────────
        ['key' => 'character/world_map_1000x1000', 'file' => 'uploads/telegram/character/world_map_1000x1000.png', 'lexicon' => 'map.island', 'scene' => 'a hand-drawn island map pinned to a board, water-stained, marked', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'world map screen', 'notes' => 'РЕШИТЬ: регенерить как стилизованную карту острова, или skip (текстовая карта — исключение из ребренда; эта — декоративная картинка). Возможен дубль с beautiful_map.'],
        ['key' => 'character/beautiful_map', 'file' => 'uploads/telegram/character/beautiful_map.png', 'lexicon' => 'map.island', 'scene' => '', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'onboarding/tips «map»', 'notes' => 'РЕШИТЬ (см. world_map_1000x1000). Дубль?'],
        ['key' => 'map-lines-coordinates', 'file' => 'uploads/telegram/map-lines-coordinates.jpg', 'lexicon' => 'map.island', 'scene' => 'a map with a grid of coordinate lines drawn on it', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'map/coordinates explainer', 'notes' => 'РЕШИТЬ (см. выше). Дубль с character/map-lines-coordinates-start-position.jpg?'],
        ['key' => 'character/map-lines-coordinates-start-position', 'file' => 'uploads/telegram/character/map-lines-coordinates-start-position.jpg', 'lexicon' => 'map.island', 'scene' => 'a map with a coordinate grid and a marked starting position', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'onboarding «your start position»', 'notes' => 'РЕШИТЬ (см. выше)'],

        // ─────────────── ЛАГЕРЬ / СТРОЙКА ───────────────
        ['key' => 'camp/Construction-by-improvised', 'file' => 'uploads/telegram/camp/Construction-by-improvised.jpg', 'lexicon' => 'camp.build-hub', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'BuildListAction (build menu bg), CraftRecipes Toolkit'],
        ['key' => 'camp/an_empty_area', 'file' => 'uploads/telegram/camp/an_empty_area.jpg', 'lexicon' => 'camp.empty-plot', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'camp creation (choose cell)'],
        ['key' => 'camp/base_with_its_buildings', 'file' => 'uploads/telegram/camp/base_with_its_buildings.jpg', 'lexicon' => 'camp.overview', 'scene' => '', 'mode' => 'V1', 'status' => 'generated', 'used_in' => 'ShowBaseInfoAction'],
        ['key' => 'camp/new_camp', 'file' => 'uploads/telegram/camp/new_camp.png', 'lexicon' => 'camp.fresh', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'CampCreateConfirmAction (camp pitched)'],
        ['key' => 'camp/relocation', 'file' => 'uploads/telegram/camp/relocation.png', 'lexicon' => 'camp.relocation', 'scene' => 'mid-move, gear on a handcart', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'base relocation in progress'],
        ['key' => 'camp/relocation_finish', 'file' => 'uploads/telegram/camp/relocation_finish.jpg', 'lexicon' => 'camp.fresh', 'scene' => 'the camp re-pitched on new ground, unpacking', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'base relocation finished'],
        ['key' => 'camp/tax_for_building', 'file' => 'uploads/telegram/camp/tax_for_building.png', 'lexicon' => 'camp.tax-report', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'daily building tax report'],
        ['key' => 'camp/entrenchAction', 'file' => 'uploads/telegram/camp/entrenchAction.jpg', 'lexicon' => 'camp.fortify', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'entrench action'],
        ['key' => 'camp/WorkShop', 'file' => 'uploads/telegram/camp/WorkShop.png', 'lexicon' => 'building.workshop', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'Workshop building (Buildings.php, WorkshopConstruction)'],
        ['key' => 'camp/arsenal', 'file' => 'uploads/telegram/camp/arsenal.png', 'lexicon' => 'building.arsenal', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'Arsenal building (ArsenalHandler)'],
        ['key' => 'camp/arsenal_in_progress', 'file' => 'uploads/telegram/camp/arsenal_in_progress.jpg', 'lexicon' => 'building.in-progress', 'scene' => 'an armoury shack half-built — sandbags being stacked, a weapon rack frame', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'Arsenal construction in progress'],
        ['key' => 'camp/laboratory', 'file' => 'uploads/telegram/camp/laboratory.jpg', 'lexicon' => 'building.laboratory', 'scene' => '', 'mode' => 'V1', 'status' => 'generated', 'used_in' => 'Laboratory building (LaboratoryHandler)'],
        ['key' => 'camp/Greenhouse_craft', 'file' => 'uploads/telegram/camp/Greenhouse_craft.png', 'lexicon' => 'building.greenhouse', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'Greenhouse building (GreenhouseHandler)'],
        ['key' => 'camp/Warehouse', 'file' => 'uploads/telegram/camp/Warehouse.png', 'lexicon' => 'building.warehouse', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'Warehouse building'],
        ['key' => 'camp/hand_pump', 'file' => 'uploads/telegram/camp/hand_pump.jpg', 'lexicon' => 'building.hand-pump', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'HandPump building (HandPumpHandler)'],
        ['key' => 'camp/solar_power_station', 'file' => 'uploads/telegram/camp/solar_power_station.jpg', 'lexicon' => 'building.solar-station', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'SolarStation building'],
        ['key' => 'camp/Gym', 'file' => 'uploads/telegram/camp/Gym.png', 'lexicon' => 'building.gym', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'Gym building (GymHandler)'],
        ['key' => 'camp/communication_tower', 'file' => 'uploads/telegram/camp/communication_tower.png', 'lexicon' => 'building.comm-tower', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'CommTower building (CommunicationTowerHandler)'],
        ['key' => 'camp/communication_tower_in_progress', 'file' => 'uploads/telegram/camp/communication_tower_in_progress.jpg', 'lexicon' => 'building.in-progress', 'scene' => 'a tall antenna mast half-raised — lashed scaffold, cables not yet strung', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'CommTower construction in progress'],
        ['key' => 'camp/blast_furnace', 'file' => 'uploads/telegram/camp/blast_furnace.png', 'lexicon' => 'building.blast-furnace', 'scene' => 'the furnace centred, smoking and glowing', 'mode' => 'V1', 'status' => 'generated', 'used_in' => 'BlastFurnace building (BlastFurnaceHandler)'],
        ['key' => 'camp/teleport_center', 'file' => 'uploads/telegram/camp/teleport_center.png', 'lexicon' => 'building.teleport-center', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'TeleportCenter building'],
        ['key' => 'camp/Robotics-Workshop', 'file' => 'uploads/telegram/camp/Robotics-Workshop.jpg', 'lexicon' => 'building.robotics-workshop', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'RoboticsWorkshop building (RoboticsWorkshopHandler)'],

        // ─────────────── ВЕРСТАКИ / ОБЛАСТИ КРАФТА ───────────────
        ['key' => 'workbench/workbench_one', 'file' => 'uploads/telegram/workbench/workbench_one.png', 'lexicon' => 'building.workshop', 'scene' => 'a general improvised crafting workbench, tools laid out', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'workbench general (multiple craft handlers)'],
        ['key' => 'workbench/workbenchSelect', 'file' => 'uploads/telegram/workbench/workbenchSelect.jpg', 'lexicon' => 'building.workshop', 'scene' => 'choosing which workbench to use — two improvised benches side by side', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'workbench select'],
        ['key' => 'craft/crafting_area', 'file' => 'uploads/telegram/craft/crafting_area.png', 'lexicon' => 'building.workshop', 'scene' => 'a camp crafting corner — workbench, tools, materials, work in progress', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'general craft hub'],
        ['key' => 'craft/general_crafting_img', 'file' => 'uploads/telegram/craft/general_crafting_img.png', 'lexicon' => 'building.workshop', 'scene' => 'the general crafting bench seen from above, hands working', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'general craft menu'],
        ['key' => 'craft/huge_mechanical_workbench', 'file' => 'uploads/telegram/craft/huge_mechanical_workbench.jpg', 'lexicon' => 'building.workshop', 'scene' => 'a large heavy improvised workbench, vices and tools, the in-progress crafting bench', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'tools craft in-progress (CraftRecipes)'],
        ['key' => 'craft/simple_craft_kit', 'file' => 'uploads/telegram/craft/simple_craft_kit.jpg', 'lexicon' => 'loot.tool', 'scene' => 'a simple bundled craft kit — a few hand tools and bindings rolled in cloth', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'simple craft kit recipe'],
        ['key' => 'craft/standard/standard_craft_area', 'file' => 'uploads/telegram/craft/standard/standard_craft_area.jpg', 'lexicon' => 'building.arsenal', 'scene' => 'the advanced crafting area — a sturdier bench with weapon/armour/robot work, lab gear nearby', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'standard craft menu'],
        ['key' => 'craft/components/A_cozy_workshop_with_tools_and_crafting_materials', 'file' => 'uploads/telegram/craft/components/A_cozy_workshop_with_tools_and_crafting_materials.jpg', 'lexicon' => 'building.workshop', 'scene' => 'a cramped improvised workshop crowded with tools and salvaged materials', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'components craft area', 'notes' => 'имя файла «cozy» — НЕ уютная, переосмыслить как тесную/захламлённую'],

        // ─────────────── ЛЕКАРСТВА / АПТЕКА ───────────────
        ['key' => 'craft/bandage_that_is_made_in_the_wild', 'file' => 'uploads/telegram/craft/bandage_that_is_made_in_the_wild.jpg', 'lexicon' => 'loot.first-aid', 'scene' => 'rolled improvised bandages and a tin first-aid box, on a bench', 'mode' => 'V4', 'status' => 'generated', 'used_in' => 'Bandage recipe (CraftRecipes in/completed)'],
        ['key' => 'craft/antiseptic_craft', 'file' => 'uploads/telegram/craft/antiseptic_craft.jpg', 'lexicon' => 'loot.medicine', 'scene' => 'a bottle of cloudy antiseptic and a cloth swab on a bench', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'Antiseptic recipe'],
        ['key' => 'craft/analgesic_powder', 'file' => 'uploads/telegram/craft/analgesic_powder.jpg', 'lexicon' => 'loot.medicine', 'scene' => 'a paper twist of pale painkiller powder, a pestle, a tin', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'AnalgesicPowder recipe'],
        ['key' => 'craft/tonic_elixir', 'file' => 'uploads/telegram/craft/tonic_elixir.jpg', 'lexicon' => 'loot.medicine', 'scene' => 'a small bottle of dark tonic, corked, on a bench', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'TonicElixir recipe'],
        ['key' => 'craft/dry_herb_tea', 'file' => 'uploads/telegram/craft/dry_herb_tea.jpg', 'lexicon' => 'loot.medicine', 'scene' => 'a tin mug of herbal infusion and a bundle of dried herbs', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'DryHerbTea recipe'],
        ['key' => 'craft/liquid_mixture_of_very_invigorating_acid-green_beverage', 'file' => 'uploads/telegram/craft/liquid_mixture_of_very_invigorating_acid-green_beverage.jpg', 'lexicon' => 'loot.medicine', 'scene' => 'a bottle of a sharp pale-green stimulant draught on a bench', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'Stimulant draught recipe', 'notes' => 'оригинал «acid-green» — приглушить под палитру, не кислотный'],
        ['key' => 'craft/health_and_strength_regenerator', 'file' => 'uploads/telegram/craft/health_and_strength_regenerator.jpg', 'lexicon' => 'loot.medicine', 'scene' => 'an improvised regenerator dose — a jar with a syringe-like applicator', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'Regenerator recipe'],
        ['key' => 'craft/many_medicinal_things', 'file' => 'uploads/telegram/craft/many_medicinal_things.jpg', 'lexicon' => 'loot.medicine', 'scene' => 'a shelf of assorted improvised medicines — bottles, jars, bandages, a still', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'pharmacy / medicine overview'],
        ['key' => 'craft/craft_medications_in_the_field', 'file' => 'uploads/telegram/craft/craft_medications_in_the_field.jpg', 'lexicon' => 'loot.medicine', 'scene' => 'mixing medicine in the open — a burner, a retort, jars laid out on a cloth', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'field medicine craft'],

        // ─────────────── ИНСТРУМЕНТЫ ───────────────
        ['key' => 'craft/create-an-image-of-an-ancient-stone-pickaxe', 'file' => 'uploads/telegram/craft/create-an-image-of-an-ancient-stone-pickaxe.jpg', 'lexicon' => 'loot.tool', 'scene' => 'a crude stone-headed pickaxe lashed to a wooden haft, low-grade', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'StonePickaxe recipe'],
        ['key' => 'craft/old-stone-primitive-axe-of-stone-and-logs', 'file' => 'uploads/telegram/craft/old-stone-primitive-axe-of-stone-and-logs.jpg', 'lexicon' => 'loot.tool', 'scene' => 'a primitive stone axe of chipped rock and a log handle, low-grade', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'StoneAxe recipe'],
        ['key' => 'craft/image-of-a-typical-metal-shovel', 'file' => 'uploads/telegram/craft/image-of-a-typical-metal-shovel.jpg', 'lexicon' => 'loot.tool', 'scene' => 'a battered metal shovel with a taped wooden handle', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'Shovel recipe'],
        ['key' => 'craft/robust-iron-pickaxe', 'file' => 'uploads/telegram/craft/robust-iron-pickaxe.jpg', 'lexicon' => 'loot.tool', 'scene' => 'a solid forged iron pickaxe, well made, mid-grade', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'IronPickaxe recipe'],
        ['key' => 'craft/high-quality-fishing-rod', 'file' => 'uploads/telegram/craft/high-quality-fishing-rod.jpg', 'lexicon' => 'loot.tool', 'scene' => 'a carefully built improvised fishing rod with salvaged line and reel', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'FishingRod recipe (own in-progress image)'],
        ['key' => 'craft/traditional-hoe', 'file' => 'uploads/telegram/craft/traditional-hoe.jpg', 'lexicon' => 'loot.tool', 'scene' => 'a worn garden hoe with a forged blade and a long worn handle', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'Hoe recipe'],
        ['key' => 'craft/craftTireIron', 'file' => 'uploads/telegram/craft/craftTireIron.jpg', 'lexicon' => 'loot.tool', 'scene' => 'a salvaged tyre iron / pry bar, rusted, repurposed as a tool', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'TireIron recipe'],
        ['key' => 'craft/an-old-but-sharp-folding-knife', 'file' => 'uploads/telegram/craft/an-old-but-sharp-folding-knife.jpg', 'lexicon' => 'loot.tool', 'scene' => 'an old folding knife, worn handle, the blade still keen, open on a cloth', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'FoldingKnife recipe'],
        ['key' => 'craft/tools_standing_by_an_old_fence_in_the_wild', 'file' => 'uploads/telegram/craft/tools_standing_by_an_old_fence_in_the_wild.jpg', 'lexicon' => 'loot.tool', 'scene' => 'an assortment of hand tools leaning against a weathered fence in the wild', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'tools overview'],

        // ─────────────── КОМПОНЕНТЫ ───────────────
        ['key' => 'craft/components/wiring_craft', 'file' => 'uploads/telegram/craft/components/wiring_craft.jpg', 'lexicon' => 'resource.mid', 'scene' => 'coils of salvaged wiring stripped and bundled on a bench', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'Wiring component recipe'],
        ['key' => 'craft/components/electronic_components', 'file' => 'uploads/telegram/craft/components/electronic_components.jpg', 'lexicon' => 'resource.mid', 'scene' => 'salvaged circuit boards and small electronic parts sorted in a tin', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'Electronics component recipe'],
        ['key' => 'craft/components/craftFabric', 'file' => 'uploads/telegram/craft/components/craftFabric.jpg', 'lexicon' => 'resource.mid', 'scene' => 'a folded bolt of rough patched fabric and a needle', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'Fabric component recipe'],
        ['key' => 'craft/components/craftFertilizer', 'file' => 'uploads/telegram/craft/components/craftFertilizer.jpg', 'lexicon' => 'resource.mid', 'scene' => 'a sack of dark improvised fertiliser, a scoop, on the ground', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'Fertilizer component recipe'],
        ['key' => 'craft/components/craftSoil', 'file' => 'uploads/telegram/craft/components/craftSoil.jpg', 'lexicon' => 'resource.mid', 'scene' => 'a heap of dark sieved soil in a wooden trough', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'Soil component recipe'],
        ['key' => 'craft/components/craftStoneBlocks', 'file' => 'uploads/telegram/craft/components/craftStoneBlocks.jpg', 'lexicon' => 'resource.mid', 'scene' => 'roughly cut stone blocks stacked beside a chisel', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'StoneBlocks component recipe'],
        ['key' => 'craft/components/craftMetalFragments', 'file' => 'uploads/telegram/craft/components/craftMetalFragments.jpg', 'lexicon' => 'resource.mid', 'scene' => 'a pile of cut and bent scrap-metal fragments in a crate', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'MetalFragments component recipe'],
        ['key' => 'craft/components/craftWoodMaterials', 'file' => 'uploads/telegram/craft/components/craftWoodMaterials.jpg', 'lexicon' => 'resource.mid', 'scene' => 'planks, dowels and offcuts of weathered timber bundled together', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'WoodMaterials component recipe'],
        ['key' => 'craft/components/craftCharcoalBriquettes', 'file' => 'uploads/telegram/craft/components/craftCharcoalBriquettes.png', 'lexicon' => 'resource.mid', 'scene' => 'a heap of black charcoal briquettes beside a smoky kiln', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'CharcoalBriquettes component recipe', 'notes' => 'историческое .png расширение (остальные components — .jpg)'],
        ['key' => 'craft/components/craftGlassBags', 'file' => 'uploads/telegram/craft/components/craftGlassBags.jpg', 'lexicon' => 'resource.mid', 'scene' => 'salvaged glass shards and small glass containers wrapped in cloth bags', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'GlassBags component recipe'],

        // ─────────────── STANDARD: ОРУЖИЕ ───────────────
        ['key' => 'craft/standard/wired_bat', 'file' => 'uploads/telegram/craft/standard/wired_bat.jpg', 'lexicon' => 'loot.common-weapon', 'scene' => 'a baseball bat tightly wrapped in barbed wire — the Common benchmark — rusty, taped grip', 'mode' => 'V4', 'status' => 'generated', 'used_in' => 'WiredBat recipe'],
        ['key' => 'craft/standard/pipe_gun', 'file' => 'uploads/telegram/craft/standard/pipe_gun.jpg', 'lexicon' => 'loot.common-weapon', 'scene' => 'a crude single-shot pipe gun, taped and wired together, low-grade', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'PipeGun recipe'],
        ['key' => 'craft/standard/metal_spear', 'file' => 'uploads/telegram/craft/standard/metal_spear.jpg', 'lexicon' => 'loot.mid-weapon', 'scene' => 'a sharpened-rebar / forged-tip spear, well bound, mid-grade', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'MetalSpear recipe'],
        ['key' => 'craft/standard/crossbow_mk1', 'file' => 'uploads/telegram/craft/standard/crossbow_mk1.jpg', 'lexicon' => 'loot.epic-weapon', 'scene' => 'a carefully built composite-limbed crossbow, blued steel, skilled work', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'CrossbowMk1 recipe'],
        ['key' => 'craft/standard/default_weapon', 'file' => 'uploads/telegram/craft/standard/default_weapon.jpg', 'lexicon' => 'loot.weapon-overview', 'scene' => '', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'weapon select default'],
        ['key' => 'craft/standard/all_weapons', 'file' => 'uploads/telegram/craft/standard/all_weapons.jpg', 'lexicon' => 'loot.weapon-overview', 'scene' => '', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'all weapons overview'],

        // ─────────────── STANDARD: БРОНЯ / ОДЕЖДА / РЮКЗАК ───────────────
        ['key' => 'craft/standard/drifter_clothes', 'file' => 'uploads/telegram/craft/standard/drifter_clothes.jpg', 'lexicon' => 'loot.clothing', 'scene' => "a drifter's patched layered outfit — worn coat, scarf, scuffed boots — laid out", 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'DrifterClothes recipe'],
        ['key' => 'craft/standard/ragged_shirt', 'file' => 'uploads/telegram/craft/standard/ragged_shirt.jpg', 'lexicon' => 'loot.clothing', 'scene' => 'a ragged, much-mended shirt, faded, low-grade', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'RaggedShirt recipe'],
        ['key' => 'craft/standard/leather_jacket', 'file' => 'uploads/telegram/craft/standard/leather_jacket.jpg', 'lexicon' => 'loot.armor', 'scene' => 'a worn leather jacket with a few stitched-on patches, mid-grade armour', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'LeatherJacket recipe'],
        ['key' => 'craft/standard/reinforced_leather_jacket', 'file' => 'uploads/telegram/craft/standard/reinforced_leather_jacket.jpg', 'lexicon' => 'loot.armor', 'scene' => 'a leather jacket reinforced with bolted scrap-metal plates and rivets, sturdier grade', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'ReinforcedLeatherJacket recipe'],
        ['key' => 'craft/standard/backpack_craft', 'file' => 'uploads/telegram/craft/standard/backpack_craft.jpg', 'lexicon' => 'loot.backpack', 'scene' => '', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'Backpack recipe'],
        ['key' => 'craft/standard/default_armor', 'file' => 'uploads/telegram/craft/standard/default_armor.jpg', 'lexicon' => 'loot.armor', 'scene' => 'overview of survivor armour options laid out', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'armor select default'],
        ['key' => 'craft/standard/all_armor', 'file' => 'uploads/telegram/craft/standard/all_armor.jpg', 'lexicon' => 'loot.armor', 'scene' => 'all armour pieces laid out together', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'all armor overview'],

        // ─────────────── STANDARD: РОБОТЫ ───────────────
        ['key' => 'craft/standard/robot_explorer', 'file' => 'uploads/telegram/craft/standard/robot_explorer.jpg', 'lexicon' => 'robot.explorer', 'scene' => '', 'mode' => 'V4', 'status' => 'generated', 'used_in' => 'RobotExplorer recipe / robot management'],
        ['key' => 'craft/standard/robot_gatherer', 'file' => 'uploads/telegram/craft/standard/robot_gatherer.jpg', 'lexicon' => 'robot.gatherer', 'scene' => '', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'RobotGatherer display', 'notes' => 'orphan-вариант robot_gatherer2.jpg НЕ регенерим'],
        ['key' => 'craft/standard/craftRobotGatherer', 'file' => 'uploads/telegram/craft/standard/craftRobotGatherer.jpg', 'lexicon' => 'robot.gatherer', 'scene' => 'a gatherer robot half-built and clamped on a stand in a robotics workshop', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'RobotGatherer craft in-progress'],
        ['key' => 'craft/standard/all_robots', 'file' => 'uploads/telegram/craft/standard/all_robots.jpg', 'lexicon' => 'robot.overview', 'scene' => '', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'all robots overview'],

        // ─────────────── STANDARD: МАЯКИ ───────────────
        ['key' => 'craft/standard/beacon_craft', 'file' => 'uploads/telegram/craft/standard/beacon_craft.jpg', 'lexicon' => 'beacon', 'scene' => 'a teleport beacon being assembled — pipe tripod, batteries taped on, coils being wound', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'Beacon recipe in-progress'],
        ['key' => 'craft/standard/default_beacon', 'file' => 'uploads/telegram/craft/standard/default_beacon.jpg', 'lexicon' => 'beacon', 'scene' => 'a finished makeshift teleport beacon standing in the open, faint blue glow', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'beacon default / completed'],
        ['key' => 'craft/standard/beacon_select', 'file' => 'uploads/telegram/craft/standard/beacon_select.jpg', 'lexicon' => 'beacon', 'scene' => 'choosing a beacon to teleport to — a map and a beacon side by side', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'beacon select screen'],

        // ─────────────── ЭКОНОМИКА / ИНВЕНТАРЬ / ТОРГОВЛЯ ───────────────
        ['key' => 'craft/vendor_kiosk_in_the_game_world', 'file' => 'uploads/telegram/craft/vendor_kiosk_in_the_game_world.jpg', 'lexicon' => 'economy.vendor-stall', 'scene' => '', 'mode' => 'V1', 'status' => 'generated', 'used_in' => 'Sell/Buy actions (main vendor bg, 4 callers)'],
        ['key' => 'vendor_kiosk_in_the_game_world', 'file' => 'uploads/telegram/vendor_kiosk_in_the_game_world.png', 'lexicon' => 'economy.vendor-stall', 'scene' => 'the trader\'s stall seen from a slightly different angle', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'Sell/Buy actions (alt callers, 3)', 'notes' => 'дубль с craft/vendor_kiosk...jpg — рассмотреть консолидацию ref в коде'],
        ['key' => 'in-game_sales', 'file' => 'uploads/telegram/in-game_sales.png', 'lexicon' => 'economy.vendor-stall', 'scene' => 'a survivor handing goods over a barter stall, a deal being struck', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'sales / trade confirmation'],
        ['key' => 'loot_resources_in_the_box', 'file' => 'uploads/telegram/loot_resources_in_the_box.png', 'lexicon' => 'resource.junk', 'scene' => 'a crate of hauled-in resources — wood, ore, scrap — opened on the ground', 'mode' => 'V1', 'status' => 'generated', 'used_in' => 'loot in box / gathered resources'],
        ['key' => 'water_and_food_resources', 'file' => 'uploads/telegram/water_and_food_resources.png', 'lexicon' => 'resource.food', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'food/water inventory'],
        ['key' => 'warehouse_in_the_forest', 'file' => 'uploads/telegram/warehouse_in_the_forest.png', 'lexicon' => 'economy.inventory-warehouse', 'scene' => 'a survivor\'s store of supplies tucked among forest trees — a patched shed full of crates', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'inventory / warehouse view'],

        // ─────────────── ОБЪЕКТЫ МИРА ───────────────
        ['key' => 'objects/an-old-long-abandoned-warehouse', 'file' => 'uploads/telegram/objects/an-old-long-abandoned-warehouse.jpg', 'lexicon' => 'world.abandoned', 'scene' => 'a collapsing long-abandoned warehouse in the wild, doors gaping', 'mode' => 'V2', 'status' => 'pending', 'used_in' => 'world object: abandoned warehouse'],
        ['key' => 'objects/collection-of-rusted-and-weathered-tools-including', 'file' => 'uploads/telegram/objects/collection-of-rusted-and-weathered-tools-including.jpg', 'lexicon' => 'world.abandoned', 'scene' => 'a heap of rusted, weathered tools and junk found in the wild', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'world object: tool cache'],
        ['key' => 'objects/send_empty_warehouse', 'file' => 'uploads/telegram/objects/send_empty_warehouse.jpg', 'lexicon' => 'economy.inventory-warehouse', 'scene' => 'an empty storage shed, bare shelves — nothing left to take', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'world object: emptied warehouse'],

        // ─────────────── КВЕСТЫ ───────────────
        ['key' => 'quests/Quests-and-Missions', 'file' => 'uploads/telegram/quests/Quests-and-Missions.jpg', 'lexicon' => 'quests.journal', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'QuestAndTaskAction menu'],
        ['key' => 'quests/explore_30cells', 'file' => 'uploads/telegram/quests/explore_30cells.jpg', 'lexicon' => 'biome.collage', 'scene' => 'a journal page sketching the first stretch of land a newcomer maps', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'quest «explore 30 cells»'],
        ['key' => 'quests/explore_allBiomes_finish', 'file' => 'uploads/telegram/quests/explore_allBiomes_finish.jpg', 'lexicon' => 'biome.collage', 'scene' => 'seen as a completed journal spread, every biome sketched in pencil, no writing', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'quest «explore all biomes» finished'],

        // ─────────────── СОБЫТИЯ (events.img_path в проде) ───────────────
        ['key' => 'berry_boom', 'file' => 'uploads/telegram/berry_boom.png', 'lexicon' => 'event.berry-boom', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'event BerryBoom (events.img_path)'],
        ['key' => 'drought', 'file' => 'uploads/telegram/drought.png', 'lexicon' => 'event.dryness', 'scene' => '', 'mode' => 'V3', 'status' => 'pending', 'used_in' => 'event Dryness (events.img_path)', 'notes' => 'добавить «засуха» в таблицу канона GAME_DESCRIPTION'],
        ['key' => 'epidemic', 'file' => 'uploads/telegram/epidemic.png', 'lexicon' => 'event.epidemic', 'scene' => '', 'mode' => 'V2', 'status' => 'generated', 'used_in' => 'event Epidemic (events.img_path)'],
        ['key' => 'exotic_flowering', 'file' => 'uploads/telegram/exotic_flowering.png', 'lexicon' => 'event.exotic-flowering', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'event ExoticFlowering (events.img_path)', 'notes' => 'добавить в таблицу канона'],
        ['key' => 'fever', 'file' => 'uploads/telegram/fever.png', 'lexicon' => 'event.fever', 'scene' => '', 'mode' => 'V2', 'status' => 'pending', 'used_in' => 'event Fever (events.img_path)'],
        ['key' => 'fish_stock', 'file' => 'uploads/telegram/fish_stock.png', 'lexicon' => 'event.fish-stock', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'event FishStock (events.img_path)', 'notes' => 'добавить в таблицу канона'],
        ['key' => 'flash_forest_fire', 'file' => 'uploads/telegram/flash_forest_fire.png', 'lexicon' => 'event.forest-fire', 'scene' => '', 'mode' => 'V2', 'status' => 'pending', 'used_in' => 'event FlashForestFire (events.img_path)'],
        ['key' => 'geothermal_fountains', 'file' => 'uploads/telegram/geothermal_fountains.png', 'lexicon' => 'event.geothermal', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'event GeothermalFountains (events.img_path)', 'notes' => 'orphan-дубль geothermal__fountains.png НЕ регенерим'],
        ['key' => 'gold_mine', 'file' => 'uploads/telegram/gold_mine.png', 'lexicon' => 'event.gold-mine', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'event GoldMine (events.img_path)', 'notes' => 'orphan gold_ingots_of_different_sizes_were_found.png НЕ регенерим'],
        ['key' => 'hurricane', 'file' => 'uploads/telegram/hurricane.png', 'lexicon' => 'event.hurricane', 'scene' => '', 'mode' => 'V2', 'status' => 'pending', 'used_in' => 'event Hurricane (events.img_path)', 'notes' => 'orphan strong_winds_and_downpours_causing_destruction.png НЕ регенерим'],
        ['key' => 'locust_exodus', 'file' => 'uploads/telegram/locust_exodus.png', 'lexicon' => 'event.locust-exodus', 'scene' => '', 'mode' => 'V2', 'status' => 'pending', 'used_in' => 'event LocustExodus (events.img_path)', 'notes' => 'добавить в таблицу канона'],
        ['key' => 'due_to_meteor_impact', 'file' => 'uploads/telegram/due_to_meteor_impact.png', 'lexicon' => 'event.meteor-impact', 'scene' => '', 'mode' => 'V2', 'status' => 'pending', 'used_in' => 'event MeteorImpact (events.img_path)'],
        ['key' => 'meteor_shower', 'file' => 'uploads/telegram/meteor_shower.png', 'lexicon' => 'event.meteor-rain', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'event MeteorRain (events.img_path)'],
        ['key' => 'oasis_mirages', 'file' => 'uploads/telegram/oasis_mirages.png', 'lexicon' => 'event.mirage-oases', 'scene' => '', 'mode' => 'V3', 'status' => 'pending', 'used_in' => 'event MirageOases (events.img_path)', 'notes' => 'orphan-дубль oasis__mirages.png НЕ регенерим'],
        ['key' => 'mountain_echo', 'file' => 'uploads/telegram/mountain_echo.png', 'lexicon' => 'event.mountain-echo', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'event MountainEcho (events.img_path)', 'notes' => 'orphan-дубль mountain_echoes.png НЕ регенерим'],
        ['key' => 'wildlife_aggressiveness', 'file' => 'uploads/telegram/wildlife_aggressiveness.png', 'lexicon' => 'event.night-attacks', 'scene' => '', 'mode' => 'V2', 'status' => 'pending', 'used_in' => 'event NightAttacks (events.img_path)'],
        ['key' => 'northern_lights', 'file' => 'uploads/telegram/northern_lights.png', 'lexicon' => 'event.northern-lights', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'event NorthernLights (events.img_path)'],
        ['key' => 'polar_night', 'file' => 'uploads/telegram/polar_night.png', 'lexicon' => 'event.polar-night', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'event PolarNight (events.img_path)'],
        ['key' => 'discovery_of_hidden_dungeons', 'file' => 'uploads/telegram/discovery_of_hidden_dungeons.png', 'lexicon' => 'event.hidden-discovery', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'event RevealingHiddenCameras (events.img_path)', 'notes' => 'имя файла «dungeons» vs событие «hidden cameras» — свериться с каноном'],
        ['key' => 'sandstorm', 'file' => 'uploads/telegram/sandstorm.png', 'lexicon' => 'event.sandstorm', 'scene' => '', 'mode' => 'V2', 'status' => 'pending', 'used_in' => 'event Sandstorm (events.img_path)', 'notes' => 'orphan-дубль sand_storm.png НЕ регенерим'],
        ['key' => 'snowfall', 'file' => 'uploads/telegram/snowfall.png', 'lexicon' => 'event.snowfall', 'scene' => '', 'mode' => 'V2', 'status' => 'pending', 'used_in' => 'event Snowfall (events.img_path)', 'notes' => 'orphan-дубли heavy_snowfalls_cause_avalanches_and_snowfalls.png x2 НЕ регенерим'],
        ['key' => 'a_huge_stream_of_water_from_the_mountains', 'file' => 'uploads/telegram/a_huge_stream_of_water_from_the_mountains.png', 'lexicon' => 'event.spring-flood', 'scene' => '', 'mode' => 'V2', 'status' => 'pending', 'used_in' => 'event SpringFlood (events.img_path)'],
        ['key' => 'shooting_star', 'file' => 'uploads/telegram/shooting_star.png', 'lexicon' => 'event.starfall', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'event Starfall (events.img_path)', 'notes' => 'orphan hundreds_of_shooting_stars.png НЕ регенерим'],
        ['key' => 'aftershock', 'file' => 'uploads/telegram/aftershock.png', 'lexicon' => 'event.tremor', 'scene' => '', 'mode' => 'V2', 'status' => 'pending', 'used_in' => 'event Tremor (events.img_path)', 'notes' => 'orphan earthquake.png НЕ регенерим'],
        ['key' => 'volcanic_eruption', 'file' => 'uploads/telegram/volcanic_eruption.png', 'lexicon' => 'event.volcanic-eruption', 'scene' => '', 'mode' => 'V3', 'status' => 'generated', 'used_in' => 'event volcanic_eruption (events.img_path)', 'notes' => 'orphan-дубль volcanic_eruption_image.png + orphan huge_forest_fires.png НЕ регенерим'],
        ['key' => 'default_event_image', 'file' => 'uploads/telegram/default_event_image.png', 'lexicon' => 'event.generic', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'fallback for events without an image'],

        // ─────────────── МИНИ-ИГРЫ ───────────────
        ['key' => 'wheel_fortune_game', 'file' => 'uploads/telegram/wheel_fortune_game.png', 'lexicon' => 'minigame.wheel', 'scene' => '', 'mode' => 'V4', 'status' => 'generated', 'used_in' => 'WheelOfFortune mini-game'],
        ['key' => 'guess_the_number', 'file' => 'uploads/telegram/guess_the_number.png', 'lexicon' => 'minigame.guess-number', 'scene' => '', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'GuessTheNumber mini-game'],
        ['key' => 'stone_paper_scissors', 'file' => 'uploads/telegram/stone_paper_scissors.png', 'lexicon' => 'minigame.rps', 'scene' => '', 'mode' => 'V4', 'status' => 'pending', 'used_in' => 'RockPaperScissors mini-game'],
        ['key' => 'fun_games', 'file' => 'uploads/telegram/fun_games.png', 'lexicon' => 'minigame.hub', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'EntertaimentAction (games hub)'],

        // ─────────────── СЛУЖЕБНОЕ / FALLBACK ───────────────
        ['key' => 'fallback_image', 'file' => 'uploads/telegram/fallback_image.jpg', 'lexicon' => 'world.generic', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'global fallback image', 'notes' => 'проверить, реально ли используется (grep не нашёл прямого ref) — возможен orphan'],

        // ─────────────── ФРАКЦИИ — НОВЫЕ КАРТИНКИ (фракционного арта пока нет) ───────────────
        ['key' => 'faction/military', 'file' => 'uploads/telegram/faction/military.jpg', 'lexicon' => 'faction.military', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'faction reveal (lvl 10) — Military', 'notes' => 'НОВАЯ — папка faction/ создать'],
        ['key' => 'faction/military_endgame', 'file' => 'uploads/telegram/faction/military_endgame.jpg', 'lexicon' => 'faction.military.endgame', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'endgame scenario — Domination', 'notes' => 'НОВАЯ; 1:1 инвариант — взаимно неперепутываема с другими endgame'],
        ['key' => 'faction/partisans', 'file' => 'uploads/telegram/faction/partisans.jpg', 'lexicon' => 'faction.partisans', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'faction reveal — Partisans/Raiders', 'notes' => 'НОВАЯ'],
        ['key' => 'faction/partisans_endgame', 'file' => 'uploads/telegram/faction/partisans_endgame.jpg', 'lexicon' => 'faction.partisans.endgame', 'scene' => '', 'mode' => 'V2', 'status' => 'pending', 'used_in' => 'endgame scenario — Anarchy', 'notes' => 'НОВАЯ; 1:1 инвариант'],
        ['key' => 'faction/engineers', 'file' => 'uploads/telegram/faction/engineers.jpg', 'lexicon' => 'faction.engineers', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'faction reveal — Engineers', 'notes' => 'НОВАЯ'],
        ['key' => 'faction/engineers_endgame', 'file' => 'uploads/telegram/faction/engineers_endgame.jpg', 'lexicon' => 'faction.engineers.endgame', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'endgame scenario — Scientific Breakthrough', 'notes' => 'НОВАЯ; 1:1 инвариант'],
        ['key' => 'faction/farmers', 'file' => 'uploads/telegram/faction/farmers.jpg', 'lexicon' => 'faction.farmers', 'scene' => '', 'mode' => 'V3', 'status' => 'generated', 'used_in' => 'faction reveal — Farmers', 'notes' => 'НОВАЯ'],
        ['key' => 'faction/farmers_endgame', 'file' => 'uploads/telegram/faction/farmers_endgame.jpg', 'lexicon' => 'faction.farmers.endgame', 'scene' => '', 'mode' => 'V3', 'status' => 'pending', 'used_in' => 'endgame scenario — Evacuation', 'notes' => 'НОВАЯ; 1:1 инвариант'],
        ['key' => 'faction/reveal_hub', 'file' => 'uploads/telegram/faction/reveal_hub.jpg', 'lexicon' => 'faction.reveal-hub', 'scene' => '', 'mode' => 'V1', 'status' => 'pending', 'used_in' => 'faction choice screen (lvl 10)', 'notes' => 'НОВАЯ'],
    ];
}
