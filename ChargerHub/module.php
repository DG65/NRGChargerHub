<?php

// ===========================================================================
// ChargerHub — generisches Modbus-TCP-Framework für Wallboxen verschiedener
// Hersteller. Ein Modul, ein Auswahlfeld „Wallbox-Typ" — je nach Auswahl
// werden die passenden Register und Bedienelemente freigeschaltet.
//
// Aufbau analog zu InverterHub/MeterHub:
//   CHUB_ModbusTcpClient        — gemeinsame Modbus-TCP-Grundfunktionen
//   ChargerDriverInterface — Vertrag, den jeder Wallbox-Treiber erfüllt
//   KebaDriver / AlfenDriver / HeidelbergDriver / GoeChargerDriver
//   ChargerHub              — Hauptmodul, lädt den Treiber laut Manufacturer-Property
//
// WICHTIG: Alle Registeradressen der vier Treiber stammen aus den jeweiligen
// öffentlichen Hersteller-Dokumentationen, sind aber NOCH NICHT an echter
// Hardware verifiziert (anders als bei InverterHub/MeterHub, wo viele Quirks
// live bestätigt sind). Vor Produktiveinsatz bitte die Werte gegen ein reales
// Gerät prüfen und Rückmeldungen einpflegen.
//
// Schreibzugriff (Ladefreigabe, Stromlimit) ist Kernfunktion von ChargerHub
// (Unterschied zu MeterHub, das nur liest) — das Interface ist daher näher an
// InverterHub. Der endgültige CHUB_GetFunctions-Schreib-Vertrag (welche Felder
// EMS zum Steuern nutzt) ist laut CLAUDE.md noch offen und mit der EMS-Sitzung
// abzustimmen; die hier registrierten Ident-Namen sind ein erster, änderbarer
// Vorschlag.
// ===========================================================================

class CHUB_ModbusTcpClient
{
    public $host;
    public $port;
    public $unitId;

    // Klartext-Grund des letzten fehlgeschlagenen Schreibzugriffs (writeSingle/
    // writeMultiple) — von der aufrufenden Instanz nach jedem Schreibversuch
    // auslesbar, damit ein stiller false-Rückgabewert nicht mehr unbegründet bleibt.
    public $lastWriteError = '';

    private $batchSock = null;

    public function beginBatch()
    {
        $this->endBatch();
        $s = @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
        if ($s !== false) {
            stream_set_timeout($s, 3);
            $this->batchSock = $s;
        }
    }

    public function endBatch()
    {
        if ($this->batchSock !== null) {
            @fclose($this->batchSock);
            $this->batchSock = null;
        }
    }

    public function __construct($host, $port, $unitId)
    {
        $this->host   = $host;
        $this->port   = $port;
        $this->unitId = $unitId;
    }

    public function readHolding($startReg, $count)
    {
        return $this->modbusRead(0x03, $startReg, $count);
    }

    public function readInput($startReg, $count)
    {
        return $this->modbusRead(0x04, $startReg, $count);
    }

    private function modbusRead($fc, $startReg, $count)
    {
        $sock = $this->batchSock ?: @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
        if ($sock === false) {
            return null;
        }
        if ($this->batchSock === null) {
            stream_set_timeout($sock, 3);
        }

        $tid  = mt_rand(1, 65535);
        $pdu  = pack('Cnn', $fc, $startReg, $count);
        $mbap = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($this->unitId);

        @fwrite($sock, $mbap . $pdu);

        $response = '';
        $deadline = microtime(true) + 3.0;
        while (microtime(true) < $deadline) {
            $chunk = @fread($sock, 512);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
            if (strlen($response) >= 9) {
                if (ord($response[7]) & 0x80) {
                    break; // Modbus-Exception (9-Byte-Antwort)
                }
                $byteCount = ord($response[8]);
                if (strlen($response) >= 9 + $byteCount) {
                    break;
                }
            }
        }
        if ($this->batchSock === null) {
            fclose($sock);
        }

        if (strlen($response) < 9) {
            return null;
        }

        $rfc = ord($response[7]);
        if ($rfc & 0x80 || $rfc !== $fc) {
            return null;
        }

        $byteCount = ord($response[8]);
        $data      = substr($response, 9, $byteCount);

        $regs = [];
        for ($i = 0; $i < $count && ($i * 2 + 1) < strlen($data); $i++) {
            $regs[$i] = (ord($data[$i * 2]) << 8) | ord($data[$i * 2 + 1]);
        }
        return $regs;
    }

    public function writeSingle($reg, $value)
    {
        $this->lastWriteError = '';
        $sock = @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
        if ($sock === false) {
            $this->lastWriteError = "Verbindung fehlgeschlagen: $errstr ($errno)";
            return false;
        }
        stream_set_timeout($sock, 3);

        $tid  = mt_rand(1, 65535);
        $pdu  = pack('Cnn', 0x06, $reg, $value & 0xFFFF);
        $mbap = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($this->unitId);

        @fwrite($sock, $mbap . $pdu);
        $resp = @fread($sock, 64);
        fclose($sock);

        return $this->CheckWriteResponse($resp, 0x06);
    }

    public function writeMultiple($startReg, $values)
    {
        $this->lastWriteError = '';
        $sock = @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
        if ($sock === false) {
            $this->lastWriteError = "Verbindung fehlgeschlagen: $errstr ($errno)";
            return false;
        }
        stream_set_timeout($sock, 3);

        $count     = count($values);
        $byteCount = $count * 2;
        $dataPart  = '';
        foreach ($values as $v) {
            $dataPart .= pack('n', $v & 0xFFFF);
        }
        $tid  = mt_rand(1, 65535);
        $pdu  = pack('CnnC', 0x10, $startReg, $count, $byteCount) . $dataPart;
        $mbap = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($this->unitId);

        @fwrite($sock, $mbap . $pdu);
        $resp = @fread($sock, 64);
        fclose($sock);

        return $this->CheckWriteResponse($resp, 0x10);
    }

    // Wertet die Modbus-TCP-Antwort auf einen Schreibzugriff aus und trägt bei
    // Misserfolg eine Klartext-Ursache in $lastWriteError ein (Timeout, leere
    // Antwort, oder eine echte Modbus-Exception mit Exception-Code laut
    // Spezifikation) — bislang wurde jeder dieser Fälle undiagnostizierbar als
    // simples false durchgereicht.
    private function CheckWriteResponse($resp, int $expectedFc): bool
    {
        if ($resp === false || $resp === '') {
            $this->lastWriteError = 'Keine Antwort vom Gerät (Timeout)';
            return false;
        }
        if (strlen($resp) < 8) {
            $this->lastWriteError = 'Antwort zu kurz (' . strlen($resp) . ' Byte)';
            return false;
        }
        $fc = ord($resp[7]);
        if ($fc === $expectedFc) {
            return true;
        }
        if ($fc === ($expectedFc | 0x80) && strlen($resp) >= 9) {
            $excCode = ord($resp[8]);
            $labels = [
                1 => 'Illegal Function (FC nicht unterstützt)',
                2 => 'Illegal Data Address (Register nicht vorhanden)',
                3 => 'Illegal Data Value (Wert außerhalb des zulässigen Bereichs)',
                4 => 'Slave Device Failure',
                6 => 'Slave Device Busy',
            ];
            $this->lastWriteError = 'Modbus-Exception Code ' . $excCode . ' (' . ($labels[$excCode] ?? 'unbekannt') . ')';
            return false;
        }
        $this->lastWriteError = 'Unerwarteter Function Code in Antwort: 0x' . dechex($fc);
        return false;
    }

    public function u16($regs, $offset)
    {
        return isset($regs[$offset]) ? ($regs[$offset] & 0xFFFF) : 0;
    }

    public function s16($regs, $offset)
    {
        $v = $this->u16($regs, $offset);
        return $v > 32767 ? $v - 65536 : $v;
    }

    public function u32($regs, $offset)
    {
        return (($this->u16($regs, $offset) << 16) | $this->u16($regs, $offset + 1));
    }

    public function s32($regs, $offset)
    {
        $v = $this->u32($regs, $offset);
        return $v > 2147483647 ? $v - 4294967296 : $v;
    }

    // 32-Bit mit getauschter Wortreihenfolge (CDAB) — go-eCharger-Firmware 60.3
    // hatte laut offizieller Modbus-Doku vertauschte Bytes, seit 60.4 behoben.
    public function u32sw($regs, $offset)
    {
        return (($this->u16($regs, $offset + 1) << 16) | $this->u16($regs, $offset));
    }

    public function readStr($regs, $offset, int $regCount)
    {
        $s = '';
        for ($i = 0; $i < $regCount; $i++) {
            $r  = $this->u16($regs, $offset + $i);
            $s .= chr(($r >> 8) & 0xFF) . chr($r & 0xFF);
        }
        return rtrim($s, "\x00 ");
    }

    // IEEE-754 Float32 über 2 Register, Big-Endian (ABCD) — Standard bei Alfen.
    public function readFloat32($regs, $offset)
    {
        $hi  = $this->u16($regs, $offset);
        $lo  = $this->u16($regs, $offset + 1);
        $raw = pack('nn', $hi, $lo);
        $val = unpack('G', $raw);
        return (float)($val[1] ?? 0.0);
    }

    public function writeFloat32($startReg, float $value)
    {
        $raw  = pack('G', $value);
        $w    = unpack('n2', $raw);
        return $this->writeMultiple($startReg, [$w[1], $w[2]]);
    }

    public function writeDouble64($startReg, float $value)
    {
        $raw = pack('E', $value);
        $w   = unpack('n4', $raw);
        return $this->writeMultiple($startReg, [$w[1], $w[2], $w[3], $w[4]]);
    }

    // IEEE-754 Float64 (Double) über 4 Register, Big-Endian (Reserve für
    // künftige Treiber mit Float64-Energiezählern, wie PAC2200 in MeterHub).
    public function readDouble64($regs, $offset)
    {
        $raw = pack(
            'nnnn',
            $this->u16($regs, $offset),
            $this->u16($regs, $offset + 1),
            $this->u16($regs, $offset + 2),
            $this->u16($regs, $offset + 3)
        );
        $val = unpack('E', $raw);
        return (float)($val[1] ?? 0.0);
    }
}

// ---------------------------------------------------------------------------
// ChargerDriverInterface — Vertrag, den jeder Wallbox-Treiber erfüllt
// ---------------------------------------------------------------------------

interface ChargerDriverInterface
{
    /**
     * Immer aktive Basisvariablen.
     * [ident, caption, type(F/I/B/S), profile, archive, group, reg]
     */
    public function getBaseVars();

    /**
     * Optionale Variablengruppen, je Property-Name (Checkbox in der Instanz).
     * ['GroupXYZ' => ['caption' => '...', 'vars' => [...]]]
     */
    public function getOptionalGroups();

    /** Custom-Profile, die dieser Treiber anlegt: [name => [type, suffix, min, max, step, digits]] */
    public function getProfiles();

    /** Enum-Profile (Assoziationen): [name => [wert => [label, farbe]]] */
    public function getEnumProfiles();

    /** Liest alle Werte. Rückgabe: Verbindung erfolgreich? */
    public function readValues($mb, $hub);

    /** Verarbeitet einen Schreibzugriff (RequestAction) auf ein Steuer-Ident. */
    public function writeControl($mb, $hub, string $ident, $value);
}

// ---------------------------------------------------------------------------
// CHUB_MqttMiniClient — minimaler MQTT-3.1.1-Client (nur QoS 0, keine
// Persistenz zwischen Aufrufen) für die go-e-RFID-Kartenzähler, die nur über
// MQTT verfügbar sind (siehe GoeChargerDriver-Kommentar). Bewusst KEINE
// Abhängigkeit von einer fremden Symcon-"MQTT Client"-Instanz (Splitter) —
// exakt dasselbe Prinzip wie CHUB_ModbusTcpClient: eigener roher Socket,
// Verbindung wird bei jedem Poll neu aufgebaut (kein Zustand über
// Timer-Aufrufe hinweg, siehe dortiger Kommentar). Da go-e seine Werte als
// RETAINED Topics veröffentlicht, liefert der Broker den aktuellen Stand
// sofort nach dem SUBSCRIBE — ein Neuaufbau pro Poll ist dafür ausreichend.
// PUBACK für QoS 1 wird bewusst NICHT gesendet (kurzlebige Verbindung, der
// Broker liefert beim nächsten Poll ohnehin erneut — unschädlich, da wir nur
// den letzten Stand je Topic übernehmen, keine Zustellgarantie brauchen).
class CHUB_MqttMiniClient
{
    private $host;
    private $port;
    private $lastError = '';

    public function __construct(string $host, int $port)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public function getLastError(): string
    {
        return $this->lastError;
    }

