<?php
// ADR-052 — заглушка технического поддомена bot.wildworld.fun (см. App\Filters\BotHostFilter).
// Самодостаточна: инлайн-стили, без внешних ассетов (иначе их пути уедут 301-редиректом).
$social  = config('Social');
$tgLink  = $social->botLink;   // CTA — играть в боте
$group   = $social->groupLink; // инфо/сообщество — группа
$site    = 'https://wildworld.fun/';
?><!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, follow">
    <link rel="canonical" href="<?= esc($site, 'attr') ?>">
    <title>Wild World — бот</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            min-height: 100vh; display: flex; align-items: center; justify-content: center;
            background: radial-gradient(circle at 50% 0%, #1a2230 0%, #0d1118 60%, #070a0f 100%);
            color: #e6e9ef; font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            padding: 24px; text-align: center;
        }
        .card {
            max-width: 460px; width: 100%; background: rgba(20, 26, 36, .7);
            border: 1px solid rgba(120, 140, 170, .18); border-radius: 18px;
            padding: 40px 32px; backdrop-filter: blur(6px);
            box-shadow: 0 18px 50px rgba(0, 0, 0, .45);
        }
        .logo { font-size: 13px; letter-spacing: .32em; text-transform: uppercase; color: #6f88ad; margin-bottom: 18px; }
        h1 { font-size: 27px; line-height: 1.2; margin-bottom: 12px; font-weight: 700; }
        h1 .accent { color: #d98c3f; }
        p { color: #9aa6b8; font-size: 15px; line-height: 1.55; margin-bottom: 28px; }
        .actions { display: flex; flex-direction: column; gap: 12px; }
        a.btn {
            display: block; padding: 14px 20px; border-radius: 12px; font-size: 16px;
            font-weight: 600; text-decoration: none; transition: transform .12s ease, filter .12s ease;
        }
        a.btn:hover { transform: translateY(-1px); filter: brightness(1.08); }
        .btn-tg   { background: linear-gradient(135deg, #2aa1e0, #1f7fc0); color: #fff; }
        .btn-site { background: rgba(120, 140, 170, .12); color: #e6e9ef; border: 1px solid rgba(120, 140, 170, .25); }
        .foot { margin-top: 26px; font-size: 12px; color: #5a6678; }
    </style>
</head>
<body>
    <main class="card">
        <div class="logo">Wild World</div>
        <h1>Это технический хост <span class="accent">бота</span></h1>
        <p>Постапокалиптическая текстовая MMORPG в Telegram. Играть — в боте, а весь контент,
           вики и devblog — на основном сайте.</p>
        <div class="actions">
            <a class="btn btn-tg" href="<?= esc($tgLink, 'attr') ?>">🎮 Открыть бота в Telegram</a>
            <a class="btn btn-site" href="<?= esc($site, 'attr') ?>">🌐 Перейти на сайт wildworld.fun</a>
        </div>
        <div class="foot"><a href="<?= esc($group, 'attr') ?>" style="color:#6f88ad">💬 Новости и сообщество</a></div>
    </main>
</body>
</html>
