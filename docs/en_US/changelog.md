# Changelog

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
