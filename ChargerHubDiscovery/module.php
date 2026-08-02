<?php

// ---------------------------------------------------------------------------
// ChargerHubDiscovery — Configurator-Modul: durchsucht einen IP-Bereich nach
// Wallboxen auf Modbus-TCP-Port 502, erkennt den Hersteller anhand weniger
// charakteristischer Register/Unit-IDs und legt auf Klick eine ChargerHub-
// Instanz mit vorausgefüllten Werten an. Aufbau analog zu
// InverterHubDiscovery/MeterHubDiscovery. Eigenständige, kompakte Modbus-
// Hilfsfunktionen (kein Zugriff auf die Klassen aus dem ChargerHub-
// Modulordner — Module sind bewusst getrennt).
//
// WICHTIG: Die Erkennungskriterien je Hersteller sind aus den öffentlichen
// Modbus-Dokumentationen abgeleitet, aber NICHT an echter Hardware
// verifiziert. Wird eine Wallbox nicht (oder falsch) erkannt, bitte die
// ChargerHub-Instanz manuell anlegen und Rückmeldung geben.
// ---------------------------------------------------------------------------

class ChargerHubDiscovery extends IPSModule
{
    private const CHARGERHUB_GUID = '{9256C34E-5CFD-4F37-8BFE-E65390EBB37C}';
    private const MIGRATIONSHUB_GUID = '{330717BB-E309-41A2-90A8-FDA3179ED948}';

    // Kandidaten je Hersteller: Unit-IDs, die typischerweise/dokumentiert
    // Standard sind (kleine Liste statt vollem 1-247-Bereich).
    private const VENDOR_UNIT_IDS = [
        'keba'       => [255, 1],
        'alfen'      => [1],
        'heidelberg' => [1],
        'goe'        => [1],
    ];

    private const VENDOR_LABELS = [
        'keba'       => 'KEBA KeContact P30/P40',
        'alfen'      => 'Alfen Eve Single/Double Pro-line',
        'heidelberg' => 'Heidelberg Energy Control',
        'goe'        => 'go-eCharger Gemini/HOME+',
    ];

    public function Create()
    {
        parent::Create();

        $prefix = $this->guessLocalSubnetPrefix();
        $this->RegisterPropertyString('RangeStart', $prefix !== '' ? $prefix . '.1'   : '');
        $this->RegisterPropertyString('RangeEnd',   $prefix !== '' ? $prefix . '.254' : '');
        $this->RegisterPropertyInteger('Port', 502);
        $this->RegisterPropertyString('NameTemplate', '');
        $this->RegisterPropertyString('IgnoreIPs', '');
        $this->RegisterAttributeString('ResultsJSON', '[]');
    }

    // Ermittelt heuristisch die ersten drei Oktette des lokalen Subnetzes
    // (z. B. „192.168.1"), um Start-/End-IP sinnvoll vorzubelegen.
    private function guessLocalSubnetPrefix()
    {
        $ip = @gethostbyname(gethostname());
        if ($ip === false || $ip === gethostname()) {
            return '';
        }
        $parts = explode('.', $ip);
        if (count($parts) !== 4) {
            return '';
        }
        $isPrivate = ($parts[0] === '10')
            || ($parts[0] === '192' && $parts[1] === '168')
            || ($parts[0] === '172' && (int)$parts[1] >= 16 && (int)$parts[1] <= 31);
        if (!$isPrivate) {
            return '';
        }
        return $parts[0] . '.' . $parts[1] . '.' . $parts[2];
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterVariableBoolean('ScanAbort', 'Suche abbrechen (intern)', '', 100);
        IPS_SetHidden($this->GetIDForIdent('ScanAbort'), true);
    }

    private function scanAborted(): bool
    {
        return @$this->GetValue('ScanAbort') === true;
    }

    public function AbortScan()
    {
        if (@IPS_GetObjectIDByIdent('ScanAbort', $this->InstanceID)) {
            $this->SetValue('ScanAbort', true);
        }
        @$this->UpdateFormField('ScanProgress', 'caption', 'Abbruch angefordert – bitte kurz warten …');
        @$this->UpdateFormField('ScanProgress', 'indeterminate', true);
    }

