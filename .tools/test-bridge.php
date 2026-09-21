<?php
// Prüfstand für die Symbox-Gateway-Brücke (SUITE.md 9j): Brücken-Vertrag
// (Forward/GetState, Binärwerte, alle Fehlerarten), ChargerHub-Seite
// (ForwardViaBridge/CHUB_ModbusGatewayClient), module.json beider Module.
// Nicht nachbildbar: Kernel-Verhalten beim Funktionsaufruf zwischen Instanzen
// und die Konsole. Aufruf: php .tools/test-bridge.php  (0 = alles grün)

define('VARIABLETYPE_FLOAT', 2); define('VARIABLETYPE_INTEGER', 1);
define('VARIABLETYPE_BOOLEAN', 0); define('VARIABLETYPE_STRING', 3);
define('KR_READY', 10103);

$GLOBALS['inst'] = []; $GLOBALS['props'] = []; $GLOBALS['parentReply'] = ''; $GLOBALS['log'] = [];
function IPS_GetInstance($id) { return $GLOBALS['inst'][$id] ?? []; }
function IPS_GetProperty($id, $n) { return $GLOBALS['props'][$id][$n] ?? false; }
function IPS_InstanceExists($id) { return isset($GLOBALS['inst'][$id]); }
function IPS_LogMessage($a, $b) { $GLOBALS['log'][] = "$a: $b"; }
function IPS_GetInstanceListByModuleID($g) { return $GLOBALS['byGuid'][$g] ?? []; }
function IPS_GetChildrenIDs($id) { return []; }
function IPS_GetName($id) { return 'Name' . $id; }
function CHUBB_GetState($id) { return $GLOBALS['bridge']->GetState(); }
function CHUBB_Forward($id, $json) { return $GLOBALS['bridge']->Forward($json); }

class IPSModule {
    public $InstanceID = 1; public $attr = []; public $prop = [];
    public function __construct($id = 1) { $this->InstanceID = $id; }
    public function Create() {} public function ApplyChanges() {}
    public function SetStatus($s) { $this->status = $s; }
    public function SendDataToParent($j) { $r = $GLOBALS['parentReply']; if ($r instanceof Throwable) throw $r; return $r; }
    public function ReadPropertyInteger($n) { return $this->prop[$n] ?? 0; }
    public function ReadPropertyString($n) { return $this->prop[$n] ?? ''; }
    public function ReadPropertyBoolean($n) { return $this->prop[$n] ?? false; }
    public function ReadPropertyFloat($n) { return $this->prop[$n] ?? 0.0; }
    public function ReadAttributeString($n) { return $this->attr[$n] ?? ''; }
    public function WriteAttributeString($n, $v) { $this->attr[$n] = $v; }
}

$fail = 0;
function check($name, $ok) { global $fail; echo ($ok ? "OK   " : "FEHL ") . $name . "\n"; if (!$ok) $fail++; }

require __DIR__ . '/../ChargerHubBridge/module.php';
$bridge = new ChargerHubBridge(10); $GLOBALS['bridge'] = $bridge;

// Kein Gateway
$GLOBALS['inst'][10] = ['ConnectionID' => 0];
check('not_connected', json_decode($bridge->Forward('{}'), true)['error'] === 'not_connected');
// Gateway inaktiv
$GLOBALS['inst'][10] = ['ConnectionID' => 20]; $GLOBALS['inst'][20] = ['InstanceStatus' => 104];
check('parent_inactive', json_decode($bridge->Forward('{}'), true)['error'] === 'parent_inactive');
// Aktiv, keine Antwort / Exception
$GLOBALS['inst'][20]['InstanceStatus'] = 102;
$GLOBALS['parentReply'] = '';
check('no_response (leer)', json_decode($bridge->Forward('{}'), true)['error'] === 'no_response');
$GLOBALS['parentReply'] = new RuntimeException('x');
check('no_response (Exception)', json_decode($bridge->Forward('{}'), true)['error'] === 'no_response');
// Binärwerte 0xFFFF / 0x8001 bit-genau über base64
$bin = chr(4) . chr(0) . pack('n', 0xFFFF) . pack('n', 0x8001);
$GLOBALS['parentReply'] = $bin;
$r = json_decode($bridge->Forward('{}'), true);
check('ok + base64 bit-genau', $r['ok'] === true && base64_decode($r['data'], true) === $bin);
// GetState / Unit-ID
$GLOBALS['props'][20]['DeviceID'] = 7;
$st = json_decode($bridge->GetState(), true);
check('GetState', $st['connected'] && $st['parentActive'] && $st['parentStatus'] === 102 && $st['unitId'] === 7);