    // Verbindet, abonniert $topicFilters, sammelt für $budgetSec Sekunden
    // eingehende PUBLISH-Nachrichten und trennt wieder. Rückgabe:
    // [['topic' => string, 'payload' => string], ...]
    public function fetch(array $topicFilters, string $clientId, float $budgetSec = 2.5): array
    {
        $this->lastError = '';
        $sock = @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
        if ($sock === false) {
            $this->lastError = "Verbindung fehlgeschlagen: $errstr ($errno)";
            return [];
        }
        stream_set_timeout($sock, 3);

        @fwrite($sock, $this->buildConnect($clientId));
        $connAck = $this->readPacket($sock, 3.0);
        if ($connAck === null || $connAck['type'] !== 2 || ord($connAck['body'][1] ?? "\xFF") !== 0) {
            $this->lastError = 'CONNACK fehlgeschlagen oder Broker abgelehnt';
            @fclose($sock);
            return [];
        }

        @fwrite($sock, $this->buildSubscribe($topicFilters, 1));
        // SUBACK abwarten, aber Inhalt nicht auswerten — reicht als Bestätigung,
        // dass der Broker die Anfrage verarbeitet hat.
        $this->readPacket($sock, 2.0);

        $results = [];
        $deadline = microtime(true) + $budgetSec;
        while (microtime(true) < $deadline) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }
            $pkt = $this->readPacket($sock, $remaining);
            if ($pkt === null) {
                break; // Timeout ohne weitere Daten — fertig.
            }
            if ($pkt['type'] === 3) { // PUBLISH
                $parsed = $this->parsePublish($pkt['flags'], $pkt['body']);
                if ($parsed !== null) {
                    $results[] = $parsed;
                }
            }
        }

        @fclose($sock);
        return $results;
    }

    private function buildConnect(string $clientId): string
    {
        $protoName  = $this->encodeStr('MQTT');
        $flags      = "\x02"; // Clean Session, kein Will/User/Pass
        $keepAlive  = pack('n', 60);
        $varHeader  = $protoName . "\x04" . $flags . $keepAlive;
        $payload    = $this->encodeStr($clientId);
        $remaining  = $this->encodeLength(strlen($varHeader) + strlen($payload));
        return "\x10" . $remaining . $varHeader . $payload;
    }

    private function buildSubscribe(array $topicFilters, int $packetId): string
    {
        $varHeader = pack('n', $packetId);
        $payload   = '';
        foreach ($topicFilters as $t) {
            $payload .= $this->encodeStr($t) . "\x00"; // QoS 0 angefragt
        }
        $remaining = $this->encodeLength(strlen($varHeader) + strlen($payload));
        return "\x82" . $remaining . $varHeader . $payload;
    }

    private function parsePublish(int $flags, string $body)
    {
        if (strlen($body) < 2) {
            return null;
        }
        $topicLen = (ord($body[0]) << 8) | ord($body[1]);
        if (strlen($body) < 2 + $topicLen) {
            return null;
        }
        $topic  = substr($body, 2, $topicLen);
        $offset = 2 + $topicLen;
        $qos    = ($flags >> 1) & 0x03;
        if ($qos > 0) {
            $offset += 2; // Packet Identifier überspringen (nicht benötigt)
        }
        $payload = substr($body, $offset);
        return ['topic' => $topic, 'payload' => $payload];
    }

    // Liest genau EIN vollständiges MQTT-Kontrollpaket (Fixed Header +
    // Remaining Length + Body) oder gibt null zurück, wenn innerhalb von
    // $timeoutSec nichts Vollständiges ankommt.
    private function readPacket($sock, float $timeoutSec)
    {
        $deadline = microtime(true) + max($timeoutSec, 0.1);
        $first = $this->readExact($sock, 1, $deadline);
        if ($first === null) {
            return null;
        }
        $type  = (ord($first) >> 4) & 0x0F;
        $flags = ord($first) & 0x0F;

        $multiplier = 1;
        $remaining  = 0;
        do {
            $b = $this->readExact($sock, 1, $deadline);
            if ($b === null) {
                return null;
            }
            $byte = ord($b);
            $remaining += ($byte & 0x7F) * $multiplier;
            $multiplier *= 128;
        } while (($byte & 0x80) !== 0);

        $body = $remaining > 0 ? $this->readExact($sock, $remaining, $deadline) : '';
        if ($body === null) {
            return null;
        }
        return ['type' => $type, 'flags' => $flags, 'body' => $body];
    }

    private function readExact($sock, int $len, float $deadline)
    {
        $buf = '';
        while (strlen($buf) < $len) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0 || feof($sock)) {
                return null;
            }
            stream_set_timeout($sock, (int)max(1, ceil($remaining)));
            $chunk = @fread($sock, $len - strlen($buf));
            if ($chunk === false || $chunk === '') {
                $meta = @stream_get_meta_data($sock);
                if (!empty($meta['timed_out'])) {
                    return null;
                }
                continue;
            }
            $buf .= $chunk;
        }
        return $buf;
    }

    private function encodeStr(string $s): string
    {
        return pack('n', strlen($s)) . $s;
    }

    private function encodeLength(int $len): string
    {
        $out = '';
        do {
            $byte = $len % 128;
            $len  = intdiv($len, 128);
            if ($len > 0) {
                $byte |= 0x80;
            }
            $out .= chr($byte);
        } while ($len > 0);
        return $out;
    }
}

// ---------------------------------------------------------------------------
// KebaDriver — KEBA KeContact P30/P40, Modbus TCP, Unit-ID standardmäßig 255.
// Registeradressen und Eigenheiten gegen die evcc-Referenzimplementierung
// (charger/keba-modbus.go, an realer Hardware erprobt) abgeglichen:
// - ALLE Werte sind 32-Bit-Werte über 2 Register (auch Status/Kabelstatus) —
//   ein 1-Register-Read liefert nur das (immer leere) High-Word.
// - Gelesen wird per FC 0x03 (Holding), geschrieben per FC 0x06.
// - KEBA unterstützt keine Block-Reads über Wertegrenzen hinweg — jeder
//   Datenpunkt wird einzeln gelesen.
// - 1036 ist die GESAMT-Energie, die Sitzungsenergie liegt auf 1502.
// - Ladefreigabe: Holding 5014 (P30). P40-Geräte haben kein 5014 und werden
//   über das Stromlimit 5004 freigegeben/gesperrt (evcc-Erkenntnis) — hier
//   noch nicht gesondert behandelt.
// Weiterhin UNGETESTET an echter Hardware in diesem Modul.
// ---------------------------------------------------------------------------

class KebaDriver implements ChargerDriverInterface
{
    // Holding-Register (FC 0x03), alle Werte U32 über 2 Register
    const REG_STATE        = 1000; // 0=Startet,1=Nicht bereit,2=Bereit,3=Lädt,4=Fehler,5=Unterbrochen
    const REG_CABLE_STATE  = 1004; // 0=kein Kabel,1=Kabel an Station,3=verriegelt,5=+Fahrzeug,7=verriegelt+Fahrzeug
    const REG_CURRENTS     = 1008; // 1008/1010/1012 = L1/L2/L3 in mA (je einzeln lesen!)
    const REG_SERIAL       = 1014;
    const REG_FIRMWARE     = 1018;
    const REG_POWER        = 1020; // mW
    const REG_ENERGY_TOTAL = 1036; // 0,1 Wh (P40 < FW 1.2.1: fälschlich Wh)
    const REG_VOLTAGES     = 1040; // 1040/1042/1044 = L1/L2/L3 in V
    const REG_ENERGY_SESS  = 1502; // 0,1 Wh — Energie der aktuellen Ladesitzung

    // Schreibbare Holding-Register (FC 0x06)
    const REG_CURR_LIMIT   = 5004; // mA, 0 oder 6000–63000
    const REG_ENABLE       = 5014; // 0/1 — Ladefreigabe (P30)

    const STATES = [
        0 => 'Startet', 1 => 'Nicht bereit', 2 => 'Bereit', 3 => 'Lädt',
        4 => 'Fehler', 5 => 'Unterbrochen',
    ];

    const CABLE_STATES = [
        0 => 'Kein Kabel', 1 => 'Kabel an Station', 3 => 'Kabel verriegelt',
        5 => 'Kabel + Fahrzeug', 7 => 'Verriegelt + Fahrzeug',
    ];

    // Einzelner U32-Datenpunkt (2 Register). KEBA lehnt Reads über
    // Wertegrenzen ab, daher kein Block-Read.
    private function u32At($mb, int $reg)
    {
        $r = $mb->readHolding($reg, 2);
        if ($r === null) {
            return null;
        }
        $v = $mb->u32($r, 0);
        // 0xFFFFFFFF = unbelegtes Register (Füllwert statt Exception, wie an
        // go-e-Firmware beobachtet) — nicht als Messwert übernehmen.
        return ($v === 0xFFFFFFFF) ? null : $v;
    }

    public function getBaseVars()
    {
        return [
            ['connected',      'Verbindung',           'B', '~Alert.Reversed',  false, 'errors', ''],
            ['state',          'Ladestatus',            'I', 'CHB.KebaState',   true,  'device', 'Holding 1000-1001 (U32)'],
            ['cable_state',    'Kabelstatus',           'I', 'CHB.KebaCable',   false, 'device', 'Holding 1004-1005 (U32)'],
            ['vehicle_plugged','Fahrzeug verbunden',    'B', '~Switch',         false, 'device', 'abgeleitet: Kabelstatus >= 5'],
            ['power',          'Ladeleistung',          'F', 'NRG.Watt',        true,  'device', 'Holding 1020-1021 (mW)'],
            ['energy_total',   'Energie gesamt',        'F', 'NRG.kWh',         true,  'device', 'Holding 1036-1037 (0,1 Wh)'],
            ['energy_session', 'Energie akt. Sitzung',  'F', 'CHB.kWhSession',  true,  'device', 'Holding 1502-1503 (0,1 Wh)'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPhases' => ['caption' => 'Strom/Spannung je Phase', 'vars' => [
                ['current_l1', 'Strom L1',    'F', 'NRG.Ampere', false, 'phases', 'Holding 1008-1009 (mA)'],
                ['current_l2', 'Strom L2',    'F', 'NRG.Ampere', false, 'phases', 'Holding 1010-1011 (mA)'],
                ['current_l3', 'Strom L3',    'F', 'NRG.Ampere', false, 'phases', 'Holding 1012-1013 (mA)'],
                ['voltage_l1', 'Spannung L1', 'F', 'NRG.Volt',   false, 'phases', 'Holding 1040-1041 (V)'],
                ['voltage_l2', 'Spannung L2', 'F', 'NRG.Volt',   false, 'phases', 'Holding 1042-1043 (V)'],
                ['voltage_l3', 'Spannung L3', 'F', 'NRG.Volt',   false, 'phases', 'Holding 1044-1045 (V)'],
            ]],
            'GroupDevice' => ['caption' => 'Geräteinformation', 'vars' => [
                ['dev_serial',   'Seriennummer',     'S', '', false, 'device', 'Holding 1014-1015 (U32)'],
                ['dev_firmware', 'Firmware-Version', 'S', '', false, 'device', 'Holding 1018-1019 (U32)'],
            ]],
            'GroupControl' => ['caption' => 'Steuerung (Ladefreigabe, Stromlimit)', 'vars' => [
                ['ctl_enable',       'Ladefreigabe',   'B', '~Switch',          false, 'control', 'RW Holding 5014 (P30)'],
                ['ctl_curr_limit',   'Stromlimit (A)', 'I', 'CHB.Ampere10to63', false, 'control', 'RW Holding 5004 (mA)'],
            ]],
        ];
    }

    public function getProfiles()
    {
        return [
            'NRG.Watt'         => [VARIABLETYPE_FLOAT,   ' W', 0.0, 22000.0, 1.0, 0],
            'NRG.kWh'          => [VARIABLETYPE_FLOAT,   ' kWh', 0.0, 9999999.0, 0.01, 2],
            // Eigenes Suffix, damit die MeterHub-Zählersuche (matcht auf
            // normalisiertes Suffix "kwh") den Sitzungswert NICHT als
            // Energiezähler aufnimmt — der springt je Ladevorgang zurück.
            'CHB.kWhSession'   => [VARIABLETYPE_FLOAT,   ' kWh (Sitzung)', 0.0, 999.0, 0.01, 2],
            'NRG.Volt'         => [VARIABLETYPE_FLOAT,   ' V', 0.0, 260.0, 0.1, 1],
            'NRG.Ampere'       => [VARIABLETYPE_FLOAT,   ' A', 0.0, 80.0, 0.1, 1],
            'CHB.Ampere10to63' => [VARIABLETYPE_INTEGER, ' A', 0, 63, 1, 0],
        ];
    }

    public function getEnumProfiles()
    {
        $states = [];
        foreach (self::STATES as $k => $label) {
            $color = ($k === 3) ? 0x27D07F : (($k === 4) ? 0xE74C3C : 0x7A8A99);
            $states[$k] = [$label, $color];
        }
        $cable = [];
        foreach (self::CABLE_STATES as $k => $label) {
            $cable[$k] = [$label, in_array($k, [5, 7], true) ? 0x27D07F : 0x7A8A99];
        }
        return ['CHB.KebaState' => $states, 'CHB.KebaCable' => $cable];
    }

    public function readValues($mb, $hub)
    {
        $state = $this->u32At($mb, self::REG_STATE);
        $ok    = ($state !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }
        $hub->SetVarInt('state', $state);

        $cable = $this->u32At($mb, self::REG_CABLE_STATE);
        if ($cable !== null) {
            $hub->SetVarInt('cable_state', $cable);
            // 5/7 = Kabel an Station UND Fahrzeug angesteckt
            $hub->SetVarBool('vehicle_plugged', $cable >= 5);
        }

        $power = $this->u32At($mb, self::REG_POWER);
        if ($power !== null) {
            $hub->SetVarFloat('power', $power / 1000.0); // mW -> W
        }

        $etot = $this->u32At($mb, self::REG_ENERGY_TOTAL);
        if ($etot !== null) {
            $hub->SetVarFloat('energy_total', $etot / 10000.0); // 0,1 Wh -> kWh
        }

        $esess = $this->u32At($mb, self::REG_ENERGY_SESS);
        if ($esess !== null) {
            $hub->SetVarFloat('energy_session', $esess / 10000.0);
        }

        if ($hub->GroupActive('GroupPhases')) {
            foreach ([1, 2, 3] as $ph) {
                $i = $this->u32At($mb, self::REG_CURRENTS + ($ph - 1) * 2);
                if ($i !== null) {
                    $hub->SetVarFloat('current_l' . $ph, $i / 1000.0); // mA -> A
                }
                $u = $this->u32At($mb, self::REG_VOLTAGES + ($ph - 1) * 2);
                if ($u !== null) {
                    $hub->SetVarFloat('voltage_l' . $ph, (float)$u);
                }
            }
        }

        if ($hub->GroupActive('GroupDevice')) {
            $sn = $this->u32At($mb, self::REG_SERIAL);
            if ($sn !== null) {
                $hub->SetVarStr('dev_serial', (string)$sn);
            }
            $fw = $this->u32At($mb, self::REG_FIRMWARE);
            if ($fw !== null) {
                // U32 wie 30107 = Version 3.1.7 (evcc-Deutung: Ziffernfolge)
                $hub->SetVarStr('dev_firmware', (string)$fw);
            }
        }

        return true;
    }

    public function writeControl($mb, $hub, string $ident, $value)
    {
        switch ($ident) {
            case 'ctl_enable':
                $val = (bool)$value ? 1 : 0;
                if ($mb->writeSingle(self::REG_ENABLE, $val)) {
                    $hub->SetVarBool('ctl_enable', (bool)$value);
                }
                break;

            case 'ctl_curr_limit':
                $amp = max(0, min($hub->GetMaxCurrentA(), (int)$value));
                $mA  = ($amp === 0) ? 0 : max(6000, $amp * 1000);
                if ($mb->writeSingle(self::REG_CURR_LIMIT, $mA)) {
                    $hub->SetVarInt('ctl_curr_limit', $amp);
                }
                break;
        }
    }
}

