<?php
// Prüfstand für die Symbox-Gateway-Brücke (SUITE.md 9j): Brücken-Vertrag
// (Forward/GetState, Binärwerte, alle Fehlerarten), ChargerHub-Seite
// (ForwardViaBridge/CHUB_ModbusGatewayClient), module.json beider Module.
// Nicht nachbildbar: Kernel-Verhalten beim Funktionsaufruf zwischen Instanzen
// und die Konsole. Aufruf: php .tools/test-bridge.php  (0 = alles grün)

define('VARIABLETYPE_FLOAT', 2); define('VARIABLETYPE_INTEGER', 1);
define('VARIABLETYPE_BOOLEAN', 0); define('VARIABLETYPE_STRING', 3);
define('KR_READY', 10103);
define('KL_MESSAGE', 10201); define('KL_WARNING', 10204); define('KL_ERROR', 10205); define('KL_DEBUG', 10206);

$GLOBALS['inst'] = []; $GLOBALS['props'] = []; $GLOBALS['parentReply'] = ''; $GLOBALS['log'] = [];
function IPS_GetInstance($id) { return $GLOBALS['inst'][$id] ?? []; }
function IPS_GetProperty($id, $n) { return $GLOBALS['props'][$id][$n] ?? false; }
function IPS_InstanceExists($id) { return isset($GLOBALS['inst'][$id]); }
function IPS_LogMessage($a, $b) { $GLOBALS['log'][] = "$a: $b"; }
function IPS_GetInstanceListByModuleID($g) { return $GLOBALS['byGuid'][$g] ?? []; }
$GLOBALS['objs'] = [];
function IPS_GetChildrenIDs($id)
{
    $r = [];
    foreach ($GLOBALS['objs'] as $cid => $o) {
        if (($o['ParentID'] ?? null) === $id) {
            $r[] = $cid;
        }
    }
    return $r;
}
function IPS_GetObject($id) { return $GLOBALS['objs'][$id] ?? ['ObjectIdent' => '', 'ObjectType' => -1]; }
function IPS_GetVariable($id) { return $GLOBALS['objs'][$id] ?? []; }
function IPS_SetPosition($id, $pos) { if (isset($GLOBALS['objs'][$id])) { $GLOBALS['objs'][$id]['ObjectPosition'] = $pos; } }
function IPS_SetParent($id, $pid) { if (isset($GLOBALS['objs'][$id])) { $GLOBALS['objs'][$id]['ParentID'] = $pid; } }
function IPS_SetName($id, $n) { if (isset($GLOBALS['objs'][$id])) { $GLOBALS['objs'][$id]['ObjectName'] = $n; } }
function IPS_SetIdent($id, $ident) { if (isset($GLOBALS['objs'][$id])) { $GLOBALS['objs'][$id]['ObjectIdent'] = $ident; } }
function IPS_CreateCategory() { $id = 30000 + count($GLOBALS['objs']); $GLOBALS['objs'][$id] = ['ObjectIdent' => '', 'ObjectType' => 0, 'ParentID' => 0]; return $id; }
function IPS_SetVariableCustomProfile($id, $p) { if (isset($GLOBALS['objs'][$id])) { $GLOBALS['objs'][$id]['VariableCustomProfile'] = $p; } }
function IPS_SetInfo($id, $s) {}
function SetValueBoolean($id, $v) { $GLOBALS['objs'][$id]['Value'] = $v; }
function SetValueString($id, $v) { $GLOBALS['objs'][$id]['Value'] = $v; }
function SetValueInteger($id, $v) { $GLOBALS['objs'][$id]['Value'] = $v; }
function IPS_DeleteVariable($id) { unset($GLOBALS['objs'][$id]); }
function IPS_GetName($id) { return 'Name' . $id; }
function IPS_SemaphoreEnter($n, $ms) { return true; }
function IPS_SemaphoreLeave($n) {}
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
    public function RegisterAttributeString($n, $d) { if (!isset($this->attr[$n])) $this->attr[$n] = $d; }
    public $attrb = [];
    public function RegisterAttributeBoolean($n, $d) { if (!isset($this->attrb[$n])) $this->attrb[$n] = $d; }
    public function ReadAttributeBoolean($n) { return $this->attrb[$n] ?? false; }
    public function WriteAttributeBoolean($n, $v) { $this->attrb[$n] = $v; }
    public $attri = [];
    public function RegisterAttributeInteger($n, $d) { if (!isset($this->attri[$n])) $this->attri[$n] = $d; }
    public function ReadAttributeInteger($n) { return $this->attri[$n] ?? 0; }
    public function WriteAttributeInteger($n, $v) { $this->attri[$n] = $v; }
    public $logs = [];
    public function LogMessage($text, $type) { $this->logs[] = [$type, $text]; }
    public function EnableAction($ident) {}
    public $formFields = [];
    public function UpdateFormField($name, $prop, $value) { $this->formFields[$name][$prop] = $value; }
    public function GetIDForIdent($ident)
    {
        foreach ($GLOBALS['objs'] as $id => $o) {
            if (($o['ParentID'] ?? null) === $this->InstanceID && $o['ObjectIdent'] === $ident) {
                return $id;
            }
        }
        return false;
    }
    private function createVar($ident, $pos, $type)
    {
        $vid = 20000 + count($GLOBALS['objs']);
        $GLOBALS['objs'][$vid] = ['ObjectIdent' => $ident, 'ObjectType' => 2, 'ParentID' => $this->InstanceID, 'ObjectPosition' => $pos, 'VariableType' => $type, 'VariableCustomProfile' => ''];
        return $vid;
    }
    public function RegisterVariableFloat($ident, $name, $profile, $pos) { return $this->createVar($ident, $pos, 2); }
    public function RegisterVariableInteger($ident, $name, $profile, $pos) { return $this->createVar($ident, $pos, 1); }
    public function RegisterVariableBoolean($ident, $name, $profile, $pos) { return $this->createVar($ident, $pos, 0); }
    public function RegisterVariableString($ident, $name, $profile, $pos) { return $this->createVar($ident, $pos, 3); }
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
    function GetValue($id) { return $GLOBALS['objs'][$id]['Value'] ?? -1234; }
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
    public $r = []; public $w = []; public $reads = [];
    function set($a, $vals) { foreach ($vals as $i => $v) $this->r[$a + $i] = $v; }
    function readInput($a, $n) { return $this->readHolding($a, $n); }
    function u32sw($x, $o) { return (($x[$o + 1] & 0xFFFF) << 16) | ($x[$o] & 0xFFFF); }
    function readHolding($a, $n) { $this->reads[] = $a; $o = []; for ($i = 0; $i < $n; $i++) { if (!isset($this->r[$a + $i])) return null; $o[] = $this->r[$a + $i]; } return $o; }
    function u16($x, $o) { return $x[$o] & 0xFFFF; }
    function u32($x, $o) { return (($x[$o] & 0xFFFF) << 16) | ($x[$o + 1] & 0xFFFF); }
    function readStr($x, $o, $n) { $s = ''; for ($i = 0; $i < $n; $i++) $s .= chr(($x[$o + $i] >> 8) & 255) . chr($x[$o + $i] & 255); return rtrim($s, "\0 "); }
    function writeSingle($a, $v) { $this->w[$a] = $v; return true; }
    function writeMultiple($a, $v) { $this->w[$a] = $v[0]; return true; }
}
class FakeHub {
    public $v = []; public $cp = 2;
    function GetChargePointNo() { return $this->cp; }
    public $groups = [];
    function GroupActive($g) { return $this->groups[$g] ?? true; }
    public $notes = [];
    function LogNote($m) { $this->notes[] = $m; }
    public $phaseInfo = null;
    function SetPhaseInfo($p, $s) { $this->phaseInfo = [$p, $s]; }
    function GetMaxCurrentA() { return 16; }
    function GetVarValue($i) { return $this->v[$i] ?? ''; }
    public $hidden = [];
    public $flags = []; public $via = 'cmd';
    function GetDaheimEnableVia() { return $this->via; }
    function SetVarHidden($i, $h) { $this->hidden[$i] = $h; }
    function GetDriverFlag($k) { return $this->flags[$k] ?? false; }
    function SetDriverFlag($k, $v) { $this->flags[$k] = $v; }
    function SetVarBool($i, $x) { $this->v[$i] = $x; } function SetVarInt($i, $x) { $this->v[$i] = $x; }
    function SetVarFloat($i, $x) { $this->v[$i] = $x; } function SetVarStr($i, $x) { $this->v[$i] = $x; }
}
$mb = new FakeMb(); $ch = new FakeHub(); $drv = new CharxDriver();
$b = 2000;
$mb->set($b + 299, [(ord('C') << 8) | ord('2')]);
$mb->set($b + 244, [0x0000, 0x2B67]);          // 11111 mW
$mb->set($b + 250, [0x0000, 0x0000, 0x0001, 0x86A0]); // 100000 Wh (INT64 über 4 Register)
$mb->set($b + 285, [0, 5430]); $mb->set($b + 287, [0, 3600]);
$mb->set($b + 289, [0, 0, 0x0000, 0x0BB8]);    // 3000 Wh
$mb->set($b + 232, [3, 33392, 3, 34392, 3, 35392]); // 230000/231000/232000 mV (MSW, LSW)
$mb->set($b + 238, [0, 10500, 0, 10600, 0, 10700]);
$mb->set($b + 300, [1]); $mb->set($b + 301, [13]); $mb->set($b + 120, [5]); $mb->set($b + 297, [7]);
$mb->set($b + 113, [0x4142, 0x4344, 0x4546]); $mb->set(110, [0x312E, 0x3900, 0, 0]);
$mb->set($b + 293, [0, 0x0040]);
check('CHARX lesen: Status/Leistung/Energie', $drv->readValues($mb, $ch) === true && $ch->v['state'] === 31 && $ch->v['vehicle_plugged'] === true && abs($ch->v['power'] - 11.111) < 0.001 && $ch->v['energy_total'] === 100.0 && $ch->v['energy_session'] === 3.0);
check('CHARX lesen: Spannung/Strom je Phase, Freigabe, Limit', $ch->v['voltage_l2'] === 231.0 && abs($ch->v['current_l3'] - 10.7) < 1e-9 && $ch->v['ctl_enable'] === true && $ch->v['ctl_curr_limit'] === 13 && $ch->v['release_mode'] === 5);
check('CHARX lesen: angewandtes Limit x297 = 7 A', ($ch->v['actual_curr'] ?? null) === 7.0);
check('CHARX lesen: Energie gesamt als INT64 (Hochwort 0, Tiefwort 100000 Wh)', $ch->v['energy_total'] === 100.0);
check('CHARX lesen: Verbindungszeit x285 / Ladezeit x287', ($ch->v['connection_time_sek'] ?? null) === 5430 && ($ch->v['ladezeit_sek'] ?? null) === 3600);
check('CHARX lesen: UID/Fehlercode', $ch->v['dev_serial'] === 'ABCDEF' && $ch->v['error_code'] === 0x40);
$ch->v['ctl_enable'] = true;
$drv->writeControl($mb, $ch, 'ctl_curr_limit', 4);
check('CHARX schreiben: Limit auf x301, mind. 6 A', $mb->w === [2301 => 6]);
$mb->w = []; $drv->writeControl($mb, $ch, 'ctl_curr_limit', 60); check('CHARX schreiben: Limit gedeckelt (Max 16 A)', $mb->w === [2301 => 16]);
$mb->w = []; $ch->v['ctl_curr_limit'] = 13; $drv->writeControl($mb, $ch, 'ctl_enable', false); check('CHARX schreiben: Sperre = 0 nach x301 (wie evcc), nicht x300', $mb->w === [2301 => 0] && $ch->v['ctl_enable'] === false);
$mb->w = []; $drv->writeControl($mb, $ch, 'ctl_curr_limit', 10);
check('CHARX: Limit bei gesperrter Freigabe nur gemerkt, nicht geschrieben', $mb->w === [] && $ch->v['ctl_curr_limit'] === 10);
$mb->w = []; $drv->writeControl($mb, $ch, 'ctl_enable', true);
check('CHARX schreiben: Freigabe = gemerktes Limit (10 A) nach x301', $mb->w === [2301 => 10] && $ch->v['ctl_enable'] === true);
$rb2 = new FakeMb(); $rh2 = new FakeHub();
$rb2->set(2299, [(ord('B') << 8) | ord('1')]); $rb2->set(2301, [0]);
$drv->readValues($rb2, $rh2);
check('CHARX lesen: x301 = 0 -> Freigabe aus', $rh2->v['ctl_enable'] === false && !isset($rh2->v['ctl_curr_limit']));
$mb2 = new FakeMb();
check('CHARX ohne Antwort: connected false', $drv->readValues($mb2, new FakeHub()) === false);

