# GlowScreen32

Drives ESP32 touch screens from Jeedom.

One plugin device = **one** physical screen, identified by the MAC address of
its board. The screen shows up to thirty-two buttons and tiles over four pages;
you decide in Jeedom what each one triggers or shows. The board fetches its layout at boot, then reports presses.

The fleet is multi-screen by design: create as many devices as you have
screens, each with its own MAC and its own buttons. **The firmware is identical
on every board** — nothing is compiled into it, a board discovers its identity
by reading its own MAC address at boot.

## Setting up a screen

1. **Plugins → Organisation → GlowScreen32 → Add a screen.** Name it after the
   room: the name shows in the screen banner.
2. **MAC address.** With or without separators, upper or lower case:
   `24:6F:28:12:34:56`, `24-6f-28-12-34-56` and `246f28123456` all mean the same
   board. The plugin normalises it and refuses two screens sharing one address.
3. **Buttons tab.** Six slots, in the order of the 3×2 grid. For each one: a
   label, a colour, an icon, and a **mode** — see below. The preview shows the
   screen as the board will draw it, with each button's rank.
4. **Save.** The version counter goes up, and the board redraws at its next
   check.

An empty slot is not sent to the board: it leaves no dead cell on the screen,
the following buttons move up.

## The two button modes

| Mode | What it does | What to fill in |
|---|---|---|
| **Plain action** | always plays the same thing | an action command, or a scenario |
| **Switch** | reads the state, then plays the **opposite** command | "Turn on", "Turn off", and a state command |

A device such as a Shelly exposes *separate* commands: `Turn on`, `Turn off`,
`Toggle`. Wiring a button to only one of them gives a button that **only turns
on** — the defect of version 1.0, seen on the wall. In **Switch** mode the
plugin reads the state before acting and picks the command itself.

A switch button **without a state command is refused on save**: with no state,
nothing decides which way to go.

The **Toggle** field is optional, and a fallback rather than the normal path: an
out-of-sync toggle command inverts the state shown on the screen, and the gap
never closes. It is only played when the state becomes unreadable.

## Enrolling a new board

A board that is flashed but not yet declared receives `unknown_device` and
**shows its own MAC address in large type**. Copy it into a new plugin device:
the board switches to normal mode on its own as soon as it is recognised, with
no serial cable and no reboot.

## Wiring the firmware

| | |
|---|---|
| URL | `http://<your box>/plugins/glowscreen32/core/php/api.php` |
| Header | `X-GLOWSCREEN32-APIKEY: <key>` |
| Key | the plugin API key, **the same for the whole fleet** |

The key is also found under **Settings → System → Configuration → API tab**,
row *GlowScreen32*.

## API contract

The plugin implements the 3.0 contract shared with the firmware: GET actions,
all authenticated by the API key except the token-protected `fwfile` download.

> **Changed in v1.3:** `id` is no longer a Jeedom command id, it is the
> **button's rank** in the layout (0 to 5). The board treats it as opaque and
> sends it back as is; the plugin decides which command to run. A board can no
> longer name an arbitrary command of the installation.

```bash
curl -s -H "X-GLOWSCREEN32-APIKEY: <key>" \
  "http://<box>/plugins/glowscreen32/core/php/api.php?action=layout&device=246f28123456"
curl -s -H "X-GLOWSCREEN32-APIKEY: <key>" \
  "http://<box>/plugins/glowscreen32/core/php/api.php?action=press&device=246f28123456&id=0"
curl -s -H "X-GLOWSCREEN32-APIKEY: <key>" \
  "http://<box>/plugins/glowscreen32/core/php/api.php?action=ping&device=246f28123456"
```

| `error` | HTTP | Cause |
|---|---|---|
| `bad_apikey` | 401 | key missing or invalid |
| `read_only` | 403 | `press` on a read-only screen (3.0) |
| `unknown_device` | 404 | no screen for this MAC, or screen disabled |
| `unknown_button` | 404 | rank outside this screen's layout |
| `bad_request` | 400 | missing parameter, malformed MAC, or unknown action |

