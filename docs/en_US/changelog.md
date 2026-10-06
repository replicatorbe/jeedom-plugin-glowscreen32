# Changelog

## 3.2

Hideable pages (contract 3.1). **No schema or firmware change**: a hidden page is
simply absent from the `layout` answer.

- **"Show page"** checkbox per page (Buttons tab). Existing configurations stay
  fully visible. At least one page must stay shown: the page and the save both
  refuse to hide them all.
- Hidden pages are omitted and visible ones **renumbered** 0 … n-1 in
  configuration order (hiding the first page makes the screen start on the
  next). A hidden `parent` becomes the nearest visible ancestor, else page 0; a
  navigation button to a hidden page is removed; `id`s are recomputed over what
  is served, in schemas 1, 2 and 3.
- Changing visibility changes `version`; the stale-layout `press` refusal covers
  the transition.
- Scenario commands: **Show page**, **Hide page**, **Show only page** (list of
  "position — title"), **Show all pages**, and the **Shown pages** info. They
  reload the device just before writing and only touch visibility; hiding the
  last visible page is refused and logged.
- The remote `page` command takes the configuration position and translates it
  to the served number at delivery; towards a hidden page it does nothing
  (logged).

## 3.1

Fixes from a full review. The schema does not change.

- **An unresolved button keeps its rank** (deleted or recreated command). It
  used to be dropped from the flattening, shifting every following `id`
  without a `version` change — a press fired **the neighbouring button**. It
  is now served inert (`state`/`value` null), its `press` answers
  `unknown_button`, and the fault is logged once.
- **`press` guard**: refused if the button at that rank changed since the last
  `layout` served to that screen in that schema.
- **Cheaper `ping`**: commands resolved once per request, `states` and
  `values` in one pass, held requests recheck every 15 s, contact/Wi-Fi/
  diagnostics written only on change (`up`, `heap`, `blk` at most every 5 min
  or on a >10 % move).
- Pages over the real grid, over-long labels/titles (in characters) and
  "action" buttons without a target are refused on save.
- **Wi-Fi password out of the Jeedom cache**: 0600 file under `data/secrets`
  (excluded from backups and deployment), deleted on delivery or expiry.
- "Duplicate" works: the copy leaves without MAC, OTA lock, firmware or
  signature.
- **"Sensitive command" warning** (gate, door, garage, lock, alarm).
- `fwfile`: token bound to the binary's SHA-256, refused if an OTA lock was
  closed since issue; atomic firmware upload.
- API hardening: array parameters refused, strict `id`, unreadable `fw` →
  `bad_request`, control characters stripped from logs, invalid UTF-8
  repaired, `Content-Length` on every answer. `poll` out of range → 30;
  banner numbers as "21,4".

## 3.0

Value tiles and read-only screens — **schema 3**. This time the schema number
moves, and it has to: a schema 2 board would take a value tile for a button.

- **Negotiation.** Header `3` → schema 3; `2` → schema 2 **without any value
  tile**, `id`s recomputed over what remains; `1` (or none) → unchanged, byte
  for byte. `press` resolves the rank in the flattening of the request's
  schema: **the same button has different `id`s in different schemas.**
- **"Value" mode.** A tile that shows an info command instead of acting: door,
  alarm, heating, temperature. The plugin formats it (`value`, at most 16
  chars) and gives it a meaning (`tone`: neutral, ok, warn, alert); the board
  only displays. Binary: label and tone for 0 and 1, invert. Numeric: unit,
  decimals, ascending thresholds. Text: value → label + tone table. Prefilled
  from the Jeedom generic type, with a **preview of the real formatted value**
  in the page — what "1" means varies from one module to another.
- **Value tile staleness**: "—" when the command's device is disabled or in
  Jeedom communication alert; a numeric value also goes stale after 60 min
  without collection (configurable, 0 = never). Binary and text: no default
  threshold — a door closed for three days sends nothing and is right.
- **`values`** in schema 3 `ping` (same length as `states`); `rev` covers it and
  the listener watches value tile commands.