// DaheimLader: nicht lesbares Register 0x300A -> Schalter ausgeblendet, lesbar -> sichtbar.
$dmb = new FakeMb(); $dh = new FakeHub(); $dd = new DaheimLaderDriver();
for ($i = 0; $i < 114; $i++) { $dmb->r[$i] = 0; }
$dmb->r[0] = 1;
$dmb->reads = []; $dd->readValues($dmb, $dh);
check('DaheimLader: 0x300A nicht lesbar -> Variable ausgeblendet', ($dh->hidden['ctl_auto_phase_switch'] ?? null) === true && !isset($dh->v['ctl_auto_phase_switch']));
$n1 = count(array_filter($dmb->reads, fn ($a) => $a === 0x300A));
$dmb->reads = []; $dd->readValues($dmb, $dh);
$n2 = count(array_filter($dmb->reads, fn ($a) => $a === 0x300A));
check('DaheimLader: 0x300A nach Fehlschlag nicht erneut abgefragt (1x, dann 0x)', $n1 === 1 && $n2 === 0 && $dh->flags['no_auto_phase_switch'] === true);
$dh2 = new FakeHub(); $dmb->r[0x300A] = 1; $dd->readValues($dmb, $dh2);
check('DaheimLader: 0x300A lesbar -> sichtbar, Wert übernommen', ($dh2->hidden['ctl_auto_phase_switch'] ?? null) === false && $dh2->v['ctl_auto_phase_switch'] === true);

// Schreibprotokoll: Roh-Anfrage (FC16, Unit 255, Reg 95, Wert 1) und Antwort bit-genau erfasst.
if (function_exists('pcntl_fork')) {
    $srv = stream_socket_server('tcp://127.0.0.1:0', $en, $es);
    $port = (int)substr(strrchr(stream_socket_get_name($srv, false), ':'), 1);
    $tc = new CHUB_ModbusTcpClient('127.0.0.1', $port, 255);
    $pid = pcntl_fork();
    if ($pid === 0) {
        $c = stream_socket_accept($srv, 5); $req = fread($c, 64);
        fwrite($c, substr($req, 0, 2) . "\x00\x00\x00\x06\xff\x10\x00\x5f\x00\x01"); fclose($c); exit(0);
    }
    $okw = $tc->writeMultiple(95, [1]);
    pcntl_waitpid($pid, $st2);
    $tr = $tc->writeTrace[0] ?? '';
    check('Schreibprotokoll: Anfrage FC16 Reg 95 = 1, Unit 255, bit-genau', $okw === true && preg_match('/^FC16 Reg 95 Werte \\[1\\] \\| Unit 255 \\| Anfrage [0-9a-f]{4}00000009ff10005f0001020001 \\|/', $tr) === 1);
    check('Schreibprotokoll: Antwort erfasst', preg_match('/Antwort [0-9a-f]{4}00000006ff10005f0001$/', $tr) === 1);
} else {
    echo "SKIP Schreibprotokoll (pcntl nicht verfügbar)\n";
}