`ping` takes an optional `&rssi=-64` parameter (contract 2.1): the Wi-Fi level
the board measures, in dBm, feeding the **Wi-Fi level** command. Anything
missing, non-numeric or outside **−120 to 0** is ignored **without an error** —
a malformed diagnostic must never cost a screen its link. No response field
changes and **the schema number stays 2**.

The board follows `poll` while its screen is lit, may stretch to **2 × `poll`**
once dimmed, and always pings **immediately on wake**. The offline threshold is
therefore counted on `3 × 2 × poll`; counting on `poll` alone would take the
whole fleet offline every night.

`ping` also returns a `states` array, in the same order as the `layout`
buttons, giving each button's current state. It is what keeps the screen's
indicators fresh: `version` only changes on *configuration* changes, so a lamp
switched on from the Jeedom app, a wall switch or a scenario would otherwise
leave the indicator stale until the next full reload.

The optional **state command** field on each button names the information
command carrying that state. Left empty, the plugin reuses the link Jeedom
itself declares between an action command and its state. Only a **binary**
subtype yields a state; anything else returns `null`.

`press` answers `{ "ok": true, "id": 0, "state": 1, "pending": true }`. `state`
is the **expected** state after execution — in switch mode, the opposite of the
state read just before — and `pending` says it is not confirmed yet. The source
of truth remains the `states` array of the next `ping`.

A button that starts a scenario is a plain-action button targeting a scenario;
since ids are ranks, it needs no special convention (the negative-id convention
of 1.0 is gone).

### Contract 2.2 — long polling, remote commands, diagnostics

Schema 2 `layout` and `ping` answers end with
`"features": {"wait": 25, "cmd": true}` and `"rev": "a41f09c2"`. A 2.2 board
resends `ping` with `&wait=<s>&rev=<last rev>`; if `rev` is still current and no
command is queued, the plugin **holds** the request until a button state, the
banner (staleness included), `version` or the command queue changes, or `wait`
seconds (25 at most) elapse. Without `wait`/`rev`, or with a different `rev`:
immediate answer, as in 2.1. Schema 1 is never held and never changes.

While holding, the plugin rereads a small wake file
(`/tmp/jeedom/glowscreen32/wake-<id>`) four times a second, **without any SQL
query**; a Jeedom listener on the buttons' state commands and the banner command
moves it, and `rev` is recomputed every five seconds regardless. A new request
from the same screen releases the previous one. Contact and diagnostics are
recorded when the request **arrives**.

A schema 2 `ping` may carry **one** remote command, e.g.
`"cmd": {"seq": 17, "do": "message", "text": "…", "duration": 30}` — verbs
`reboot`, `identify` (1–120 s), `message` (≤ 64 chars, 1–600 s), `page` (0–3),
`calibrate`, `ota`, `wifi` (device page only). Queue of 8 per screen, 10-minute
lifetime, **at-most-once** delivery, per-screen persistent `seq`.

Optional diagnostics on `ping`: `up`, `rst`, `heap`, `blk`, `ip`, `ssid` — ignored
without error when missing or malformed, like `rssi`.

**The API never saves the device.** Contact, firmware, diagnostics and banner
staleness go to info commands or the cache: a held 25 s request re-saving the
device as loaded on arrival would overwrite any configuration saved meanwhile.

### Contract 3.0 — schema 3, value tiles, read-only screens

Header `3` → schema 3; `2` → schema 2 **without any value tile**, `id`s
recomputed; `1`/none → schema 1 byte for byte. A **Value** tile (`mode: "view"`)
shows an info command: the plugin formats `value` (≤ 16 chars) and `tone`
(`neutral`, `ok`, `warn`, `alert`) — binary labels/tones/invert, numeric unit,
decimals and ascending thresholds, text value → label table — prefilled from the
Jeedom generic type, with a live preview of the real value in the page. It shows
"—" when the device is disabled or in communication alert, and, for numeric
values, after 60 min without collection (configurable). Schema 3 `ping` adds
`values` (same length as `states`), covered by `rev`. A **read-only** screen
(`ui.readonly`) has every `press` refused with `403 read_only`, whatever its
schema; a `press` on a value tile answers `unknown_button`. The same button has
different `id`s in different schemas.