// ChargerHub-Seite: ForwardViaBridge + Gateway-Client-Parsing
require __DIR__ . '/../ChargerHub/module.php';
$hub = new ChargerHub(1); $hub->prop = ['BridgeInstanceID' => 10, 'ConnectionType' => 'gateway'];
$m = new ReflectionMethod($hub, 'ForwardViaBridge');
check('Hub: Binärantwort unverändert', $m->invoke($hub, '{}') === $bin);
$hub->prop['BridgeInstanceID'] = 0;
check('Hub: ohne Brücke leer + no_bridge', $m->invoke($hub, '{}') === '' && $hub->attr['LastBridgeError'] === 'no_bridge');
$hub->prop['BridgeInstanceID'] = 10; $GLOBALS['inst'][20]['InstanceStatus'] = 104;
check('Hub: parent_inactive', $m->invoke($hub, '{}') === '' && $hub->attr['LastBridgeError'] === 'parent_inactive');
$GLOBALS['inst'][20]['InstanceStatus'] = 102;
$client = new CHUB_ModbusGatewayClient('', 0, 1, fn ($p) => $bin);
$regs = $client->readHolding(0, 2);
check('Gateway-Client: 0xFFFF/0x8001', $regs === [0xFFFF, 0x8001]);

// Verbund-Statuszeilen (SUITE.md 21.09.2026): live berechnet UND im ausgelieferten
// Formular-JSON angekommen (rekursiv, Platzhalter weg), je Zustand.
$form = ['elements' => [
    ['type' => 'ExpansionPanel', 'name' => 'P', 'items' => [
        ['type' => 'RowLayout', 'items' => [['type' => 'Label', 'name' => 'LinkBridgeStatus', 'caption' => 'LINKSTATUS']]],
    ]],
]];
$rep = new ReflectionMethod($hub, 'SetFormLabelCaption');
$ok = $rep->invokeArgs($hub, [&$form['elements'], 'LinkBridgeStatus', 'x']);
check('Statuszeile: rekursiv in verschachteltem Panel ersetzt', $ok && strpos(json_encode($form), 'LINKSTATUS') === false);

$ls = new ReflectionMethod($hub, 'LinkStatusLines');
$GLOBALS['parentReply'] = $bin;
$hub->prop = ['BridgeInstanceID' => 10, 'ConnectionType' => 'gateway'];
$l = $ls->invoke($hub);
check('Zeile Brücke ✅ (Gateway aktiv, Unit-ID)', strpos($l['LinkBridgeStatus'], '✅') === 0 && strpos($l['LinkBridgeStatus'], 'Unit-ID 7') !== false);
$GLOBALS['inst'][20]['InstanceStatus'] = 104;
check('Zeile Brücke ⚠️ (Gateway inaktiv)', strpos($ls->invoke($hub)['LinkBridgeStatus'], '⚠️') === 0);
$GLOBALS['inst'][20]['InstanceStatus'] = 102;
$hub->prop['BridgeInstanceID'] = 0;
check('Zeile Brücke ⛔ (keine Brücke)', strpos($ls->invoke($hub)['LinkBridgeStatus'], '⛔') === 0);
$l = $ls->invoke($hub);
foreach (['LinkDuplicateStatus', 'LinkEmsStatus', 'LinkGridStatus', 'LinkBatteryStatus', 'LinkVehicleStatus'] as $k) {
    check("Zeile $k vorhanden, ℹ️ ohne Partnermodul, kein Platzhalter", isset($l[$k]) && strpos($l[$k], 'ℹ️') === 0 && strpos($l[$k], 'LINKSTATUS') === false);
}
$hub->prop['DuplicateOfKey'] = 'ocpphub:999';
check('Zeile Dublette ⚠️ (Ziel fehlt)', strpos($ls->invoke($hub)['LinkDuplicateStatus'], '⚠️') === 0);