// DaheimLader: Ladefreigabe über Ladebefehl (95: 1/2, Standard) oder Stromlimit (91, wie evcc: Freigabe >= 6 A, Sperre 0,1 A).
$wm = new FakeMb(); $wh = new FakeHub(); $wh->v['ctl_curr_limit'] = 10;
$dd->writeControl($wm, $wh, 'ctl_enable', true);  $a = $wm->w;
$dd->writeControl($wm, $wh, 'ctl_enable', false); $b = $wm->w;
check('DaheimLader Standard: Freigabe über Register 95 (1/2), Register 91 unberührt', $a === [95 => 1] && $b === [95 => 2]);
$wm = new FakeMb(); $wh = new FakeHub(); $wh->via = 'limit'; $wh->v['ctl_curr_limit'] = 10; $wh->v['ctl_enable'] = false;
$dd->writeControl($wm, $wh, 'ctl_curr_limit', 12);
check('DaheimLader Limit-Modus: Limit bei gesperrter Freigabe nur gemerkt, nicht geschrieben', $wm->w === [] && $wh->v['ctl_curr_limit'] === 12);
$dd->writeControl($wm, $wh, 'ctl_enable', true);
check('DaheimLader Limit-Modus: Freigabe schreibt Limit x10 in Register 91 (12 A = 120)', $wm->w === [91 => 120]);
$dd->writeControl($wm, $wh, 'ctl_enable', false);
check('DaheimLader Limit-Modus: Sperre schreibt 1 (0,1 A) in Register 91, nicht 0', $wm->w === [91 => 1]);
$rb = new FakeMb(); $rh = new FakeHub(); $rh->via = 'limit';
for ($i = 0; $i < 114; $i++) { $rb->r[$i] = 0; } $rb->r[0] = 1; $rb->r[91] = 100;
$dd->readValues($rb, $rh);
check('DaheimLader Limit-Modus: Rücklesen Register 91 = 100 -> Freigabe an, 10 A', $rh->v['ctl_enable'] === true && $rh->v['ctl_curr_limit'] === 10);
$rb->r[91] = 1; $dd->readValues($rb, $rh);
check('DaheimLader Limit-Modus: Rücklesen Register 91 = 1 -> Freigabe aus', $rh->v['ctl_enable'] === false);

// Dauerhafte Verbindung (CHUB_ModbusTcpClient::persistent): zwei Schreibzugriffe laufen über
// dieselbe wiederverwendete TCP-Verbindung statt über zwei getrennte (Fund sieckendieck).
$srv2 = stream_socket_server('tcp://127.0.0.1:0', $en2, $es2);
$port2 = (int)substr(strrchr(stream_socket_get_name($srv2, false), ':'), 1);
$pc = new CHUB_ModbusTcpClient('127.0.0.1', $port2, 1);
$pc->persistent = true;
$pid2 = pcntl_fork();
if ($pid2 === 0) {
    $c = stream_socket_accept($srv2, 5); // genau EINE eingehende Verbindung für beide Schreibzugriffe
    for ($i = 0; $i < 2; $i++) {
        $req = fread($c, 64);
        if ($req === false || $req === '') { exit(1); }
        fwrite($c, substr($req, 0, 2) . "\x00\x00\x00\x06\x01\x10\x00\x5f\x00\x01");
    }
    fclose($c);
    exit(0);
}
$r1 = $pc->writeMultiple(95, [1]);
$r2 = $pc->writeMultiple(95, [1]);
pcntl_waitpid($pid2, $st3);
check('Dauerhafte Verbindung: zwei Schreibzugriffe über dieselbe (geteilte) Verbindung', $r1 === true && $r2 === true && pcntl_wexitstatus($st3) === 0);

// Symcon-Review-Fund (SUITE.md 9m, Referenz InverterHub-Fix, 28.09.2026): Position nur bei
// Neuanlage einer Variable setzen, nicht bei jedem ApplyChanges — sonst wirft ein „Übernehmen"
// eine manuelle Umsortierung im Objektbaum wieder auf die feste Reihenfolge zurück.
$rvHub = new ChargerHub(777);
$rv = new ReflectionMethod($rvHub, 'RegisterVar');
$fvbi = new ReflectionMethod($rvHub, 'FindVarByIdent');
$def = ['connected', 'Verbindung', 'B', '', false, 'errors', ''];
$rv->invoke($rvHub, $def, 5);
$vid = $fvbi->invoke($rvHub, 'connected');
check('RegisterVar: Neuanlage bekommt die übergebene Position', $vid && $GLOBALS['objs'][$vid]['ObjectPosition'] === 5);
// Nutzer sortiert im Objektbaum manuell um ...
$GLOBALS['objs'][$vid]['ObjectPosition'] = 42;
// ... und ein zweites ApplyChanges (andere Positionsnummer, wie bei einer echten Neuberechnung) darf das nicht zurücksetzen.
$rv->invoke($rvHub, $def, 9);
check('RegisterVar: Position bei erneutem ApplyChanges NICHT zurückgesetzt (Symcon-Review-Fund 9m)', $GLOBALS['objs'][$vid]['ObjectPosition'] === 42);

// „Auswahlfelder: Zeile folgt der Auswahl“ (SUITE.md 21.09.2026, EMS-Fund 28.09.2026): die
// Dubletten-Statuszeile muss der noch nicht übernommenen Formularauswahl folgen, nicht der
// gespeicherten Property.
$dcHub = new ChargerHub(778);
$dcHub->prop = ['Active' => true, 'DuplicateOfKey' => '']; // gespeichert: keine Zuordnung
$dcHub->RequestAction('DuplicateOfKeyChanged', 'ocpphub:999'); // Auswahl im Formular: Ziel #999 (existiert nicht)
check('DuplicateOfKeyChanged: Zeile folgt der Auswahl (⚠️ fehlendes Ziel), nicht der gespeicherten Property (ℹ️)', ($dcHub->formFields['LinkDuplicateStatus']['caption'] ?? '') !== '' && strpos($dcHub->formFields['LinkDuplicateStatus']['caption'], '⚠️') === 0);
$dcHub->RequestAction('DuplicateOfKeyChanged', '');
check('DuplicateOfKeyChanged: Auswahl zurück auf leer -> ℹ️-Zeile', strpos($dcHub->formFields['LinkDuplicateStatus']['caption'], 'ℹ️') === 0);