// ---------------------------------------------------------------------------
// AlfenDriver — Alfen Eve Single/Double Pro-line, NG9xx. Registeradressen
// laut Alfen "Modbus TCP/RTU Slave Register Table" — UNGETESTET an echter
// Hardware. Nur Sockel 1 (Basisadresse ohne Offset) wird bedient; Eve Double
// bräuchte für Sockel 2 dieselben Adressen +0x80, das ist hier bewusst noch
// nicht umgesetzt.
// ---------------------------------------------------------------------------

class AlfenDriver implements ChargerDriverInterface
{
    // Meter-Register (FC 0x03, lesend), Basis 300 — Float32 (2 Register)
    const REG_VOLTAGE_L1  = 308;
    const REG_VOLTAGE_L2  = 310;
    const REG_VOLTAGE_L3  = 312;
    const REG_CURRENT_L1  = 322;
    const REG_CURRENT_L2  = 324;
    const REG_CURRENT_L3  = 326;
    const REG_POWER_SUM   = 344; // W

    // Sockel-Status (Basis 1200), Sockel 1
    const REG_AVAILABILITY   = 1200; // U16: 0=nicht betriebsbereit,1=betriebsbereit,2=in Betrieb
    const REG_ACTUAL_CURRENT = 1206; // Float32, tatsächlich angewandtes Stromlimit (A)
    const REG_SETPOINT_CURR  = 1210; // Float32, Modbus-Slave-Stromlimit (A) — schreiben
    const REG_SETPOINT_TTL   = 1212; // U16, Gültigkeitsdauer des Setpoints (s) — muss periodisch erneuert werden
    const REG_SLAVE_CONTROL  = 1214; // U16, 1=Modbus-Slave-Steuerung aktiv, 0=Ladestation entscheidet selbst

    const AVAIL_LABELS = [0 => 'Nicht betriebsbereit', 1 => 'Betriebsbereit', 2 => 'In Betrieb'];

    public function getBaseVars()
    {
        return [
            ['connected',   'Verbindung',        'B', '~Alert.Reversed', false, 'errors', ''],
            ['state',       'Sockel-Status',      'I', 'CHB.AlfenAvail',  true,  'device', 'Holding 1200'],
            ['power',       'Ladeleistung',       'F', 'NRG.Watt',        true,  'device', 'Holding 344'],
            ['actual_curr', 'Angewandtes Limit',  'F', 'NRG.Ampere',      false, 'device', 'Holding 1206'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPhases' => ['caption' => 'Spannung/Strom je Phase', 'vars' => [
                ['voltage_l1', 'Spannung L1', 'F', 'NRG.Volt',   false, 'phases', 'Holding 308'],
                ['voltage_l2', 'Spannung L2', 'F', 'NRG.Volt',   false, 'phases', 'Holding 310'],
                ['voltage_l3', 'Spannung L3', 'F', 'NRG.Volt',   false, 'phases', 'Holding 312'],
                ['current_l1', 'Strom L1',    'F', 'NRG.Ampere', false, 'phases', 'Holding 322'],
                ['current_l2', 'Strom L2',    'F', 'NRG.Ampere', false, 'phases', 'Holding 324'],
                ['current_l3', 'Strom L3',    'F', 'NRG.Ampere', false, 'phases', 'Holding 326'],
            ]],
            'GroupControl' => ['caption' => 'Steuerung (Ladefreigabe, Stromlimit)', 'vars' => [
                ['ctl_enable',     'Ladefreigabe',   'B', '~Switch',    false, 'control', 'RW Holding 1210/1214'],
                ['ctl_curr_limit', 'Stromlimit (A)', 'F', 'NRG.Ampere', false, 'control', 'RW Holding 1210'],
            ]],
        ];
    }

    public function getProfiles()
    {
        return [
            'NRG.Watt'   => [VARIABLETYPE_FLOAT, ' W', 0.0, 22000.0, 1.0, 0],
            'NRG.Volt'   => [VARIABLETYPE_FLOAT, ' V', 0.0, 260.0, 0.1, 1],
            'NRG.Ampere' => [VARIABLETYPE_FLOAT, ' A', 0.0, 80.0, 0.1, 1],
        ];
    }

    public function getEnumProfiles()
    {
        $avail = [];
        foreach (self::AVAIL_LABELS as $k => $label) {
            $avail[$k] = [$label, $k === 2 ? 0x27D07F : 0x7A8A99];
        }
        return ['CHB.AlfenAvail' => $avail];
    }

    public function readValues($mb, $hub)
    {
        $state = $mb->readHolding(self::REG_AVAILABILITY, 1);
        $ok    = ($state !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }
        $hub->SetVarInt('state', $mb->u16($state, 0));

        $power = $mb->readHolding(self::REG_POWER_SUM, 2);
        if ($power !== null) {
            $hub->SetVarFloat('power', $mb->readFloat32($power, 0));
        }

        $actual = $mb->readHolding(self::REG_ACTUAL_CURRENT, 2);
        if ($actual !== null) {
            $hub->SetVarFloat('actual_curr', $mb->readFloat32($actual, 0));
        }

        if ($hub->GroupActive('GroupPhases')) {
            $v1 = $mb->readHolding(self::REG_VOLTAGE_L1, 2);
            $v2 = $mb->readHolding(self::REG_VOLTAGE_L2, 2);
            $v3 = $mb->readHolding(self::REG_VOLTAGE_L3, 2);
            $i1 = $mb->readHolding(self::REG_CURRENT_L1, 2);
            $i2 = $mb->readHolding(self::REG_CURRENT_L2, 2);
            $i3 = $mb->readHolding(self::REG_CURRENT_L3, 2);
            if ($v1 !== null) { $hub->SetVarFloat('voltage_l1', $mb->readFloat32($v1, 0)); }
            if ($v2 !== null) { $hub->SetVarFloat('voltage_l2', $mb->readFloat32($v2, 0)); }
            if ($v3 !== null) { $hub->SetVarFloat('voltage_l3', $mb->readFloat32($v3, 0)); }
            if ($i1 !== null) { $hub->SetVarFloat('current_l1', $mb->readFloat32($i1, 0)); }
            if ($i2 !== null) { $hub->SetVarFloat('current_l2', $mb->readFloat32($i2, 0)); }
            if ($i3 !== null) { $hub->SetVarFloat('current_l3', $mb->readFloat32($i3, 0)); }
        }

        return true;
    }

    public function writeControl($mb, $hub, string $ident, $value)
    {
        switch ($ident) {
            case 'ctl_enable':
                $enable = (bool)$value;
                // Ladefreigabe = Slave-Steuerung aktivieren + aktuelles Stromlimit
                // (0 A, wenn Freigabe entzogen wird) für 60 s gültig schreiben.
                $limit = $enable ? max(6.0, (float)$hub->GetVarValue('ctl_curr_limit')) : 0.0;
                $mb->writeFloat32(self::REG_SETPOINT_CURR, $limit);
                $mb->writeSingle(self::REG_SETPOINT_TTL, 60);
                $mb->writeSingle(self::REG_SLAVE_CONTROL, 1);
                $hub->SetVarBool('ctl_enable', $enable);
                break;

            case 'ctl_curr_limit':
                $amp = max(0.0, min((float)$hub->GetMaxCurrentA(), (float)$value));
                $mb->writeFloat32(self::REG_SETPOINT_CURR, $amp);
                $mb->writeSingle(self::REG_SETPOINT_TTL, 60);
                $mb->writeSingle(self::REG_SLAVE_CONTROL, 1);
                $hub->SetVarFloat('ctl_curr_limit', $amp);
                break;
        }
    }
}

// ---------------------------------------------------------------------------
// HeidelbergDriver — Heidelberg Energy Control. Registeradressen laut
// Heidelberg "Modbus Register Map" (offizielles PDF, weit verbreitet u. a.
// in evcc/openWB) — UNGETESTET an echter Hardware, bitte verifizieren.
// Standard Unit-ID 1, alle Register FC 0x03 lesend / FC 0x06 schreibend.
// ---------------------------------------------------------------------------

class HeidelbergDriver implements ChargerDriverInterface
{
    const REG_VERSION      = 4;
    const REG_STATE        = 5;  // 2=Kein Fahrzeug,3=Fahrzeug erkannt,4=bereit,5=lädt,6=Fehler,7=lädt(reduziert),8=lädt(abgeschlossen)
    const REG_CURRENTS     = 6;  // 6/7/8 = L1/L2/L3, 0,1 A
    const REG_TEMP         = 9;  // 0,1 °C
    const REG_VOLTAGES     = 10; // 10/11/12 = L1/L2/L3, V
    const REG_POWER        = 14; // W

    const REG_STANDBY_CTL  = 257; // 4 = Standby-Steuerung aktivieren (einmalig setzen)
    const REG_CURR_LIMIT   = 261; // 0,1 A: 0=Laden gesperrt, 60–320 = 6,0–32,0 A

    const STATES = [
        2 => 'Kein Fahrzeug', 3 => 'Fahrzeug erkannt', 4 => 'Bereit', 5 => 'Lädt',
        6 => 'Fehler', 7 => 'Lädt (reduziert)', 8 => 'Lädt (abgeschlossen)',
    ];

    public function getBaseVars()
    {
        return [
            ['connected', 'Verbindung',  'B', '~Alert.Reversed', false, 'errors', ''],
            ['state',     'Ladestatus',  'I', 'CHB.HdbState',    true,  'device', 'Holding 5'],
            ['vehicle_plugged', 'Fahrzeug verbunden', 'B', '~Switch', false, 'device', 'abgeleitet: Status 3-8 (ohne 6)'],
            ['power',     'Leistung',    'F', 'NRG.Watt',        true,  'device', 'Holding 14'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPhases' => ['caption' => 'Spannung/Strom je Phase + Temperatur', 'vars' => [
                ['current_l1', 'Strom L1',      'F', 'NRG.Ampere', false, 'phases', 'Holding 6'],
                ['current_l2', 'Strom L2',      'F', 'NRG.Ampere', false, 'phases', 'Holding 7'],
                ['current_l3', 'Strom L3',      'F', 'NRG.Ampere', false, 'phases', 'Holding 8'],
                ['voltage_l1', 'Spannung L1',   'F', 'NRG.Volt',   false, 'phases', 'Holding 10'],
                ['voltage_l2', 'Spannung L2',   'F', 'NRG.Volt',   false, 'phases', 'Holding 11'],
                ['voltage_l3', 'Spannung L3',   'F', 'NRG.Volt',   false, 'phases', 'Holding 12'],
                ['pcb_temp',   'PCB-Temperatur','F', 'NRG.Celsius',false, 'device', 'Holding 9'],
            ]],
            'GroupControl' => ['caption' => 'Steuerung (Ladefreigabe, Stromlimit)', 'vars' => [
                ['ctl_enable',     'Ladefreigabe',   'B', '~Switch',   false, 'control', 'RW Holding 261'],
                ['ctl_curr_limit', 'Stromlimit (A)', 'I', 'CHB.Ampere6to32', false, 'control', 'RW Holding 261'],
            ]],
        ];
    }

    public function getProfiles()
    {
        return [
            'NRG.Watt'          => [VARIABLETYPE_FLOAT,   ' W', 0.0, 22000.0, 1.0, 0],
            'NRG.Volt'          => [VARIABLETYPE_FLOAT,   ' V', 0.0, 260.0, 0.1, 1],
            'NRG.Ampere'        => [VARIABLETYPE_FLOAT,   ' A', 0.0, 80.0, 0.1, 1],
            'NRG.Celsius'       => [VARIABLETYPE_FLOAT,   ' °C', -20.0, 100.0, 0.1, 1],
            'CHB.Ampere6to32'   => [VARIABLETYPE_INTEGER, ' A', 0, 32, 1, 0],
        ];
    }

    public function getEnumProfiles()
    {
        $states = [];
        foreach (self::STATES as $k => $label) {
            $color = in_array($k, [5, 7, 8], true) ? 0x27D07F : (($k === 6) ? 0xE74C3C : 0x7A8A99);
            $states[$k] = [$label, $color];
        }
        return ['CHB.HdbState' => $states];
    }

    public function readValues($mb, $hub)
    {
        $state = $mb->readHolding(self::REG_STATE, 1);
        $ok    = ($state !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }
        $st = $mb->u16($state, 0);
        $hub->SetVarInt('state', $st);
        // 2 = kein Fahrzeug; 3-8 = Fahrzeug erkannt/lädt/fertig (6 = Fehler,
        // dort ist der Steckzustand unbekannt -> nicht als verbunden werten).
        $hub->SetVarBool('vehicle_plugged', in_array($st, [3, 4, 5, 7, 8], true));

        $power = $mb->readHolding(self::REG_POWER, 1);
        if ($power !== null) {
            $hub->SetVarFloat('power', (float)$mb->u16($power, 0));
        }

        if ($hub->GroupActive('GroupPhases')) {
            $curr = $mb->readHolding(self::REG_CURRENTS, 3);
            if ($curr !== null) {
                $hub->SetVarFloat('current_l1', $mb->u16($curr, 0) / 10.0);
                $hub->SetVarFloat('current_l2', $mb->u16($curr, 1) / 10.0);
                $hub->SetVarFloat('current_l3', $mb->u16($curr, 2) / 10.0);
            }
            $volt = $mb->readHolding(self::REG_VOLTAGES, 3);
            if ($volt !== null) {
                $hub->SetVarFloat('voltage_l1', (float)$mb->u16($volt, 0));
                $hub->SetVarFloat('voltage_l2', (float)$mb->u16($volt, 1));
                $hub->SetVarFloat('voltage_l3', (float)$mb->u16($volt, 2));
            }
            $temp = $mb->readHolding(self::REG_TEMP, 1);
            if ($temp !== null) {
                $hub->SetVarFloat('pcb_temp', $mb->s16($temp, 0) / 10.0);
            }
        }

        return true;
    }

    public function writeControl($mb, $hub, string $ident, $value)
    {
        // Standby-Steuerung vor dem ersten Schreibzugriff einmalig aktivieren
        // (laut Heidelberg-Doku Voraussetzung für externe Stromvorgabe).
        $mb->writeSingle(self::REG_STANDBY_CTL, 4);

        switch ($ident) {
            case 'ctl_enable':
                $enable = (bool)$value;
                $limit  = $enable ? max(6, (int)$hub->GetVarValue('ctl_curr_limit')) : 0;
                if ($mb->writeSingle(self::REG_CURR_LIMIT, $limit * 10)) {
                    $hub->SetVarBool('ctl_enable', $enable);
                }
                break;

            case 'ctl_curr_limit':
                // 0 = Laden sperren (siehe Profil CHB.Ampere6to32, das 0 erlaubt);
                // sonst laut Doku nur 6–32 A gültig.
                $amp = (int)$value === 0 ? 0 : max(6, min($hub->GetMaxCurrentA(), (int)$value));
                if ($mb->writeSingle(self::REG_CURR_LIMIT, $amp * 10)) {
                    $hub->SetVarInt('ctl_curr_limit', $amp);
                }
                break;
        }
    }
}