// „Wert kommt automatisch“ (SUITE.md 21.09.2026): MeterHub liefert den Zähler, Feld leer ->
// automatisch (Panel eingeklappt, Zeile 🔗); eigene Wahl -> ✏️, nie automatisch.
if (true) {
    function MHUB_GetFunctions($id) { return json_encode(['assignments' => [['function' => 'grid', 'latency' => 'realtime', 'powerID' => 500]]]); }
    function GetValue($id) { return -1234; }
}
$GLOBALS['byGuid']['{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}'] = [77];
$gm = new ReflectionMethod($hub, 'GridMeterIsAutomatic');
$hub->prop = ['SurplusMeterID' => 0];
check('Feld: Zähler kommt automatisch, Feld leer -> automatisch', $gm->invoke($hub) === true);
check('Zeile 🔗 mit Wert und Quelle', strpos($ls->invoke($hub)['LinkGridStatus'], '🔗 Netzzähler:') === 0 && strpos($ls->invoke($hub)['LinkGridStatus'], '1234 W') !== false);
$hub->prop = ['SurplusMeterID' => 77];
check('Feld: eigene Wahl -> nicht automatisch, Zeile ✏️', $gm->invoke($hub) === false && strpos($ls->invoke($hub)['LinkGridStatus'], '✏️') === 0);

// 🔗-Zeilen grün, andere Standardfarbe
$f2 = [['type' => 'Label', 'name' => 'A', 'caption' => 'x'], ['type' => 'Label', 'name' => 'B', 'caption' => 'x']];
$rep->invokeArgs($hub, [&$f2, 'A', '🔗 Netzzähler: X (automatisch von MeterHub)']);
$rep->invokeArgs($hub, [&$f2, 'B', '✏️ Netzzähler: eigene Wahl']);
check('Farbe: 🔗 grün 0x2E8B3D, ✏️ Standard -1', $f2[0]['color'] === 0x2E8B3D && $f2[1]['color'] === -1);