// Angesteckt-/Abgesteckt-Zeit (Wunsch Mstaudi): aus dem Wechsel von „Fahrzeug verbunden“ abgeleitet.
$GLOBALS['objs'][40001] = ['ObjectIdent' => 'vehicle_plugged', 'ObjectType' => 2, 'ParentID' => 779];
$GLOBALS['objs'][40002] = ['ObjectIdent' => 'plugged_in_at',  'ObjectType' => 2, 'ParentID' => 779, 'Value' => 0];
$GLOBALS['objs'][40003] = ['ObjectIdent' => 'plugged_out_at', 'ObjectType' => 2, 'ParentID' => 779, 'Value' => 0];
$pt = new ChargerHub(779);
$pt->prop = ['Manufacturer' => 'peblar', 'PlugTimes' => true];
$pt->RegisterAttributeInteger('PlugState', -1);
$pt->SetVarBool('vehicle_plugged', false);
check('Zeiten: erster gesehener Zustand setzt KEINEN Zeitstempel', $GLOBALS['objs'][40002]['Value'] === 0 && $GLOBALS['objs'][40003]['Value'] === 0);
$pt->SetVarBool('vehicle_plugged', true);
$inAt = $GLOBALS['objs'][40002]['Value'];
check('Zeiten: Wechsel auf angesteckt setzt „Angesteckt um“ (jetzt)', abs($inAt - time()) <= 5 && $GLOBALS['objs'][40003]['Value'] === 0);
$GLOBALS['objs'][40002]['Value'] = 123;
$pt->SetVarBool('vehicle_plugged', true);
check('Zeiten: gleicher Zustand beim nächsten Poll ändert nichts', $GLOBALS['objs'][40002]['Value'] === 123);
$pt->SetVarBool('vehicle_plugged', false);
check('Zeiten: Wechsel auf abgesteckt setzt „Abgesteckt um“, „Angesteckt um“ bleibt', abs($GLOBALS['objs'][40003]['Value'] - time()) <= 5 && $GLOBALS['objs'][40002]['Value'] === 123);
$pt2 = new ChargerHub(779);
$pt2->prop = ['Manufacturer' => 'peblar', 'PlugTimes' => true];
$pt2->RegisterAttributeInteger('PlugState', -1);
$GLOBALS['objs'][40002]['Value'] = 0; $GLOBALS['objs'][40003]['Value'] = 0;
$pt2->SetVarBool('vehicle_plugged', true);
check('Zeiten: nach Neustart/Einschalten (Zustand unbekannt) steckendes Fahrzeug bekommt keine Zeit', $GLOBALS['objs'][40002]['Value'] === 0);
$pt3 = new ChargerHub(779);
$pt3->prop = ['Manufacturer' => 'peblar', 'PlugTimes' => false];
$pt3->RegisterAttributeInteger('PlugState', 0);
$pt3->SetVarBool('vehicle_plugged', true);
check('Zeiten: Option aus -> nie Zeitstempel', $GLOBALS['objs'][40002]['Value'] === 0);
$pt4 = new ChargerHub(779);
$pt4->prop = ['Manufacturer' => 'alfen', 'PlugTimes' => true];
check('Zeiten: Alfen hat kein „Fahrzeug verbunden“ -> Funktion nicht aktiv', (new ReflectionMethod($pt4, 'PlugTimesActive'))->invoke($pt4) === false);

// Vertrag 1.7: Zeit-Referenzfelder (0 = Variable nicht vorhanden).
$GLOBALS['objs'][40010] = ['ObjectIdent' => 'plugged_in_at',        'ObjectType' => 2, 'ParentID' => 780];
$GLOBALS['objs'][40011] = ['ObjectIdent' => 'ladezeit_sek',         'ObjectType' => 2, 'ParentID' => 780];
$gf = new ChargerHub(780);
$gf->prop = ['Manufacturer' => 'charx'];
$gf->RegisterAttributeInteger('LastSeenAt', 0);
$fn7 = $gf->GetFunctions()[0];
check('Vertrag 1.7-Felder bleiben im aktuellen Vertrag (contractVersion >= 1.7)', version_compare($fn7['contractVersion'], '1.7', '>='));
check('Vertrag 1.7: vorhandene Zeit-Variablen als ID, fehlende als 0', $fn7['pluggedInAtID'] === 40010 && $fn7['chargeTimeSecID'] === 40011 && $fn7['pluggedOutAtID'] === 0 && $fn7['connectionTimeSecID'] === 0);

// Store-Review-Konvention (12.10.2026): Logging nur über $this->LogMessage(), nie über die globale
// Log-Funktion. Strukturtest über alle Moduldateien + Funktionstest für den Callback der Bibliotheksklasse.
$logFree = true;
foreach (['ChargerHub/module.php', 'ChargerHubBridge/module.php', 'ChargerHubDiscovery/module.php'] as $f) {
    if (strpos(file_get_contents(__DIR__ . '/../' . $f), 'IPS_LogMessage' . '(') !== false) {
        $logFree = false;
        echo "  Fundstelle: $f\n";
    }
}
check('Logging: kein IPS_LogMessage( in den Moduldateien (nur $this->LogMessage)', $logFree);
$lg = new CHUB_ModbusGatewayClient('', 0, 1, fn ($p) => '');
$captured = [];
$lg->logger = function (string $m) use (&$captured) { $captured[] = $m; };
$lg->writeMultiple(95, [1]);
$lg->writeMultiple(95, [1]);
check('Logging: Gateway-Client meldet den Schreib-Hinweis über den Callback (einmal)', count($captured) === 1 && strpos($captured[0], 'Symbox-Gateway') === 0);
$lh = new ChargerHub(781);
$lh->prop = ['Active' => true, 'DemoMode' => true];
$lh->RequestAction('ctl_enable', true);
check('Logging: Vorführmodus loggt per LogMessage (KL_WARNING)', ($lh->logs[0][0] ?? null) === KL_WARNING && strpos($lh->logs[0][1], 'Vorführmodus') === 0);

// Gerätetausch (vormals MigrationsHub): neuer Präfix GTAUSCH_ wird von der Suche genutzt (13.10.2026).
function GTAUSCH_FindLegacyCandidates($id, $host, $port = 0, $unitId = 0, $excl = 0)
{
    $GLOBALS['gtauschCall'] = [$id, $host, $port, $unitId, $excl];
    return [['InstanceID' => 51234, 'Name' => 'Alte Wallbox']];
}
$GLOBALS['byGuid']['{330717BB-E309-41A2-90A8-FDA3179ED948}'] = [900];
$GLOBALS['inst'][51234] = ['ModuleInfo' => ['ModuleID' => '{OTHER}']];
require __DIR__ . '/../ChargerHubDiscovery/module.php';
$disc = new ChargerHubDiscovery(782);
$disc->prop = ['Port' => 502];
$lc = new ReflectionMethod($disc, 'LegacyCandidateFor');
$res = $lc->invoke($disc, '192.168.1.50', 255, 777);
check('Gerätetausch: GTAUSCH_FindLegacyCandidates wird mit unveränderten Parametern genutzt', ($GLOBALS['gtauschCall'] ?? null) === [900, '192.168.1.50', 502, 255, 777] && $res['id'] === 51234 && $res['ambiguous'] === false);
$srcDisc = file_get_contents(__DIR__ . '/../ChargerHubDiscovery/module.php');
check('Gerätetausch: neuer Präfix zuerst, alter MIGHUB_ als Rückfall (Suche und Vorbereiten)',
    strpos($srcDisc, "function_exists('GTAUSCH_FindLegacyCandidates')") < strpos($srcDisc, "elseif (function_exists('MIGHUB_FindLegacyCandidates')")
    && strpos($srcDisc, "function_exists('GTAUSCH_PrefillMigration')") < strpos($srcDisc, "elseif (function_exists('MIGHUB_PrefillMigration')"));

