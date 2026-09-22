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
   label, a colour, an icon, and what the press triggers — an action command of
   your Jeedom, or a scenario. The preview shows the screen as the board will
   draw it.
4. **Save.** The version counter goes up, and the board redraws at its next
   check.

An empty slot is not sent to the board: it leaves no dead cell on the screen,
the following buttons move up.

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

The plugin implements the v1.2 contract shared with the firmware: three GET
actions, all authenticated.

```bash
curl -s -H "X-GLOWSCREEN32-APIKEY: <key>" \
  "http://<box>/plugins/glowscreen32/core/php/api.php?action=layout&device=246f28123456"
curl -s -H "X-GLOWSCREEN32-APIKEY: <key>" \
  "http://<box>/plugins/glowscreen32/core/php/api.php?action=press&device=246f28123456&id=12"
curl -s -H "X-GLOWSCREEN32-APIKEY: <key>" \
  "http://<box>/plugins/glowscreen32/core/php/api.php?action=ping&device=246f28123456"
```

| `error` | HTTP | Cause |
|---|---|---|
| `bad_apikey` | 401 | key missing or invalid |
| `unknown_device` | 404 | no screen for this MAC, or screen disabled |
| `unknown_button` | 404 | unknown button id for this screen |
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

A button that starts a scenario carries the **opposite** of the scenario id
(`-7` for scenario 7): the contract has no provision for scenarios, and a
negative integer cannot collide with any command id.

## Device commands

| Command | Meaning |
|---|---|
| Layout version | the counter the board watches |
| Last contact | stamped on every call received, `layout` as well as `ping` |
| Last button | the label of the last button pressed |

## Log

`log::add('glowscreen32', …)`, under **Analysis → Logs → glowscreen32**.
