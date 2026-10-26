---
story: w-reg-banner-fit-02
status: todo
returned:
worker: worker-code
model: opus
wave: 2
blocked_by: []
---

# Repair round 1

## Goal
Make the asks and findings council round 1 left RED pass, and change nothing else.

## Requirements
> 1. На ≥768 px самое новое сообщение плашки авто-раскрывается, только если после раскрытия верх экрана игры

## Findings
1. public/assets/js/wildworld-play.js:78 [ask 1] the fit check must judge the newest message at its final laid-out height, so a photo message is measured with its image occupying its real height and not 0 px: line 78 reads `#play-state` synchronously right after `first.open = true` (at `defer` time on page load, right after `replaceWith` on poll), while app/Views/site/_play/banner.php:66 renders the photo as `<img loading="lazy">` with no width/height and public/assets/css/wildworld-ui.css:952 sizes it `height: auto; max-height: 420px`, so an image not yet loaded contributes 0 px, the check passes, the message stays open, and when the image arrives (up to 420 px) `#play-state` drops below ¾ of the window - exactly the "длинное с фото" case the brief names as the one that must stay collapsed; from the smoke table (1440×900, threshold 675 px) a single new photo message leaves `#play-state` at roughly 600 px when measured and roughly 1000 px once the image loads - repro on preprod: make the newest unread a photo message, open /play at 1440×900, after the `load` event evaluate `!!document.querySelector('#play-banner details[open]') && document.getElementById('play-state').getBoundingClientRect().top + scrollY > innerHeight * 0.75` → `true` (expected `false`).

## Files
- public/assets/js/wildworld-play.js
- app/Views/site/play.php
- tests/database/PlayInboxBannerTest.php

## Verification
`vendor/bin/phpunit --no-coverage --no-progress tests/database/PlayInboxBannerTest.php`