// Store-Review-Funde (EMS, 18.10.2026): (1) Presentation-OPTIONS-Farbschlüssel hängen von der Darstellung ab
// (Wertanzeige kennt nur ColorActive/ColorValue, ein 'Color' bricht in Symcon 9.1 das Anlegen ab). ChargerHub
// nutzt klassische Variablenprofile statt Presentation-OPTIONS; der Test hält das fest, bis das jemand ändert.
// (2) Variablen-Pflege in ApplyChanges darf nicht von Fremdmodul-Abfragen abhängen (löscht sonst beim
// Symcon-Start, wenn die Fremdinstanz noch nicht instanziiert ist).
$noPresentation = true;
foreach (['ChargerHub/module.php', 'ChargerHubBridge/module.php', 'ChargerHubDiscovery/module.php'] as $f) {
    $src = file_get_contents(__DIR__ . '/../' . $f);
    if (preg_match("/'(ColorValue|ColorActive|VALUE_PRESENTATION|PRESENTATION_)/", $src) || stripos($src, 'SetVariableCustomPresentation') !== false) {
        $noPresentation = false;
        echo "  Fundstelle Presentation: $f\n";
    }
}
check('Presentation: keine Presentation-OPTIONS/Farbschlüssel in den Moduldateien (klassische Profile)', $noPresentation);
$rvm = new ReflectionMethod('ChargerHub', 'RegisterVariables');
$rvLines = array_slice(file($rvm->getFileName()), $rvm->getStartLine() - 1, $rvm->getEndLine() - $rvm->getStartLine() + 1);
$rvBody = implode('', $rvLines);
check('ApplyChanges-Variablenpflege: RegisterVariables() fragt keine Fremdmodule ab (function_exists/GetFunctions/Instanzliste)',
    strpos($rvBody, 'function_exists(') === false && strpos($rvBody, '_GetFunctions(') === false && strpos($rvBody, 'IPS_GetInstanceListByModuleID') === false);

// go-e: vertauschte Wortreihenfolge der 32-Bit-Werte wird an der Netzspannung ERKANNT und gemeldet, aber nicht
// ausgeglichen (Entscheidung Dietmar, 20.10.2026; Fund baraka/Adam): keine falschen Werte in Variablen/Archiv.
$goe = new GoeChargerDriver();
function goeRegs(FakeMb $m, array $volts, bool $swapped) {
    $m->set(100, [2]);
    foreach ($volts as $i => $v) { $m->set(108 + $i * 2, $swapped ? [$v, 0] : [0, $v]); }
    foreach ([0, 1, 2] as $i) { $m->set(114 + $i * 2, $swapped ? [62, 0] : [0, 62]); }  // 6,2 A (0,1 A)
    $m->set(128, $swapped ? [0x73B5, 0x0001] : [0x0001, 0x73B5]);                         // 95157 = 9515,7 kWh
}
$gm = new FakeMb(); goeRegs($gm, [224, 223, 225], true);
$gh = new FakeHub(); $gh->groups['GoeWordSwap'] = false;
$goe->readValues($gm, $gh);
check('go-e: vertauschte Wortreihenfolge (Spannung [V, 0]) erkannt, Merker gesetzt, Hinweis einmal im Log', ($gh->flags['goe_order_mismatch'] ?? false) === true && count($gh->notes) === 1 && strpos($gh->notes[0], 'Register tauschen') !== false);
check('go-e: bei Vertauschung KEINE 32-Bit-Werte in Variablen (keine 14 Mio. V, kein Archiv-Müll), Status weiter gelesen', !isset($gh->v['voltage_l1']) && !isset($gh->v['current_l1']) && !isset($gh->v['energy_total']) && ($gh->v['state'] ?? null) === 2);
$gh->notes = []; $goe->readValues($gm, $gh);
check('go-e: kein erneuter Hinweis bei folgenden Polls', count($gh->notes) === 0);
$gm2 = new FakeMb(); goeRegs($gm2, [224, 223, 225], false);
$gh2 = new FakeHub(); $gh2->groups['GoeWordSwap'] = false;
$goe->readValues($gm2, $gh2);
check('go-e: Standardreihenfolge ([0, V]) liest normal (224 V, 6,2 A, 9515,7 kWh), kein Hinweis', empty($gh2->flags['goe_order_mismatch']) && count($gh2->notes) === 0 && ($gh2->v['voltage_l1'] ?? null) === 224.0 && abs(($gh2->v['current_l1'] ?? 0) - 6.2) < 1e-9 && abs(($gh2->v['energy_total'] ?? 0) - 9515.7) < 1e-6);
$gm3 = new FakeMb(); goeRegs($gm3, [0, 0, 0], false);
$gh3 = new FakeHub(); $gh3->groups['GoeWordSwap'] = false;
$goe->readValues($gm3, $gh3);
check('go-e: ohne Spannung (0 V) keine Entscheidung und kein Hinweis', empty($gh3->flags['goe_order_mismatch']) && count($gh3->notes) === 0);
$gm4 = new FakeMb(); goeRegs($gm4, [224, 223, 225], true);
$gh4 = new FakeHub(); $gh4->groups['GoeWordSwap'] = true; // Nutzer erzwingt „getauscht“
$goe->readValues($gm4, $gh4);
check('go-e: erzwungene Option liest vertauschte Werte richtig (224 V, 9515,7 kWh), keine Erkennung/kein Hinweis', empty($gh4->flags['goe_order_mismatch']) && count($gh4->notes) === 0 && ($gh4->v['voltage_l1'] ?? null) === 224.0 && abs(($gh4->v['energy_total'] ?? 0) - 9515.7) < 1e-6);
$gm5 = new FakeMb(); goeRegs($gm5, [224, 223, 225], true);
$gh5 = new FakeHub(); $gh5->groups['GoeWordSwap'] = false;
$goe->readValues($gm5, $gh5);
$gm6 = new FakeMb(); goeRegs($gm6, [224, 223, 225], false);
$gh5->notes = []; $goe->readValues($gm6, $gh5);
check('go-e: nach Korrektur am Gerät folgt die Erkennung ohne Übernehmen (Merker zurück, Werte wieder da, ein Hinweis)', ($gh5->flags['goe_order_mismatch'] ?? true) === false && count($gh5->notes) === 1 && ($gh5->v['voltage_l1'] ?? null) === 224.0 && abs(($gh5->v['energy_total'] ?? 0) - 9515.7) < 1e-6);
// Firmware-Version als 4-Byte-Zeichenkette hängt an derselben Wortreihenfolge (Adam: FW 56.2 kam als „.256“).
$fm = new FakeMb(); $fm->set(100, [2]); $fm->set(105, [0x2E32, 0x3536]); // „.2“ „56“ = vertauscht
$fh = new FakeHub(); $fh->groups['GoeWordSwap'] = true;
$goe->readValues($fm, $fh);
check('go-e Firmware-String: bei erzwungener Option Wörter getauscht -> „56.2“ statt „.256“', ($fh->v['dev_firmware'] ?? null) === '56.2');
$fm2 = new FakeMb(); $fm2->set(100, [2]); $fm2->set(105, [0x3536, 0x2E32]);
$fh2 = new FakeHub(); $fh2->groups['GoeWordSwap'] = false;
$goe->readValues($fm2, $fh2);
check('go-e Firmware-String: Standardreihenfolge -> „56.2“', ($fh2->v['dev_firmware'] ?? null) === '56.2');
$fh3 = new FakeHub(); $fh3->groups['GoeWordSwap'] = false; $fh3->flags['goe_order_mismatch'] = true;
$fm3 = new FakeMb(); $fm3->set(100, [2]); $fm3->set(105, [0x2E32, 0x3536]);
$goe->readValues($fm3, $fh3);
check('go-e Firmware-String: bei erkannter, nicht ausgeglichener Vertauschung nicht übernommen', !isset($fh3->v['dev_firmware']));

// Leistung je Phase: Einheit 0,1 W (Rohwert 40000 = 4000 W), vorher 1000-fach zu groß (4 000 000 W).
$pm = new FakeMb(); $pm->set(100, [2]);
$pm->set(146, [0, 40000]); $pm->set(148, [0, 40000]); $pm->set(150, [0, 39000]);
$pm->set(120, [0x0006, 0x4190 + 0]);                 // Gesamtleistung 0,01 W: 0x00064190 = 410000 = 4100 W
$ph = new FakeHub(); $ph->groups['GoeWordSwap'] = false;
$goe->readValues($pm, $ph);
check('go-e Leistung je Phase: Rohwert 40000 = 4000 W (0,1 W), nicht 4 000 000 W', ($ph->v['power_l1'] ?? null) === 4000.0 && ($ph->v['power_l2'] ?? null) === 4000.0 && ($ph->v['power_l3'] ?? null) === 3900.0);

