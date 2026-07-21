<?php

// ===========================================================================
// ChargerHub — generisches Modbus-TCP-Framework für Wallboxen verschiedener
// Hersteller. Ein Modul, ein Auswahlfeld „Wallbox-Typ" — je nach Auswahl
// werden die passenden Register und Bedienelemente freigeschaltet.
//
// Aufbau analog zu InverterHub/MeterHub:
//   ModbusTcpClient        — gemeinsame Modbus-TCP-Grundfunktionen
//   ChargerDriverInterface — Vertrag, den jeder Wallbox-Treiber erfüllt
//   (Treiber folgen einzeln, sobald ein konkretes Wallbox-Modell feststeht)
//   ChargerHub             — Hauptmodul, lädt den Treiber laut Wallbox-Property
//
// Im Unterschied zu MeterHub (nur lesen) braucht ChargerHub auch Schreib-
// zugriff (Ladefreigabe, Stromlimit) — das Interface ist daher näher an
// InverterHub als an MeterHub.
//
// Stand: Gerüst. Noch kein konkreter Treiber implementiert — folgt, sobald
// ein Wallbox-Modell zum Testen feststeht.
// ===========================================================================

interface ChargerDriverInterface
{
    // Momentanwerte lesen: Ladeleistung (W), Ladestrom je Phase (A), Status.
    public function readValues(string $host, int $port, int $unitId): ?array;

    // Ladefreigabe setzen (true = laden erlaubt).
    public function setEnabled(string $host, int $port, int $unitId, bool $enabled): bool;

    // Stromlimit setzen (A, je Phase gleich).
    public function setCurrentLimit(string $host, int $port, int $unitId, float $ampere): bool;
}

class ChargerHub extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Host', '');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyInteger('UnitID', 1);
        $this->RegisterPropertyInteger('ChargerType', 0);
        $this->RegisterPropertyInteger('UpdateInterval', 10);

        $this->RegisterTimer('Update', 0, 'CHUB_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if ($this->ReadPropertyString('Host') === '') {
            $this->SetTimerInterval('Update', 0);
            $this->SetStatus(104);
            return;
        }
        $this->SetTimerInterval('Update', $this->ReadPropertyInteger('UpdateInterval') * 1000);
        $this->SetStatus(102);
    }

    public function Update()
    {
        // TODO: Treiber laut ChargerType laden, readValues() aufrufen,
        // Variablen aktualisieren — Muster wie MeterHub::ReadMeterData().
    }

    // Vertrag für Partnermodule (EMS, Kacheln) — Muster wie MHUB_GetFunctions
    // in MeterHub. Liefert Rolle, Bezeichnung und Variablen-IDs je Ladepunkt.
    // Immer hinter function_exists('CHUB_GetFunctions') beim Aufrufer.
    public function GetFunctions(): array
    {
        return [];
    }
}