// ---------------------------------------------------------------------------
// GoeChargerDriver — go-eCharger Gemini/HOME+ (API v2, Modbus TCP; muss laut
// offizieller Doku erst über App/HTTP-API aktiviert werden). Registeradressen
// gemäß offizieller Doku https://github.com/goecharger/go-eCharger-API-v2
// (modbus-de.md, Stand des dortigen main-Branchs), am 2026-07-22 abgerufen —
// im Unterschied zu den anderen drei Treibern also gegen die verbindliche
// Herstellerquelle geprüft, aber weiterhin NICHT an echter Hardware getestet.
//
// Wichtige Eigenheiten laut Doku:
// - FC 0x06 (Preset Single Register) wird NICHT unterstützt — Schreiben
//   erfolgt immer über FC 0x16 (writeMultiple), auch für ein einzelnes
//   Register.
// - Registeradressen hier sind die "wire format"-Adressen (Wert in Klammern
//   in der Doku, z. B. 200 für ALLOW/40201) — dieselbe 0-basierte Konvention
//   wie bei den anderen drei Treibern.
// - Firmware 60.3 hatte laut Doku eine vertauschte Byte-Reihenfolge bei
//   32-Bit-Werten, seit 60.4 behoben. Für 60.3 die Option "Byte-Reihenfolge
//   getauscht" aktivieren.
// - Ladefreigabe: FORCE_STATE (Register 337) ist laut HTTP-API-Doku (api key
//   "frc") die für Automatisierung vorgesehene R/W-Steuerung
//   (Neutral=0, Off=1, On=2) — nicht das ebenfalls vorhandene ALLOW-Register
//   (200), das laut HTTP-API nur lesend ("alw") als reiner Status gedacht ist.
// ---------------------------------------------------------------------------

class GoeChargerDriver implements ChargerDriverInterface
{
    // Holding-Register (FC 0x03 lesend, FC 0x16 schreibend)
    const REG_ALLOW           = 200; // U16, RO-Status laut HTTP-API (alw) — nur informativ
    const REG_ACCESS_STATE    = 201; // U16: 0=Offen,1=RFID/App,2=Strompreis/automatisch,3=Scheduler
    const REG_CABLE_LOCK_MODE = 204; // U16: 0=Verriegelt solange Auto an,1=Autom. entriegeln,2=Immer verriegelt
    const REG_AMPERE_MAX      = 211; // U16, absolutes Maximum (App-Einstellung)
    const REG_LED_BRIGHTNESS  = 206; // U16, 0-255
    const REG_AMPERE_VOLATILE = 299; // U16, 6-32A, NICHT im EEPROM gespeichert — für Automatisierung vorgesehen
    const REG_PHASE_SWITCH    = 332; // U16 (api key psm): 0=Auto,1=1-phasig,2=3-phasig (ab FW 55.5)
    const REG_ENERGY_LIMIT    = 333; // Float64, 4 Register (api key dwo): Wh, Inf = kein Limit (ab FW 55.5)
    const REG_FORCE_STATE     = 337; // U16: 0=Neutral (automatisch/App),1=Aus (erzwungen),2=An (erzwungen)

    // Input-Register (FC 0x04, nur lesend)
    const REG_CAR_STATE   = 100; // U16: 0=unbekannt/defekt,1=bereit(kein Fzg),2=lädt,3=wartet auf Fzg,4=beendet(Fzg verbunden)
    const REG_PP_CABLE    = 101; // U16: 13-32=Kabel-Ampere-Codierung, 0=kein Kabel
    const REG_FWV         = 105; // ASCII, 2 Register (4 Byte)
    const REG_ERROR       = 107; // U16: 1=RCCB,3=Phasenstörung,8=Erdungsfehler,10=INTERNAL(Standard bei Fehler)
    const REG_VOLT_L1     = 108; // U32, 2 Register, V
    const REG_VOLT_L2     = 110;
    const REG_VOLT_L3     = 112;
    const REG_AMP_L1      = 114; // U32, 2 Register, 0,1 A
    const REG_AMP_L2      = 116;
    const REG_AMP_L3      = 118;
    const REG_POWER_TOTAL = 120; // U32, 2 Register, 0,01 W
    const REG_ENERGY_TOTAL  = 128; // U32, 2 Register, 0,1 kWh (Gesamt seit Inbetriebnahme)
    const REG_ENERGY_CHARGE = 132; // U32, 2 Register, Deka-Wattsekunden (Ws*10) — aktuelle/letzte Ladesitzung
    const REG_VOLT_N      = 144;
    const REG_POWER_L1    = 146; // U32, 2 Register, 0,1 kW
    const REG_POWER_L2    = 148;
    const REG_POWER_L3    = 150;
    const REG_ADAPTER     = 202; // U16: 0=kein Adapter, 1=16A-Adapter
    const REG_UNLOCKED_BY = 203; // U16: Nummer der RFID-Karte des akt. Ladevorgangs
    const REG_PHASES      = 205; // U16 Bitmaske: Bits 0-2 = L1/L2/L3 NACH dem Schütz
    const REG_SNR         = 304; // ASCII, 6 Register (12 Byte)

    const CAR_STATES = [
        0 => 'Unbekannt / Ladestation defekt', 1 => 'Bereit, kein Fahrzeug', 2 => 'Fahrzeug lädt',
        3 => 'Wartet auf Fahrzeug', 4 => 'Ladung beendet, Fahrzeug verbunden',
    ];

    const ERRORS = [
        0 => 'Kein Fehler', 1 => 'RCCB (Fehlerstromschutzschalter)', 3 => 'Phasenstörung',
        8 => 'Erdungsfehler', 10 => 'Interner Fehler',
    ];

    const PHASE_MODES = [0 => 'Automatisch', 1 => '1-phasig', 2 => '3-phasig'];
    const ACCESS_MODES = [0 => 'Offen', 1 => 'RFID/App', 2 => 'Strompreis/automatisch', 3 => 'Scheduler'];
    const CABLE_LOCK_MODES = [0 => 'Verriegelt solange Auto angesteckt', 1 => 'Nach Ladevorgang entriegeln', 2 => 'Immer verriegelt'];

    // U32 mit optionalem Wort-Swap. Rückgabe null bei 0xFFFFFFFF: Unbelegte
    // Register beantwortet die go-e-Firmware mit 0xFF-Füllwerten statt einer
    // Modbus-Exception (an echtem go-e Controller verifiziert, MeterHub-
    // Befund) — ungefiltert landete daraus z. B. eine Leistung von
    // 42.949.672,95 W in einer archivierten Variable.
    private function u32($mb, $hub, $regs, $offset)
    {
        $v = $hub->GroupActive('GoeWordSwap') ? $mb->u32sw($regs, $offset) : $mb->u32($regs, $offset);
        return ($v === 0xFFFFFFFF) ? null : $v;
    }

