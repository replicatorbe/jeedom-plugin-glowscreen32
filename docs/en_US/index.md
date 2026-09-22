# GlowScreen32

Drives ESP32 touch screens from Jeedom.

One plugin device = **one** physical screen, identified by the MAC address of
its board. The screen shows up to six buttons; you decide in Jeedom what each
one triggers. The board fetches its layout at boot, then reports presses.

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

The plugin implements the v1.3 contract shared with the firmware: three GET
actions, all authenticated.

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
| `unknown_device` | 404 | no screen for this MAC, or screen disabled |
| `unknown_button` | 404 | rank outside this screen's layout |
| `bad_request` | 400 | missing parameter, malformed MAC, or unknown action |

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

## Device commands

| Command | Meaning |
|---|---|
| Layout version | the counter the board watches |
| Last contact | stamped on every call received, `layout` as well as `ping`, rounded to the minute |
| Last button | the label of the last button pressed |

## Log

`log::add('glowscreen32', …)`, under **Analysis → Logs → glowscreen32**.
