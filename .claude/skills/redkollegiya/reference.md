# Редколлегия — reference: механика публикации

Справочник для SKILL.md. Все шаги отработаны (Wave-1/Wave-2 гайды).

## 1. Обложка (gpt-image-2, стиль «Найденная фотоплёнка»)

Throwaway spark-команда (НЕ коммитить — L9-gate; удалить после генерации):
- Файл `app/Commands/ThrowawaySiteCover.php`, group `Throwaway`, name `site:cover-gen`.
- Промпт = `Config\ImageRegistry::$styleCore` с подстановкой `{SCENE}` := нормализованная `cover_scene` + ` ` + `modeModifiers['V4']` (минимум артефактов, читается с превью).
- Провайдер: `new App\Services\Images\OpenAiImageProvider(env('images.api_key'), $cfg->model, $cfg->quality)` → `generate($prompt, ['aspectRatio'=>'3:2'])`.
- Re-encode через GD: `imagecreatefromstring` → `imageinterlace(true)` → `imagejpeg($path, 82)` (держим ≤300KB).
- Выход: `writable/site-covers/<slug>.jpg` (gitignored).

Запуск: `php spark site:cover-gen` (можно в фоне). **Посмотри картинку (Read) ДО заливки:** 0 текста, on-brand, читается с thumbnail. Если текст/брак — перегенери.

Заливка на прод (SSH-юзер сайта = `wildworld`):
```
scp -i ~/.ssh/wildworld_deploy -o StrictHostKeyChecking=no \
  "/c/laragon/www/mmorpg/writable/site-covers/<slug>.jpg" \
  wildworld@185.161.208.135:~/shared/uploads/site/<slug>.jpg
ssh -i ~/.ssh/wildworld_deploy wildworld@185.161.208.135 "chmod 644 ~/shared/uploads/site/<slug>.jpg"
```
`public/uploads/site` симлинкнут в `~/shared/uploads/site`. featured_image = `uploads/site/<slug>.jpg`.

**Удали throwaway-команду** (`rm app/Commands/ThrowawaySiteCover.php`) — не коммить, проверь `git status` чист.

⚠️ **Суффикс имени файла — без ведущего дефиса.** `php spark site:cover-gen <slug> "<scene>" -2` → spark съедает `-2` как флаг, файл пишется под именем без суффикса и **перезаписывает уже готовую обложку** (2026-07-22, пришлось перегенерировать). Передавай суффикс частью имени: `site:cover-gen <slug>-2 "<scene>"`.

## 2. Admin-CRUD поста (MCP Chrome)

Логин: `https://wildworld.fun/admin/login`, креды — `writable/secrets/admin-credentials.txt`. Сессия держится.

Форма `https://wildworld.fun/admin/site/posts/create` (поля по `take_snapshot`):
| Поле | Значение |
|---|---|
| Заголовок | `post_title` |
| Slug | `post_slug` |
| Краткое описание | `post_excerpt` |
| Содержимое (HTML) | блок `content_html` |
| Meta description | `post_meta_description` (≤320) |
| Статус | **«Черновик»** на фазе 3 → **«Опубликован»** на фазе 4 |
| Сверено с каноном | `true` (после PASS lore-keeper) |
| Дата публикации | `ГГГГ-ММ-ДД ЧЧ:ММ:СС` |
| Картинка (путь) | `uploads/site/<slug>.jpg` |
| Категории | по `post_category` (Информация/Крафт/Сырье/NPC/Местность/Летопись Мира/DevBlog) |

После «Создать» — пост в списке `/admin/site/posts`; `id` виден в ссылке `edit/{id}`. Сверь поля через `evaluate_script` ДО сабмита (особенно отсутствие посторонних символов: `content_html.includes('短')` и т.п.).

## 3. UI/UX — сбор метрик 3 вьюпортов

Открой `https://wildworld.fun/admin/site/posts/preview/{id}`. Для каждого вьюпорта: `resize_page` (1440×900 / 768×1024 / 375×812), затем:

⚠️ **375 не достигается через `resize_page`** — окно браузера клампится к минимуму ~500px (вернёт `innerWidth:500`). Для настоящего мобайла используй `emulate` с device-override: `viewport: "375x812x2,mobile,touch"` (даёт `innerWidth:375`, DPR2). После мобайл-проверки верни десктоп: `emulate viewport "1440x900x1"` ПЕРЕД admin-CRUD редактированием (иначе форма откроется в мобайл-раскладке). Урок — первый боевой прогон (гайд #6 PvP, 2026-05-31).

⚠️ **То же касается 768** (уточнено 2026-07-22): `resize_page 768×1024` тоже упирается в клампинг и отдаёт ~485px — метрики «планшета» оказываются метриками узкого мобайла, а H1/таблицы на реальных 768 не проверяются. **Через `emulate` — оба узких вьюпорта:** `"768x1024x2,touch"` и `"375x812x3,mobile,touch"`. Только 1440 можно снимать `resize_page`.

⚠️ **Ложные показания lazy-картинок.** В эмулированном мобайл-вьюпорте `img.currentSrc` может вернуться пустым даже после `scrollIntoView` + паузы — картинка ещё не декодирована, и это читается как «битое изображение». Прежде чем чинить несуществующий баг: `img.loading='eager'; img.scrollIntoView(); await img.decode()` — и только потом верить `naturalWidth`. Отдельно проверь URL через `fetch(src)`: 200 + `image/jpeg` = файл на месте (2026-07-22, дев-блог #8).

```js
() => {
  const hero = document.querySelector('img[src*="uploads/site"]');
  const imgs = [...document.querySelectorAll('img')].map(i => ({src:i.getAttribute('src'), ok:i.naturalWidth>0}));
  const ctas = [...document.querySelectorAll('a[href*="wildworldrpg_bot"]')].map(a=>a.getAttribute('href'));
  const internal = [...document.querySelectorAll('a[href^="/"]')].map(a=>a.getAttribute('href'));
  const h2 = [...document.querySelectorAll('h2')].map(h=>h.textContent.trim());
  const body = document.body.innerText;
  const stray = /[一-鿿가-힯]|�|\?\?/.test(body); // CJK/битые/??
  return {
    w: window.innerWidth, ready: document.readyState,
    h1: document.querySelector('h1')?.textContent?.trim() || null,
    heroLoaded: hero ? hero.naturalWidth>0 : null,
    heroNatural: hero ? hero.naturalWidth+'x'+hero.naturalHeight : null,
    brokenImgs: imgs.filter(i=>!i.ok),
    overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
    scrollW: document.documentElement.scrollWidth, clientW: document.documentElement.clientWidth,
    ctaCount: ctas.length, internalCount: internal.length,
    h2Count: h2.length, strayChars: stray, bodyLen: body.length
  };
}
```
Плюс `list_console_messages` (ошибки) и `take_screenshot` (описание). Передай всё (3 вьюпорта) агенту `redkollegiya-uiux` для вердикта.

⚠️ На чате Telegram `take_snapshot` крашится — но это admin-страница сайта, snapshot/screenshot тут безопасны.

## 4. Деплой-зависимость
Preview-роут `Front::preview` задеплоен с `v0.51.325`. Если на проде 404 на `/admin/site/posts/preview/{id}` (а пост-id существует) — проверь, что прод-релиз с preview-роутом доехал (GHA `deploy` + `deploy-website`).

## 5. Версии/числа
НЕ хардкодь точные tunable-числа баланса в статью (дрейфуют) — качественные формулировки. Канон-стабильные числа (3% vs 50% потерь, добыча 10м–12ч, ±20%, аптечка +40/+20) — можно.

## 6. CTA-перебивка `.ww-cta-break` (внутристатейный разделитель)
Узнаваемый flat-блок (компонент в `wildworld-ui.css`, демо в `/ui-kit.html`, задеплоен с `v0.51.326`). Вставляется в `content_html` для long-read'ов в естественную смысловую паузу. Сниппет:
```html
<div class="ww-cta-break">
  <p class="ww-cta-kicker">Wild World</p>
  <p class="ww-cta-title">Перестань читать — начни играть</p>
  <a class="ww-cta-btn" href="https://t.me/wildworldrpg_bot?start=src_site_<источник>_cta" target="_blank" rel="noopener" aria-label="Играть в Wild World в Telegram">▶ Войти в Пустошь</a>
</div>
```
- `<источник>` = тот же тег, что у основного CTA статьи (напр. `dnevnik_2` → `src_site_dnevnik_2_cta`) — для атрибуции переходов именно с перебивки.
- Слоган `.ww-cta-title` — узнаваемый, можно лёгкую вариацию под тему, но держи «перестань читать — начни играть»-смысл. Не плоди разных стилей: только этот компонент (никаких inline-стилей/самодельных кнопок — ADR-062).
- Сколько: 1 на ~2500–4000 символов content_html, в смысловую паузу (~45–60%); не у обложки, не впритык к финальному `.cta`.

## 7. 2-я картинка (правило ≥1000 символов) — 2:1 кинобаннер
Генерим как обложку (gpt-image-2 V4, throwaway `site:cover-gen`), затем **кроп 3:2 → 2:1** (1536×1024 → 1536×768, центр) через GD:
```php
$src = imagecreatefromjpeg($path);                 // 1536×1024 от провайдера
$dst = imagecrop($src, ['x'=>0,'y'=>128,'width'=>1536,'height'=>768]); // срезать по 128px сверху/снизу
imageinterlace($dst, true); imagejpeg($dst, $bannerPath, 82);
```
- Выход `writable/site-covers/<slug>-2.jpg` → scp на прод `~/shared/uploads/site/<slug>-2.jpg` + chmod 644 (как обложка, §1).
- Сцена: `cover_scene_2` из frontmatter (нет — придумай по смыслу середины статьи / ключевому образу). 0 текста, on-brand, посмотреть (Read) ДО заливки.
- Вставка в `content_html` (полная ширина `.prose`, адаптивно — CSS сам масштабирует):
```html
<figure>
  <img src="/uploads/site/<slug>-2.jpg" alt="<краткое описание сцены>" loading="lazy">
</figure>
```
⚠️ **Без `style="width:100%"`.** Inline-стиль в публичном view запрещён ADR-062 (PUBLIC-WEB FLAT STYLE),
а нужды в нём нет: глобальное правило `img { max-width: 100% }` (`wildworld-ui.css:109`) и так зажимает
картинку по ширине `.prose`. Поймано корректурой 2026-07-29 на статье №4 плана-50; в старых записях
(напр. `tekstovye-igry-i-rpg-2026`) атрибут ещё встречается — это легаси, не образец.
- Место: логичная середина (после смыслового блока), НЕ впритык к обложке, CTA-перебивке или финальному CTA. `.prose figure`/`.prose img` уже дают рамку и отступы.

## 8. Перелинковка — каталог
Реестр таргетов — `C:\Projects\mmorpg-vault\reference\site-content-map.md` (хабы `/mestnost`·`/syre`·`/kraft`·`/npc`·`/map`·`/devblog` + все статьи + серии с prev/next). Передавай его таблицы агенту `redkollegiya-linker` в Фазе 1. При публикации (Фаза 4) — добавляй туда строку новой статьи и проставляй серийные prev/next; предыдущую запись серии обнови в CMS (next-ссылка вниз).