- **Read-only** (Screen tab checkbox): `ui.readonly` in schema 3, and **every
  `press` from that screen is refused by the plugin** (`403 read_only`, before
  any resolution), whatever the firmware.
- A `press` on a value tile answers `unknown_button`, logged.

## 2.2

Responsiveness and fleet operation. **The schema stays 2**: 2.2 only adds
optional request parameters and response fields a 2.0/2.1 board does not read.
**Schema 1 is served byte for byte as before.**

- **The API never saves the device any more** (normative rule of contract
  2.2). Last contact, firmware version and banner staleness used to be written
  through `save(true)` — the whole eqLogic as loaded at the start of the
  request, silently overwriting any configuration saved meanwhile. They now go
  to info commands (contact, firmware) and to the cache (staleness). The fleet
  table, offline detection and the device page read those commands.
- **Long-poll `ping`** (`wait`, `rev`), schema 2 only: a state change made
  elsewhere reaches the screen in about a second instead of `poll`. Schema 2
  `layout` and `ping` answers carry `features: {wait: 25, cmd: true}` and
  `rev`. A held request rereads a wake counter four times a second **without
  any SQL query**; a Jeedom *listener* on the buttons' state commands and the
  banner command moves that counter. A new request from the same screen
  releases the previous one.
- **Remote commands**: Reboot, Identify, Message, Page, Calibrate, Check
  firmware — usable from the dashboard and from scenarios. Queue of 8 per
  screen, 10-minute lifetime, **at-most-once** delivery, increasing persistent
  `seq`. The Wi-Fi change, which carries a password, can only be sent from the
  device page.
- **Diagnostics** attached to `ping` by firmware 2.2 (`up`, `rst`, `heap`,
  `blk`, `ip`, `ssid`): six new info commands; Wi-Fi level, free heap and
  largest free block are historized. IP address, uptime and last reset cause
  show in the fleet table.
- The "incomplete button" warning is no longer written on every `ping`, only on
  transitions — essential with long polling.
- Help texts fixed: "offline" past `3 × 2 × poll`, not "three intervals".
  Screen name and page titles escaped in the page (XSS).

## 2.1

Three comfort fixes, no break. **The schema number does not move**: 2.1 only
adds an optional *request* parameter, and a parameter a server does not know is
a parameter it ignores. A 2.0 firmware talks to this plugin unchanged, and the
other way round.

- **The log no longer floods when the banner goes stale.** `infoText()` logged
  on every call — every `ping`, every `layout`: eight identical lines for a
  single save, then two a minute per screen, forever. The message stays (a
  command that is no longer collected is a real and silent failure) but it is
  written **only on transitions**, return to normal included — exactly what
  `noteFirmware()` already does for the board's version.
- **"Online" command** (binary) and a **one-minute cron**. `isOnline()` fed
  nothing but an icon in the fleet table, so no scenario could react to a wall
  panel gone dark. It turns 1 as soon as a call arrives; the cron puts it back
  to 0, since an offline screen is precisely the one no longer calling.
- **"Wi-Fi level" command** (numeric, dBm), fed by the optional `rssi`
  parameter of `ping`. The project's leading cause of failure, and its most
  misleading one: `ping` still gets through where a `press` is lost (−88 dBm)
  and an OTA dies at 2 % (−92/−93 dBm).
- **Offline threshold recomputed on `3 × 2 × poll`.** The board stretches its
  `ping` to `2 × poll` once its screen dims (contract 2.1); the threshold had
  to follow, or **the whole fleet would have gone offline every night** while
  every board worked perfectly. An alert that cries wolf nightly is one people
  stop believing — and it would stay silent the day a screen really dies.
- Both new commands are **created retroactively** on existing devices when the
  plugin updates: nothing to reopen or re-save by hand.

## 2.0

Schema 2 of the API contract: pages, an adjustable grid, a banner — and **schema
negotiation**, without which none of it could have been published.