    public function getBaseVars()
    {
        return [
            ['connected',      'Verbindung',            'B', '~Alert.Reversed', false, 'errors', ''],
            ['state',          'Ladestatus',             'I', 'CHB.GoeCarState', true,  'device', 'Input 100'],
            ['vehicle_plugged','Fahrzeug verbunden',     'B', '~Switch',         false, 'device', 'abgeleitet: CAR_STATE 2/3/4'],
            ['power',          'Ladeleistung',           'F', 'NRG.Watt',        true,  'device', 'Input 120-121 (0,01 W)'],
            ['energy_session', 'Energie akt. Sitzung',   'F', 'CHB.kWhSession',  true,  'device', 'Input 132-133 (Deka-Ws)'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPhases' => ['caption' => 'Spannung/Strom/Leistung je Phase', 'vars' => [
                ['voltage_l1', 'Spannung L1', 'F', 'NRG.Volt',   false, 'phases', 'Input 108-109 (V)'],
                ['voltage_l2', 'Spannung L2', 'F', 'NRG.Volt',   false, 'phases', 'Input 110-111 (V)'],
                ['voltage_l3', 'Spannung L3', 'F', 'NRG.Volt',   false, 'phases', 'Input 112-113 (V)'],
                ['voltage_n',  'Spannung N',  'F', 'NRG.Volt',   false, 'phases', 'Input 144-145 (V)'],
                ['current_l1', 'Strom L1',    'F', 'NRG.Ampere', false, 'phases', 'Input 114-115 (0,1 A)'],
                ['current_l2', 'Strom L2',    'F', 'NRG.Ampere', false, 'phases', 'Input 116-117 (0,1 A)'],
                ['current_l3', 'Strom L3',    'F', 'NRG.Ampere', false, 'phases', 'Input 118-119 (0,1 A)'],
                ['power_l1',   'Leistung L1', 'F', 'NRG.Watt',   false, 'phases', 'Input 146-147 (0,1 kW)'],
                ['power_l2',   'Leistung L2', 'F', 'NRG.Watt',   false, 'phases', 'Input 148-149 (0,1 kW)'],
                ['power_l3',   'Leistung L3', 'F', 'NRG.Watt',   false, 'phases', 'Input 150-151 (0,1 kW)'],
                ['phases_charging', 'Genutzte Phasen (nach Schütz)', 'I', 'CHB.GoePhaseCount', false, 'phases', 'Input 205 (Bitmaske)'],
            ]],
            'GroupDevice' => ['caption' => 'Geräteinformation', 'vars' => [
                ['dev_serial',    'Seriennummer',        'S', '', false, 'device', 'Input 304-309 (ASCII)'],
                ['dev_firmware',  'Firmware-Version',    'S', '', false, 'device', 'Input 105-106 (ASCII)'],
                ['energy_total',  'Energie gesamt',      'F', 'NRG.kWh', true, 'device', 'Input 128-129 (0,1 kWh)'],
                ['dev_error',     'Fehlercode',          'I', 'CHB.GoeError', true, 'errors', 'Input 107'],
                ['cable_current', 'Kabel-Strombegrenzung','I', 'CHB.Ampere6to32', false, 'device', 'Input 101 (13-32, 0=kein Kabel)'],
                ['adapter',       'Adapter angesteckt',  'B', '~Switch', false, 'device', 'Input 202 (1=16A-Adapter)'],
                ['unlocked_by',   'Entsperrt durch RFID-Karte', 'I', '', false, 'device', 'Input 203'],
            ]],
            'GroupControl' => ['caption' => 'Steuerung (Ladefreigabe, Stromlimit, Phasen, Energie-Limit …)', 'vars' => [
                ['ctl_enable',       'Ladefreigabe',        'B', '~Switch',           false, 'control', 'RW Holding 337 (FORCE_STATE)'],
                ['ctl_curr_limit',   'Stromlimit (A)',      'I', 'CHB.Ampere6to32',   false, 'control', 'RW Holding 299 (AMPERE_VOLATILE)'],
                ['ctl_phase_mode',   'Phasenumschaltung',   'I', 'CHB.GoePhaseMode',  false, 'control', 'RW Holding 332 (psm, ab FW 55.5)'],
                ['ctl_access',       'Zugangskontrolle',    'I', 'CHB.GoeAccess',     false, 'control', 'RW Holding 201 (ACCESS_STATE)'],
                ['ctl_cable_lock',   'Kabelverriegelung',   'I', 'CHB.GoeCableLock',  false, 'control', 'RW Holding 204'],
                ['ctl_energy_limit', 'Energie-Limit Ladevorgang (0 = kein Limit)', 'F', 'CHB.kWhLimit', false, 'control', 'RW Holding 333-336 (dwo, Float64 Wh)'],
                ['ctl_led',          'LED-Helligkeit',      'I', 'CHB.Led255',        false, 'control', 'RW Holding 206 (0-255)'],
            ]],
            // Keine eigenen Variablen — reine Konfigurations-Checkbox (nutzt die
            // generische Datenpunkt-Gruppen-Infrastruktur mit). Default AUS, da
            // nur die veraltete Firmware 60.3 betroffen ist.
            'GoeWordSwap' => ['caption' => 'Byte-Reihenfolge getauscht (nur Firmware 60.3 — mit 60.4 behoben)', 'vars' => [], 'default' => false],
        ];
    }

    public function getProfiles()
    {
        return [
            'NRG.Watt'        => [VARIABLETYPE_FLOAT,   ' W', 0.0, 22000.0, 1.0, 0],
            'NRG.kWh'         => [VARIABLETYPE_FLOAT,   ' kWh', 0.0, 9999999.0, 0.01, 2],
            // Eigenes Suffix: hält die MeterHub-Zählersuche vom rückspringenden
            // Sitzungswert fern (siehe KebaDriver::getProfiles).
            'CHB.kWhSession'  => [VARIABLETYPE_FLOAT,   ' kWh (Sitzung)', 0.0, 999.0, 0.01, 2],
            'NRG.Volt'        => [VARIABLETYPE_FLOAT,   ' V', 0.0, 260.0, 0.1, 1],
            'NRG.Ampere'      => [VARIABLETYPE_FLOAT,   ' A', 0.0, 80.0, 0.1, 1],
            'CHB.Ampere6to32' => [VARIABLETYPE_INTEGER, ' A', 0, 32, 1, 0],
            // Suffix bewusst NICHT " kWh": hält die MeterHub-Zählersuche fern
            // (Limit-Sollwert, kein Zählerstand).
            'CHB.kWhLimit'    => [VARIABLETYPE_FLOAT,   ' kWh (Limit)', 0.0, 200.0, 0.5, 1],
            'CHB.Led255'      => [VARIABLETYPE_INTEGER, '', 0, 255, 5, 0],
        ];
    }

    public function getEnumProfiles()
    {
        $states = [];
        foreach (self::CAR_STATES as $k => $label) {
            $states[$k] = [$label, $k === 2 ? 0x27D07F : (($k === 0) ? 0xE74C3C : 0x7A8A99)];
        }
        $errors = [];
        foreach (self::ERRORS as $k => $label) {
            $errors[$k] = [$label, $k === 0 ? 0x7A8A99 : 0xE74C3C];
        }
        $mk = function (array $map, $activeColor = 0x2BB3C0) {
            $out = [];
            foreach ($map as $k => $label) {
                $out[$k] = [$label, $k === 0 ? 0x7A8A99 : $activeColor];
            }
            return $out;
        };
        return [
            'CHB.GoeCarState'   => $states,
            'CHB.GoeError'      => $errors,
            'CHB.GoePhaseMode'  => $mk(self::PHASE_MODES),
            'CHB.GoeAccess'     => $mk(self::ACCESS_MODES),
            'CHB.GoeCableLock'  => $mk(self::CABLE_LOCK_MODES),
            'CHB.GoePhaseCount' => [
                0 => ['0 Phasen', 0x7A8A99], 1 => ['1 Phase', 0x2BB3C0],
                2 => ['2 Phasen', 0x2BB3C0], 3 => ['3 Phasen', 0x27D07F],
            ],
        ];
    }

    public function readValues($mb, $hub)
    {
        $car = $mb->readInput(self::REG_CAR_STATE, 1);
        $ok  = ($car !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }
        $carState = $mb->u16($car, 0);
        $hub->SetVarInt('state', $carState);
        // 2=lädt, 3=wartet auf Fahrzeug(-Freigabe), 4=Ladung beendet+verbunden
        $hub->SetVarBool('vehicle_plugged', in_array($carState, [2, 3, 4], true));

        $power = $mb->readInput(self::REG_POWER_TOTAL, 2);
        $p     = ($power !== null) ? $this->u32($mb, $hub, $power, 0) : null;
        if ($p !== null) {
            $hub->SetVarFloat('power', $p / 100.0); // 0,01 W -> W
        }

        $energy = $mb->readInput(self::REG_ENERGY_CHARGE, 2);
        $e      = ($energy !== null) ? $this->u32($mb, $hub, $energy, 0) : null;
        if ($e !== null) {
            // Deka-Wattsekunden (Ws*10) -> kWh: Ws = Rohwert*10, kWh = Ws/3.600.000
            $hub->SetVarFloat('energy_session', $e * 10.0 / 3600000.0);
        }

        if ($hub->GroupActive('GroupPhases')) {
            foreach ([1, 2, 3] as $ph) {
                $vr = $mb->readInput(self::REG_VOLT_L1 + ($ph - 1) * 2, 2);
                $v  = ($vr !== null) ? $this->u32($mb, $hub, $vr, 0) : null;
                if ($v !== null) {
                    $hub->SetVarFloat('voltage_l' . $ph, (float)$v);
                }
                $ir = $mb->readInput(self::REG_AMP_L1 + ($ph - 1) * 2, 2);
                $i  = ($ir !== null) ? $this->u32($mb, $hub, $ir, 0) : null;
                if ($i !== null) {
                    $hub->SetVarFloat('current_l' . $ph, $i / 10.0);
                }
                $pr = $mb->readInput(self::REG_POWER_L1 + ($ph - 1) * 2, 2);
                $pw = ($pr !== null) ? $this->u32($mb, $hub, $pr, 0) : null;
                if ($pw !== null) {
                    $hub->SetVarFloat('power_l' . $ph, $pw * 100.0); // 0,1 kW -> W
                }
            }
            $nr = $mb->readInput(self::REG_VOLT_N, 2);
            $nv = ($nr !== null) ? $this->u32($mb, $hub, $nr, 0) : null;
            if ($nv !== null) {
                $hub->SetVarFloat('voltage_n', (float)$nv);
            }
            $phm = $mb->readInput(self::REG_PHASES, 1);
            if ($phm !== null && $mb->u16($phm, 0) !== 0xFFFF) {
                // Bits 0-2 = L1/L2/L3 NACH dem Schütz -> Anzahl aktiver Phasen
                $mask = $mb->u16($phm, 0) & 0x07;
                $hub->SetVarInt('phases_charging', substr_count(decbin($mask), '1'));
            }
        }

        if ($hub->GroupActive('GroupDevice')) {
            $sn = $mb->readInput(self::REG_SNR, 6);
            if ($sn !== null) {
                $hub->SetVarStr('dev_serial', $mb->readStr($sn, 0, 6));
            }
            $fw = $mb->readInput(self::REG_FWV, 2);
            if ($fw !== null) {
                $hub->SetVarStr('dev_firmware', $mb->readStr($fw, 0, 2));
            }
            $etotR = $mb->readInput(self::REG_ENERGY_TOTAL, 2);
            $etot  = ($etotR !== null) ? $this->u32($mb, $hub, $etotR, 0) : null;
            if ($etot !== null) {
                $hub->SetVarFloat('energy_total', $etot / 10.0);
            }
            $err = $mb->readInput(self::REG_ERROR, 1);
            if ($err !== null && $mb->u16($err, 0) !== 0xFFFF) {
                $hub->SetVarInt('dev_error', $mb->u16($err, 0));
            }
            $pp = $mb->readInput(self::REG_PP_CABLE, 1);
            if ($pp !== null && $mb->u16($pp, 0) !== 0xFFFF) {
                $hub->SetVarInt('cable_current', $mb->u16($pp, 0));
            }
            $ad = $mb->readInput(self::REG_ADAPTER, 1);
            if ($ad !== null && $mb->u16($ad, 0) !== 0xFFFF) {
                $hub->SetVarBool('adapter', $mb->u16($ad, 0) === 1);
            }
            $ub = $mb->readInput(self::REG_UNLOCKED_BY, 1);
            if ($ub !== null && $mb->u16($ub, 0) !== 0xFFFF) {
                $hub->SetVarInt('unlocked_by', $mb->u16($ub, 0));
            }
        }

        // Steuerwerte vom Gerät zurücklesen — so zeigen die ctl_*-Variablen
        // auch Änderungen aus App/Cloud/anderen Reglern, nicht nur eigene.
        if ($hub->GroupActive('GroupControl')) {
            $frc = $mb->readHolding(self::REG_FORCE_STATE, 1);
            if ($frc !== null && $mb->u16($frc, 0) !== 0xFFFF) {
                // FORCE_STATE: 0=Neutral (Gerät/App entscheidet), 1=Aus, 2=An.
                // Als Bool: nur der erzwungene Aus-Zustand gilt als "gesperrt".
                $hub->SetVarBool('ctl_enable', $mb->u16($frc, 0) !== 1);
            }
            $amp = $mb->readHolding(self::REG_AMPERE_VOLATILE, 1);
            if ($amp !== null && $mb->u16($amp, 0) !== 0xFFFF) {
                $hub->SetVarInt('ctl_curr_limit', $mb->u16($amp, 0));
            }
            $psm = $mb->readHolding(self::REG_PHASE_SWITCH, 1);
            if ($psm !== null && $mb->u16($psm, 0) <= 2) {
                $hub->SetVarInt('ctl_phase_mode', $mb->u16($psm, 0));
            }
            $acc = $mb->readHolding(self::REG_ACCESS_STATE, 1);
            if ($acc !== null && $mb->u16($acc, 0) <= 3) {
                $hub->SetVarInt('ctl_access', $mb->u16($acc, 0));
            }
            $cul = $mb->readHolding(self::REG_CABLE_LOCK_MODE, 1);
            if ($cul !== null && $mb->u16($cul, 0) <= 2) {
                $hub->SetVarInt('ctl_cable_lock', $mb->u16($cul, 0));
            }
            $led = $mb->readHolding(self::REG_LED_BRIGHTNESS, 1);
            if ($led !== null && $mb->u16($led, 0) <= 255) {
                $hub->SetVarInt('ctl_led', $mb->u16($led, 0));
            }
            $lim = $mb->readHolding(self::REG_ENERGY_LIMIT, 4);
            if ($lim !== null) {
                $wh = $mb->readDouble64($lim, 0);
                // Inf/NaN = "kein Limit" (dwo=null) -> 0 anzeigen.
                $hub->SetVarFloat('ctl_energy_limit', is_finite($wh) ? $wh / 1000.0 : 0.0);
            }
        }

        return true;
    }

    // go-e unterstützt FC 0x06 nicht (siehe Klassenkommentar) — jeder
    // Schreibzugriff muss über writeMultiple (FC 0x16) laufen, auch für ein
    // einzelnes Register.
    public function writeControl($mb, $hub, string $ident, $value)
    {
        switch ($ident) {
            case 'ctl_enable':
                $val = (bool)$value ? 2 : 1; // FORCE_STATE: 1=Aus (erzwungen), 2=An (erzwungen)
                if ($mb->writeMultiple(self::REG_FORCE_STATE, [$val])) {
                    $hub->SetVarBool('ctl_enable', (bool)$value);
                }
                break;

            case 'ctl_curr_limit':
                $amp = max(6, min($hub->GetMaxCurrentA(), (int)$value));
                if ($mb->writeMultiple(self::REG_AMPERE_VOLATILE, [$amp])) {
                    $hub->SetVarInt('ctl_curr_limit', $amp);
                }
                break;

            case 'ctl_phase_mode':
                $val = (int)$value;
                if ($val < 0 || $val > 2) { return; }
                if ($mb->writeMultiple(self::REG_PHASE_SWITCH, [$val])) {
                    $hub->SetVarInt('ctl_phase_mode', $val);
                }
                break;

            case 'ctl_access':
                $val = (int)$value;
                if ($val < 0 || $val > 3) { return; }
                if ($mb->writeMultiple(self::REG_ACCESS_STATE, [$val])) {
                    $hub->SetVarInt('ctl_access', $val);
                }
                break;

            case 'ctl_cable_lock':
                $val = (int)$value;
                if ($val < 0 || $val > 2) { return; }
                if ($mb->writeMultiple(self::REG_CABLE_LOCK_MODE, [$val])) {
                    $hub->SetVarInt('ctl_cable_lock', $val);
                }
                break;

            case 'ctl_energy_limit':
                $kwh = max(0.0, (float)$value);
                // 0 = Limit deaktivieren -> laut Doku Float64 Inf schreiben.
                $wh  = ($kwh <= 0.0) ? INF : $kwh * 1000.0;
                if ($mb->writeDouble64(self::REG_ENERGY_LIMIT, $wh)) {
                    $hub->SetVarFloat('ctl_energy_limit', $kwh);
                }
                break;

            case 'ctl_led':
                $val = max(0, min(255, (int)$value));
                if ($mb->writeMultiple(self::REG_LED_BRIGHTNESS, [$val])) {
                    $hub->SetVarInt('ctl_led', $val);
                }
                break;
        }
    }
}

// ---------------------------------------------------------------------------
// ChargerHub — Hauptmodul
// ---------------------------------------------------------------------------

class ChargerHub extends IPSModule
{
    private const ATTR_REVIEW_HINT_GONE = 'ReviewHintDismissed';

    // „Was ist neu"-Banner (siehe newsBanner()/AckNews()) — Verbund-Konvention
    // für die Formular-Optik (SUITE.md, Referenz InverterHub).
    private const NEWS_VERSION = '0.9.24';
    private const NEWS_ITEMS = [
        'Ladefreigabe/Stromlimit sind jetzt wirklich bedienbar (Console/WebFront) — Ursache war eine falsche SDK-API bei der Steuer-Variablen-Verknüpfung, live durchleuchtet und behoben.',
        'Neu: RFID-Kartenzähler (Name + Energie je Karte, 0–9) für go-eCharger über MQTT — Modbus bietet diese Werte nicht an. Siehe Panel „RFID-Kartenzähler" (Broker-IP direkt im Panel eintragen, keine zusätzliche Instanz nötig).',
    ];

    private const DRIVERS = [
        'keba'       => 'KebaDriver',
        'alfen'      => 'AlfenDriver',
        'heidelberg' => 'HeidelbergDriver',
        'goe'        => 'GoeChargerDriver',
    ];

