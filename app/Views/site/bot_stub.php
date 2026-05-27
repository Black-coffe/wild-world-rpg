<?php
/**
 * Заглушка технического поддомена bot.wildworld.fun (ADR-052, см. App\Filters\BotHostFilter).
 *
 * САМОДОСТАТОЧНА: ВСЕ стили — inline. Никаких внешних ассетов: на bot.* статика
 * 301-редиректом уезжает на основной домен, поэтому подключать wildworld-ui.css
 * нельзя. Палитра/шрифты/правила (0 радиусов, 0 теней) скопированы с
 * `wildworld-ui.css` вручную ради единства с основным сайтом (ADR-062).
 */
$social  = config('Social');
$tgLink  = $social->botLink;
$group   = $social->groupLink;
$site    = 'https://wildworld.fun/';
?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, follow">
    <link rel="canonical" href="<?= esc($site, 'attr') ?>">
    <title>Wild World — бот (технический хост)</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Manrope:wght@400;500;600&display=swap">
    <style>
        :root {
            color-scheme: dark;
            --bg-base:    #0E0B07;
            --bg-elev-1:  #221C14;
            --bg-elev-2:  #2C241A;
            --border:     #3A2F22;
            --border-strong: #5A4630;
            --text:       #E8DCC4;
            --text-dim:   #A89A82;
            --text-muted: #6B6050;
            --accent:     #E89B2E;
            --accent-hi:  #F2B045;
            --accent-ink: #1A1106;
            --font-display: "Oswald", "Segoe UI", system-ui, sans-serif;
            --font-body: "Manrope", "Segoe UI", system-ui, sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            background: var(--bg-base);
            color: var(--text);
            font: 16px/1.55 var(--font-body);
            padding: 24px;
        }
        .card {
            max-width: 480px; width: 100%;
            background: var(--bg-elev-1);
            border: 1px solid var(--border);
            padding: 40px 32px;
            position: relative;
        }
        .card::before, .card::after,
        .corner-tl, .corner-br {
            content: "";
            position: absolute;
            width: 14px; height: 14px;
            border: 1px solid var(--accent);
            opacity: .6;
        }
        .card::before  { top: -1px; left: -1px;  border-right: 0; border-bottom: 0; }
        .card::after   { top: -1px; right: -1px; border-left: 0;  border-bottom: 0; }
        .corner-tl     { bottom: -1px; left: -1px; border-right: 0; border-top: 0; }
        .corner-br     { bottom: -1px; right: -1px; border-left: 0;  border-top: 0; }
        .stamp {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 4px 10px;
            border: 1px solid var(--border-strong);
            background: rgba(232,155,46,.06);
            color: var(--accent);
            font-family: var(--font-display);
            font-weight: 500; font-size: 12px;
            letter-spacing: .18em; text-transform: uppercase;
            margin-bottom: 18px;
        }
        .stamp::before { content: ""; width: 6px; height: 6px; background: var(--accent); }
        h1 {
            font-family: var(--font-display);
            font-weight: 700;
            font-size: 26px; line-height: 1.1;
            letter-spacing: .015em;
            text-transform: uppercase;
            margin-bottom: 14px;
        }
        h1 .accent { color: var(--accent); }
        p { color: var(--text-dim); font-size: 15px; line-height: 1.55; margin-bottom: 24px; }
        .actions { display: flex; flex-direction: column; gap: 10px; }
        a.btn {
            display: flex; align-items: center; justify-content: center;
            gap: 8px;
            height: 48px; padding: 0 18px;
            font-family: var(--font-display);
            font-size: 14px; letter-spacing: .12em; text-transform: uppercase;
            border: 1px solid var(--border-strong);
            background: var(--bg-elev-2);
            color: var(--text);
            text-decoration: none;
            transition: background .14s, border-color .14s, color .14s;
        }
        a.btn:hover { border-color: var(--accent); color: var(--accent); }
        a.btn.primary { background: var(--accent); color: var(--accent-ink); border-color: var(--accent); }
        a.btn.primary:hover { background: var(--accent-hi); border-color: var(--accent-hi); color: var(--accent-ink); }
        .foot {
            margin-top: 26px;
            padding-top: 18px;
            border-top: 1px solid var(--border);
            font-family: "JetBrains Mono", monospace;
            font-size: 12px;
            color: var(--text-muted);
            text-align: center;
        }
        .foot a { color: var(--text-dim); text-decoration: none; }
        .foot a:hover { color: var(--accent); }
    </style>
</head>
<body>
    <main class="card">
        <i class="corner-tl"></i>
        <i class="corner-br"></i>
        <span class="stamp">технический хост · бот</span>
        <h1>Это&nbsp;хост <span class="accent">Wild&nbsp;World&nbsp;бота</span>.<br>Контент&nbsp;— на&nbsp;основном сайте.</h1>
        <p>Постапокалиптическая текстовая MMORPG в&nbsp;Telegram. Играй — в&nbsp;боте; вики, девблог, карта мира — на&nbsp;wildworld.fun.</p>
        <div class="actions">
            <a class="btn primary" href="<?= esc($tgLink, 'attr') ?>">▶ Открыть бота в&nbsp;Telegram</a>
            <a class="btn" href="<?= esc($site, 'attr') ?>">🌐 Перейти на&nbsp;wildworld.fun</a>
        </div>
        <div class="foot">
            <a href="<?= esc($group, 'attr') ?>">💬 новости и&nbsp;сообщество</a>
        </div>
    </main>
</body>
</html>