// Sichtbarer Hinweis im Formular
$hf = new ChargerHub(783);
$hf->prop = ['Manufacturer' => 'goe'];
$hi = new ReflectionMethod($hf, 'GoeOrderHintItems');
check('go-e Formularhinweis: ohne Merker kein Hinweis', $hi->invoke($hf) === []);
$hf->RegisterAttributeString('DriverFlags', '{}');
$hf->SetDriverFlag('goe_order_mismatch', true);
$items = $hi->invoke($hf);
check('go-e Formularhinweis: bei Vertauschung ⚠️-Zeile mit App-Anleitung (Register tauschen) und „NICHT übernommen“', count($items) === 1 && strpos($items[0]['caption'], '⚠️') === 0 && strpos($items[0]['caption'], 'Register tauschen') !== false && strpos($items[0]['caption'], 'API Einstellungen') !== false && strpos($items[0]['caption'], 'NICHT übernommen') !== false);
$hf->prop['GoeWordSwap'] = true;
check('go-e Formularhinweis: bei erzwungener Option kein Hinweis', $hi->invoke($hf) === []);

// MeterHub „Virtueller Zähler“ (MHUBV_) als Netzzähler-Quelle (Stefan/somm: Netzbetreiber-Zähler per MQTT-Variable).
function MHUBV_GetFunctions($id) { return json_encode(['assignments' => [['function' => 'grid', 'latency' => 'realtime', 'authority' => 'auxiliary', 'powerID' => 600]]]); }
$GLOBALS['byGuid']['{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}'] = [];            // kein normaler MeterHub vorhanden
$GLOBALS['byGuid']['{ADF18291-2E60-4354-92F5-B96863C127C8}'] = [88];          // nur ein Virtueller Zähler
$GLOBALS['inst'][88] = ['ModuleInfo' => ['ModuleID' => '{ADF18291-2E60-4354-92F5-B96863C127C8}']];
$vm = new ChargerHub(784);
$vm->prop = ['SurplusMeterID' => 0];
$gmv = new ReflectionMethod($vm, 'GridMeterIsAutomatic');
check('Virtueller Zähler: ohne MeterHub-Instanz als Netzzähler automatisch gefunden', $gmv->invoke($vm) === true);
$vls = new ReflectionMethod($vm, 'LinkStatusLines');
$vline = $vls->invoke($vm)['LinkGridStatus'];
check('Virtueller Zähler: Statuszeile 🔗 mit Wert (+ = Bezug, -1234 = 1234 W Überschuss)', strpos($vline, '🔗 Netzzähler:') === 0 && strpos($vline, '1234 W') !== false);
$vm->prop = ['SurplusMeterID' => 88];
check('Virtueller Zähler: von Hand gewählt (✏️), Instanz wird über ihre Modul-GUID erkannt', $gmv->invoke($vm) === false && strpos($vls->invoke($vm)['LinkGridStatus'], '✏️') === 0 && strpos($vls->invoke($vm)['LinkGridStatus'], '1234 W') !== false);
$GLOBALS['byGuid']['{ADF18291-2E60-4354-92F5-B96863C127C8}'] = [];
$vm->prop = ['SurplusMeterID' => 0];
check('Virtueller Zähler: ohne jede Zähler-Instanz keine Quelle', $gmv->invoke($vm) === false && strpos($vls->invoke($vm)['LinkGridStatus'], 'ℹ️') === 0);

// Vertrag 1.8 (EMS-Anfrage 21.10.2026): phaseModeID/phaseModeValues/phaseSwitchPauseSec/currentStepA/controlLatencySec.
function contract18(string $mfr, array $attrs, array $vars): array {
    static $n = 50000;
    $inst = 790 + ($n++ % 200);
    foreach ($vars as $ident => $vid) { $GLOBALS['objs'][$vid] = ['ObjectIdent' => $ident, 'ObjectType' => 2, 'ParentID' => $inst]; }
    $h = new ChargerHub($inst);
    $h->prop = ['Manufacturer' => $mfr];
    $h->RegisterAttributeInteger('LastSeenAt', 0);
    foreach ($attrs as $k => $v) { $h->WriteAttributeInteger($k, $v); }
    return $h->GetFunctions()[0];
}
$c1 = contract18('goe', ['DevicePhaseSwitch' => 1, 'DevicePhases' => 3], ['ctl_phase_mode' => 41001]);
check('Vertrag 1.12: contractVersion 1.12', $c1['contractVersion'] === '1.12');
check('Vertrag 1.12: planInfoID vorhanden (int)', isset($c1['planInfoID']) && is_int($c1['planInfoID']));
check('Vertrag 1.11: stateID/errorCodeID vorhanden (int)', isset($c1['stateID'], $c1['errorCodeID']) && is_int($c1['stateID']) && is_int($c1['errorCodeID']));
check('Vertrag 1.10: sessionEnergyID/energyLimitID/chargingStateID vorhanden (int)', isset($c1['sessionEnergyID'], $c1['energyLimitID'], $c1['chargingStateID']) && is_int($c1['sessionEnergyID']) && is_int($c1['energyLimitID']) && is_int($c1['chargingStateID']));
check('Vertrag 1.9: controlOwner/Source/Reason/Since vorhanden', isset($c1['controlOwner'], $c1['controlSource'], $c1['controlReason'], $c1['controlSince']));
check('Vertrag 1.8 go-e: phaseModeID + Werte {one 1, three 2, auto 0}, Schritt 1 A, Latenz unbekannt', $c1['phaseModeID'] === 41001 && $c1['phaseModeValues'] === ['one' => 1, 'three' => 2, 'auto' => 0] && $c1['currentStepA'] === 1.0 && $c1['controlLatencySec'] === 0 && $c1['phaseSwitchPauseSec'] === 0);
$c2 = contract18('daheimlader', ['DevicePhaseSwitch' => 1, 'DevicePhases' => 1], ['ctl_phase_mode' => 41002]);
check('Vertrag 1.8 DaheimLader: Werte 1/3, auto null, Umschaltpause 120 s', $c2['phaseModeID'] === 41002 && $c2['phaseModeValues'] === ['one' => 1, 'three' => 3, 'auto' => null] && $c2['phaseSwitchPauseSec'] === 120);
$c3 = contract18('daheimlader', [], ['ctl_phase_mode' => 41003]);
check('Vertrag 1.8 DaheimLader ohne Nachweis (keine PRO): phaseModeID 0, keine Werte, Pause bleibt 120', $c3['phaseModeID'] === 0 && !isset($c3['phaseModeValues']));
$c4 = contract18('peblar', ['DevicePhaseSwitch' => 0, 'DevicePhases' => 3], ['ctl_phase_mode' => 41004]);
check('Vertrag 1.8 Peblar ohne unabhängiges Relais: nicht umschaltbar (phaseModeID 0)', $c4['phaseModeID'] === 0 && !isset($c4['phaseModeValues']));
$c5 = contract18('peblar', ['DevicePhaseSwitch' => 1, 'DevicePhases' => 3], ['ctl_phase_mode' => 41005]);
check('Vertrag 1.8 Peblar mit unabhängigem Relais: umschaltbar, Werte 1/3', $c5['phaseModeID'] === 41005 && $c5['phaseModeValues']['three'] === 3);
$c6 = contract18('alfen', ['DevicePhases' => 3, 'DevicePhaseSwitch' => -1], ['ctl_phase_mode' => 41006]);
check('Vertrag 1.8 Alfen: Phasenzahl lesbar -> umschaltbar, Stromstufe 0,1 A', $c6['phaseModeID'] === 41006 && $c6['currentStepA'] === 0.1);
$c7 = contract18('foxess', ['DevicePhaseSwitch' => 1], ['ctl_phase_mode' => 41007]);
$c8 = contract18('keba', [], []);
check('Vertrag 1.8 Fox ESS/KEBA: keine eindeutige Phasen-Kodierung bzw. keine Umschaltung -> phaseModeID 0, keine Werte', $c7['phaseModeID'] === 0 && $c8['phaseModeID'] === 0 && !isset($c7['phaseModeValues']) && !isset($c8['phaseModeValues']));

