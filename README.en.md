# ChargerHub

[Deutsch](README.md) · **English**

![Module version](https://img.shields.io/badge/Modul_Version-0.12.0-blue)
![Symcon version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
[![Check Style](https://github.com/DG65/NRGChargerHub/actions/workflows/check-style.yml/badge.svg)](https://github.com/DG65/NRGChargerHub/actions/workflows/check-style.yml)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

Symcon module for wallboxes (charging stations for electric vehicles) from various manufacturers
via Modbus (TCP; ABL uses Modbus ASCII through a serial converter) — similar to
[InverterHub](https://github.com/DG65/NRGInverterHub) (inverters) and
[MeterHub](https://github.com/DG65/NRGMeterHub) (energy meters).

Part of the **NRG-Stack**, DG65's family of energy modules.

**Note on hardware verification:** The register assignments are based on the manufacturers' publicly available
Modbus documentation, but — with the exception of the go-eCharger (see below) —
**have not yet been verified on real hardware**. Feedback about wrong or missing values is
welcome — please report it with the wallbox type and the affected register.

The module follows the language of your Symcon installation (German or English): variable names, profiles,
messages and the configuration form. Identifiers (idents) and the `CHUB_GetFunctions` contract are the same in
both languages.

## Supported wallboxes

| Wallbox | Scope | Notes |
|---|---|---|
| **KEBA KeContact P30/P40** | Charging status, cable status, charging power, total energy + current session, optional current/voltage per phase, serial number/firmware, control (charging enable, current limit 6–63 A) | Holding registers (FC 0x03) from 1000, all values U32 over 2 registers, unit ID 255 by default. Checked against the evcc reference implementation (proven on real hardware). P40: charging enable works through the current limit (5004), register 5014 does not exist there — not handled separately yet. |
| **Alfen Eve Single/Double Pro-line, NG9xx** | Socket status, charging power, applied current limit, optional voltage/current per phase, control (charging enable, current limit), phase switching 1/3 (register 1215) | Holding registers (FC 0x03), Float32 big-endian. Only socket 1 (base address without offset) is served. |
| **Heidelberg Energy Control** | Charging status, power, optional current/voltage per phase, PCB temperature, control (charging enable, current limit 6–32 A) | Holding registers (FC 0x03), unit ID 1 by default. |
| **go-eCharger Gemini/HOME+** | Charging status, charging power, energy current session/total, optional voltage/current/power per phase (+ N), phases in use after the contactor, cable coding, adapter, RFID card, error code, serial number/firmware; control: charging enable (FORCE_STATE), current limit (AMPERE_VOLATILE 6–32 A), **phase switching** (auto/1-/3-phase), access control, cable lock, energy limit per charging session, LED brightness — control values are read back from the device | Checked against the official manufacturer documentation ([go-eCharger-API-v2](https://github.com/goecharger/go-eCharger-API-v2), modbus-de.md). FC 0x06 is not supported by go-e, writes use FC 0x16. Firmware 60.3 had a documented byte order bug (switch “Swap byte order”), fixed since 60.4. **Modbus must first be enabled via the app/HTTP API** (`men=true`) — otherwise port 502 stays closed. ⚠️ **Two-controller warning:** if a go-e Controller, OCPPHub (or another OCPP backend) or anything else already controls the wallbox itself (load management/surplus charging), EMS control and the foreign control exclude each other — disable one of them, or choose the right value in the instance's selection field “Who controls this charge point?” (reported as `managedBy`/`externallyManaged` through `CHUB_GetFunctions`; the load management state cannot be read via Modbus). **Live incident (2026-09-01):** charging enable “Off” sets `FORCE_STATE=1` (forced off) on the go-eCharger — according to the go-e firmware this hard-blocks EVERY other channel (app, OCPP backend) until it is released again. When switching from “Nobody” to another controller, ChargerHub automatically releases a previously set forced-off lock (`FORCE_STATE=0`) — with older versions without this fix, reset it once manually in the go-e app or with a Modbus tool if necessary. |
| 🧪 **ABL eMH1/eMH2/eMH3** | Charging status, current per phase (1 A resolution), charging power + total energy (both **estimated/integrated** — no power/energy register in this API extract), control (charging enable, current limit 6–32 A) | **No binary Modbus TCP** — ABL speaks Modbus ASCII over a plain RS485-to-Ethernet converter (no protocol gateway), with its own transport client `CHUB_ModbusAsciiClient` (hex text framing with LRC checksum). Register addresses from ABL's public document “EVCC2/3 API subset for external applications” (05 0015 20 Rev. B). Charging enable deliberately uses ONLY the documented Icmax register (100 % duty cycle = “no current allowed”) instead of the internal state machine (register 0x0005) — a wrong state change without test hardware would be a safety risk. State byte values (0xA0…0xF0) come from a community-made Symcon template, not from the PDF itself — **untested on real hardware**, feedback explicitly welcome. |
| 🧪 **DaheimLader** (Smart V1/V2, Touch, Smart/Touch/Business PRO) | Charging status, cable status, error code, current/power/voltage per phase, meter reading, session energy, charging time, serial number, control (charging enable via start/stop command, current limit); on PRO models also phase switching | Plain binary Modbus TCP (standard MBAP, FC 0x03/0x10), unit ID 255 by default, port 502. Register addresses from the manufacturer's public PDF “DaheimLader Modbus/TCP specification” (2026-07-28) — a much more complete and cleaner protocol than the other manufacturers have. **Untested on real hardware.** |
| 🧪 **Fox ESS EV Charger** (models A/L/C) | Charging status, voltage/current per phase, charging power, energy (total + current session), system error bit mask, model code, serial number, firmware version, control (charging enable, current limit), optional phase switching (only with an external phase switching box) | Plain binary Modbus TCP, unit ID 1 by default, port 502. Register addresses from the manufacturer's public PDF “Fox ESS EV Charger Modbus TCP Protocol 1.6” (2024-08-12). The PDF contradicts itself for the register “Current Phase Sequence” (the text says UINT32, the register list assigns only 1 register) — the self-consistent register list was followed. **Untested on real hardware.** |
| 🧪 **Peblar Home/Home Plus/Business** (also ChargeLine) — **experimental** | Charging status (CP A–F), power, total energy, power/voltage/current per phase, serial number/product/firmware, “Limited by”, cable lock; control: charging enable (= Modbus current limit 0), current limit, force single-phase (only with an independent relay) | Binary Modbus TCP, unit ID 1 (confirmed on real hardware; 255 according to the example client, not checked), port 502, firmware 1.6+, enable the Modbus server in the web interface, set smart charging to “Standard”. Measured values in input registers (FC 0x04) from 30000, control in holding registers from 40000. Register map cross-checked against Peblar's official example client and evcc. **Reading, charging enable, current limit and phase switching confirmed on real hardware (forum tester, 2026-09-20).** |
| 🧪 **Phoenix Contact CHARX SEC-3xxx** (charge controller) — **experimental** | Charging status (IEC 61851-1, ASCII), power, total energy + current charging session, voltage/current per phase, identifier/software version, enable type, error code; control: charging enable (x300) and current limit 6–80 A (x301) | Binary Modbus TCP, unit ID 1, port 502, 16-bit holding registers (holding and input identical), 32-bit values MSW first. One register block per charge point (start address = number × 1000, 1000–48000): set “Charge point number” in ChargerHub, one instance per charge point. Enable the Modbus server in the WBM, open port 502, choose the enable type “Modbus” for control; better assign start addresses statically (with “automatic” they can change after a restart). Register map from the CHARX SEC-XXXX manual (109999_en_09, appendix 8.4). **Not yet confirmed on real hardware.** Not implemented: watchdog (x306/x307), phase switching (not documented). |

Register addresses are shown in the **description field** of every variable (object tree, column
“Description”) — handy for comparing with the manufacturer's manual.

ℹ️ The **go-e Controller** (energy measuring unit) is not supported here but by
[MeterHub](https://github.com/DG65/NRGMeterHub) (from 0.14.0, including automatic
detection in its meter search) — it is an energy meter, not a wallbox.

## Goal

One module, one selection field “Wallbox manufacturer” — depending on the selection, the matching registers
and controls are enabled. Unlike MeterHub (read only), ChargerHub also needs write access: setting the
charging enable and the current limit — which makes the integration closer to
InverterHub than to MeterHub.

## PV surplus charging (fallback without an EMS)

In the panel “Control authority & safety”, “Control surplus charging myself” can be switched on. It is a
**fallback without an EMS**: “Who controls?” set to “Nobody”, no active EMS and exactly one ChargerHub instance with a
plugged-in vehicle. The control switches between 1 and 3 phases when needed (with hysteresis) and can reserve a
percentage of the surplus for a storage battery. It starts at about 1.4 kW surplus (6 A single-phase).

**Where the grid value comes from (in this order):**
1. **Grid power directly from a variable** (without MeterHub): any Symcon variable, e.g. filled via MQTT or the
   grid/smart meter value of an InverterHub. Set the sign (`+ = import` as in MeterHub or `+ = feed-in`),
   the unit (W or kW) and the **maximum age of the value** (default 30 s). **If the value is older or invalid, the
   control stops a running charge** (fail-safe: no continued charging with a frozen measurement) and starts again as soon as
   current values arrive. The status line shows the value, its age and the surplus calculated from it. *Check the sign:*
   if a surplus greater than 0 W is shown during feed-in, it is correct.
2. A selected MeterHub instance (meter or “Virtual meter”) or, if empty, automatically the MeterHub contract
   (function `grid`, real time, `+ = import`).

Not included: modes such as “Hybrid”, “PV share” or a 15-minute peak limitation (a task for the future EMS).

## Symbox gateway (built-in RS485 port)

In addition to the direct TCP path, ChargerHub can read through Symcon's native **ModBus gateway** (e.g. the built-in Symbox RS485 port). This path runs through its own bridge instance (**“NRG-Stack ChargerHub Bridge (ModBus gateway)”**, module `ChargerHubBridge`), so that normal direct instances do not need a parent instance.

1. In the ChargerHub instance set “Connection method” to **Symbox gateway**.
2. Select the native ModBus gateway, click **“Create bridge and connect”** (creates the bridge or reuses an existing one on the same gateway), then **“Apply”**.
3. The unit ID is set at the gateway (**“DeviceID”**), not at the hub. **One bridge serves exactly one unit ID** — several wallboxes with different unit IDs each need their own gateway and their own bridge.

Status: **reading** works, **writing** (charging enable/current limit) is still untested. Modbus ASCII (ABL) always runs over the direct path. If you set up the gateway path directly on the hub with earlier beta versions: the instance and its history are kept — select the gateway, click the button, apply.

## Installation

1. In the Symcon console: **Module control → Add** and enter the URL of this repository:
   `https://github.com/DG65/NRGChargerHub` (branch **main**).
2. Create a new instance of type **“ChargerHub”** — or **“ChargerHub Search”**
   (ChargerHubDiscovery) for an automatic network search.

## Network search

The **ChargerHubDiscovery** module searches an IP range for the wallbox types listed above
(Modbus TCP port 502) and, on a click, creates the devices found as ChargerHub instances
with the IP address, unit ID and manufacturer already filled in — like InverterHubDiscovery/
MeterHubDiscovery.

## Archiving

ChargerHub archives measured values and counters **only if the user switches it on** (form, panel “Polling”: “Archive variables”, off by default). The Dashboard (history charts, charging sessions, costs) and MigrationsHub for a device change need archived values. Cumulative energy counters (total energy, energy per RFID card) are archived as counters (aggregation type 1). Instances that already archived before this setting existed keep archiving as before; “Disable archiving” is additionally shown there. Archiving that was switched on from outside (for example by the device takeover) is not switched off by ChargerHub.

## Migration from the go-eCharger module (IPSCoyote/GO-eCharger)

Anyone switching from this widespread third-party module (github.com/IPSCoyote/GO-eCharger, GUID
`{B4624A42-...}`) to ChargerHub can take over the history via **MigrationsHub** (see “Integration into the module family” below). The following ident mapping was verified against the
source code of the third-party module (as of 2026-08-03, MigrationsHub session). The old module stores power in kW, ChargerHub in W: `CHUB_GetIdentMapping` therefore provides the additive field `archiveFactor` (1000.0) for each power ident; the taking-over side multiplies every archived value of the old variable by it (if the field is missing, 1.0 applies):

| Old ident (GO-eCharger) | New ident (ChargerHub)    | Note                                 |
| ------------------------ | -------------------------- | ------------------------------------- |
| `status`                 | `state`                    |                                        |
| `powerToCarLineL1/L2/L3` | `power_l1`/`power_l2`/`power_l3` | Old module in kW → `archiveFactor` 1000 |
| `powerToCarTotal`        | `power`                     | Old module in kW → `archiveFactor` 1000 |
| `ampToCarLineL1/L2/L3`   | `current_l1`/`current_l2`/`current_l3` |                            |
| `energyTotal`            | `energy_total`              |                                        |
| `energyLoadCycle`        | `energy_session`            |                                        |
| `serialID`               | `dev_serial`                |                                        |
| `error`                  | `dev_error`                 |                                        |
| `supplyLineN`            | `voltage_n`                 |                                        |
| `supplyLineL1/L2/L3`     | `voltage_l1`/`voltage_l2`/`voltage_l3` |                             |
| `adapterAttached`        | `adapter`                   |                                        |
| `unlockedByRFID`         | `unlocked_by`                |                                       |
| `cableUnlockMode`        | `ctl_cable_lock`             |                                       |
| `accessControl`          | `ctl_access`                 |                                       |
| `cableCapability`        | `cable_current`              |                                       |
| `energyChargedCard1`…`10`| `card0_energy`…`card9_energy` | **Beware of the index offset**: the old module counts cards 1-based (1–10), ChargerHub 0-based (0–9) — `energyChargedCard1` corresponds to `card0_energy`, not `card1_energy`. |

**No old counterpart** (not contained in the third-party module's source code, no migration
needed/possible): `carConnected`, `firmwareVersion`. ChargerHub reads these values anyway — just
without transferable old history. `carConnected`/`vehicle_plugged` could in principle be derived from
the old ident `status` (e.g. status≠1 → connected) — but that is a
value transformation, not a pure ident reference, and would have to be solved in ChargerHub itself if needed,
not through MigrationsHub's generic ident matching.

**RFID card names** (e.g. “Card 0: name”) are not a variable of their own in the third-party module —
in ChargerHub they come exclusively through the new MQTT card counter (see above,
“RFID card counters”), not from a migration.

## Integration into the module family

Like `MHUB_GetFunctions`, ChargerHub offers a function `CHUB_GetFunctions($id)` through
which an EMS or a tile can query charge points, current power and control options.
The contract is **agreed with the EMS development** (version 1.3); one entry per charge point:

| Field | Type | Meaning |
|---|---|---|
| `contractVersion` | string | Contract version `major.minor` (currently `'1.12'`); consumers check the major, additive fields only raise the minor. If the field is missing, `'1.0'` is conservatively assumed |
| `function` | string | `'charger'` |
| `label` | string | Instance name |
| `powerID` | int | Variable ID of the charging power (W); 0 if not available |
| `energyImportID` | int | Variable ID of the energy counter (kWh, cumulative — `energy_total` preferred); 0 if not available |
| `measured` | bool | `true` (real measured values) |
| `chargeEnableID` | int | Variable ID of the charging enable (bool, writable via `RequestAction`) — **for the EMS only**, not for display modules |
| `currentLimitID` | int | Variable ID of the current limit (A, writable via `RequestAction`) — **for the EMS only**; total limit, per-phase limits possibly later and additively |
| `plugStateID` | int | Variable ID of “Vehicle connected” (bool); 0 if the wallbox does not provide it (e.g. Alfen) |
| `minCurrent` | int | Value (A): smallest valid charging current (6 A). If the EMS budget falls below it, the EMS pauses via `chargeEnableID` instead of setting an invalid limit |
| `maxCurrent` | int | Value (A): effective upper limit = min(manufacturer's hardware limit, property “Maximum connection current”). Every write is additionally hard-clamped to it in the driver |
| `managedBy` | string | Who has authority over the charge point: `none`, `ems`, `goe-controller`, `tibber`, `p14a`, `marketer`, `other`. For anything except `none`/`ems` the EMS does **not** control by itself; `tibber` counts as a hard lock (balancing energy, penalty risk). A selection field in the instance, with a suitable subset per manufacturer (`goe-controller` only for the go-eCharger) |
| `externallyManaged` | bool | Derived from `managedBy` (`true` as soon as a controller other than `none`/`ems` has authority). Kept for compatibility with contract 1.0 |
| `vehicleNameID` | int | Variable ID of “Assigned vehicle” (string, empty if none is assigned) — set exclusively from outside via `CHUB_SetVehicleName()`, ChargerHub never guesses |
| `lastSeenAt` | int | Unix timestamp of the last successful read cycle (0 = never). Additive since 1.3: distinguishes “currently 0 W” from “has not delivered a fresh measurement for a long time” — otherwise a deactivated or hanging instance silently passes on its last known value. If the field is missing (contract < 1.3), behavior is unchanged |
| `deviceSerial` | string | Serial number of the device, empty if the manufacturer does not provide one (Alfen/Heidelberg) — for duplicate detection when the same wallbox is connected more than once (e.g. ChargerHub AND OCPPHub at the same time) |
| `deviceHost` | string | IP address/hostname (property “Host”) — present for EVERY manufacturer, therefore the more reliable matching point than `deviceSerial` |
| `manufacturer` | string | Internal manufacturer key (`goe`/`keba`/`alfen`/`heidelberg`/`abl`/`daheimlader`/`foxess`/`peblar`/`charx`) |
| `duplicateOf` | array\|null | Soft duplicate marker (`null` = counts normally), otherwise `{"source": "chargerhub"\|"ocpphub", "instanceID": int}` — points to the instance that counts for the same physical device. Set exclusively by the user in the form, never guessed automatically. Affects ONLY the counting (totals/sessions/power) — who writes to the device is still decided exclusively by `managedBy`; an instance can be a duplicate for counting AND the active controller at the same time. Consumers skip entries marked as duplicates when measuring/summing/counting sessions |
| `phases` | int | **Optional (1.6), missing = unknown.** Currently used number of phases (1 or 3), only if the device reports it itself (Peblar: register 30092; go-e: only in forced 1-/3-phase mode, the field is missing in automatic mode). Never guessed from wiring or measured values |
| `phasesSwitchable` | bool | **Optional (1.6), missing = unknown.** Device can switch between 1 and 3 phases, only on proof by the device (Peblar: independent relay, register 30093; go-e: the switching register answers). Missing does NOT mean “not switchable” |
| `pluggedInAtID` | int | **Optional (1.7).** Variable ID of “Plugged in at” (`plugged_in_at`, Unix timestamp); 0 = not present (only with the option “Plugged-in/unplugged time” and for manufacturers with “Vehicle connected”, not Alfen). Derived by the module from the change of “Vehicle connected” (accuracy = read interval); value 0 = never changed yet (unknown) |
| `pluggedOutAtID` | int | **Optional (1.7).** Variable ID of “Unplugged at” (`plugged_out_at`), otherwise like `pluggedInAtID` |
| `chargeTimeSecID` | int | **Optional (1.7).** Variable ID of “Charging time (sec.)” (`ladezeit_sek`), 0 = not present. Only manufacturers that report the time themselves (CHARX, DaheimLader) |
| `connectionTimeSecID` | int | **Optional (1.7).** Variable ID of “Connection time (sec.)” (`connection_time_sek`), 0 = not present. CHARX only |
| `phaseModeID` | int | **Optional (1.8).** Variable ID of `ctl_phase_mode` (phase switching), **0 = not switchable or not available**. Only > 0 if the device itself provides the switching (go-e: `psm` readable; Peblar: independent relay; DaheimLader: register 184 readable, PRO only; Alfen: number of phases readable). Fox ESS and KEBA/Heidelberg/ABL/CHARX return 0 |
| `phaseModeValues` | object | **Only with `phaseModeID` > 0 (1.8).** Values to write to `ctl_phase_mode`: `{"one": int, "three": int, "auto": int\|null}`. go-e `{1, 2, 0}`, DaheimLader/Peblar/Alfen `{1, 3, null}` (`null` = no automatic value) |
| `phaseSwitchPauseSec` | int | **1.8.** Charging pause caused by a phase switch, in seconds; only set for DaheimLader (120 according to the documentation), otherwise 0 = unknown |
| `currentStepA` | float | **1.8.** Smallest step of the setpoint `currentLimitID`: 1.0 (integer variable), 0.1 for Alfen |
| `controlOwner` | string | **1.9.** Who currently controls the charge point: `none`, `ems`, `surplus` (ChargerHub's own surplus control), `goe-controller`, `tibber`, `p14a`, `marketer`, `other`. Information only, derived from `managedBy`, EMS activity and surplus control |
| `controlSource` | string | **1.9.** Origin of the statement: `einstellung` (form “Who controls?”), `ems`, `ueberschuss`, `manuell` (these four values are fixed contract keys and stay German) |
| `controlReason` | string | **1.9.** Reason as plain text in the language of the Symcon installation (meant for display, do not evaluate by machine) |
| `controlSince` | int | **1.9.** Unix timestamp of the last owner change (0 = no change since start/unknown) |
| `sessionEnergyID` | int | **1.10.** Variable ID of “Energy current session” (`energy_session`, kWh); 0 = not present (Alfen, Heidelberg, ABL). Jumps back each session, therefore **not a meter reading** (use `energyImportID` for that) |
| `energyLimitID` | int | **1.10.** Variable ID of “Energy limit charging session” (`ctl_energy_limit`, hard limit of the wallbox, writable via `RequestAction`); 0 = not present. go-e only |
| `chargingStateID` | int | **1.10.** Variable ID of “Charging” (`charging_active`, bool): charging power above 100 W. Distinguishes “enabled” from “current is flowing” |
| `stateID` | int | **1.11.** Variable ID of “Charging status” (`state`, integer); 0 = not present. Meaning of the values per manufacturer (variable profile `CHB.*State`), not comparable across manufacturers |
| `errorCodeID` | int | **1.11.** Variable ID of “Error code” (`error_code`); 0 = not present. Only DaheimLader, Fox ESS and CHARX report an error code (0 = no error); a bit field for Fox ESS and CHARX |
| `planInfoID` | int | **1.12.** Variable ID of “Plan (what happens next)” (`control_plan`, string), filled from outside with `CHUB_SetPlanInfo($id, $text, $validUntil)` (e.g. by the EMS). Information only, ChargerHub does not control based on it. `$validUntil` (Unix time, 0 = no expiry): the text is deleted afterwards |
| `controlLatencySec` | int | **1.8.** Typical time from setpoint to effect on the power. **Not measured on any device: always 0 = unknown** |

Contract version (`contractVersion`): currently **`1.12`** (1.12: optional field `planInfoID`, plan text from outside via `CHUB_SetPlanInfo`; 1.11: optional reference fields `stateID`/`errorCodeID`, so that the EMS connects status and errors automatically; 1.10: optional reference fields `sessionEnergyID`/`energyLimitID`/`chargingStateID` for charging target and deadline in the EMS; 1.9: optional information fields `controlOwner`/`controlSource`/`controlReason`/`controlSince`, central display “who controls, why”; 1.8: optional control fields `phaseModeID`/`phaseModeValues`/`phaseSwitchPauseSec`/`currentStepA`/`controlLatencySec`, EMS request 2026-10-21; 1.7: optional reference fields `pluggedInAtID`/`pluggedOutAtID`/`chargeTimeSecID`/`connectionTimeSecID`, Dashboard request 2026-10-06; 1.6: optional fields `phases`/`phasesSwitchable`, see table; 1.1: `managedBy`; 1.2: `vehicleNameID`; 1.3: `lastSeenAt` — EMS incident 2026-09-12, Grid Rewards treated a frozen 0 W measurement as valid and discharged the house battery into the car; 1.4: `deviceSerial`/`deviceHost`/`manufacturer`; 1.5: `duplicateOf` — both MeterHub request 2026-09-13, duplicate detection for wallboxes connected twice, hard path `CHUB_SetActive()` plus soft marker `duplicateOf`).

**Fixing the control values (1.8, at the EMS's request):**
- `currentLimitID` is a **writable setpoint in A** in the range `minCurrent` to `maxCurrent` (integer; Alfen with 0.1 A). ChargerHub limits values to this range. **0 is not a valid pause value:** go-e, DaheimLader, Fox ESS, Peblar, CHARX and ABL raise values below `minCurrent` to `minCurrent`, KEBA, Heidelberg and Alfen write 0 to the device as a lock. **To pause, always write `chargeEnableID` = false.**
- Enable and limit are separate control variables, **to start always write `chargeEnableID` = true first, then `currentLimitID`.** For devices where both share the same register (ABL, Peblar, CHARX), a limit is only remembered and not written while the enable is locked. With KEBA, Heidelberg and Alfen, writing a limit greater than 0 can release a locked wallbox (same register).
- `phases` (1.6) = the **current** number of phases **reported by the device**, never guessed from measured values: go-e from the phase mask after the contactor (register 205, also in automatic mode; otherwise the forced mode), DaheimLader from register 184 (PRO), Alfen from register 1215 (last set), Peblar from register 30092 (the device's number of phases, not necessarily while charging). If the field is missing, the number of phases is unknown.
- `powerID` = total power of the charge point in W.

**Safety net for `duplicateOf`** (EMS request, 2026-09-13): Since `duplicateOf` deliberately does not decide on the right to write, an instance marked as a duplicate can be the active
controller at the same time — which also means that an accidental marking could unnoticed lead to two
simultaneous controllers. If `duplicateOf` is set, the instance checks the `managedBy` value of the target instance on every
poll (via `CHUB_GetFunctions`/`OHUB_GetFunctions`,
the latter secured with `function_exists`). If both sides have `managedBy` `none`/`ems`, the
instance shows a warning status (instance status 206, variable “Duplicate warning”) — purely
visible, no automatic lock. The decision stays with the user.

The controller authority (“Who controls?”) can also be changed without the form, e.g. from the wallbox detail page of the Dashboard: `CHUB_GetManagedByOptions($id): array` returns the values permitted for this manufacturer as `[{value, label}]` (`goe-controller` only for the go-e), `CHUB_SetManagedBy($id, string $value): array` sets and applies a permitted value and returns `{ok, error, previous, current}`. Impermissible values are rejected and change nothing. The handover logic of the form change runs along, every change is written to the message log with old/new. Callers secure both functions with `function_exists()`.

For the same duplicate situation there is also the public method `CHUB_SetActive($id, bool): string` — deactivates (or reactivates) measuring AND control of an instance completely, with visible feedback. When deactivating, a previously set go-e forced-off lock is additionally released automatically and, if “Who controls?” is still on the default “Nobody”, switched to “Other” (a deliberately set other value is left untouched). A running charge is NOT interrupted by this — only the instance's own observation/control ends.

See [CLAUDE.md](CLAUDE.md) for the conventions of the module family.

### go-e: Eco and PV data via the HTTP API (since 0.11.0)

Besides Modbus, the go-e knows the keys `ids`, `fup`, `lmo` and `lpsc` only through the **HTTP API v2** (switch it on in the go-e app).
ChargerHub uses them on request in two separate settings (panel “go-e: Eco and PV data”, both **off by default**,
only in the direct connection path, not in the Symbox gateway path):

- **Send grid, PV and storage power to the wallbox** (`ids`, every 5 s, values are valid for 10 s): `pGrid` from the MeterHub grid meter
  or the own grid variable (+ = import, − = feed-in), `pPv` (PV generation) and `pAkku` (battery, negative = charging) from the
  InverterHub, if available. This allows the wallbox to control the PV surplus itself (Eco) and books the energy origin in the
  go-e portal. **Fail-safe:** without current grid power nothing is sent, and the wallbox then ends the surplus by itself after 10 s.
  The status is shown in the variable “PV data to the wallbox” and in the message log (on change only).
- **Read the wallbox's Eco status** (every 30 s): `fup` (use PV surplus), `lmo` (logic mode) and `lpsc` (last
  PV surplus calculation). If `lpsc` changes and `fup` is on, “Wallbox calculates PV surplus itself” applies and “Control (who, why)”
  shows the go-e Controller.

ChargerHub does **not** switch `fup` and the logic mode itself, that stays in the go-e app. If the wallbox calculates by itself, no
second controller (EMS, own surplus charging) should additionally write the current limit; the form and the status point this out.

## License

PolyForm Noncommercial 1.0.0 — free for private/non-commercial use, commercial use requires a license (contact: DG65). See [LICENSE](LICENSE).
