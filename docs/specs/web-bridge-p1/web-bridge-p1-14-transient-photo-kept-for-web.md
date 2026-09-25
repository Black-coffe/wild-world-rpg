---
story: web-bridge-p1-14
spec: web-bridge-p1
status: done
returned: DONE
tier: 3
worker: worker-code
tracer: false
wave: 7
blocked_by: [web-bridge-p1-11, web-bridge-p1-13]
---

# A temp-file photo (the map) stays visible on `/play` after the handler deletes its file

## Goal
Fix for council round 4, Ask 3 (seat opus, RED). `MapService::showMapWithPlayer` (reached from the
«Карта» dock button and `MapOverviewAction`) renders the map into a temp file under
`public/uploads/tmp/`, sends it, then `@unlink($tempFile)` (`MapService.php:176`). `WebDelivery`
(`photoUrl`/`buildMsg`) stores only the URL of that file, so the `/play` map screen's `<img>`
points at a deleted file: a 404, a console error (Ask 10), and the web player never sees the map.
After this story:
- When the seam records a photo (actor capture, virtual inbox, linked mirror) whose local file
  lies under a transient prefix (`Config\WebPlay::transientPhotoPrefixes`, `['uploads/tmp/']`),
  `WebDelivery` copies the file **at record time** (before the caller's `unlink`) to
  `public/uploads/web/<sha1-of-content>.<ext>`. The stored `photo_url` is the site-relative
  `/uploads/web/…` path of the copy. Identical content reuses one file (and refreshes its mtime).
  The write is atomic (temp name + rename).
- On each new copy, files in `public/uploads/web/` older than `Config\WebPlay::photoKeepHours`
  (168, infrastructure per A8, plan A18) are deleted.
- The `/play` views render `<img>` for a **site-relative** `photo_url` only when the file exists
  under `FCPATH`. A pruned, deploy-wiped or never-copied file gives the caption alone (A12), not a
  404. `http(s)://` URLs and the story-13 protocol-relative guard are unchanged.
- A photo under any other `public/` path (for example `uploads/telegram/`) keeps its URL, not copied.

## Requirements
> Фото идут с полной подписью, и экран понятен без картинки.
> Ответ бота показывается как экран, а исправление сообщения заменяет экран.
> в консоли нет ошибок.

## Files
- app/Services/Web/WebDelivery.php
- app/Config/WebPlay.php
- app/Views/site/_play/state.php
- app/Views/site/_play/inbox.php
- .gitignore
- tests/database/WebDeliveryTest.php
- tests/unit/Views/PlayViewsTest.php

## Non-goals
- Do not edit `MapService`, `MapOverviewAction`, `MediaSender` or any other photo sender. Do not
  stop or defer their `unlink`. The seam fixes every temp-file sender at once.
- Do not copy photos outside the transient prefixes, and do not inline images as `data:` URIs into
  `web_play_state` / `web_inbox` JSON.
- No new table, column, cron entry or `Config\Tasks` job. Pruning happens on copy.
- `_play/inbox.php`: touch it only if it renders `photo_url`. If it does not, leave it alone and say
  so in Implementation notes.
- `.gitignore`: add one line for `public/uploads/web/` only if it is not already ignored (for
  example by an existing `public/uploads/*` rule). Otherwise leave it alone.
- No change to the A16 branch, the capture/virtual/mirror routing, or `WebScreenStore`.
- No CSS change; no change to the `http(s)://` or protocol-relative rules of the photo guard.

## Map slice
- Plan `## Contracts` → `Msg`, `WebDelivery`, `Config\WebPlay` (round-4 lines), View data; A8, A12,
  A18; Tradeoffs (14).
- Story 04 Implementation notes (`photoUrl`, where the seam sees the photo stream URI).
- Story 13 Implementation notes (photo guard regex in `_play/state.php`, DOM-based `PlayViewsTest`).
- `docs/specs/web-bridge-p1/council/round-4/opus.md` ASK 3.
- Memory: `feedback_gear_image_must_resolve_via_is_file` (resolve the file before trusting a path).

## Acceptance criteria
- [ ] The worker runs its own test files singly while iterating. The close-story gate is the three
      commands below, run sequentially on the shared `wildworld_tests`.
- [ ] `WebDeliveryTest`: a captured `sendPhoto` whose file lies under `public/uploads/tmp/`, then
      that file is deleted (as `MapService` does). The stored `Msg.photo_url` is a
      `/uploads/web/…` path, the file exists, and its bytes equal the original. The caption is
      stored in full.
- [ ] Same for a virtual-chat inbox photo and a linked-mirror inbox photo (one test each, or one
      data-provider test).
- [ ] A photo under `public/uploads/telegram/` keeps its original URL, and no copy is made.
- [ ] Two sends of identical content give one file in `public/uploads/web/`.
- [ ] A copy older than `photoKeepHours` (mtime set in the test) is gone after the next copy; a
      fresh one stays.
- [ ] `PlayViewsTest`: a site-relative `photo_url` whose file does not exist → no `<img>`, the
      caption rendered in full. An existing file (fixture created by the test) → `<img>`. The
      story-13 cases (`//evil.example/x.png`, `https://…`) keep their outcome. Where a story-13 case
      used a non-existent `/uploads/x.png`, the test creates that fixture; the intent stays.
- [ ] Tests clean up every file they create under `public/uploads/`.
- [ ] Ask 5: a photo send to a real-range chat outside capture still reaches `parent::send()`
      unchanged. The existing `WebDeliveryTest` cases stay green, unmodified in intent.
- [ ] Implementation notes state whether `public/uploads/web/` survives the deploy rsync (read
      `deploy/` config, do not edit it). If it does not, the view guard makes that caption-only.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Implementation notes
- `Config\WebPlay`: `transientPhotoPrefixes=['uploads/tmp/']`, `photoDir='uploads/web/'` (plan contract names it), `photoKeepHours=168`.
- `WebDelivery::photoUrl` (one choke point for `buildMsg` and `editMessageMedia`): a resolved file under `public/` whose relative path starts with a transient prefix goes to private `keepTransient()` - `sha1_file` name, lower-cased ext if `[a-z0-9]{1,5}`, dedup hit = `touch` + reuse, else prune (skips `index.html`) then copy to `<hash>.<rand>.tmp` + `rename`. Any failure logs and returns null (caption-only). Returns site-relative `/uploads/web/<sha1>.<ext>`; non-transient files still get `base_url(...)` as before.
- Virtual path calls `buildMsg` twice (toInbox + messageResult) - the second call is a dedup hit, no second file.
- `_play/state.php`: a `/…` `photo_url` renders `<img>` only if `is_file(FCPATH . path)` (query stripped, `..` rejected). `_play/inbox.php` does not render `photo_url` - untouched.
- `.gitignore`: `public/uploads/web/` was not ignored (only `telegram/_legacy/` and `site/`) - one line added.
- Deploy: `public/uploads/web/` does NOT survive a release. `deploy.yml` rsyncs the checkout into a fresh `~/releases/$TS` and `post-deploy.sh` symlinks only `public/uploads/site` to shared. After each release old copies are gone; the view guard makes those entries caption-only (A18 as written).
- Tests: existing `PlayViewsTest` case `/uploads/x.png` now creates that fixture (intent kept). New `WebDeliveryTest` helpers track created files/dirs/handles; handles must be closed before `rmdir` on Windows, or `public/uploads/tmp` is left behind.
- Surprise: the first WebDeliveryTest run collided with another session's full suite on `wildworld_tests` (random "table doesn't exist"); rerun after it finished was green.

## Findings
