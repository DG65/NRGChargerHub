<?php

// ===========================================================================
// ChargerHub — generisches Modbus-TCP-Framework für Wallboxen verschiedener
// Hersteller. Ein Modul, ein Auswahlfeld „Wallbox-Typ" — je nach Auswahl
// werden die passenden Register und Bedienelemente freigeschaltet.
//
// Aufbau analog zu InverterHub/MeterHub:
//   ModbusTcpClient        — gemeinsame Modbus-TCP-Grundfunktionen
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

class ModbusTcpClient
{
    public $host;
    public $port;
    public $unitId;

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
        $sock = @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
        if ($sock === false) {
            return false;
        }
        stream_set_timeout($sock, 3);

        $tid  = mt_rand(1, 65535);
        $pdu  = pack('Cnn', 0x06, $reg, $value & 0xFFFF);
        $mbap = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($this->unitId);

        @fwrite($sock, $mbap . $pdu);
        $resp = @fread($sock, 64);
        fclose($sock);

        return ($resp !== false && strlen($resp) >= 8 && ord($resp[7]) === 0x06);
    }

    public function writeMultiple($startReg, $values)
    {
        $sock = @fsockopen($this->host, $this->port, $errno, $errstr, 3.0);
        if ($sock === false) {
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

        return ($resp !== false && strlen($resp) >= 8 && ord($resp[7]) === 0x10);
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
        return ($r === null) ? null : $mb->u32($r, 0);
    }

    public function getBaseVars()
    {
        return [
            ['connected',      'Verbindung',           'B', '~Alert.Reversed',  false, 'errors', ''],
            ['state',          'Ladestatus',            'I', 'CHB.KebaState',   true,  'device', 'Holding 1000-1001 (U32)'],
            ['cable_state',    'Kabelstatus',           'I', 'CHB.KebaCable',   false, 'device', 'Holding 1004-1005 (U32)'],
            ['power',          'Ladeleistung',          'F', 'CHB.Watt',        true,  'device', 'Holding 1020-1021 (mW)'],
            ['energy_total',   'Energie gesamt',        'F', 'CHB.kWh',         true,  'device', 'Holding 1036-1037 (0,1 Wh)'],
            ['energy_session', 'Energie akt. Sitzung',  'F', 'CHB.kWhSession',  true,  'device', 'Holding 1502-1503 (0,1 Wh)'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPhases' => ['caption' => 'Strom/Spannung je Phase', 'vars' => [
                ['current_l1', 'Strom L1',    'F', 'CHB.Ampere', false, 'phases', 'Holding 1008-1009 (mA)'],
                ['current_l2', 'Strom L2',    'F', 'CHB.Ampere', false, 'phases', 'Holding 1010-1011 (mA)'],
                ['current_l3', 'Strom L3',    'F', 'CHB.Ampere', false, 'phases', 'Holding 1012-1013 (mA)'],
                ['voltage_l1', 'Spannung L1', 'F', 'CHB.Volt',   false, 'phases', 'Holding 1040-1041 (V)'],
                ['voltage_l2', 'Spannung L2', 'F', 'CHB.Volt',   false, 'phases', 'Holding 1042-1043 (V)'],
                ['voltage_l3', 'Spannung L3', 'F', 'CHB.Volt',   false, 'phases', 'Holding 1044-1045 (V)'],
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
            'CHB.Watt'         => [VARIABLETYPE_FLOAT,   ' W', 0.0, 22000.0, 1.0, 0],
            'CHB.kWh'          => [VARIABLETYPE_FLOAT,   ' kWh', 0.0, 9999999.0, 0.01, 2],
            // Eigenes Suffix, damit die MeterHub-Zählersuche (matcht auf
            // normalisiertes Suffix "kwh") den Sitzungswert NICHT als
            // Energiezähler aufnimmt — der springt je Ladevorgang zurück.
            'CHB.kWhSession'   => [VARIABLETYPE_FLOAT,   ' kWh (Sitzung)', 0.0, 999.0, 0.01, 2],
            'CHB.Volt'         => [VARIABLETYPE_FLOAT,   ' V', 0.0, 260.0, 0.1, 1],
            'CHB.Ampere'       => [VARIABLETYPE_FLOAT,   ' A', 0.0, 80.0, 0.1, 1],
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
                $amp = max(0, min(63, (int)$value));
                $mA  = ($amp === 0) ? 0 : max(6000, min(63000, $amp * 1000));
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
            ['power',       'Ladeleistung',       'F', 'CHB.Watt',        true,  'device', 'Holding 344'],
            ['actual_curr', 'Angewandtes Limit',  'F', 'CHB.Ampere',      false, 'device', 'Holding 1206'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPhases' => ['caption' => 'Spannung/Strom je Phase', 'vars' => [
                ['voltage_l1', 'Spannung L1', 'F', 'CHB.Volt',   false, 'phases', 'Holding 308'],
                ['voltage_l2', 'Spannung L2', 'F', 'CHB.Volt',   false, 'phases', 'Holding 310'],
                ['voltage_l3', 'Spannung L3', 'F', 'CHB.Volt',   false, 'phases', 'Holding 312'],
                ['current_l1', 'Strom L1',    'F', 'CHB.Ampere', false, 'phases', 'Holding 322'],
                ['current_l2', 'Strom L2',    'F', 'CHB.Ampere', false, 'phases', 'Holding 324'],
                ['current_l3', 'Strom L3',    'F', 'CHB.Ampere', false, 'phases', 'Holding 326'],
            ]],
            'GroupControl' => ['caption' => 'Steuerung (Ladefreigabe, Stromlimit)', 'vars' => [
                ['ctl_enable',     'Ladefreigabe',   'B', '~Switch',    false, 'control', 'RW Holding 1210/1214'],
                ['ctl_curr_limit', 'Stromlimit (A)', 'F', 'CHB.Ampere', false, 'control', 'RW Holding 1210'],
            ]],
        ];
    }

    public function getProfiles()
    {
        return [
            'CHB.Watt'   => [VARIABLETYPE_FLOAT, ' W', 0.0, 22000.0, 1.0, 0],
            'CHB.Volt'   => [VARIABLETYPE_FLOAT, ' V', 0.0, 260.0, 0.1, 1],
            'CHB.Ampere' => [VARIABLETYPE_FLOAT, ' A', 0.0, 80.0, 0.1, 1],
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
                $amp = max(0.0, min(80.0, (float)$value));
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
            ['power',     'Leistung',    'F', 'CHB.Watt',        true,  'device', 'Holding 14'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPhases' => ['caption' => 'Spannung/Strom je Phase + Temperatur', 'vars' => [
                ['current_l1', 'Strom L1',      'F', 'CHB.Ampere', false, 'phases', 'Holding 6'],
                ['current_l2', 'Strom L2',      'F', 'CHB.Ampere', false, 'phases', 'Holding 7'],
                ['current_l3', 'Strom L3',      'F', 'CHB.Ampere', false, 'phases', 'Holding 8'],
                ['voltage_l1', 'Spannung L1',   'F', 'CHB.Volt',   false, 'phases', 'Holding 10'],
                ['voltage_l2', 'Spannung L2',   'F', 'CHB.Volt',   false, 'phases', 'Holding 11'],
                ['voltage_l3', 'Spannung L3',   'F', 'CHB.Volt',   false, 'phases', 'Holding 12'],
                ['pcb_temp',   'PCB-Temperatur','F', 'CHB.Celsius',false, 'device', 'Holding 9'],
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
            'CHB.Watt'          => [VARIABLETYPE_FLOAT,   ' W', 0.0, 22000.0, 1.0, 0],
            'CHB.Volt'          => [VARIABLETYPE_FLOAT,   ' V', 0.0, 260.0, 0.1, 1],
            'CHB.Ampere'        => [VARIABLETYPE_FLOAT,   ' A', 0.0, 80.0, 0.1, 1],
            'CHB.Celsius'       => [VARIABLETYPE_FLOAT,   ' °C', -20.0, 100.0, 0.1, 1],
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
        $hub->SetVarInt('state', $mb->u16($state, 0));

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
                $amp = (int)$value === 0 ? 0 : max(6, min(32, (int)$value));
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
    const REG_AMPERE_VOLATILE = 299; // U16, 6-32A, NICHT im EEPROM gespeichert — für Automatisierung vorgesehen
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
    const REG_SNR         = 304; // ASCII, 6 Register (12 Byte)

    const CAR_STATES = [
        0 => 'Unbekannt / Ladestation defekt', 1 => 'Bereit, kein Fahrzeug', 2 => 'Fahrzeug lädt',
        3 => 'Wartet auf Fahrzeug', 4 => 'Ladung beendet, Fahrzeug verbunden',
    ];

    const ERRORS = [
        0 => 'Kein Fehler', 1 => 'RCCB (Fehlerstromschutzschalter)', 3 => 'Phasenstörung',
        8 => 'Erdungsfehler', 10 => 'Interner Fehler',
    ];

    private function u32($mb, $hub, $regs, $offset)
    {
        return $hub->GroupActive('GoeWordSwap') ? $mb->u32sw($regs, $offset) : $mb->u32($regs, $offset);
    }

    public function getBaseVars()
    {
        return [
            ['connected',      'Verbindung',            'B', '~Alert.Reversed', false, 'errors', ''],
            ['state',          'Ladestatus',             'I', 'CHB.GoeCarState', true,  'device', 'Input 100'],
            ['power',          'Ladeleistung',           'F', 'CHB.Watt',        true,  'device', 'Input 120-121 (0,01 W)'],
            ['energy_session', 'Energie akt. Sitzung',   'F', 'CHB.kWhSession',  true,  'device', 'Input 132-133 (Deka-Ws)'],
        ];
    }

    public function getOptionalGroups()
    {
        return [
            'GroupPhases' => ['caption' => 'Spannung/Strom je Phase', 'vars' => [
                ['voltage_l1', 'Spannung L1', 'F', 'CHB.Volt',   false, 'phases', 'Input 108-109 (V)'],
                ['voltage_l2', 'Spannung L2', 'F', 'CHB.Volt',   false, 'phases', 'Input 110-111 (V)'],
                ['voltage_l3', 'Spannung L3', 'F', 'CHB.Volt',   false, 'phases', 'Input 112-113 (V)'],
                ['current_l1', 'Strom L1',    'F', 'CHB.Ampere', false, 'phases', 'Input 114-115 (0,1 A)'],
                ['current_l2', 'Strom L2',    'F', 'CHB.Ampere', false, 'phases', 'Input 116-117 (0,1 A)'],
                ['current_l3', 'Strom L3',    'F', 'CHB.Ampere', false, 'phases', 'Input 118-119 (0,1 A)'],
            ]],
            'GroupDevice' => ['caption' => 'Geräteinformation', 'vars' => [
                ['dev_serial',    'Seriennummer',        'S', '', false, 'device', 'Input 304-309 (ASCII)'],
                ['dev_firmware',  'Firmware-Version',    'S', '', false, 'device', 'Input 105-106 (ASCII)'],
                ['energy_total',  'Energie gesamt',      'F', 'CHB.kWh', true, 'device', 'Input 128-129 (0,1 kWh)'],
                ['dev_error',     'Fehlercode',          'I', 'CHB.GoeError', true, 'errors', 'Input 107'],
                ['cable_current', 'Kabel-Strombegrenzung','I', 'CHB.Ampere6to32', false, 'device', 'Input 101 (13-32, 0=kein Kabel)'],
            ]],
            'GroupControl' => ['caption' => 'Steuerung (Ladefreigabe, Stromlimit)', 'vars' => [
                ['ctl_enable',     'Ladefreigabe',   'B', '~Switch',         false, 'control', 'RW Holding 337 (FORCE_STATE)'],
                ['ctl_curr_limit', 'Stromlimit (A)', 'I', 'CHB.Ampere6to32', false, 'control', 'RW Holding 299 (AMPERE_VOLATILE)'],
            ]],
            // Keine eigenen Variablen — reine Konfigurations-Checkbox (nutzt die
            // generische Datenpunkt-Gruppen-Infrastruktur mit). Default AUS, da
            // nur die veraltete Firmware 60.3 betroffen ist.
            'GoeWordSwap' => ['caption' => 'Byte-Reihenfolge getauscht (nur Firmware 60.3 — 60.4 hat den Bug behoben)', 'vars' => [], 'default' => false],
        ];
    }

    public function getProfiles()
    {
        return [
            'CHB.Watt'        => [VARIABLETYPE_FLOAT,   ' W', 0.0, 22000.0, 1.0, 0],
            'CHB.kWh'         => [VARIABLETYPE_FLOAT,   ' kWh', 0.0, 9999.0, 0.01, 2],
            // Eigenes Suffix: hält die MeterHub-Zählersuche vom rückspringenden
            // Sitzungswert fern (siehe KebaDriver::getProfiles).
            'CHB.kWhSession'  => [VARIABLETYPE_FLOAT,   ' kWh (Sitzung)', 0.0, 999.0, 0.01, 2],
            'CHB.Volt'        => [VARIABLETYPE_FLOAT,   ' V', 0.0, 260.0, 0.1, 1],
            'CHB.Ampere'      => [VARIABLETYPE_FLOAT,   ' A', 0.0, 80.0, 0.1, 1],
            'CHB.Ampere6to32' => [VARIABLETYPE_INTEGER, ' A', 0, 32, 1, 0],
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
        return ['CHB.GoeCarState' => $states, 'CHB.GoeError' => $errors];
    }

    public function readValues($mb, $hub)
    {
        $car = $mb->readInput(self::REG_CAR_STATE, 1);
        $ok  = ($car !== null);
        $hub->SetVarBool('connected', $ok);
        if (!$ok) {
            return false;
        }
        $hub->SetVarInt('state', $mb->u16($car, 0));

        $power = $mb->readInput(self::REG_POWER_TOTAL, 2);
        if ($power !== null) {
            $hub->SetVarFloat('power', $this->u32($mb, $hub, $power, 0) / 100.0); // 0,01 W -> W
        }

        $energy = $mb->readInput(self::REG_ENERGY_CHARGE, 2);
        if ($energy !== null) {
            // Deka-Wattsekunden (Ws*10) -> kWh: Ws = Rohwert*10, kWh = Ws/3.600.000
            $hub->SetVarFloat('energy_session', $this->u32($mb, $hub, $energy, 0) * 10.0 / 3600000.0);
        }

        if ($hub->GroupActive('GroupPhases')) {
            $v1 = $mb->readInput(self::REG_VOLT_L1, 2);
            $v2 = $mb->readInput(self::REG_VOLT_L2, 2);
            $v3 = $mb->readInput(self::REG_VOLT_L3, 2);
            $i1 = $mb->readInput(self::REG_AMP_L1, 2);
            $i2 = $mb->readInput(self::REG_AMP_L2, 2);
            $i3 = $mb->readInput(self::REG_AMP_L3, 2);
            if ($v1 !== null) { $hub->SetVarFloat('voltage_l1', (float)$this->u32($mb, $hub, $v1, 0)); }
            if ($v2 !== null) { $hub->SetVarFloat('voltage_l2', (float)$this->u32($mb, $hub, $v2, 0)); }
            if ($v3 !== null) { $hub->SetVarFloat('voltage_l3', (float)$this->u32($mb, $hub, $v3, 0)); }
            if ($i1 !== null) { $hub->SetVarFloat('current_l1', $this->u32($mb, $hub, $i1, 0) / 10.0); }
            if ($i2 !== null) { $hub->SetVarFloat('current_l2', $this->u32($mb, $hub, $i2, 0) / 10.0); }
            if ($i3 !== null) { $hub->SetVarFloat('current_l3', $this->u32($mb, $hub, $i3, 0) / 10.0); }
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
            $etot = $mb->readInput(self::REG_ENERGY_TOTAL, 2);
            if ($etot !== null) {
                $hub->SetVarFloat('energy_total', $this->u32($mb, $hub, $etot, 0) / 10.0);
            }
            $err = $mb->readInput(self::REG_ERROR, 1);
            if ($err !== null) {
                $hub->SetVarInt('dev_error', $mb->u16($err, 0));
            }
            $pp = $mb->readInput(self::REG_PP_CABLE, 1);
            if ($pp !== null) {
                $hub->SetVarInt('cable_current', $mb->u16($pp, 0));
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
                $amp = max(6, min(32, (int)$value));
                if ($mb->writeMultiple(self::REG_AMPERE_VOLATILE, [$amp])) {
                    $hub->SetVarInt('ctl_curr_limit', $amp);
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
    private const DRIVERS = [
        'keba'       => 'KebaDriver',
        'alfen'      => 'AlfenDriver',
        'heidelberg' => 'HeidelbergDriver',
        'goe'        => 'GoeChargerDriver',
    ];

    private $driver = null;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('Active', true);
        $this->RegisterPropertyString('Manufacturer', 'keba');
        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyInteger('UnitId', 255);
        $this->RegisterPropertyInteger('IntervalFast', 10);

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

    // Wird kurz nach ApplyChanges einmalig aufgerufen, sobald die Instanz die
    // Erstellungstransaktion sicher verlassen hat (Muster wie InverterHub).
    public function EnableActions()
    {
        $this->SetTimerInterval('EnableActionsTimer', 0);

        $driver = $this->GetDriver();
        foreach ($driver->getOptionalGroups() as $group) {
            foreach ($group['vars'] as $v) {
                if ($v[5] === 'control') {
                    $vid = $this->FindVarByIdent($v[0]);
                    if ($vid) {
                        IPS_SetVariableCustomAction($vid, $this->InstanceID);
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
    }

    public function RequestAction($Ident, $Value)
    {
        if (!$this->ReadPropertyBoolean('Active')) {
            return;
        }
        $this->GetDriver()->writeControl($this->GetModbusClient(), $this, $Ident, $Value);
    }

    // Vertrag für Partnermodule (EMS, Kacheln) — Muster wie MHUB_GetFunctions.
    // Immer hinter function_exists('CHUB_GetFunctions') beim Aufrufer, siehe
    // CLAUDE.md. Der Schreib-Teil (chargeEnableID/currentLimitID) ist ein
    // erster Vorschlag und noch mit der EMS-Sitzung abzustimmen, bevor er als
    // stabiler Vertrag gilt — die Steuer-IDs sind fürs EMS bestimmt, nicht für
    // Anzeigemodule.
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
            'function'        => 'charger',
            'label'           => IPS_GetName($this->InstanceID),
            'powerID'         => $powerID ?: 0,
            'energyImportID'  => $energyID ?: 0,
            'measured'        => true,
            'chargeEnableID'  => $enableID ?: 0,
            'currentLimitID'  => $limitID ?: 0,
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

    private function GetModbusClient(): ModbusTcpClient
    {
        return new ModbusTcpClient(
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

        $form = [
            'elements' => [
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '📖  Dokumentation & Hilfe',
                    'expanded' => false,
                    'items'    => [
                        ['type' => 'Label', 'caption' => 'ChargerHub liest und steuert Wallboxen verschiedener Hersteller per Modbus TCP. Hersteller wählen, IP-Adresse/Hostname eintragen, Datenpunkt-Gruppen aktivieren.'],
                        ['type' => 'Label', 'caption' => '⚠️ Die Registeradressen der Treiber stammen aus den öffentlichen Hersteller-Dokumentationen, sind aber noch nicht an echter Hardware verifiziert. Bitte nach der Ersteinrichtung die Werte gegen die reale Wallbox prüfen, bevor die Steuerung (Ladefreigabe/Stromlimit) produktiv genutzt wird.'],
                        ['type' => 'Label', 'caption' => '• KEBA KeContact P30/P40: Standard-Unit-ID 255, Port 502.'],
                        ['type' => 'Label', 'caption' => '• Alfen Eve Single/Double Pro-line: Standard-Unit-ID 1, Port 502. Nur Sockel 1 wird bedient.'],
                        ['type' => 'Label', 'caption' => '• Heidelberg Energy Control: Standard-Unit-ID 1, Port 502.'],
                        ['type' => 'Label', 'caption' => '• go-eCharger Gemini/HOME+: Standard-Unit-ID 1, Port 502. Modbus muss erst per go-e-App/HTTP-API aktiviert werden; Firmware 60.3 hatte einen Byte-Order-Bug (Schalter „Byte-Reihenfolge getauscht", seit 60.4 behoben).'],
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
                    'caption'  => '📊  Datenpunkte',
                    'expanded' => true,
                    'items'    => $groupItems,
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

        return json_encode($form);
    }

    // -----------------------------------------------------------------------
    // Variablen-Registrierung (generisch, treiberunabhängig) — Muster wie
    // InverterHub/MeterHub.
    // -----------------------------------------------------------------------

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
    }

    // Variablen liegen in Untergruppen-Kategorien (siehe EnsureCategory), nicht
    // direkt unter der Instanz — daher rekursiv sammeln (Muster wie
    // InverterHub), sonst würden Variablen eines abgewählten Herstellers/einer
    // deaktivierten Gruppe nie erkannt und blieben stehen.
    private function PruneForeignObjects(array $validIdents)
    {
        $all = [];
        $collect = function ($pid) use (&$collect, &$all) {
            foreach (@IPS_GetChildrenIDs($pid) ?: [] as $cid) {
                $all[] = $cid;
                if (IPS_GetObject($cid)['ObjectType'] === 0) {
                    $collect($cid);
                }
            }
        };
        $collect($this->InstanceID);

        foreach ($all as $cid) {
            if (!IPS_ObjectExists($cid)) {
                continue;
            }
            $obj = IPS_GetObject($cid);
            if ($obj['ObjectType'] !== 2 || $obj['ObjectIdent'] === '') {
                continue;
            }
            if (!isset($validIdents[$obj['ObjectIdent']])) {
                @IPS_DeleteVariable($cid);
            }
        }
    }

    // WICHTIG: bewusst KEIN RegisterVariable*() — das bindet den Ident an die
    // Instanzebene. Beim zweiten ApplyChanges (Configurator-Erstellung ruft es
    // mehrfach) fände RegisterVariable* die längst in die Kategorie verschobene
    // Variable nicht mehr, legte sie neu an und kollidierte beim Verschieben
    // („Ident muss für jede Ebene eindeutig sein" — real gemeldet beim Anlegen
    // aus der Discovery). Stattdessen das InverterHub-Muster: rekursiv suchen,
    // sonst IPS_CreateVariable + IPS_SetIdent direkt unter der Kategorie.
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
        $created = false;
        if (!$vid) {
            $vid = IPS_CreateVariable($vtype);
            IPS_SetIdent($vid, $ident);
            $created = true;
        }

        $catID = $this->EnsureCategory($group);
        IPS_SetParent($vid, $catID);
        IPS_SetPosition($vid, $pos);
        IPS_SetName($vid, $caption);

        // Profil nur bei Neuanlage setzen: Ab IPS 7 leert eine vom Nutzer
        // gewählte Presentation das CustomProfile — bei jedem „Übernehmen"
        // neu gesetzt, spränge die Variable auf „Legacy" zurück.
        if ($profile !== '' && $created) {
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