- **Schema negotiation.** The board announces what it can read in the
  `X-GLOWSCREEN32-SCHEMA` header. Absent, empty or `1` → **schema 1 unchanged,
  byte for byte**. `2` or more → schema 2. The plugin never answers above what
  was announced.
  This is what makes updating the fleet possible: the plugin deploys in one
  second and all at once, whereas screens move over the air one by one, over
  several days. Without negotiation, publishing this version made **every**
  screen unusable at the same instant — and a screen that shows nothing can no
  longer receive the OTA that would repair it. **The plugin always goes first,
  the firmware after.**
- **Up to 32 buttons, over 4 pages of 12.** The configuration's `buttons` array
  stays **flat**: each button simply carries its `page` and its `slot`. An older
  configuration therefore reads as is — a button with neither page nor slot
  lands on the home page, in the slot of its rank, which is exactly where it
  was. **No migration script to run.**
- **Adjustable grid**: 3×2, 3×3, 4×2 or 4×3, **3×3 by default**. Shrinking the
  grid loses no button: those whose slot no longer exists are moved to the first
  free slot, and the log says so.
- **New "navigation" button mode**: the button opens another page. It commands
  nothing, its state is always `null`, and **the board answers it by itself,
  without the network** — a screen cut off from Jeedom keeps navigating.
- **Banner**: an information command of your choice, **formatted by the plugin**
  (rounded, with its unit, sixteen characters at most), plus an optional clock
  based on server time. The time offset is computed from Jeedom's timezone,
  **daylight saving included** — no hard-coded value.
- **A banner value that is too old is not displayed**: the `info` field is then
  `null`, and the log names the screen, the command and the real age. Threshold
  set per screen (`info_max_age`), **60 minutes by default, 0 to never expire** —
  sensors do not all refresh at the same pace. The case came up: the weather
  command chosen had never been collected, its cron not running, and the screen
  would have shown the same temperature forever. It is the **collection** date
  that is read, not the date of the last value change: a temperature stable at
  18 °C for two hours is fresh, and relying on `valueDate` would have wiped it
  wrongly. Expiry does **not** move `version` — it is a state change, it travels
  in the `ping`.
- **Closed icon vocabulary, plus an alias table.** `icon` becomes a drop-down
  list: the firmware turns the name into a numeric id at parse time and can only
  draw the ones it knows. A free-text field allowed icons to be configured that
  would never show. A name outside the vocabulary whose intent is clear —
  `fire`, `volet`, `temperature` — is **resolved to its equivalent** rather than
  lost; anything resolved by nothing becomes `none` **and leaves a log line
  naming the screen and the button**, so one knows which to fix.
- **In schema 1, `icon` is a pass-through: the stored string, as is.** No
  lowercasing, no aliases, no `none`, no empty — exactly what v1.4 did, where
  the field was free text. Schema 1 is a frozen contract, not a place to apply
  schema 2's rules: that is precisely what negotiation is for. The "byte for
  byte" promise thus becomes true **by construction, for every screen**, with no
  exception to remember.
- **Schema 1 flattening.** A screen configured with pages, queried without the
  header, returns its **first six buttons**, **navigation buttons excluded**,
  renumbered 0 to 5. And `press` resolves the rank received **in that very
  flattening**: a schema 1 board sending rank 2 means the third button of *its*
  list, not the button with global id 2.
- **Overflows are logged, never silently absorbed**: more than 32 buttons or
  more than 12 per page are refused on save; a full page, a moved button or an
  answer beyond 8,192 bytes each leave a log line.
- The layout signature accounts for pages, grid, swipe, clock, banner command,
  and each button's page, slot and navigation target. **Otherwise `version`
  would not move and no screen would redraw** — saving would succeed, the page
  would show the new layout, and the wall the old one.
- English translations, behind since 1.2, are up to date.

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
  `0xE9` is refused. The version is read from the `GLOWSCREEN32-FW:` marker the
  firmware burns into the image; SHA-256 and size are computed on the written
  file. A binary without the marker is refused — the image's own ESP-IDF
  descriptor comes from the precompiled Arduino libraries and is identical in
  every build, so an OTA based on it would never trigger.
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