## Over-the-air firmware updates

`GET ...core/php/api.php?action=firmware&device=246f28123456&fw=1.3.0` answers
either `{ "ok": true, "update": false }` or the full object with `version`,
`url`, `sha256` and `size`. `fw` is the version the board is running; it is
mandatory, and it also fills the **Firmware** column of the fleet table.

This is the one feature where Jeedom can **permanently break** a screen from a
distance, so there are **two independent locks**, both server side, both closed
by default:

| Lock | Where | Default |
|---|---|---|
| `ota_enabled` | plugin-wide, on the plugin page | **closed** |
| `ota_allowed` | per screen, in the device's Screen tab | **closed** |

**Both** must be open for a screen to get `update: true`. The per-screen lock is
what makes a **staged rollout** possible — open one pilot screen, check it comes
back online, then open the rest. The global lock is what **stops a bad firmware
dead**: flip it off and the screens that have not updated yet keep polling and
keep being told there is nothing new. A blocked board gets exactly the answer an
up-to-date board gets: it cannot tell the difference, so it cannot work around
it.

Upload the `.bin` from the plugin page. The plugin refuses anything that does
not start with byte `0xE9`, reads the version from the `GLOWSCREEN32-FW:` marker
the firmware burns into the image, computes the SHA-256 and the size itself, and
stores the file under `data/firmware/`. A binary without that marker is
**refused**: the image's own ESP-IDF descriptor comes from the precompiled
Arduino libraries, is identical in every one of our builds, and would therefore
never trigger an update at all. That folder's binaries are excluded from
`deploy-plugin.sh`, so redeploying the plugin does not wipe the uploaded
firmware. The binary is **not** served by Apache (Jeedom's root `.htaccess` returns 403 for any
`.bin` under a `data/` folder): the board downloads it from `api.php?action=fwfile&token=…`,
with a random token valid for 15 minutes, issued only when both locks are open.

Every OTA decision is logged, naming the version asked for, the answer, and
which lock blocked it.

## Device commands

| Command | Meaning |
|---|---|
| Layout version | the counter the board watches |
| Last contact | stamped on every call received, `layout` as well as `ping`, rounded to the minute — since 2.2 the only place it is written |
| Last button | the label of the last button pressed |
| Firmware version | the version the board reports, written only when it changes |
| **Online** | binary, 2.1 — 1 as soon as a call arrives, 0 when the one-minute cron notices the silence |
| **Wi-Fi level** | numeric, dBm, 2.1 — the `rssi` the board reports on `ping` |
| Uptime, Reset cause | 2.2 — `up` and `rst` from `ping` |
| Free heap, Largest free block | 2.2 — bytes, **historized** (Wi-Fi level too) |
| IP address, Wi-Fi network | 2.2 — where the screen sits on the network |
| **Reboot, Identify, Message, Page, Calibrate, Check firmware** | actions, 2.2 — queue a remote command; usable from scenarios. Message: the "title" field holds the duration in seconds (30 if empty) |

Changing the Wi-Fi is **not** a Jeedom command: it carries a password, and can
only be sent by an administrator from the device page.

**Online** turns a dark wall panel into an ordinary Jeedom fact: a scenario can
finally react to it. Until 2.1 the answer lived only in the plugin's own screen
table, so noticing meant walking past the panel.

**Wi-Fi level** is the project's leading cause of failure and its most
misleading one: `ping` still gets through where a `press` is lost (−88 dBm) and
where an OTA dies at 2 % (−92/−93 dBm). The banner shows it below −75 dBm, but
only to someone standing in front of the screen.

## Log

`log::add('glowscreen32', …)`, under **Analysis → Logs → glowscreen32**.