// go-e: 'phases' = tatsächlich ladende Phasen aus der Phasenmaske nach dem Schütz (Register 205), sonst erzwungener Modus.
$gp = new FakeMb(); $gp->set(100, [2]); $gp->set(332, [0]); $gp->set(205, [0b001]);   // psm 0 (Auto), nur L1 aktiv
$gph = new FakeHub(); $gph->groups['GoeWordSwap'] = false;
$goe->readValues($gp, $gph);
check('go-e phases: Automatikmodus, Maske 001 -> 1 Phase tatsächlich (Umschaltbarkeit belegt)', $gph->phaseInfo === [1, 1]);
$gp2 = new FakeMb(); $gp2->set(100, [2]); $gp2->set(332, [2]); $gp2->set(205, [0]);   // psm 2 (3-phasig erzwungen), Maske 0 (kein Ladevorgang)
$gph2 = new FakeHub(); $gph2->groups['GoeWordSwap'] = false;
$goe->readValues($gp2, $gph2);
check('go-e phases: Maske 0 -> Rückfall auf erzwungenen Modus (3 Phasen)', $gph2->phaseInfo === [3, 1]);

// DaheimLader: Register 184 (Phasenstatus) belegt Phasenzahl und Umschaltbarkeit (nur PRO).
$dp = new FakeMb(); for ($i = 0; $i < 114; $i++) { $dp->r[$i] = 0; } $dp->r[0] = 1; $dp->r[184] = 3;
$dph = new FakeHub();
$dd->readValues($dp, $dph);
check('DaheimLader PRO: Phasenstatus 3 -> phases 3, umschaltbar', $dph->phaseInfo === [3, 1]);
$dp2 = new FakeMb(); for ($i = 0; $i < 114; $i++) { $dp2->r[$i] = 0; } $dp2->r[0] = 1;   // 184 nicht lesbar (kein PRO)
$dph2 = new FakeHub();
$dd->readValues($dp2, $dph2);
check('DaheimLader ohne PRO: Register 184 nicht lesbar -> keine Phasenangabe', $dph2->phaseInfo === null);

// Netzleistung direkt aus einer Variablen (Anregung Dietmar, 21.10.2026): Vorzeichen, Einheit, maximales Alter.
$GLOBALS['byGuid']['{BAB8E05C-9150-43B9-9F2B-E5215FA54F0A}'] = [77];   // ein normaler MeterHub wäre da, hat aber Nachrang
$gvh = new ChargerHub(785);
$fgs = new ReflectionMethod($gvh, 'FindGridSurplusW');
$GLOBALS['objs'][52000] = ['ObjectIdent' => '', 'ObjectType' => 2, 'ParentID' => 0, 'Value' => -2500, 'VariableUpdated' => time() - 3];
$gvh->prop = ['SurplusGridVarID' => 52000, 'SurplusGridSign' => 'import', 'SurplusGridUnit' => 'W', 'SurplusGridMaxAgeSec' => 30];
check('Netzvariable: + = Bezug, -2500 W = 2500 W Überschuss (Vorrang vor MeterHub)', $fgs->invoke($gvh) === 2500.0);
$GLOBALS['objs'][52000]['Value'] = 800;
check('Netzvariable: Bezug 800 W -> kein Überschuss (0 W)', $fgs->invoke($gvh) === 0.0);
$gvh->prop['SurplusGridSign'] = 'export'; $GLOBALS['objs'][52000]['Value'] = 3000;
check('Netzvariable: + = Einspeisung, 3000 W -> 3000 W Überschuss', $fgs->invoke($gvh) === 3000.0);
$gvh->prop['SurplusGridUnit'] = 'kW'; $GLOBALS['objs'][52000]['Value'] = 3.2;
check('Netzvariable: Einheit kW, 3,2 kW -> 3200 W', abs($fgs->invoke($gvh) - 3200.0) < 1e-9);
$GLOBALS['objs'][52000]['VariableUpdated'] = time() - 120;
check('Netzvariable: Wert 120 s alt (erlaubt 30 s) -> null, KEIN Rückfall auf den MeterHub', $fgs->invoke($gvh) === null);
$gvh->prop['SurplusGridMaxAgeSec'] = 300;
check('Netzvariable: erlaubtes Alter 300 s -> Wert gilt wieder', abs($fgs->invoke($gvh) - 3200.0) < 1e-9);
$gvh->prop['SurplusGridVarID'] = 59999;
check('Netzvariable: Variable existiert nicht mehr -> null (kein Rückfall)', $fgs->invoke($gvh) === null);
$gvh->prop['SurplusGridVarID'] = 0; $gvh->prop['SurplusMeterID'] = 0;
check('Ohne Netzvariable gilt wieder der MeterHub-Vertrag (1234 W)', $fgs->invoke($gvh) === 1234.0);
$gvl = new ReflectionMethod($gvh, 'LinkStatusLines');
$gvh->prop = ['SurplusGridVarID' => 52000, 'SurplusGridSign' => 'import', 'SurplusGridUnit' => 'W', 'SurplusGridMaxAgeSec' => 30];
$GLOBALS['objs'][52000]['Value'] = -1800; $GLOBALS['objs'][52000]['VariableUpdated'] = time() - 2;
$gl = $gvl->invoke($gvh)['LinkGridStatus'];
check('Netzvariable Statuszeile ✏️ mit Variable, Vorzeichen, Einheit, Wert und Alter', strpos($gl, '✏️ Netzleistung aus eigener Variable') === 0 && strpos($gl, '1800 W') !== false && strpos($gl, 's alt') !== false);
$GLOBALS['objs'][52000]['VariableUpdated'] = time() - 400;
check('Netzvariable Statuszeile ⚠️ bei veraltetem Wert', strpos($gvl->invoke($gvh)['LinkGridStatus'], '⚠️') === 0 && strpos($gvl->invoke($gvh)['LinkGridStatus'], 'gestoppt') !== false);

// FAIL-SAFE: veraltete Netzvariable stoppt eine laufende Ladung der Überschussregelung (kein Weiterladen mit eingefrorenem Wert).
class FailSafeHub extends ChargerHub { public $actions = []; public function RequestAction($i, $v) { $this->actions[] = [$i, $v]; } }
$GLOBALS['objs'][53001] = ['ObjectIdent' => 'vehicle_plugged', 'ObjectType' => 2, 'ParentID' => 786, 'Value' => true];
$GLOBALS['objs'][53002] = ['ObjectIdent' => 'ctl_enable', 'ObjectType' => 2, 'ParentID' => 786, 'Value' => true];
$GLOBALS['objs'][52100] = ['ObjectIdent' => '', 'ObjectType' => 2, 'ParentID' => 0, 'Value' => -2000, 'VariableUpdated' => time() - 500];
$GLOBALS['byGuid']['{9256C34E-5CFD-4F37-8BFE-E65390EBB37C}'] = [];
$fs = new FailSafeHub(786);
$fs->prop = ['EnableSurplusCharging' => true, 'ManagedBy' => 'none', 'SurplusGridVarID' => 52100, 'SurplusGridMaxAgeSec' => 30, 'SurplusGridSign' => 'import', 'SurplusGridUnit' => 'W'];
(new ReflectionMethod($fs, 'SurplusChargeControl'))->invoke($fs);
check('Fail-Safe: veralteter Netzwert -> laufende Ladung wird gestoppt (ctl_enable=false)', $fs->actions === [['ctl_enable', false]]);
$GLOBALS['objs'][52100]['VariableUpdated'] = time() - 1; $GLOBALS['objs'][52100]['Value'] = -6000;
$fs->actions = [];
// Aktueller Wert mit 6000 W Überschuss (8 A dreiphasig, über dem Minimum) -> die Regelung rechnet normal weiter, kein Stopp.
(new ReflectionMethod($fs, 'SurplusChargeControl'))->invoke($fs);
check('Fail-Safe: aktueller Netzwert (6000 W Überschuss) -> kein Stopp, Regelung setzt den Strom (ctl_curr_limit)', !in_array(['ctl_enable', false], $fs->actions, true) && in_array('ctl_curr_limit', array_column($fs->actions, 0), true));

