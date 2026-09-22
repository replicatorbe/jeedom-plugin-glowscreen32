# Changelog

## 1.2

Over-the-air firmware updates (API contract v1.4).

- **`action=firmware`**: the board reports the version it runs, the plugin
  answers `update: false` or the full object with `version`, `url`, `sha256` and
  `size`. The board checks the digest before switching partitions.
- **Two independent locks, both closed by default**: `ota_enabled` plugin-wide
  and `ota_allowed` per screen. Both must be open for an update to go out. The
  per-screen lock makes staged rollouts possible; the global one stops a bad
  firmware dead. A blocked board gets exactly the answer an up-to-date board
  gets.
- **Firmware upload from the plugin page.** A file not starting with byte
  `0xE9` is refused. The version is read from the ESP-IDF application descriptor
  inside the image; SHA-256 and size are computed on the written file.
- The binary lives in `data/firmware/` and is **excluded from deployment**:
  redeploying the plugin does not wipe it. `data/firmware/.htaccess` reopens
  `.bin` files so the board can download them.
- **Fleet tracking**: each screen's reported firmware version is stored (written
  only when it changes), exposed as a "Firmware version" information command,
  and shown in the fleet table next to both locks' state.
- **Every OTA decision is logged**, naming the version asked for, the answer,
  and which lock blocked it.

## 1.1

Two fixes from a test on real hardware.

- **Button mode** (API contract v1.3). "Plain action" keeps the previous
  behaviour; "Switch" reads the state and plays the **opposite** command, so one
  press turns on and the next turns off. A button wired to a Shelly's "Turn on"
  command alone never turned anything off: fixed.
- A switch button **without a state command is refused on save**, naming the
  offending slot.
- A device's "Toggle" command is accepted as an alternative setup, but only
  played as a fallback: an out-of-sync toggle inverts the state shown on screen.
- **`id` is now the button's rank** (0 to 5) rather than a Jeedom command id, in
  `layout` as well as `press`. A board can no longer name an arbitrary command
  of the installation. The negative-id convention for scenarios is gone.
- `press` returns `pending`, and `state` is the **expected** state rather than
  the observed one. The `states` array of the next `ping` remains the source of
  truth.
- **Last contact is now written to the device configuration**, not only to an
  information command — `getConfiguration('lastcontact')` used to return an
  empty string on a perfectly healthy screen. Rounded to the minute so a `ping`
  from every screen does not write to the database, and displayed in readable
  form with an "offline" label.
- The plugin icon is served again: the `plugin_info/` `.htaccess` was blocking
  images too, unlike every other plugin's.

## 1.0

First release.

- One device per ESP32 screen, identified by its MAC address. The MAC is
  normalised (lower case, no separator) before storage and before comparison,
  and two screens cannot share one address.
- Six buttons per screen: label, colour, icon, and an action command or a
  scenario to trigger.
- `core/php/api.php` endpoint implementing the v1.2 API contract: `layout`,
  `press`, `ping`, authenticated by the `X-GLOWSCREEN32-APIKEY` header with a
  `?apikey=` fallback.
- `ping` returns a `states` array, in the same order as the `layout` buttons.
- Optional per-button state command, naming the information command that
  carries the button's state.
- Per-screen version counter, bumped when the layout changes — and only then.
- Three information commands per screen: layout version, last contact, last
  button pressed.
- 3×2 grid preview in the configuration page, and display of the `layout`
  response actually served.
