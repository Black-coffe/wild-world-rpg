---
story: web-bridge-p1-19
spec: web-bridge-p1
status: todo
tier: 1
worker: worker-code
tracer: false
wave: 11
blocked_by: [web-bridge-p1-18]
model: opus
---

# A photo sent as an HTTP stream shows on `/play`

## Goal
Preprod Tier-3 (2026-09-25, web-only character 516): the Inventory and Craft screens arrive with a
full caption but `photo_url = null`, so `/play` shows no picture. Handlers such as
`InventoryAction.php:60-67` pass `Request::encodeFile(base_url('uploads/telegram/….png'))` — an
`fopen()` **HTTP stream**. `WebDelivery::photoUrl()` (`app/Services/Web/WebDelivery.php:576-616`)
takes the stream's `uri` and runs it through `realpath()` as a file path, which is always false for
`https://…`, so it returns null. The string branch already returns an `http(s)` URL as is; the
resource / `StreamInterface` branches do not.

After this story a resource or stream whose `uri` is an `http(s)` URL resolves to that URL (same
rule as the string branch), so the picture shows. File-path streams keep today's behaviour
(under `public/` → `base_url`, transient prefixes → kept copy, anything else → null).

## Requirements
> Фото идут с полной подписью, и экран понятен без картинки.

## Files
- app/Services/Web/WebDelivery.php
- tests/unit/Services/Web/WebDeliveryPhotoUrlTest.php

## Acceptance criteria
- [ ] Unit test: `photoUrl()` of an HTTP stream resource (or a stub whose metadata `uri` is
      `https://example.test/uploads/x.png`) returns that URL; the same for a `StreamInterface`.
- [ ] Existing path cases unchanged: a file under `public/` → `base_url(rel)`; a file outside
      `public/` → null; a non-http string path unchanged.
- [ ] Only `http`/`https` URIs pass; `file://`, `php://`, `data:` and protocol-relative stay null or
      go through the path branch.

## Verification
`vendor/bin/phpunit --no-coverage --no-progress`
`vendor/bin/phpstan analyse --memory-limit=512M --no-progress`
`git ls-files 'app/Database/Migrations/*.php' | xargs -n1 php -l > /dev/null`

## Map slice
`memory/map/telegram.md` (WebDelivery)

## Implementation notes

## Findings