    // Hardware-Obergrenze des Ladestroms je Hersteller (A) — der wirksame
    // Clamp ist min(Hardware, Property MaxCurrent).
    private const DRIVER_MAX_CURRENT = [
        'keba'       => 63,
        'alfen'      => 32,
        'heidelberg' => 16,
        'goe'        => 32,
    ];
    private const MIN_CURRENT = 6; // A — kleinster IEC-61851-Ladestrom

    // Regler-Kennzeichnung (Verbund-Vokabular, mit EMS abgestimmt). Wer hat die
    // Hoheit über diesen Ladepunkt? Wird als 'managedBy' im Vertrag gemeldet;
    // bei allem außer 'none'/'ems' steuert das EMS NICHT selbst.
    private const MANAGEDBY_ALL = ['none', 'ems', 'goe-controller', 'tibber', 'p14a', 'marketer', 'other'];
    private const MANAGEDBY_LABELS = [
        'none'           => 'Niemand — frei / manuell (Standard)',
        'ems'            => 'Energiemanagement (EMS)',
        'goe-controller' => 'go-e Controller (Überschussladen)',
        'tibber'         => 'Tibber (Regelenergie / Grid Rewards)',
        'p14a'           => '§14a-Steuerung (Netzbetreiber)',
        'marketer'       => 'Direktvermarkter',
        'other'          => 'Anderes externes Lastmanagement',
    ];
    // 'goe-controller' ist nur für den go-eCharger sinnvoll (der go-e Controller
    // regelt nur go-e-Wallboxen). Andere Hersteller bekommen die Auswahl nicht.
    private const MANAGEDBY_ONLY_GOE = ['goe-controller'];

    private $driver = null;

    public function Create()
    {
        parent::Create();

        $this->RegisterAttributeString('SeenNews', '');
        $this->RegisterAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, false);
        // Einmal-Marker für die 0.9.14-Migration (control-Variablen ohne
        // Kernel-Standardaktion neu anlegen, siehe RegisterVar()). Ein
        // Attribut statt einer Live-Zustandsprüfung, weil sich Letzteres live
        // als nicht zuverlässig herausstellte — 0.9.14 löschte/erzeugte die
        // Steuervariablen dadurch bei JEDEM Übernehmen neu (IDs wechselten
        // ständig), statt nur einmalig zu migrieren.
        $this->RegisterAttributeBoolean('ControlActionsMigrated', false);

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('Manufacturer', 'keba');
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyInteger('UnitId', 255);
        $this->RegisterPropertyInteger('IntervalFast', 10);
        // Zwei-Regler-Schutz: WER hat die Hoheit über diesen Ladepunkt?
        // (Verbund-Vokabular, siehe MANAGEDBY_ALL). Per Modbus nicht erkennbar
        // (die Lastmanagement-Zustände loe/loa liegen nur in der HTTP/MQTT-API),
        // daher manuelle Auswahl. Wird über CHUB_GetFunctions als 'managedBy'
        // gemeldet; bei allem außer 'none'/'ems' nimmt das EMS den Ladepunkt von
        // der eigenen Steuerung aus, sonst überschreiben sich zwei Regler.
        $this->RegisterPropertyString('ManagedBy', 'none');
        // Alt-Property (bis 0.8.x): boolescher Schalter. Bleibt registriert,
        // damit bestehende Instanzen nach dem Update nicht mit „Eigenschaft
        // nicht gefunden" scheitern; wird als Rückfall gelesen (siehe
        // GetManagedBy) und im Formular nicht mehr angezeigt.
        $this->RegisterPropertyBoolean('ExternallyManaged', false);
        // Maximaler Anschlussstrom (A) der Zuleitung/Absicherung dieses
        // Ladepunkts. Harter Clamp in jedem Treiber-Schreibzugriff — letzte
        // Verteidigungslinie unabhängig vom EMS (EMS-Vertragsabsprache).
        $this->RegisterPropertyInteger('MaxCurrent', 16);
        // go-e-exklusiv: RFID-Kartenzähler (Name + Energie je Karte 0–9)
        // stehen NICHT über Modbus zur Verfügung (offizielle Registertabelle
        // enthält sie nicht), nur über MQTT (Topics go-eCharger/<Seriennummer>/
        // c0n…c9n bzw. c0e…c9e). Eigener roher MQTT-Client (CHUB_MqttMiniClient),
        // daher direkt Host/Port statt einer Symcon-Splitter-Verknüpfung.
        $this->RegisterPropertyBoolean('MqttCardsEnabled', false);
        $this->RegisterPropertyString('MqttHost', '');
        $this->RegisterPropertyInteger('MqttPort', 1883);

        // Treiber-spezifische Gruppen-Properties für ALLE Treiber registrieren
        // (Create() legt Properties einmalig zum Erstellungszeitpunkt an; der
        // gewünschte Manufacturer-Wert wird oft erst danach über die Konfiguration
        // gesetzt, z. B. von der Discovery-Instanz). Ungenutzte Properties anderer
        // Treiber bleiben einfach unbenutzt — unschädlich.
        $allProps = [];
        foreach (self::DRIVERS as $driverClass) {
            $drv = new $driverClass();
            foreach ($drv->getOptionalGroups() as $propName => $group) {
                if (!array_key_exists($propName, $allProps)) {
                    $allProps[$propName] = $group['default'] ?? true;
                }
            }
        }
        foreach ($allProps as $name => $default) {
            $this->RegisterPropertyBoolean($name, $default);
        }

        $this->RegisterTimer('FastTimer', 0, 'CHUB_Update($_IPS[\'TARGET\']);');
        $this->RegisterTimer('EnableActionsTimer', 0, 'CHUB_EnableActions($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->CreateProfiles();
        $this->RegisterVariables();

        if (!$this->ReadPropertyBoolean('Active') || $this->ReadPropertyString('Host') === '') {
            $this->SetTimerInterval('FastTimer', 0);
            $this->SetTimerInterval('EnableActionsTimer', 0);
            $this->SetStatus(104);
            return;
        }

        $this->SetTimerInterval('FastTimer', $this->ReadPropertyInteger('IntervalFast') * 1000);
        $this->SetTimerInterval('EnableActionsTimer', 200);
        $this->SetStatus(102);
    }

    // Wird 200 ms nach ApplyChanges einmalig aufgerufen (Muster wie
    // InverterHub) — setzt die Custom Action, die Ladefreigabe/Stromlimit
    // etc. in der Konsole bedienbar macht.
    public function EnableActions()
    {
        $this->SetTimerInterval('EnableActionsTimer', 0);
        $this->SetControlActions();
    }

    private function SetControlActions()
    {
        $driver = $this->GetDriver();
        // Zweiter Parameter von IPS_SetVariableCustomAction ist keine
        // Instanz-ID, sondern laut Doku 0 = Standardaktion aktivieren,
        // 1 = deaktivieren, >1 = konkrete Skript-ID. Die zuvor übergebene
        // Instanz-ID wurde als (nicht existente) Skript-ID interpretiert,
        // daher "Skript #<InstanceID> existiert nicht".
        foreach ($driver->getOptionalGroups() as $group) {
            foreach ($group['vars'] as $v) {
                if ($v[5] === 'control') {
                    $vid = $this->FindVarByIdent($v[0]);
                    if ($vid) {
                        IPS_SetVariableCustomAction($vid, 0);
                    }
                }
            }
        }
    }