// Regelungsstatus (Vertrag 1.9): wer regelt, warum.
$cs = new FailSafeHub(786);
$csm = new ReflectionMethod($cs, 'ControlState');
$cs->prop = ['EnableSurplusCharging' => false, 'ManagedBy' => 'none'];
$r = $csm->invoke($cs);
check('Regelung: nichts aktiv -> Niemand, manuell', $r['owner'] === 'none' && $r['source'] === 'manuell');
$cs->prop = ['EnableSurplusCharging' => false, 'ManagedBy' => 'tibber'];
$r = $csm->invoke($cs);
check('Regelung: managedBy tibber -> Besitzer tibber, Quelle Einstellung, Grund nennt Wert', $r['owner'] === 'tibber' && $r['source'] === 'einstellung' && strpos($r['reason'], 'tibber') !== false);
$cs->prop = ['EnableSurplusCharging' => false, 'ManagedBy' => 'ems'];
check('Regelung: managedBy ems -> Besitzer ems', $csm->invoke($cs)['owner'] === 'ems');
$cs->prop = ['EnableSurplusCharging' => true, 'ManagedBy' => 'none'];
$cs->WriteAttributeBoolean('SurplusRegulating', true);
check('Regelung: Überschussladen regelt -> Besitzer surplus', $csm->invoke($cs)['owner'] === 'surplus');
$cs->WriteAttributeBoolean('SurplusRegulating', false);
$r = $csm->invoke($cs);
check('Regelung: Überschussladen an, greift nicht ein -> Niemand mit Grund', $r['owner'] === 'none' && strpos($r['reason'], 'greift gerade nicht ein') !== false);
$GLOBALS['objs'][53010] = ['ObjectIdent' => 'control_status', 'ObjectType' => 2, 'ParentID' => 786, 'Value' => ''];
$cs->prop = ['EnableSurplusCharging' => false, 'ManagedBy' => 'tibber'];
(new ReflectionMethod($cs, 'UpdateControlState'))->invoke($cs);
$t1 = $cs->ReadAttributeInteger('ControlSince');
check('Regelung: Anzeigevariable gefüllt mit Besitzer und Grund', strpos($GLOBALS['objs'][53010]['Value'], 'Tibber') !== false && $t1 > 0);
(new ReflectionMethod($cs, 'UpdateControlState'))->invoke($cs);
check('Regelung: Zeitpunkt bleibt ohne Besitzerwechsel unverändert', $cs->ReadAttributeInteger('ControlSince') === $t1);

// Dubletten-Ziel ohne passende Funktion: OHUB_GetFunctions wirft „Instance does not implement this function“
// (Nutzermeldung 23.10.2026) — weder Statuszeile noch Lesezyklus dürfen abbrechen.
if (!function_exists('OHUB_GetFunctions')) {
    function OHUB_GetFunctions($id) { throw new Exception('Instance does not implement this function'); }
}
$GLOBALS['inst'][36981] = true;
$dt = new FailSafeHub(786);
$dt->prop = ['DuplicateOfKey' => 'ocpphub:36981', 'ManagedBy' => 'none'];
$line = (new ReflectionMethod($dt, 'DuplicateLine'))->invoke($dt, 'ocpphub:36981');
check('Dublette: Zielinstanz ohne GetFunctions wirft nicht, Regler dort „unbekannt“', strpos($line, 'unbekannt') !== false);
$threw = false;
try { (new ReflectionMethod($dt, 'CheckDuplicateManagedByConflict'))->invoke($dt); } catch (\Throwable $e) { $threw = true; }
check('Dublette: Lesezyklus-Prüfung wirft bei Zielinstanz ohne GetFunctions nicht', !$threw);

// „Lädt“ (charging_active) folgt der Ladeleistung (> 100 W).
$GLOBALS['objs'][53020] = ['ObjectIdent' => 'power', 'ObjectType' => 2, 'ParentID' => 786, 'Value' => 3200.0];
$GLOBALS['objs'][53021] = ['ObjectIdent' => 'charging_active', 'ObjectType' => 2, 'ParentID' => 786, 'Value' => false];
(new ReflectionMethod($cs, 'UpdateControlState'))->invoke($cs);
check('Lädt: 3200 W -> true', $GLOBALS['objs'][53021]['Value'] === true);
$GLOBALS['objs'][53020]['Value'] = 20.0;
(new ReflectionMethod($cs, 'UpdateControlState'))->invoke($cs);
check('Lädt: 20 W (Standby) -> false', $GLOBALS['objs'][53021]['Value'] === false);

// Plantext von außen (CHUB_SetPlanInfo): setzen, ablaufen lassen, sofort löschen.
$GLOBALS['objs'][53030] = ['ObjectIdent' => 'control_plan', 'ObjectType' => 2, 'ParentID' => 786, 'Value' => ''];
$cs->SetPlanInfo('Heute 02:15 bis 04:30 laden', time() + 600);
check('Plan: Text gesetzt', $GLOBALS['objs'][53030]['Value'] === 'Heute 02:15 bis 04:30 laden');
(new ReflectionMethod($cs, 'UpdateControlState'))->invoke($cs);
check('Plan: vor Ablauf bleibt der Text stehen', $GLOBALS['objs'][53030]['Value'] === 'Heute 02:15 bis 04:30 laden');
$cs->SetPlanInfo('Alter Plan', time() - 5);
(new ReflectionMethod($cs, 'UpdateControlState'))->invoke($cs);
check('Plan: nach Ablauf wird der Text gelöscht', $GLOBALS['objs'][53030]['Value'] === '');
$cs->SetPlanInfo('Plan ohne Ablauf');
(new ReflectionMethod($cs, 'UpdateControlState'))->invoke($cs);
check('Plan: ohne Ablaufzeit bleibt der Text', $GLOBALS['objs'][53030]['Value'] === 'Plan ohne Ablauf');
$cs->SetPlanInfo('');
check('Plan: leerer Text löscht sofort', $GLOBALS['objs'][53030]['Value'] === '');

// module.json beider Module
$main = json_decode(file_get_contents(__DIR__ . '/../ChargerHub/module.json'), true);
$br = json_decode(file_get_contents(__DIR__ . '/../ChargerHubBridge/module.json'), true);
check('module.json Hub leer', $main['parentRequirements'] === [] && $main['implemented'] === []);
check('module.json Brücke', $br['type'] === 3 && $br['parentRequirements'] === ['{E310B701-4AE7-458E-B618-EC13A1A6F6A8}'] && $br['implemented'] === ['{77B31ABB-18FA-4B91-BB63-E5B2AB5588F4}'] && $br['prefix'] === 'CHUBB');

echo $fail === 0 ? "\nAlles grün.\n" : "\n$fail Fehler.\n";
exit($fail === 0 ? 0 : 1);
