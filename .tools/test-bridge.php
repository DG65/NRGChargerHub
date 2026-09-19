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
function CHUBB_Forward($id, $json) { return $GLOBALS['bridge']->Forward($json); }

class IPSModule {
    public $InstanceID = 1; public $attr = []; public $prop = [];
    public function __construct($id = 1) { $this->InstanceID = $id; }
    public function Create() {} public function ApplyChanges() {}
    public function SetStatus($s) { $this->status = $s; }
    public function SendDataToParent($j) { $r = $GLOBALS['parentReply']; if ($r instanceof Throwable) throw $r; return $r; }
    public function ReadPropertyInteger($n) { return $this->prop[$n] ?? 0; }
    public function ReadPropertyString($n) { return $this->prop[$n] ?? ''; }
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

// module.json beider Module
$main = json_decode(file_get_contents(__DIR__ . '/../ChargerHub/module.json'), true);
$br = json_decode(file_get_contents(__DIR__ . '/../ChargerHubBridge/module.json'), true);
check('module.json Hub leer', $main['parentRequirements'] === [] && $main['implemented'] === []);
check('module.json Brücke', $br['type'] === 3 && $br['parentRequirements'] === ['{E310B701-4AE7-458E-B618-EC13A1A6F6A8}'] && $br['implemented'] === ['{77B31ABB-18FA-4B91-BB63-E5B2AB5588F4}'] && $br['prefix'] === 'CHUBB');

echo $fail === 0 ? "\nAlles grün.\n" : "\n$fail Fehler.\n";
exit($fail === 0 ? 0 : 1);