    public function Update()
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        $this->GetDriver()->readValues($this->GetModbusClient(), $this);
        if ($this->ReadPropertyString('Manufacturer') === 'goe' && $this->ReadPropertyBoolean('MqttCardsEnabled')) {
            // Die Seriennummer (fürs Topic nötig) ist erst NACH dem ersten
            // erfolgreichen Modbus-Lesevorgang bekannt — daher hier statt in
            // ApplyChanges/direkt am Timer.
            $this->PollMqttCards();
        }
    }

    // Eigener, roher MQTT-Poll — kein Symcon-Splitter/Parent-Instanz nötig,
    // exakt dasselbe Prinzip wie GetModbusClient()/CHUB_ModbusTcpClient.
    private function PollMqttCards(): void
    {
        $host = trim($this->ReadPropertyString('MqttHost'));
        $serial = trim((string)$this->GetVarValue('dev_serial'));
        if ($host === '' || $serial === '') {
            return;
        }
        $client = new CHUB_MqttMiniClient($host, $this->ReadPropertyInteger('MqttPort'));
        $clientId = 'chub_' . $this->InstanceID;
        // MQTT-Wildcards gelten nur je ganzer Ebene ("+" allein, nicht "c+") —
        // daher hier breiter abonniert und im Loop unten per Regex auf
        // c0..c9 + n/e gefiltert.
        $messages = $client->fetch(['go-eCharger/' . $serial . '/+'], $clientId);
        if ($messages === [] && $client->getLastError() !== '') {
            IPS_LogMessage('ChargerHub-MQTT', 'Instanz ' . $this->InstanceID . ': ' . $client->getLastError());
            return;
        }
        foreach ($messages as $msg) {
            // Topic: go-eCharger/<Seriennummer>/c<N><n|e> — die Seriennummer
            // steht bereits im Subscribe-Filter fest, hier nur noch Index+Feld.
            if (!preg_match('#/c([0-9])([ne])$#', $msg['topic'], $m)) {
                continue;
            }
            $idx = (int)$m[1];
            $payload = json_decode($msg['payload']);
            if ($m[2] === 'n') {
                $this->SetVarStr("card{$idx}_name", is_string($payload) ? $payload : $msg['payload']);
            } else {
                // c0e…c9e: Energie in Wh (uint64) laut offizieller go-e-API-Doku.
                $wh = is_numeric($payload) ? (float)$payload : 0.0;
                $this->SetVarFloat("card{$idx}_energy", $wh / 1000.0);
            }
        }
    }

    public function RequestAction($Ident, $Value)
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        $mb = $this->GetModbusClient();
        $this->GetDriver()->writeControl($mb, $this, $Ident, $Value);
        // writeControl setzt den Variablenwert nur bei erfolgreichem Schreiben
        // (siehe Treiber) — schlägt der Modbus-Zugriff fehl, bleibt der Wert in
        // der Konsole unverändert (springt nach dem nächsten Poll sichtbar
        // zurück) und blieb bisher ohne jede Fehlermeldung. Jetzt sichtbar im
        // Meldungen-Log unter "ChargerHub-Schreibfehler".
        if ($mb->lastWriteError !== '') {
            IPS_LogMessage('ChargerHub-Schreibfehler', 'Instanz ' . $this->InstanceID . ', ' . $Ident . ': ' . $mb->lastWriteError);
        }
    }

    // Vertrag für Partnermodule (EMS, Kacheln) — Muster wie MHUB_GetFunctions.
    // Immer hinter function_exists('CHUB_GetFunctions') beim Aufrufer, siehe
    // CLAUDE.md. Der Schreib-Teil (chargeEnableID/currentLimitID) ist ein
    // erster Vorschlag und noch mit der EMS-Sitzung abzustimmen, bevor er als
    // stabiler Vertrag gilt — die Steuer-IDs sind fürs EMS bestimmt, nicht für
    // Anzeigemodule.
    // Wirksame Ladestrom-Obergrenze: Hardware-Limit des Herstellers,
    // zusätzlich begrenzt durch die Property „Maximaler Anschlussstrom".
    public function GetMaxCurrentA(): int
    {
        $hw = self::DRIVER_MAX_CURRENT[$this->ReadPropertyString('Manufacturer')] ?? 32;
        $cfg = (int)$this->ReadPropertyInteger('MaxCurrent');
        return ($cfg >= self::MIN_CURRENT) ? min($hw, $cfg) : $hw;
    }

    // Für diesen Hersteller zulässige managedBy-Werte (Teilmenge des Gesamt-
    // Vokabulars): 'goe-controller' nur beim go-eCharger.
    private function ManagedByAllowed(): array
    {
        $all = self::MANAGEDBY_ALL;
        if ($this->ReadPropertyString('Manufacturer') !== 'goe') {
            $all = array_values(array_diff($all, self::MANAGEDBY_ONLY_GOE));
        }
        return $all;
    }

    // Aufgelöste Regler-Kennzeichnung. Rückfall auf die Alt-Property
    // ExternallyManaged (bool) für Instanzen von vor 0.9.0. Ist der gespeicherte
    // Wert für den aktuellen Hersteller nicht zulässig (z. B. 'goe-controller'
    // nach Wechsel goe→keba), wird konservativ auf 'other' abgebildet — dann
    // bleibt das EMS read-only, statt den Ladepunkt fälschlich zu übernehmen.
    public function GetManagedBy(): string
    {
        $v = $this->ReadPropertyString('ManagedBy');
        if ($v === 'none' && $this->ReadPropertyBoolean('ExternallyManaged')) {
            $v = 'other'; // Migration: alter Haken „extern geregelt"
        }
        if (!in_array($v, self::MANAGEDBY_ALL, true)) {
            $v = 'none';
        }
        if (!in_array($v, $this->ManagedByAllowed(), true)) {
            $v = 'other';
        }
        return $v;
    }

    public function GetFunctions(): array
    {
        $powerID = $this->FindVarByIdent('power');
        $enableID = $this->FindVarByIdent('ctl_enable');
        $limitID  = $this->FindVarByIdent('ctl_curr_limit');

        // Energie: kumulierten Gesamtzähler bevorzugen — der Sitzungswert
        // springt je Ladevorgang zurück und taugt nicht als Zählerstand.
        $energyID = $this->FindVarByIdent('energy_total');
        if (!$energyID) {
            $energyID = $this->FindVarByIdent('energy_session');
        }

        return [[
            // Vertragsversion Major.Minor (Verbund-Konvention, siehe SUITE.md
            // im EMS-Repo). Konsumenten prüfen die Major; additive Felder
            // erhöhen nur die Minor. Fehlt das Feld, gilt konservativ '1.0'.
            // 1.1: managedBy ergänzt.
            'contractVersion'    => '1.1',
            'function'           => 'charger',
            'label'              => IPS_GetName($this->InstanceID),
            'powerID'            => $powerID ?: 0,
            'energyImportID'     => $energyID ?: 0,
            'measured'           => true,
            'chargeEnableID'     => $enableID ?: 0,
            'currentLimitID'     => $limitID ?: 0,
            // Fahrzeug angesteckt (optional, 0 wenn die Wallbox es nicht
            // liefert) — fürs EMS: „kein Fahrzeug" vs. „wartet auf Freigabe".
            'plugStateID'        => $this->FindVarByIdent('vehicle_plugged') ?: 0,
            // Statische Stromgrenzen (Werte, keine IDs): Budget-Verteilung im
            // EMS; unter minCurrent pausiert das EMS über chargeEnableID.
            'minCurrent'         => self::MIN_CURRENT,
            'maxCurrent'         => $this->GetMaxCurrentA(),
            // Wer hat die Hoheit über diesen Ladepunkt (Verbund-Vokabular):
            // none/ems/goe-controller/tibber/p14a/marketer/other. Bei allem
            // außer none/ems steuert das EMS NICHT selbst; 'tibber' ist eine
            // harte Sperre (Regelenergie, Pönale-Risiko).
            'managedBy'          => $this->GetManagedBy(),
            // Kompatibilität (Vertrag 1.0): abgeleitet aus managedBy — true,
            // sobald ein anderer Regler als none/ems die Hoheit hat.
            'externallyManaged'  => !in_array($this->GetManagedBy(), ['none', 'ems'], true),
        ]];
    }

    private function GetDriver()
    {
        if ($this->driver !== null) {
            return $this->driver;
        }
        $key   = $this->ReadPropertyString('Manufacturer');
        $class = self::DRIVERS[$key] ?? self::DRIVERS['keba'];
        $this->driver = new $class();
        return $this->driver;
    }

    private function GetModbusClient(): CHUB_ModbusTcpClient
    {
        return new CHUB_ModbusTcpClient(
            $this->ReadPropertyString('Host'),
            $this->ReadPropertyInteger('Port'),
            $this->ReadPropertyInteger('UnitId')
        );
    }

    public function GroupActive(string $propName): bool
    {
        try {
            return $this->ReadPropertyBoolean($propName);
        } catch (Throwable $e) {
            return false;
        }
    }

    public function GetVarValue(string $ident)
    {
        $vid = $this->FindVarByIdent($ident);
        return $vid ? GetValue($vid) : null;
    }

    public function GetConfigurationForm()
    {
        $driver = $this->GetDriver();

        $groupItems = [];
        foreach ($driver->getOptionalGroups() as $propName => $group) {
            $groupItems[] = [
                'type'    => 'CheckBox',
                'name'    => $propName,
                'caption' => $group['caption'],
            ];
        }
        // Zwei-Regler-Schutz (siehe Create): Auswahlfeld „Wer regelt?", wird
        // über CHUB_GetFunctions als 'managedBy' gemeldet. Nur die für den
        // gewählten Hersteller sinnvollen Werte anbieten. Eigenes Panel
        // "Steuerungshoheit & Sicherheit" zusammen mit MaxCurrent (siehe
        // unten) — beides Steuer-/Sicherheitsfelder, gehört weder zu
        // "Verbindung" noch zu "Datenpunkte" (Layout-Konvention Verbund).
        $managedByOptions = [];
        foreach ($this->ManagedByAllowed() as $key) {
            $managedByOptions[] = ['caption' => self::MANAGEDBY_LABELS[$key], 'value' => $key];
        }

        $form = [
            'elements' => [
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '📖  Dokumentation & Hilfe (Version 0.9.25-beta.1)',
                    'expanded' => false,
                    'items'    => [
                        ['type' => 'Label', 'caption' => 'ChargerHub liest und steuert Wallboxen verschiedener Hersteller per Modbus TCP. Hersteller wählen, IP-Adresse/Hostname eintragen, Datenpunkt-Gruppen aktivieren.'],
                        ['type' => 'Label', 'caption' => '⚠️ Die Registeradressen der Treiber stammen aus den öffentlichen Hersteller-Dokumentationen, sind aber noch nicht an echter Hardware verifiziert. Bitte nach der Ersteinrichtung die Werte gegen die reale Wallbox prüfen, bevor die Steuerung (Ladefreigabe/Stromlimit) produktiv genutzt wird.'],
                        ['type' => 'Label', 'caption' => '• KEBA KeContact P30/P40: Standard-Unit-ID 255, Port 502.'],
                        ['type' => 'Label', 'caption' => '• Alfen Eve Single/Double Pro-line: Standard-Unit-ID 1, Port 502. Nur Sockel 1 wird bedient.'],
                        ['type' => 'Label', 'caption' => '• Heidelberg Energy Control: Standard-Unit-ID 1, Port 502.'],
                        ['type' => 'Label', 'caption' => '• go-eCharger Gemini/HOME+: Standard-Unit-ID 1, Port 502. Modbus muss erst per go-e-App/HTTP-API aktiviert werden; Firmware 60.3 vertauschte die Byte-Reihenfolge (Schalter „Byte-Reihenfolge getauscht", seit 60.4 behoben). Achtung: Regelt ein go-e Controller die Wallbox bereits selbst (Lastmanagement/Überschussladen), nicht zusätzlich von hier aus steuern (Zwei-Regler-Konflikt) — siehe Kennzeichnung unter „Steuerungshoheit & Sicherheit".'],
                    ],
                ],
                ['type' => 'CheckBox', 'name' => 'Active', 'caption' => 'Kommunikation aktiv'],
                [
                    'type'    => 'Select',
                    'name'    => 'Manufacturer',
                    'caption' => 'Wallbox-Hersteller',
                    'options' => [
                        ['label' => 'KEBA (KeContact P30/P40)',        'value' => 'keba'],
                        ['label' => 'Alfen (Eve Single/Double Pro-line)', 'value' => 'alfen'],
                        ['label' => 'Heidelberg Energy Control',       'value' => 'heidelberg'],
                        ['label' => 'go-eCharger (Gemini/HOME+)',      'value' => 'goe'],
                    ],
                ],
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '🔌  Verbindung',
                    'expanded' => true,
                    'items'    => [
                        ['type' => 'Label', 'caption' => 'ℹ️ Über die Suche (Modul „ChargerHub Suche") werden diese Felder beim Anlegen automatisch befüllt — von Hand eintragen ist nur nötig, wenn die Instanz manuell angelegt oder die IP-Adresse der Wallbox geändert wurde.'],
                        ['type' => 'ValidationTextBox', 'name' => 'Host', 'caption' => 'IP-Adresse oder Hostname', 'validate' => '^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$'],
                        ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'TCP-Port', 'minimum' => 1, 'maximum' => 65535],
                        ['type' => 'NumberSpinner', 'name' => 'UnitId', 'caption' => 'Unit ID', 'minimum' => 1, 'maximum' => 247],
                    ],
                ],
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '⏱️  Polling',
                    'expanded' => false,
                    'items'    => [
                        ['type' => 'NumberSpinner', 'name' => 'IntervalFast', 'caption' => 'Lese-Intervall (Sekunden)', 'minimum' => 5, 'maximum' => 300, 'suffix' => 's'],
                    ],
                ],
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '🛡️  Steuerungshoheit & Sicherheit',
                    'expanded' => true,
                    'items'    => [
                        ['type' => 'Select', 'name' => 'ManagedBy', 'caption' => '🆕 Wer regelt diesen Ladepunkt?', 'options' => $managedByOptions],
                        ['type' => 'Label', 'caption' => '⚠️ Zwei-Regler-Warnung: Regelt bereits etwas anderes diese Wallbox — go-e Controller, Lastmanagement, Tibber Grid Rewards oder eine §14a-Steuerung —, darf ein Energiemanagement nicht parallel Ladefreigabe/Stromlimit schreiben (beide Regler überschreiben sich sonst). Hier eintragen, wer die Hoheit hat: Bei allem außer „Niemand" und „Energiemanagement (EMS)" hält sich das EMS zurück und liest nur mit.'],
                        ['type' => 'NumberSpinner', 'name' => 'MaxCurrent', 'caption' => 'Maximaler Anschlussstrom (A)', 'minimum' => 6, 'maximum' => 63, 'suffix' => 'A'],
                        ['type' => 'Label', 'caption' => 'Zuleitung/Absicherung dieses Ladepunkts — harte Obergrenze für jedes Stromlimit, das über dieses Modul geschrieben wird (zusätzlich zum Hardware-Limit der Wallbox), unabhängig davon, was ein EMS anfordert.'],
                    ],
                ],
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '📊  Datenpunkte',
                    'expanded' => true,
                    'items'    => $groupItems,
                ],
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '🆕 📇  RFID-Kartenzähler (nur go-eCharger, per MQTT)',
                    'expanded' => false,
                    'items'    => [
                        ['type' => 'Label', 'caption' => 'Kartenname + Energie je RFID-Karte (0–9) stehen nicht über Modbus zur Verfügung — nur über MQTT. Der go-eCharger muss dafür MQTT aktiviert haben und auf denselben Broker zeigen, der hier eingetragen wird (App → Internet → Erweiterte Einstellungen → MQTT). Modul verbindet sich selbst — keine zusätzliche Symcon-Instanz nötig.'],
                        ['type' => 'CheckBox', 'name' => 'MqttCardsEnabled', 'caption' => 'RFID-Kartenzähler per MQTT abbilden'],
                        ['type' => 'ValidationTextBox', 'name' => 'MqttHost', 'caption' => 'MQTT-Broker (IP/Hostname)', 'validate' => '^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$'],
                        ['type' => 'NumberSpinner', 'name' => 'MqttPort', 'caption' => 'MQTT-Port', 'minimum' => 1, 'maximum' => 65535],
                    ],
                ],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Verbindung testen / Daten sofort lesen', 'onClick' => 'CHUB_Update($id);'],
            ],
            'status' => [
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Bitte IP-Adresse oder Hostname eintragen.'],
                ['code' => 102, 'icon' => 'active',   'caption' => 'Verbindung aktiv.'],
                ['code' => 201, 'icon' => 'error',    'caption' => 'Verbindungsfehler – Wallbox nicht erreichbar.'],
            ],
        ];

        // Symcon-Forum-Hinweis nach den Haupteinstellungen, einmalig
        // ausblendbar (nicht versionsscharf) — noch kein Beitrag online,
        // daher vorerst Verweis auf die GitHub-Rückmeldungen statt Link.
        if (!$this->ReadAttributeBoolean(self::ATTR_REVIEW_HINT_GONE)) {
            $form['elements'][] = [
                'type' => 'RowLayout',
                'name' => 'ReviewHint',
                'items' => [
                    ['type' => 'Label', 'caption' => '🧪 ChargerHub ist Beta — Rückmeldungen sind willkommen, bitte über die GitHub-Seite (github.com/DG65/ChargerHub) oder demnächst im Symcon-Forum:'],
                    ['type' => 'Button', 'caption' => 'Nicht mehr anzeigen', 'onClick' => 'CHUB_DismissReviewHint($id);'],
                ],
            ];
        }

        // „Was ist neu"-Banner nach einem Update ganz oben.
        $banner = $this->newsBanner();
        if ($banner !== null) {
            array_unshift($form['elements'], $banner);
        }

        return json_encode($form);
    }

    // „Was ist neu"-Banner: erscheint nach einem Update (Attribut startet
    // leer), bis der Nutzer „Verstanden" klickt. Neuinstallation sieht es
    // einmalig.
    private function newsBanner()
    {
        if ($this->ReadAttributeString('SeenNews') === self::NEWS_VERSION) {
            return null;
        }
        $items = [['type' => 'Label', 'caption' => '🆕 Neu in diesem Modul — bitte kurz ansehen und ggf. die Einstellungen prüfen:']];
        foreach (self::NEWS_ITEMS as $line) {
            $items[] = ['type' => 'Label', 'caption' => '• ' . $line];
        }
        $items[] = ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'CHUB_AckNews($id);'];
        return ['type' => 'ExpansionPanel', 'name' => 'NewsPanel', 'caption' => '🆕 Neu in Version ' . self::NEWS_VERSION, 'expanded' => true, 'items' => $items];
    }

    public function AckNews()
    {
        $this->WriteAttributeString('SeenNews', self::NEWS_VERSION);
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function DismissReviewHint()
    {
        $this->WriteAttributeBoolean(self::ATTR_REVIEW_HINT_GONE, true);
        $this->UpdateFormField('ReviewHint', 'visible', false);
    }

    // -----------------------------------------------------------------------
    // Variablen-Registrierung (generisch, treiberunabhängig) — Muster wie
    // InverterHub/MeterHub.
    // -----------------------------------------------------------------------

    // RFID-Kartenzähler je Karte (0–9): Name + Energie. Nur go-eCharger, nur
    // wenn per Property freigeschaltet — kommt ausschließlich über MQTT
    // (siehe ReceiveData()), nicht über Modbus, daher unabhängig von den
    // Treiber-Datenpunktgruppen behandelt statt über ChargerDriverInterface.
    private function RegisterMqttCardVars(): void
    {
        for ($i = 0; $i <= 9; $i++) {
            $this->RegisterVar(["card{$i}_name", "Karte {$i}: Name", 'S', '', false, 'cards', ''], 100 + $i * 2);
            $this->RegisterVar(["card{$i}_energy", "Karte {$i}: Energie", 'F', '~Electricity', false, 'cards', ''], 100 + $i * 2 + 1);
        }
    }

    private function RegisterVariables()
    {
        $driver = $this->GetDriver();

        $valid = [];
        foreach ($driver->getBaseVars() as $v) {
            $valid[$v[0]] = true;
        }
        foreach ($driver->getOptionalGroups() as $propName => $group) {
            if ($this->GroupActive($propName)) {
                foreach ($group['vars'] as $v) {
                    $valid[$v[0]] = true;
                }
            }
        }
        $mqttCardsActive = $this->ReadPropertyString('Manufacturer') === 'goe'
            && $this->ReadPropertyBoolean('MqttCardsEnabled');
        if ($mqttCardsActive) {
            for ($i = 0; $i <= 9; $i++) {
                $valid["card{$i}_name"]   = true;
                $valid["card{$i}_energy"] = true;
            }
        }
        $this->PruneForeignObjects($valid);

        $pos = 0;
        foreach ($driver->getBaseVars() as $v) {
            $this->RegisterVar($v, $pos++);
        }
        foreach ($driver->getOptionalGroups() as $propName => $group) {
            if ($this->GroupActive($propName)) {
                foreach ($group['vars'] as $v) {
                    $this->RegisterVar($v, $pos++);
                }
            }
        }
        if ($mqttCardsActive) {
            $this->RegisterMqttCardVars();
        }

        // Migration abgeschlossen — ab hier nie wieder Steuervariablen
        // löschen/neu anlegen (siehe RegisterVar()).
        $this->WriteAttributeBoolean('ControlActionsMigrated', true);
    }

    // Variablen liegen in Untergruppen-Kategorien (siehe EnsureCategory), nicht
    // direkt unter der Instanz — daher rekursiv sammeln (Muster wie
    // InverterHub), sonst würden Variablen eines abgewählten Herstellers/einer
    // deaktivierten Gruppe nie erkannt und blieben stehen.
    private function PruneForeignObjects(array $validIdents)
    {
        // (id, parentIsInstance) je Ident sammeln, um anschließend zwei
        // Aufräumschritte in einem Durchlauf zu erledigen.
        $byIdent = [];
        $collect = function ($pid, $depth) use (&$collect, &$byIdent) {
            foreach (@IPS_GetChildrenIDs($pid) ?: [] as $cid) {
                $obj = IPS_GetObject($cid);
                if ($obj['ObjectType'] === 2 && $obj['ObjectIdent'] !== '') {
                    $byIdent[$obj['ObjectIdent']][] = ['id' => $cid, 'directChild' => ($depth === 0)];
                }
                if ($obj['ObjectType'] === 0) {
                    $collect($cid, $depth + 1);
                }
            }
        };
        $collect($this->InstanceID, 0);

        foreach ($byIdent as $ident => $entries) {
            if (!isset($validIdents[$ident])) {
                // Fremder/nicht mehr gültiger Ident (abgewählte Gruppe,
                // Herstellerwechsel) — alle Fundstellen löschen.
                foreach ($entries as $e) {
                    $this->DeleteVariableSafely($e['id']);
                }
                continue;
            }
            if (count($entries) > 1) {
                // Karteileiche aus dem 0.9.13-Zwischenfall (ApplyChanges brach
                // mitten im Lauf ab, siehe CHANGELOG): RegisterVariableX legte
                // eine zweite Variable direkt unter der Instanz an, das
                // anschließende IPS_SetParent in die Kategorie schlug wegen
                // der Ident-Kollision fehl und blieb dort stehen — die
                // korrekte, in einer Kategorie verschachtelte Variable blieb
                // daneben unangetastet bestehen. Direkte Instanz-Kinder mit
                // einem Ident, der auch verschachtelt vorkommt, sind immer
                // die Karteileiche.
                foreach ($entries as $e) {
                    if ($e['directChild']) {
                        $this->DeleteVariableSafely($e['id']);
                    }
                }
            }
        }
    }

    // IPS_DeleteVariable() scheitert still (kein Fehler, keine Löschung),
    // wenn unter der Variable noch ein Kind-Objekt hängt — live beobachtet
    // bei einer Karteileiche mit einem Link-Objekt darunter (vermutlich aus
    // einer Visualisierung). Kind-Objekte vorher entfernen.
    private function DeleteVariableSafely(int $vid): void
    {
        foreach (@IPS_GetChildrenIDs($vid) ?: [] as $cid) {
            $obj = IPS_GetObject($cid);
            if ($obj['ObjectType'] === 6) {
                @IPS_DeleteLink($cid);
            }
        }
        @IPS_DeleteVariable($vid);
    }

    // Erzeugung über RegisterVariableX (nötig für die Kernel-Standardaktion,
    // siehe EnableAction()-Aufruf unten), aber NUR bei echter Neuanlage
    // (!$vid) — ein erneuter Aufruf, nachdem die Variable längst in ihre
    // Kategorie verschoben wurde, kollidiert dort beim IPS_SetParent
    // ("Ident muss für jede Ebene eindeutig sein", siehe CHANGELOG 0.9.13/14).
    // Bereits bestehende Variablen werden über die rekursive FindVarByIdent()
    // gefunden (InverterHub-Muster), nicht erneut registriert.
    private function RegisterVar(array $def, int $pos)
    {
        [$ident, $caption, $type, $profile, $archive, $group, $reg] = $def;

        $vtype = [
            'F' => VARIABLETYPE_FLOAT,
            'I' => VARIABLETYPE_INTEGER,
            'B' => VARIABLETYPE_BOOLEAN,
            'S' => VARIABLETYPE_STRING,
        ][$type];

        $vid = $this->FindVarByIdent($ident);
        // Typ-Migration: IPS kann den Typ einer Variable nicht nachträglich
        // ändern — bei Typwechsel neu anlegen.
        if ($vid && IPS_GetVariable($vid)['VariableType'] !== $vtype) {
            @IPS_DeleteVariable($vid);
            $vid = 0;
        }
        // Einmalige Migration für "control"-Variablen, die noch VOR dem
        // RegisterVariableX-Fix per rohem IPS_CreateVariable() erzeugt wurden
        // (fehlende Kernel-Standardaktion). Steuerung über ein PERSISTENTES
        // Attribut, NICHT über eine Live-Zustandsprüfung von VariableAction:
        // Live bestätigt (25.07.2026) lieferte IPS_GetVariable()['VariableAction']
        // nach der Migration weiterhin 0 zurück (das Feld spiegelt offenbar
        // etwas anderes als die per RegisterVariableX/IPS_SetVariableCustomAction
        // gesetzte Bindung), wodurch dieser Zweig bei JEDEM Übernehmen erneut
        // feuerte und die IDs bei jedem Aufruf neu vergeben wurden — ein
        // Attribut kann das nicht, es wird exakt einmal auf true gesetzt.
        if ($vid && $group === 'control' && !$this->ReadAttributeBoolean('ControlActionsMigrated')) {
            @IPS_DeleteVariable($vid);
            $vid = 0;
        }
        // RegisterVariableX NUR bei echter Neuanlage aufrufen (!$vid), nicht
        // bei jedem ApplyChanges: der Ident-Registrierung dieser SDK-Methode
        // ist instanzweit, nicht nur auf direkte Kinder beschränkt — ein
        // erneuter Aufruf NACHDEM die Variable längst in eine Kategorie
        // verschoben wurde, kollidiert dort mit sich selbst ("Ident muss für
        // jede Ebene eindeutig sein", live reproduziert 25.07.2026). Die bei
        // der Neuanlage gesetzte Kernel-Standardaktion bleibt beim späteren
        // IPS_SetParent in die Kategorie erhalten, muss also nicht erneut
        // durch RegisterVariableX bestätigt werden — nur das explizite
        // IPS_SetVariableCustomAction($vid, 0) weiter unten läuft jedes Mal.
        if (!$vid) {
            switch ($type) {
                case 'F':
                    $vid = $this->RegisterVariableFloat($ident, $caption, $profile, $pos);
                    break;
                case 'I':
                    $vid = $this->RegisterVariableInteger($ident, $caption, $profile, $pos);
                    break;
                case 'B':
                    $vid = $this->RegisterVariableBoolean($ident, $caption, $profile, $pos);
                    break;
                case 'S':
                    $vid = $this->RegisterVariableString($ident, $caption, $profile, $pos);
                    break;
            }
            // WICHTIG: IPS_SetVariableCustomAction($vid, 0) ist die falsche API
            // für modul-EIGENE Variablen (von dieser Instanz per
            // RegisterVariableX angelegt) — sie sieht korrekt aus (kein Fehler),
            // bleibt aber wirkungslos, insbesondere in WebFront/Konsole
            // (unabhängig bestätigt sowohl live bei uns als auch bei
            // InverterHub, das denselben Bug hatte). Richtig ist die
            // SDK-eigene $this->EnableAction($Ident). Die MUSS hier, VOR dem
            // IPS_SetParent() weiter unten, aufgerufen werden: EnableAction()
            // löst den Ident intern über das flache GetIDForIdent() auf, das
            // nur direkte Instanz-Kinder findet — die Variable steht hier noch
            // an der Instanz, bevor sie gleich in ihre Kategorie verschoben
            // wird. Die dabei gesetzte Standardaktion bleibt über den
            // IPS_SetParent()-Umzug hinweg erhalten.
            if ($group === 'control') {
                $this->EnableAction($ident);
            }
        }

        $catID = $this->EnsureCategory($group);
        IPS_SetParent($vid, $catID);
        IPS_SetPosition($vid, $pos);
        IPS_SetName($vid, $caption);

        // Profil bei jedem Übernehmen unconditional nachziehen (siehe oben,
        // 0.9.11) — RegisterVariableX setzt es zwar schon bei Neuanlage, aber
        // ein Hersteller-/Typwechsel kann ein anderes Profil erfordern.
        if ($profile !== '') {
            if (@IPS_GetVariable($vid)['VariableCustomProfile'] !== $profile) {
                IPS_SetVariableCustomProfile($vid, $profile);
            }
        }
        if ($reg !== '') {
            @IPS_SetInfo($vid, (string)$reg);
        }
        if ($archive) {
            $this->SetArchive($vid);
        }
    }

    private function EnsureCategory($key)
    {
        $ident = 'CAT_' . $key;
        $cid   = @$this->GetIDForIdent($ident);
        if ($cid !== false) {
            return $cid;
        }
        $labels = [
            'errors'  => 'Status',
            'device'  => 'Gerät',
            'phases'  => 'Phasen',
            'control' => 'Steuerung',
            'cards'   => 'RFID-Karten',
        ];
        $cid = IPS_CreateCategory();
        IPS_SetParent($cid, $this->InstanceID);
        IPS_SetName($cid, $labels[$key] ?? ucfirst($key));
        IPS_SetIdent($cid, $ident);
        return $cid;
    }

    private function FindVarByIdent($ident)
    {
        return $this->FindIdentRecursive($this->InstanceID, $ident);
    }

    private function FindIdentRecursive(int $parentID, $ident)
    {
        foreach (@IPS_GetChildrenIDs($parentID) ?: [] as $cid) {
            $obj = IPS_GetObject($cid);
            if ($obj['ObjectIdent'] === $ident) {
                return $cid;
            }
            if ($obj['ObjectType'] === 0) { // Kategorie -> rekursiv weitersuchen
                $found = $this->FindIdentRecursive($cid, $ident);
                if ($found) {
                    return $found;
                }
            }
        }
        return 0;
    }

    private function SetArchive($vid)
    {
        if (function_exists('AC_SetLoggingStatus') && IPS_GetInstanceListByModuleID('{018EF6B5-AB94-40C6-AA53-46943E824ACF}') !== []) {
            $archiveID = IPS_GetInstanceListByModuleID('{018EF6B5-AB94-40C6-AA53-46943E824ACF}')[0];
            AC_SetLoggingStatus($archiveID, $vid, true);
            IPS_ApplyChanges($archiveID);
        }
    }

    public function SetVarFloat(string $ident, float $value)
    {
        // NaN/Inf nie in (ggf. archivierte) Variablen schreiben — unbelegte
        // Register liefern bei mancher Firmware 0xFF-Füllwerte statt einer
        // Modbus-Exception, als Float32 dekodiert ergibt das NaN.
        if (!is_finite($value)) {
            return;
        }
        $vid = $this->FindVarByIdent($ident);
        if ($vid) {
            SetValueFloat($vid, $value);
        }
    }

    public function SetVarInt(string $ident, int $value)
    {
        $vid = $this->FindVarByIdent($ident);
        if ($vid) {
            SetValueInteger($vid, $value);
        }
    }

    public function SetVarBool(string $ident, bool $value)
    {
        $vid = $this->FindVarByIdent($ident);
        if ($vid) {
            SetValueBoolean($vid, $value);
        }
    }

    public function SetVarStr(string $ident, string $value)
    {
        $vid = $this->FindVarByIdent($ident);
        if ($vid) {
            SetValueString($vid, $value);
        }
    }

    private function CreateProfiles()
    {
        $driver = $this->GetDriver();

        foreach ($driver->getProfiles() as $name => $p) {
            // Gemeinsame NRG.*-Profile (Verbund-Konvention, siehe SUITE.md):
            // kein Eigentümer-Modul — nur anlegen, falls es noch fehlt, sonst
            // unangetastet lassen. Wer zuerst startet, erzeugt es; würde jedes
            // Modul seine eigenen Werte bei jedem ApplyChanges neu draufschreiben,
            // überschrieben sich mehrere Module gegenseitig die Definition.
            if (strncmp($name, 'NRG.', 4) === 0 && IPS_VariableProfileExists($name)) {
                continue;
            }
            [$type, $suffix, $min, $max, $step, $digits] = $p;
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, $type);
            }
            IPS_SetVariableProfileText($name, '', $suffix);
            if ($type === VARIABLETYPE_FLOAT) {
                IPS_SetVariableProfileValues($name, $min, $max, $step);
                IPS_SetVariableProfileDigits($name, $digits);
            } elseif ($type === VARIABLETYPE_INTEGER) {
                IPS_SetVariableProfileValues($name, $min, $max, $step);
            }
        }

        foreach ($driver->getEnumProfiles() as $name => $values) {
            if (!IPS_VariableProfileExists($name)) {
                IPS_CreateVariableProfile($name, VARIABLETYPE_INTEGER);
            }
            foreach ($values as $val => [$label, $color]) {
                IPS_SetVariableProfileAssociation($name, $val, $label, '', $color);
            }
        }
    }
}
