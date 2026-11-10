<?php

// ---------------------------------------------------------------------------
// ChargerHubBridge — Brücke zwischen ChargerHub und Symcons NATIVEM
// "ModBus Gateway" (Symbox-RS485-Port), SUITE.md 9j. Einheitlicher Vertrag mit
// den Brücken von MeterHub/InverterHub (verbundweit abgestimmt, 19.09.2026):
//
// - Diese Instanz ist die EINZIGE, die parentRequirements/implemented des
//   Gateway-Weges trägt — ChargerHub selbst bleibt dadurch für Direkt-Instanzen
//   unberührt (kein oranger Balken "benötigt eine übergeordnete Instanz").
// - Forward(string $json): reicht den Request-JSON UNVERÄNDERT an
//   SendDataToParent, kennt keine Function Codes (Lesen wie Schreiben gehen
//   generisch). Antwort IMMER JSON, Binärdaten base64-kodiert.
// - GetState(): Verbindungs-/Gateway-Status, dazu die Unit-ID (Property
//   "DeviceID" des Gateways, falls lesbar).
// - Eine Brücke bedient genau EINE Unit-ID (die DeviceID ihres Gateways).
// ---------------------------------------------------------------------------

class ChargerHubBridge extends IPSModule
{
    public function Create()
    {
        parent::Create();
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus($this->ParentConnected() ? 102 : 104);
    }

    private function ParentId(): int
    {
        return (int)(@IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
    }

    private function ParentConnected(): bool
    {
        return $this->ParentId() > 0;
    }

    private function ParentStatus(): int
    {
        $pid = $this->ParentId();
        return $pid > 0 ? (int)(@IPS_GetInstance($pid)['InstanceStatus'] ?? 0) : 0;
    }

    private function UnitId(): ?int
    {
        $pid = $this->ParentId();
        if ($pid <= 0) {
            return null;
        }
        $v = @IPS_GetProperty($pid, 'DeviceID');
        return ($v === false || $v === null) ? null : (int)$v;
    }

    // Request-JSON unverändert an das Gateway; Antwort als JSON mit base64.
    public function Forward(string $json): string
    {
        if (!$this->ParentConnected()) {
            return json_encode(['ok' => false, 'error' => 'not_connected']);
        }
        if ($this->ParentStatus() !== 102) {
            return json_encode(['ok' => false, 'error' => 'parent_inactive']);
        }
        try {
            $response = $this->SendDataToParent($json);
        } catch (\Throwable $e) {
            return json_encode(['ok' => false, 'error' => 'no_response']);
        }
        if ($response === false || $response === null || $response === '') {
            return json_encode(['ok' => false, 'error' => 'no_response']);
        }
        return json_encode(['ok' => true, 'data' => base64_encode((string)$response)]);
    }

    public function GetState(): string
    {
        return json_encode([
            'connected'    => $this->ParentConnected(),
            'parentActive' => $this->ParentStatus() === 102,
            'parentStatus' => $this->ParentStatus(),
            'unitId'       => $this->UnitId(),
        ]);
    }

    public function GetConfigurationForm()
    {
        $unit = $this->UnitId();
        // Zeilen mit eingesetzten Werten uebersetzt das Modul selbst (die Konsole kennt nur feste Texte).
        $state = !$this->ParentConnected()
            ? '⚠️ No Modbus gateway linked (🔌 icon at the top of the instance header).'
            : ($this->ParentStatus() === 102
                ? '✅ Gateway linked and active.'
                : sprintf($this->Translate('⚠️ Gateway linked, but not active (status %s).'), (string)$this->ParentStatus()));
        $unitText = $unit === null
            ? $this->Translate('Unit ID read from the gateway: not readable / no gateway')
            : sprintf($this->Translate('Unit ID read from the gateway: %s'), (string)$unit);
        return json_encode([
            'elements' => [
                ['type' => 'Label', 'caption' => '🔗 Bridge between ChargerHub and the Modbus gateway. No settings of its own: in ChargerHub choose "Symbox gateway" and click "Create bridge and connect".'],
                ['type' => 'Label', 'caption' => '⚠️ One bridge = exactly one unit ID (the gateway\'s "DeviceID"). For more unit IDs, use a separate gateway and a separate bridge.'],
                ['type' => 'Label', 'caption' => $state],
                ['type' => 'Label', 'caption' => $unitText],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Gateway linked.'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'No Modbus gateway linked.'],
            ],
        ]);
    }
}