// CHARX-Treiber (Handbuch 109999_en_09, Anhang 8.4): Adressen je Ladepunkt (x*1000), MSW zuerst,
// Einheiten (mV/mA/mW/Wh), Statuszeichen, Schreiben nur in x300/x301, Grenzen 6-80 A.
class FakeMb {
    public $r = []; public $w = [];
    function set($a, $vals) { foreach ($vals as $i => $v) $this->r[$a + $i] = $v; }
    function readHolding($a, $n) { $o = []; for ($i = 0; $i < $n; $i++) { if (!isset($this->r[$a + $i])) return null; $o[] = $this->r[$a + $i]; } return $o; }
    function u16($x, $o) { return $x[$o] & 0xFFFF; }
    function u32($x, $o) { return (($x[$o] & 0xFFFF) << 16) | ($x[$o + 1] & 0xFFFF); }
    function readStr($x, $o, $n) { $s = ''; for ($i = 0; $i < $n; $i++) $s .= chr(($x[$o + $i] >> 8) & 255) . chr($x[$o + $i] & 255); return rtrim($s, "\0 "); }
    function writeSingle($a, $v) { $this->w[$a] = $v; return true; }
}
class FakeHub {
    public $v = []; public $cp = 2;
    function GetChargePointNo() { return $this->cp; }
    function GroupActive($g) { return true; }
    function GetMaxCurrentA() { return 16; }
    function GetVarValue($i) { return $this->v[$i] ?? ''; }
    public $hidden = [];
    function SetVarHidden($i, $h) { $this->hidden[$i] = $h; }
    function SetVarBool($i, $x) { $this->v[$i] = $x; } function SetVarInt($i, $x) { $this->v[$i] = $x; }
    function SetVarFloat($i, $x) { $this->v[$i] = $x; } function SetVarStr($i, $x) { $this->v[$i] = $x; }
}
$mb = new FakeMb(); $ch = new FakeHub(); $drv = new CharxDriver();
$b = 2000;
$mb->set($b + 299, [(ord('C') << 8) | ord('2')]);
$mb->set($b + 244, [0x0000, 0x2B67]);          // 11111 mW
$mb->set($b + 250, [0x0001, 0x86A0]);          // 100000 Wh
$mb->set($b + 289, [0, 0, 0x0000, 0x0BB8]);    // 3000 Wh
$mb->set($b + 232, [3, 33392, 3, 34392, 3, 35392]); // 230000/231000/232000 mV (MSW, LSW)
$mb->set($b + 238, [0, 10500, 0, 10600, 0, 10700]);
$mb->set($b + 300, [1]); $mb->set($b + 301, [13]); $mb->set($b + 120, [5]);
$mb->set($b + 113, [0x4142, 0x4344, 0x4546]); $mb->set(110, [0x312E, 0x3900, 0, 0]);
$mb->set($b + 293, [0, 0x0040]);
check('CHARX lesen: Status/Leistung/Energie', $drv->readValues($mb, $ch) === true && $ch->v['state'] === 31 && $ch->v['vehicle_plugged'] === true && abs($ch->v['power'] - 11.111) < 0.001 && $ch->v['energy_total'] === 100.0 && $ch->v['energy_session'] === 3.0);
check('CHARX lesen: Spannung/Strom je Phase, Freigabe, Limit', $ch->v['voltage_l2'] === 231.0 && abs($ch->v['current_l3'] - 10.7) < 1e-9 && $ch->v['ctl_enable'] === true && $ch->v['ctl_curr_limit'] === 13 && $ch->v['release_mode'] === 5);
check('CHARX lesen: UID/Fehlercode', $ch->v['dev_serial'] === 'ABCDEF' && $ch->v['error_code'] === 0x40);
$drv->writeControl($mb, $ch, 'ctl_curr_limit', 4);
check('CHARX schreiben: Limit auf x301, mind. 6 A', $mb->w === [2301 => 6]);
$mb->w = []; $drv->writeControl($mb, $ch, 'ctl_curr_limit', 60); check('CHARX schreiben: Limit gedeckelt (Max 16 A)', $mb->w === [2301 => 16]);
$mb->w = []; $drv->writeControl($mb, $ch, 'ctl_enable', false); check('CHARX schreiben: Freigabe x300', $mb->w === [2300 => 0]);
$mb2 = new FakeMb();
check('CHARX ohne Antwort: connected false', $drv->readValues($mb2, new FakeHub()) === false);

// DaheimLader: nicht lesbares Register 0x300A -> Schalter ausgeblendet, lesbar -> sichtbar.
$dmb = new FakeMb(); $dh = new FakeHub(); $dd = new DaheimLaderDriver();
for ($i = 0; $i < 114; $i++) { $dmb->r[$i] = 0; }
$dmb->r[0] = 1;
$dd->readValues($dmb, $dh);
check('DaheimLader: 0x300A nicht lesbar -> Variable ausgeblendet', ($dh->hidden['ctl_auto_phase_switch'] ?? null) === true && !isset($dh->v['ctl_auto_phase_switch']));
$dmb->r[0x300A] = 1; $dd->readValues($dmb, $dh);
check('DaheimLader: 0x300A lesbar -> sichtbar, Wert übernommen', ($dh->hidden['ctl_auto_phase_switch'] ?? null) === false && $dh->v['ctl_auto_phase_switch'] === true);

// module.json beider Module
$main = json_decode(file_get_contents(__DIR__ . '/../ChargerHub/module.json'), true);
$br = json_decode(file_get_contents(__DIR__ . '/../ChargerHubBridge/module.json'), true);
check('module.json Hub leer', $main['parentRequirements'] === [] && $main['implemented'] === []);
check('module.json Brücke', $br['type'] === 3 && $br['parentRequirements'] === ['{E310B701-4AE7-458E-B618-EC13A1A6F6A8}'] && $br['implemented'] === ['{77B31ABB-18FA-4B91-BB63-E5B2AB5588F4}'] && $br['prefix'] === 'CHUBB');

echo $fail === 0 ? "\nAlles grün.\n" : "\n$fail Fehler.\n";
exit($fail === 0 ? 0 : 1);