    public function GetConfigurationForm()
    {
        $results = json_decode($this->ReadAttributeString('ResultsJSON'), true);
        if (!is_array($results)) {
            $results = [];
        }

        $existing = $this->findExistingInstances();
        $template = trim($this->ReadPropertyString('NameTemplate'));

        $vendorCounter = [];
        $values = [];
        foreach ($results as $r) {
            $key = $r['ip'] . '|' . $r['unitId'];
            $vendorCounter[$r['vendor']] = ($vendorCounter[$r['vendor']] ?? 0) + 1;
            $nr = $vendorCounter[$r['vendor']];

            if ($template !== '') {
                $instanceName = str_replace(
                    ['{hersteller}', '{ip}', '{unitid}', '{nr}'],
                    [$r['label'], $r['ip'], $r['unitId'], $nr],
                    $template
                );
            } else {
                $instanceName = $r['label'] . ' ' . $nr;
            }

            $legacy = $this->LegacyCandidateFor($r['ip'], $r['unitId']);
            $config = [
                'Host'         => $r['ip'],
                'Port'         => $this->ReadPropertyInteger('Port'),
                'UnitId'       => $r['unitId'],
                'Manufacturer' => $r['vendor'],
            ];
            if ($legacy['id'] > 0 || $legacy['ambiguous']) {
                // Kommunikation bleibt aus, bis die Migration abgeschlossen
                // ist — sonst überlappt sich neu geloggte mit übertragener
                // Alt-Historie (siehe Doku-Panel-Hinweis oben). Auch bei
                // Mehrdeutigkeit sicherheitshalber aus, bis der Nutzer manuell
                // geklärt hat, welche Alt-Instanz die richtige ist.
                $config['Active'] = false;
            }

            if ($legacy['ambiguous']) {
                $legacyText = '⚠️ Mehrere Alt-Instanzen (' . $legacy['name'] . ') — bitte manuell in MigrationsHub verknüpfen';
            } elseif ($legacy['id'] > 0) {
                $legacyText = '⚠️ ' . $legacy['name'] . ' (#' . $legacy['id'] . ')';
            } else {
                $legacyText = '';
            }

            $values[] = [
                'name'         => $r['label'] . ' @ ' . $r['ip'] . ' (Unit ' . $r['unitId'] . ')',
                'manufacturer' => $r['label'],
                'ip'           => $r['ip'],
                'unitId'       => $r['unitId'],
                'legacy'       => $legacyText,
                'instanceID'   => $existing[$key] ?? 0,
                'create'       => [
                    'moduleID'      => self::CHARGERHUB_GUID,
                    'name'          => $instanceName,
                    'configuration' => $config,
                ],
            ];
        }

        $form = [
            'elements' => [
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '📖  Dokumentation & Hilfe',
                    'expanded' => false,
                    'items'    => [
                        ['type' => 'Label', 'caption' => 'Durchsucht einen IP-Bereich im lokalen Netz nach Wallboxen auf Modbus-TCP-Port 502 und erkennt den Hersteller anhand weniger typischer Register/Unit-IDs.'],
                        ['type' => 'Label', 'caption' => 'Start- und End-IP eintragen, dann „Netzwerk durchsuchen" klicken. Gefundene Geräte erscheinen unten — Klick auf „Erstellen" legt eine ChargerHub-Instanz mit vorausgefüllter IP-Adresse, Unit-ID und Hersteller an.'],
                        ['type' => 'Label', 'caption' => 'Erkannt werden: KEBA KeContact P30/P40, Alfen Eve Single/Double Pro-line, Heidelberg Energy Control, go-eCharger Gemini/HOME+. Die Erkennungskriterien sind aus den Hersteller-Dokumentationen abgeleitet — wird eine Wallbox nicht gefunden, bitte die ChargerHub-Instanz manuell anlegen.'],
                        ['type' => 'Label', 'caption' => 'Wird ein bekanntes Gerät nicht gefunden: einen SCHMALEN Bereich (bis 64 Adressen) um dessen IP durchsuchen — das nutzt eine langsamere, aber zuverlässigere Port-Prüfung.'],
                        ['type' => 'Label', 'caption' => '⚠️ go-eCharger: Der Modbus-Server muss am Gerät erst aktiviert sein (go-e-App → Internet → Erweiterte Einstellungen → Modbus, oder HTTP-API „men=true"), sonst ist Port 502 geschlossen und das Gerät für die Suche unsichtbar. In der Praxis beobachtet: Auch bei gespeichertem „aktiviert" lief der Server erst nach einem Aus-/Einschalten der Einstellung bzw. Neustart der Wallbox — zum Prüfen im Browser aufrufen: http://<wallbox-ip>/api/status?filter=men'],
                        ['type' => 'Label', 'caption' => '🔀 Neue Instanz kommt mit „Kommunikation aktiv" bereits eingeschaltet. Falls ein Umstieg von einem anderen Wallbox-/Hub-Modul mit Übernahme der Historie geplant ist: direkt nach dem Anlegen an der neuen ChargerHub-Instanz wieder ausschalten, bis MigrationsHub die alte Historie übernommen hat — sonst überlappen sich die neu geloggten Werte mit der übertragenen Alt-Historie.'],
                    ],
                ],
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '🔎  Suchbereich',
                    'expanded' => true,
                    'items'    => [
                        ['type' => 'ValidationTextBox', 'name' => 'RangeStart', 'caption' => 'Start-IP', 'validate' => '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$'],
                        ['type' => 'ValidationTextBox', 'name' => 'RangeEnd',   'caption' => 'End-IP',   'validate' => '^\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}\\.\\d{1,3}$'],
                        ['type' => 'NumberSpinner', 'name' => 'Port', 'caption' => 'Modbus-TCP-Port', 'minimum' => 1, 'maximum' => 65535],
                        ['type' => 'ValidationTextBox', 'name' => 'NameTemplate', 'caption' => 'Namensvorlage (leer = Hersteller + lfd. Nr.)'],
                        ['type' => 'Label', 'caption' => 'Platzhalter für die Vorlage: {hersteller} {ip} {unitid} {nr} — z. B. "{hersteller} Carport ({ip})"'],
                        ['type' => 'ValidationTextBox', 'name' => 'IgnoreIPs', 'caption' => 'IPs ignorieren (Komma-getrennt)'],
                        [
                            'type'  => 'RowLayout',
                            'items' => [
                                ['type' => 'Button', 'name' => 'BtnScan',  'caption' => '🔎  Netzwerk durchsuchen', 'onClick' => 'CHUBD_Discover($id);'],
                                ['type' => 'Button', 'name' => 'BtnAbort', 'caption' => '✖  Suche abbrechen', 'onClick' => 'CHUBD_AbortScan($id);', 'visible' => false],
                            ],
                        ],
                        [
                            'type'          => 'ProgressBar',
                            'name'          => 'ScanProgress',
                            'caption'       => 'Bereit.',
                            'minimum'       => 0,
                            'maximum'       => 100,
                            'current'       => 0,
                            'indeterminate' => false,
                            'visible'       => false,
                        ],
                    ],
                ],
                [
                    'type'     => 'ExpansionPanel',
                    'caption'  => '🛠️  Erstellen',
                    'expanded' => true,
                    'items'    => [
                        [
                            'type'     => 'Configurator',
                            'name'     => 'DiscoveryList',
                            'caption'  => 'Gefundene Wallboxen',
                            'rowCount' => 6,
                            'delete'   => false,
                            'sort'     => ['column' => 'ip', 'direction' => 'ascending'],
                            'columns'  => [
                                ['caption' => 'Hersteller', 'name' => 'manufacturer', 'width' => '250px'],
                                ['caption' => 'IP-Adresse', 'name' => 'ip',           'width' => '150px'],
                                ['caption' => 'Unit ID',    'name' => 'unitId',       'width' => '100px'],
                                ['caption' => 'Alt-Instanz gefunden (MigrationsHub)', 'name' => 'legacy', 'width' => '280px',
                                 'visible' => function_exists('MIGHUB_FindLegacyCandidates')],
                            ],
                            'values' => $values,
                        ],
                        [
                            'type' => 'Label', 'caption' => '🔀 Migration von einer Alt-Instanz (anderes Modul, gleiche IP/Unit-ID): erst oben „Erstellen" klicken — Kommunikation bleibt bei erkannter Alt-Instanz automatisch aus —, dann hier „Migration vorbereiten". Verknüpft die neue mit der alten Instanz in MigrationsHub; Simulation, Bestätigung und Ausführung bleiben dort bewusst manuelle Schritte. Bei mehreren Treffern: nach jeder abgeschlossenen Migration erneut klicken.',
                            'visible' => function_exists('MIGHUB_FindLegacyCandidates'),
                        ],
                        [
                            'type' => 'Button', 'name' => 'BtnPrepareMigration', 'caption' => '🔀  Migration vorbereiten',
                            'onClick' => 'CHUBD_PrepareMigration($id);',
                            'visible' => function_exists('MIGHUB_FindLegacyCandidates'),
                        ],
                        ['type' => 'Label', 'name' => 'MigrationResult', 'caption' => '', 'visible' => false],
                        ['type' => 'OpenObjectButton', 'name' => 'BtnOpenMigration', 'caption' => '→ Zur MigrationsHub-Instanz', 'objectID' => 0, 'visible' => false],
                    ],
                ],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active',   'caption' => 'Bereit.'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Bitte Such-IP-Bereich eintragen.'],
            ],
        ];

        return json_encode($form);
    }

    // -----------------------------------------------------------------------
    // Discovery
    // -----------------------------------------------------------------------

    private function ShowProgress($caption, $current, $indeterminate = false)
    {
        @$this->UpdateFormField('ScanProgress', 'visible', true);
        @$this->UpdateFormField('ScanProgress', 'caption', $caption);
        @$this->UpdateFormField('ScanProgress', 'indeterminate', $indeterminate);
        @$this->UpdateFormField('ScanProgress', 'current', $current);
    }

    public function Discover()
    {
        $start = $this->ReadPropertyString('RangeStart');
        $end   = $this->ReadPropertyString('RangeEnd');
        $port  = $this->ReadPropertyInteger('Port');

        if ($start === '' || $end === '') {
            $this->SetStatus(104);
            return;
        }

        if (@IPS_GetObjectIDByIdent('ScanAbort', $this->InstanceID)) {
            $this->SetValue('ScanAbort', false);
        }
        $this->WriteAttributeString('ResultsJSON', '[]');
        @$this->UpdateFormField('DiscoveryList', 'values', []);
        @$this->UpdateFormField('BtnScan', 'visible', false);
        @$this->UpdateFormField('BtnAbort', 'visible', true);

        $ips = $this->expandRange($start, $end);
        if (count($ips) > 1024) {
            $ips = array_slice($ips, 0, 1024);
        }

        $this->ShowProgress('Durchsuche ' . count($ips) . ' IP-Adressen auf Port ' . $port . ' …', 0);

        $ignore = $this->ParseIgnoreIPs();
        if (count($ignore) > 0) {
            $ips = array_values(array_diff($ips, $ignore));
        }

        $openIps = $this->scanPortOpen($ips, $port, 3.0);

        $results = [];
        $total   = count($openIps);
        $i       = 0;
        $aborted = $this->scanAborted();
        foreach ($openIps as $ip) {
            if ($this->scanAborted()) { $aborted = true; break; }
            $i++;
            $this->ShowProgress("Prüfe Hersteller: $ip ($i von $total offenen Ports) …", (int)round(($i / max(1, $total)) * 100));
            $found = $this->identifyVendor($ip, $port);
            if ($found !== null) {
                $results[] = $found;
            }
        }

        if ($aborted) {
            $this->ShowProgress('Suche abgebrochen – ' . count($results) . ' Wallboxen bis dahin gefunden.', 100);
        } else {
            $this->ShowProgress('Fertig: ' . count($results) . ' Wallboxen gefunden (von ' . $total . ' offenen Ports).', 100);
        }

        $this->WriteAttributeString('ResultsJSON', json_encode($results));
        $this->SetStatus(102);
        $this->ReloadForm();
    }

    private function findExistingInstances()
    {
        $map = [];
        foreach (IPS_GetInstanceListByModuleID(self::CHARGERHUB_GUID) as $iid) {
            $host   = @IPS_GetProperty($iid, 'Host');
            $unitId = @IPS_GetProperty($iid, 'UnitId');
            if ($host !== false && $host !== null && $host !== '') {
                $map[$host . '|' . $unitId] = $iid;
            }
        }
        return $map;
    }

    /**
     * Alt-Instanz eines Fremdmoduls an derselben IP/Unit-ID, falls
     * MigrationsHub installiert ist und eine kennt. Optionale Kopplung
     * (Verbund-Konvention 29.07.2026, mit MigrationsHub abgestimmt) — ohne
     * MigrationsHub liefert dies immer "nichts gefunden", bricht nichts.
     */
    // Rückgabe: ['id' => int, 'name' => string, 'ambiguous' => bool]. Bei
    // mehreren Treffern (live beobachtet: zwei goeCharger-Fremdinstanzen mit
    // identischer IP, eine davon offenbar eine bewusste Sicherungs-Kopie)
    // NIE automatisch den ersten wählen — das goeCharger-Modul speichert
    // keine Unit-ID, das Matching läuft dann nur über die IP und kann die
    // physisch falsche Wallbox treffen. Bei Mehrdeutigkeit lieber gar nichts
    // vorschlagen (id=0, ambiguous=true) und den Nutzer auf die manuelle
    // Verknüpfung in MigrationsHub verweisen, als eine falsche Historie zu
    // verknüpfen.
    private function LegacyCandidateFor(string $host, int $unitId): array
    {
        if (!function_exists('MIGHUB_FindLegacyCandidates')) {
            return ['id' => 0, 'name' => '', 'ambiguous' => false];
        }
        // WICHTIG: erster Parameter ist eine MIGRATIONSHUB-Instanz-ID, NICHT
        // unsere eigene ($this->InstanceID) — Verwechslung führte live zu
        // "Instance does not implement this function" (MigrationsHub prüft
        // per Reflection, ob die übergebene Instanz sein eigenes Modul ist).
        // Im schreibgeschützten Formular-Aufbau keine Instanz anlegen (das
        // bleibt PerformMigration() bei explizitem Klick vorbehalten) — ohne
        // vorhandene MigrationsHub-Instanz gibt es ohnehin nichts zu finden.
        $migIDs = @IPS_GetInstanceListByModuleID(self::MIGRATIONSHUB_GUID);
        $migID = $migIDs[0] ?? 0;
        if ($migID <= 0) {
            return ['id' => 0, 'name' => '', 'ambiguous' => false];
        }
        $found = @MIGHUB_FindLegacyCandidates($migID, $host, $this->ReadPropertyInteger('Port'), $unitId);
        if (!is_array($found) || count($found) === 0) {
            return ['id' => 0, 'name' => '', 'ambiguous' => false];
        }
        if (count($found) > 1) {
            // 'Path' (Kategorie-Pfad von der Wurzel) unterscheidet Treffer
            // mit identischem Namen an derselben IP — z. B. eine bewusste
            // Sicherungs-Kopie in einer anderen Kategorie (mit MigrationsHub
            // abgestimmt, live an genau diesem Fall verifiziert).
            $names = array_map(function ($f) {
                $id = (int)($f['instanceID'] ?? $f['id'] ?? 0);
                $label = (string)($f['name'] ?? IPS_GetName($id)) . ' (#' . $id . ')';
                if (!empty($f['Path'])) {
                    $label .= ' [' . $f['Path'] . ']';
                }
                return $label;
            }, $found);
            return ['id' => 0, 'name' => implode(', ', $names), 'ambiguous' => true];
        }
        $first = $found[0];
        $id = (int)($first['instanceID'] ?? $first['id'] ?? 0);
        if ($id <= 0) {
            return ['id' => 0, 'name' => '', 'ambiguous' => false];
        }
        return ['id' => $id, 'name' => (string)($first['name'] ?? IPS_GetName($id)), 'ambiguous' => false];
    }

    /**
     * Verknüpft die erste bereits erstellte ChargerHub-Instanz, für die eine
     * Alt-Instanz gefunden wurde, mit MigrationsHub — legt bei Bedarf eine
     * MigrationsHub-Instanz an (wiederverwendet eine vorhandene) und ruft
     * MIGHUB_PrefillMigration() auf. Absichtlich nur EIN Treffer je Klick:
     * PrefillMigration setzt Source/Target auf EINER MigrationsHub-Instanz,
     * ein zweiter Aufruf vor Abschluss der ersten Migration würde die noch
     * nicht bestätigte Zuordnung überschreiben.
     */
    public function PrepareMigration()
    {
        $say = function (string $m) {
            $this->UpdateFormField('MigrationResult', 'caption', $m);
            $this->UpdateFormField('MigrationResult', 'visible', true);
        };
        if (!function_exists('MIGHUB_FindLegacyCandidates') || !function_exists('MIGHUB_PrefillMigration')) {
            $say('❌ MigrationsHub ist nicht installiert.');
            return;
        }

        $results = json_decode($this->ReadAttributeString('ResultsJSON'), true);
        $results = is_array($results) ? $results : [];
        $existing = $this->findExistingInstances();

        foreach ($results as $r) {
            $targetID = $existing[$r['ip'] . '|' . $r['unitId']] ?? 0;
            if ($targetID <= 0) {
                continue; // Für diese Zeile wurde noch keine ChargerHub-Instanz erstellt.
            }
            $legacy = $this->LegacyCandidateFor($r['ip'], $r['unitId']);
            if ($legacy['ambiguous']) {
                // Nicht automatisch verknüpfen (siehe LegacyCandidateFor) —
                // vorher lief das hier still durch, ohne dass der Nutzer
                // erfuhr, warum nichts passiert ist.
                $say('⚠️ Mehrere Alt-Instanzen an „' . $r['ip'] . '" gefunden (' . $legacy['name'] .
                    ') — bitte manuell in MigrationsHub verknüpfen, keine automatische Zuordnung möglich.');
                continue;
            }
            if ($legacy['id'] <= 0) {
                continue;
            }

            // Kommunikation sicherheitshalber aus, falls sie inzwischen
            // (manuell oder weil die Zeile vor dieser Funktion schon einmal
            // erstellt wurde) doch aktiv ist.
            if (@IPS_GetProperty($targetID, 'Active') === true) {
                IPS_SetProperty($targetID, 'Active', false);
                IPS_ApplyChanges($targetID);
            }

            $migIDs = IPS_GetInstanceListByModuleID(self::MIGRATIONSHUB_GUID);
            $migID = $migIDs[0] ?? 0;
            if ($migID <= 0) {
                $migID = IPS_CreateInstance(self::MIGRATIONSHUB_GUID);
            }
            MIGHUB_PrefillMigration($migID, $legacy['id'], $targetID);

            $say('✅ Migration vorbereitet: „' . $legacy['name'] . '" (#' . $legacy['id'] . ') → „' .
                IPS_GetName($targetID) . '" (#' . $targetID . '). Weiter in der MigrationsHub-Instanz — dort simulieren, prüfen, ausführen.');
            $this->UpdateFormField('BtnOpenMigration', 'objectID', $migID);
            $this->UpdateFormField('BtnOpenMigration', 'visible', true);
            return;
        }

        $say('🔎 Keine passende Kombination aus bereits erstellter ChargerHub-Instanz und gefundener Alt-Instanz — erst oben „Erstellen" klicken.');
    }

    private function ParseIgnoreIPs()
    {
        $raw = (string)$this->ReadPropertyString('IgnoreIPs');
        $out = [];
        foreach (preg_split('/[\s,;]+/', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && ip2long($part) !== false) {
                $out[] = long2ip(ip2long($part));
            }
        }
        return array_unique($out);
    }

    private function expandRange($startIp, $endIp)
    {
        $start = ip2long($startIp);
        $end   = ip2long($endIp);
        if ($start === false || $end === false || $start > $end) {
            return [];
        }
        $ips = [];
        for ($i = $start; $i <= $end; $i++) {
            $ips[] = long2ip($i);
        }
        return $ips;
    }

    private function scanPortOpen($ips, $port, $timeoutSec)
    {
        if (count($ips) <= 64) {
            $open  = [];
            $total = count($ips);
            $i     = 0;
            foreach ($ips as $ip) {
                if ($this->scanAborted()) { break; }
                $i++;
                $this->ShowProgress("Port-Prüfung (genau) … $i von $total", (int)round(($i / max(1, $total)) * 90));
                $s = @fsockopen($ip, $port, $errno, $errstr, min(0.8, $timeoutSec));
                if ($s !== false) {
                    $open[] = $ip;
                    fclose($s);
                }
            }
            return $open;
        }

        $pending = [];
        foreach ($ips as $ip) {
            $s = @stream_socket_client(
                "tcp://$ip:$port",
                $errno,
                $errstr,
                0.01,
                STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT
            );
            if ($s !== false) {
                stream_set_blocking($s, false);
                $pending[$ip] = $s;
            }
        }

        $open      = [];
        $totalOpen = count($pending);
        $startTime = microtime(true);
        $deadline  = $startTime + $timeoutSec;
        $lastUi    = 0.0;
        while (count($pending) > 0 && microtime(true) < $deadline) {
            if ($this->scanAborted()) {
                break;
            }
            $write  = array_values($pending);
            $read   = [];
            $except = [];
            $n = @stream_select($read, $write, $except, 0, 200000);
            if ($n === false) {
                break;
            }
            foreach ($pending as $ip => $sock) {
                if (in_array($sock, $write, true)) {
                    $peer = @stream_socket_get_name($sock, true);
                    if ($peer !== false) {
                        $open[] = $ip;
                    }
                    fclose($sock);
                    unset($pending[$ip]);
                }
            }
            $now = microtime(true);
            if ($now - $lastUi >= 0.3) {
                $lastUi  = $now;
                $elapsed = $now - $startTime;
                $pct     = (int)round(min(95, ($elapsed / $timeoutSec) * 90));
                $this->ShowProgress(
                    "Port-Prüfung läuft … " . count($open) . " offen, " . count($pending) . " von $totalOpen noch offen",
                    $pct
                );
                $deadline += microtime(true) - $now;
            }
        }
        foreach ($pending as $sock) {
            @fclose($sock);
        }
        return $open;
    }

    private function identifyVendor($ip, $port)
    {
        $this->beginProbe($ip, $port, 3.0);
        try {
            foreach (self::VENDOR_UNIT_IDS as $vendor => $unitIds) {
                foreach ($unitIds as $unitId) {
                    if ($this->probeVendor($vendor, $ip, $port, $unitId)) {
                        return [
                            'ip'     => $ip,
                            'unitId' => $unitId,
                            'vendor' => $vendor,
                            'label'  => self::VENDOR_LABELS[$vendor],
                        ];
                    }
                }
            }
            return null;
        } finally {
            $this->endProbe();
        }
    }

    // Zwei-Kriterien-Ansatz wie bei InverterHubDiscovery: ein einzelnes
    // "Register in plausiblem Bereich"-Kriterium ist zu schwach — andere
    // Modbus-Geräte auf derselben Unit-ID würden es leicht zufällig erfüllen.
    private function probeVendor($vendor, $ip, $port, $unitId)
    {
        switch ($vendor) {
            case 'keba':
                // KEBA: alle Werte U32 über 2 Register, FC 0x03 (Holding) —
                // Konvention aus der evcc-Referenzimplementierung.
                // Holding 1000: Ladestatus, plausibel 0..5.
                $state = $this->readHolding($ip, $port, $unitId, 1000, 2, 1.0);
                if ($state === null || count($state) < 2) {
                    return false;
                }
                $stateVal = (($state[0] & 0xFFFF) << 16) | ($state[1] & 0xFFFF);
                if ($stateVal > 5) {
                    return false;
                }
                // Holding 1004: Kabelstatus, plausibel 0/1/3/5/7.
                $cable = $this->readHolding($ip, $port, $unitId, 1004, 2, 1.0);
                if ($cable === null || count($cable) < 2) {
                    return false;
                }
                $cableVal = (($cable[0] & 0xFFFF) << 16) | ($cable[1] & 0xFFFF);
                return in_array($cableVal, [0, 1, 3, 5, 7], true);

            case 'alfen':
                // Holding 1200: Sockel-Verfügbarkeit, plausibel 0..2.
                $avail = $this->readHolding($ip, $port, $unitId, 1200, 1, 1.0);
                if ($avail === null || $avail[0] > 2) {
                    return false;
                }
                // Holding 1206: angewandtes Stromlimit (Float32), plausibel 0..80 A.
                $curr = $this->readFloatHolding($ip, $port, $unitId, 1206);
                return ($curr !== null && $curr >= 0.0 && $curr <= 80.0);

            case 'heidelberg':
                // Holding 4: Firmware-Version, plausibel klein und > 0.
                $ver = $this->readHolding($ip, $port, $unitId, 4, 1, 1.0);
                if ($ver === null || $ver[0] <= 0 || $ver[0] > 100) {
                    return false;
                }
                // Holding 5: Ladestatus, plausibel 2..8.
                $state = $this->readHolding($ip, $port, $unitId, 5, 1, 1.0);
                return ($state !== null && $state[0] >= 2 && $state[0] <= 8);

            case 'goe':
                // Input-Register 100: CAR_STATE, plausibel 0..4 (offizielle
                // go-e-Modbus-Doku, https://github.com/goecharger/go-eCharger-API-v2).
                $car = $this->readInput($ip, $port, $unitId, 100, 1, 1.0);
                if ($car === null || $car[0] > 4) {
                    return false;
                }
                // Holding-Register 201: ACCESS_STATE, plausibel 0..3.
                $access = $this->readHolding($ip, $port, $unitId, 201, 1, 1.0);
                return ($access !== null && $access[0] <= 3);

        }
        return false;
    }

    private function readFloatHolding($ip, $port, $unitId, $reg)
    {
        $r = $this->readHolding($ip, $port, $unitId, $reg, 2, 1.0);
        if ($r === null || count($r) < 2) {
            return null;
        }
        $raw = pack('nn', $r[0] & 0xFFFF, $r[1] & 0xFFFF);
        $f   = unpack('G', $raw)[1] ?? null;
        return ($f !== null && is_finite($f)) ? (float)$f : null;
    }

    // -----------------------------------------------------------------------
    // Minimale Modbus-TCP-Hilfsfunktionen (nur für die kurzen Scan-Proben)
    // -----------------------------------------------------------------------

    private function readHolding($host, $port, $unitId, $startReg, $count, $timeout)
    {
        return $this->modbusRead($host, $port, $unitId, 0x03, $startReg, $count, $timeout);
    }

    private function readInput($host, $port, $unitId, $startReg, $count, $timeout)
    {
        return $this->modbusRead($host, $port, $unitId, 0x04, $startReg, $count, $timeout);
    }

    private $probeSock = null;

    private function beginProbe($host, $port, $timeout)
    {
        $this->endProbe();
        $s = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($s !== false) {
            stream_set_timeout($s, $timeout);
            $this->probeSock = $s;
        }
    }

    private function endProbe()
    {
        if ($this->probeSock !== null) {
            @fclose($this->probeSock);
            $this->probeSock = null;
        }
    }

    private function modbusRead($host, $port, $unitId, $fc, $startReg, $count, $timeout)
    {
        $r = $this->modbusReadOnce($host, $port, $unitId, $fc, $startReg, $count, $timeout);
        if ($this->probeSock === null) {
            usleep(120000);
        }
        return $r;
    }

    private function modbusReadOnce($host, $port, $unitId, $fc, $startReg, $count, $timeout)
    {
        $sock = $this->probeSock ?: @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($sock === false) {
            return null;
        }
        if ($this->probeSock === null) {
            stream_set_timeout($sock, $timeout);
        }

        $tid  = mt_rand(1, 65535);
        $pdu  = pack('Cnn', $fc, $startReg, $count);
        $mbap = pack('nnn', $tid, 0, strlen($pdu) + 1) . chr($unitId);

        @fwrite($sock, $mbap . $pdu);

        $response = '';
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $chunk = @fread($sock, 512);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $response .= $chunk;
            if (strlen($response) >= 9) {
                if (ord($response[7]) & 0x80) {
                    break;
                }
                $byteCount = ord($response[8]);
                if (strlen($response) >= 9 + $byteCount) {
                    break;
                }
            }
        }
        if ($this->probeSock === null) {
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
        $regs      = [];
        for ($i = 0; $i < $count && ($i * 2 + 1) < strlen($data); $i++) {
            $regs[$i] = (ord($data[$i * 2]) << 8) | ord($data[$i * 2 + 1]);
        }
        return $regs;
    }
}
