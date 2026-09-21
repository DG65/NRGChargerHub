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
    // Eigene Modul-GUID (module.json 'id') — für das instanzübergreifende
    // Ausblenden des "Wozu dieses Modul?"-Panels unter mehreren
    // ChargerHubDiscovery-Instanzen, siehe PropagateDismiss().
    private const DISCOVERY_GUID = '{613D9807-B975-91B2-C6BD-FDD3654EF87E}';
    private const LICENSE_URL = 'https://github.com/DG65/NRGChargerHub/blob/beta/LICENSE';
    private const PAYPAL_URL = 'https://paypal.me/DietmarGureth';
    private const FORUM_THREAD_URL = 'https://community.symcon.de/t/modul-nrg-stack-chargerhub-ein-modbus-tcp-modul-fuer-viele-wallboxen-netzwerksuche/144397';

    // Kandidaten je Hersteller: Unit-IDs, die typischerweise/dokumentiert
    // Standard sind (kleine Liste statt vollem 1-247-Bereich).
    private const VENDOR_UNIT_IDS = [
        'keba'        => [255, 1],
        'alfen'       => [1],
        'heidelberg'  => [1],
        'goe'         => [1],
        'daheimlader' => [255],
        // ABL läuft über einen seriellen RS485-zu-Ethernet-Wandler ohne
        // eigene Modbus-Adresslogik am TCP-Port — Unit-ID kommt aus dem
        // ABL-eigenen Adressbereich 0x01..0x10 (Broadcast 0x00 ausgenommen,
        // siehe ABL-PDF "Common settings"), 1 ist der praktische Standard.
        'abl'         => [1],
        'foxess'      => [1],
        // 1 an echter Hardware bestätigt (Forum-Tester, 20.09.2026); 255 laut Peblars
        // Beispielclient, an Hardware nicht gegengeprüft. 1 zuerst.
        'peblar'      => [1, 255],
    ];

    private const VENDOR_LABELS = [
        'keba'        => 'KEBA KeContact P30/P40',
        'alfen'       => 'Alfen Eve Single/Double Pro-line',
        'heidelberg'  => 'Heidelberg Energy Control',
        'goe'         => 'go-eCharger Gemini/HOME+',
        'daheimlader' => 'DaheimLader (Smart/Touch/PRO-Serie)',
        'abl'         => 'ABL eMH1/eMH2/eMH3',
        'foxess'      => 'Fox ESS EV Charger (A/L/C)',
        'peblar'      => 'Peblar Home/Business (experimentell)',
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
        $this->RegisterAttributeInteger('LastDiscoveryTs', 0);
        $this->RegisterAttributeString('PreparedTargets', '[]');
        // "Wozu dieses Modul?"-Panel (EMS-Auftrag, 14.09.2026, SUITE.md
        // Formular-Konvention Punkt 0, Referenzimplementierung MeterHubDiscovery)
        // — beim ersten Rollout am Hauptmodul ChargerHub übersehen, jetzt
        // auch hier am Suche-Modul nachgezogen.
        $this->RegisterAttributeBoolean('PurposeIntroGone', false);
        // Symcon-Forum-Hinweis (EMS-Nachprüfung 14.09.2026: fehlte hier
        // komplett, obwohl ChargerHub selbst ihn schon hatte) — einmalig
        // ausblendbar, kein Versionsbezug.
        $this->RegisterAttributeBoolean('ForumHintGone', false);
    }

    /** Siehe ChargerHub::PurposeIntro() für die volle Herleitung — steht ganz vorn im Formular. */
    private function PurposeIntro(): ?array
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'PurposeIntroPanel', 'expanded' => true,
            'caption' => '👋  Wozu dieses Modul?',
            'items' => [
                ['type' => 'Label', 'caption' => 'ChargerHub Suche durchsucht das lokale Netz nach Wallboxen (KEBA, Alfen, Heidelberg, go-eCharger) auf Modbus TCP und legt auf Klick passende ChargerHub-Instanzen mit vorausgefüllter IP-Adresse, Unit-ID und Hersteller an.'],
                ['type' => 'Label', 'caption' => 'Der Nutzen: Wallboxen von Hand einrichten heißt, IP-Adresse und Unit-ID selbst herauszufinden — hier reicht ein Klick, gerade praktisch bei mehreren Ladepunkten auf einmal.'],
                ['type' => 'Label', 'caption' => 'Läuft bereits eine andere Anbindung derselben Wallbox (anderes Modul, gleiche IP/Unit-ID)? Dann erkennt die Suche das über MigrationsHub und bietet eine Migration der bestehenden Historie an, statt doppelt zu zählen.'],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'CHUBD_AckPurposeIntro($id);'],
            ],
        ];
    }

    public function AckPurposeIntro()
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
        $this->PropagateDismiss('PurposeIntro');
    }

    // Hinweis zur Funktionsaufteilung: AdoptDismissState() (0.9.69, schon auf
    // beta veröffentlicht) behält bewusst ihre Arität ohne $what-Parameter —
    // eine Änderung wäre ein BRUCH (migrationsvergleich.php). Für den neuen
    // ForumHint-Fall gibt es stattdessen eine eigene, gleich benannte
    // Geschwisterfunktion statt den bestehenden Vertrag zu ändern.

    /** Symcon-Forum-Hinweis — einmalig dismissible, kein Versionsbezug, siehe ChargerHub::ForumHint(). */
    private function ForumHint(): ?array
    {
        if ($this->ReadAttributeBoolean('ForumHintGone')) {
            return null;
        }
        return [
            'type' => 'ExpansionPanel', 'name' => 'ForumHintPanel', 'expanded' => true,
            'caption' => '💬  Feedback im Symcon-Forum',
            'items' => [
                ['type' => 'Label', 'caption' => 'ChargerHub Suche ist Beta — Rückmeldungen sind willkommen, gerade zu nicht erkannten Wallboxen.'],
                ['type' => 'Button', 'caption' => 'Zum Forums-Thread', 'onClick' => "echo '" . self::FORUM_THREAD_URL . "';", 'link' => true],
                ['type' => 'Button', 'caption' => 'Verstanden – nicht mehr anzeigen', 'onClick' => 'CHUBD_AckForumHint($id);'],
            ],
        ];
    }

    public function AckForumHint()
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
        $this->PropagateDismiss('ForumHint');
    }

    /** Reiner Übernahme-Schritt für den ForumHint bei einer Geschwister-Instanz — siehe PropagateDismiss(). */
    public function AdoptForumHintDismissed()
    {
        $this->WriteAttributeBoolean('ForumHintGone', true);
        $this->UpdateFormField('ForumHintPanel', 'visible', false);
    }

    /**
     * Lizenz-/Unterstützungs-Hinweis — Wortlaut verbundweit identisch (siehe
     * ChargerHub::LicenseHint()), bewusst NICHT wegklickbar.
     */
    private function LicenseHint(): array
    {
        return [
            'type' => 'ExpansionPanel', 'expanded' => false,
            'caption' => '🧡  Über dieses Modul',
            'items' => [
                ['type' => 'Label', 'caption' => 'Entstanden aus echter Begeisterung für die eigene Anlage — und ein paar durchgetippten Abenden. Trotzdem: Software-Hobby hin oder her, das hier ist geistiges Eigentum und echte Arbeit steckt drin.'],
                ['type' => 'Label', 'caption' => 'Lizenz: PolyForm Noncommercial 1.0.0 — privat und nicht-kommerziell frei nutzbar, für den gewerblichen Einsatz braucht es eine gesonderte Lizenz vom Rechteinhaber.'],
                ['type' => 'Button', 'caption' => 'Lizenztext ansehen', 'onClick' => "echo '" . self::LICENSE_URL . "';", 'link' => true],
                ['type' => 'Label', 'caption' => 'Gewerbliche Nutzung oder Fragen zur Lizenz? Einfach melden: dietmar@gureth.eu'],
                ['type' => 'Label', 'caption' => 'Gefällt dir das Modul und du möchtest trotzdem etwas dalassen? Über eine kleine Spende freue ich mich — völlig freiwillig, keine Gegenleistung nötig.'],
                ['type' => 'Button', 'caption' => '☕  Spenden via PayPal', 'onClick' => "echo '" . self::PAYPAL_URL . "';", 'link' => true],
            ],
        ];
    }

    /**
     * Ausblenden über alle ChargerHubDiscovery-Geschwister-Instanzen teilen
     * (Verbund-Muster, siehe ChargerHub::PropagateDismiss()). Ruft bei jeder
     * Geschwister-Instanz nur den reinen Übernahme-Schritt auf — kein
     * Ping-Pong möglich, da AdoptDismissState() selbst nie weiterpropagiert.
     */
    private function PropagateDismiss(string $what): void
    {
        // $function bewusst je $what unterschiedlich benannt statt eines
        // gemeinsamen Funktionsnamens mit $what-Parameter — AdoptDismissState()
        // ist seit 0.9.69 mit fester 0-Parameter-Arität veröffentlicht, eine
        // nachträgliche Änderung wäre ein BRUCH (migrationsvergleich.php).
        $function = $what === 'ForumHint' ? 'CHUBD_AdoptForumHintDismissed' : 'CHUBD_AdoptDismissState';
        foreach (@IPS_GetInstanceListByModuleID(self::DISCOVERY_GUID) ?: [] as $sib) {
            if ($sib === $this->InstanceID) {
                continue;
            }
            try {
                $function($sib);
            } catch (\Throwable $e) {
                // Geschwister-Instanz mitten im Reload/Löschen darf das
                // Ausblenden der aufrufenden Instanz nicht mitreißen.
            }
        }
    }

    /** Reiner Übernahme-Schritt für PurposeIntro bei einer Geschwister-Instanz — siehe PropagateDismiss(). */
    public function AdoptDismissState()
    {
        $this->WriteAttributeBoolean('PurposeIntroGone', true);
        $this->UpdateFormField('PurposeIntroPanel', 'visible', false);
    }

    /** Für Geschwister-Instanzen, die beim erstmaligen Kontakt den Ausblenden-Stand übernehmen wollen — siehe AdoptDismissFromSibling(). */
    public function GetDismissState(): array
    {
        return [
            'purposeIntroGone' => $this->ReadAttributeBoolean('PurposeIntroGone'),
            'forumHintGone'    => $this->ReadAttributeBoolean('ForumHintGone'),
        ];
    }

    /** Gegenrichtung zu PropagateDismiss() — siehe ChargerHub::AdoptDismissFromSibling(). */
    private function AdoptDismissFromSibling(): void
    {
        if ($this->ReadAttributeBoolean('PurposeIntroGone') && $this->ReadAttributeBoolean('ForumHintGone')) {
            return;
        }
        foreach (@IPS_GetInstanceListByModuleID(self::DISCOVERY_GUID) ?: [] as $sib) {
            if ($sib === $this->InstanceID) {
                continue;
            }
            try {
                $state = CHUBD_GetDismissState($sib);
            } catch (\Throwable $e) {
                continue;
            }
            if (!is_array($state)) {
                continue;
            }
            if (!$this->ReadAttributeBoolean('PurposeIntroGone') && !empty($state['purposeIntroGone'])) {
                $this->WriteAttributeBoolean('PurposeIntroGone', true);
            }
            if (!$this->ReadAttributeBoolean('ForumHintGone') && !empty($state['forumHintGone'])) {
                $this->WriteAttributeBoolean('ForumHintGone', true);
            }
            break;
        }
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
        $this->AdoptDismissFromSibling();
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
        $results = json_decode((string)$this->ReadAttributeString('ResultsJSON'), true);
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

            $legacy = $this->LegacyCandidateFor($r['ip'], $r['unitId'], $existing[$key] ?? 0);
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
                        ['type' => 'Label', 'caption' => 'Erkannt werden: KEBA KeContact P30/P40, Alfen Eve Single/Double Pro-line, Heidelberg Energy Control, go-eCharger Gemini/HOME+, DaheimLader (Smart/Touch/PRO-Serie), ABL eMH1/eMH2/eMH3, Fox ESS EV Charger (A/L/C), Peblar Home/Business (experimentell). Die Erkennungskriterien sind aus den Hersteller-Dokumentationen abgeleitet — wird eine Wallbox nicht gefunden, bitte die ChargerHub-Instanz manuell anlegen.'],
                        ['type' => 'Label', 'caption' => '🆕 ABL spricht Modbus ASCII statt binärem Modbus TCP — die Suche prüft das automatisch mit, ein zusätzlicher Schritt ist nicht nötig.'],
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
                        ['type' => 'Label', 'name' => 'DiscoverySummary', 'caption' => $this->getDiscoverySummaryLine()],
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
            'actions' => [
                // Praktisch nach einem Modul-Update, wenn der reguläre Weg
                // (Modulverwaltung → Aktualisieren → Übernehmen) einmal nicht
                // gegriffen hat (EMS-Vorschlag, Muster wie dort).
                ['type' => 'Button', 'caption' => '🔄 Übernehmen erzwingen (ohne Formularänderung)', 'onClick' => "IPS_ApplyChanges(\$id); echo '✅ ApplyChanges() ausgeführt.';"],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active',   'caption' => 'Bereit.'],
                ['code' => 104, 'icon' => 'inactive', 'caption' => 'Bitte Such-IP-Bereich eintragen.'],
            ],
        ];

        // Symcon-Forum-Hinweis nach den Haupteinstellungen, "Über dieses
        // Modul" ganz unten (immer sichtbar, siehe LicenseHint()).
        $forumHint = $this->ForumHint();
        if ($forumHint !== null) {
            $form['elements'][] = $forumHint;
        }
        $form['elements'][] = $this->LicenseHint();

        // "Wozu dieses Modul?" ganz vorn (Verbund-Formular-Konvention Punkt 0).
        $intro = $this->PurposeIntro();
        if ($intro !== null) {
            array_unshift($form['elements'], $intro);
        }

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
        $this->WriteAttributeString('PreparedTargets', '[]');
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
        $this->WriteAttributeInteger('LastDiscoveryTs', time());
        $this->SetStatus(102);
        // SUITE.md-Stolperfalle 12: GetConfigurationForm() läuft nach einem
        // RequestAction-Button nicht automatisch neu — ReloadForm() deckt das
        // zwar meist ab, zusätzlich defensiv explizit die Kopfzeile setzen.
        @$this->UpdateFormField('DiscoverySummary', 'caption', $this->getDiscoverySummaryLine());
        $this->ReloadForm();
    }

    // Verbund-Konvention "Einheitliche Verbund-Status-Kopfzeile" (20.08.2026,
    // Referenz EMS::getDiscoverySummaryLine()): eine Zeile direkt unter dem
    // Suchen-Button statt verstreuter Fließtext-Sätze.
    private function getDiscoverySummaryLine(): string
    {
        $ts = $this->ReadAttributeInteger('LastDiscoveryTs');
        if ($ts === 0) {
            return 'ℹ️ Noch nicht gesucht — Button oben drücken.';
        }
        $results = json_decode((string)$this->ReadAttributeString('ResultsJSON'), true);
        $count = is_array($results) ? count($results) : 0;
        $icon = $count > 0 ? '✅' : '⚠️';
        return sprintf('%s %d Wallbox(en) gefunden (zuletzt %s Uhr).', $icon, $count, date('H:i:s', $ts));
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
    private function LegacyCandidateFor(string $host, int $unitId, int $excludeInstanceID = 0): array
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
        // 5. Parameter (excludeInstanceID) seit MigrationsHub-Commit f5505c0 —
        // filtert die eigene, gerade angelegte Zielinstanz serverseitig aus,
        // bevor das Host/Port/UnitId-Matching überhaupt läuft.
        $found = @MIGHUB_FindLegacyCandidates($migID, $host, $this->ReadPropertyInteger('Port'), $unitId, $excludeInstanceID);
        if (!is_array($found)) {
            return ['id' => 0, 'name' => '', 'ambiguous' => false];
        }
        // Defensiv: eigene, frisch angelegte ChargerHub-Instanzen (z. B. bei
        // einer erneuten Suche derselben IP) NIE als "Alt-Instanz" akzeptieren
        // — "migriere von dir selbst" ist sinnlos und im Extremfall
        // (Quelle=Ziel) schädlich. Zusätzlich zu einer entsprechenden Filterung
        // bei MigrationsHub selbst, nicht als Ersatz dafür.
        $found = array_values(array_filter($found, function ($f) {
            $id = (int)($f['InstanceID'] ?? $f['instanceID'] ?? $f['id'] ?? 0);
            return $id > 0 && @IPS_GetInstance($id)['ModuleInfo']['ModuleID'] !== self::CHARGERHUB_GUID;
        }));
        if (count($found) === 0) {
            return ['id' => 0, 'name' => '', 'ambiguous' => false];
        }
        if (count($found) > 1) {
            // 'Path' (Kategorie-Pfad von der Wurzel) unterscheidet Treffer
            // mit identischem Namen an derselben IP — z. B. eine bewusste
            // Sicherungs-Kopie in einer anderen Kategorie (mit MigrationsHub
            // abgestimmt, live an genau diesem Fall verifiziert).
            $names = array_map(function ($f) {
                $id = (int)($f['InstanceID'] ?? $f['instanceID'] ?? $f['id'] ?? 0);
                $label = (string)($f['Name'] ?? $f['name'] ?? IPS_GetName($id)) . ' (#' . $id . ')';
                if (!empty($f['Path'])) {
                    $label .= ' [' . $f['Path'] . ']';
                }
                return $label;
            }, $found);
            return ['id' => 0, 'name' => implode(', ', $names), 'ambiguous' => true];
        }
        $first = $found[0];
        $id = (int)($first['InstanceID'] ?? $first['instanceID'] ?? $first['id'] ?? 0);
        if ($id <= 0) {
            return ['id' => 0, 'name' => '', 'ambiguous' => false];
        }
        return ['id' => $id, 'name' => (string)($first['Name'] ?? $first['name'] ?? IPS_GetName($id)), 'ambiguous' => false];
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

        $results = json_decode((string)$this->ReadAttributeString('ResultsJSON'), true);
        $results = is_array($results) ? $results : [];
        $existing = $this->findExistingInstances();
        // Ohne Merker verarbeitete jeder Klick immer wieder die ERSTE passende
        // Zeile erneut (early return nach Erfolg) — bei mehreren Treffern kam
        // man so nie über die erste Zeile hinaus. Bereits vorbereitete
        // Ziel-Instanzen daher überspringen, damit der nächste Klick
        // automatisch zur nächsten offenen Zeile weitergeht.
        $prepared = json_decode((string)$this->ReadAttributeString('PreparedTargets'), true);
        $prepared = is_array($prepared) ? $prepared : [];

        foreach ($results as $r) {
            $targetID = $existing[$r['ip'] . '|' . $r['unitId']] ?? 0;
            if ($targetID <= 0) {
                continue; // Für diese Zeile wurde noch keine ChargerHub-Instanz erstellt.
            }
            if (in_array($targetID, $prepared, true)) {
                continue; // Für diese Zeile wurde die Migration bereits vorbereitet.
            }
            $legacy = $this->LegacyCandidateFor($r['ip'], $r['unitId'], $targetID);
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
            $prepared[] = $targetID;
            $this->WriteAttributeString('PreparedTargets', json_encode($prepared));

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

            case 'daheimlader':
                // Holding 0: Ladezustand, plausibel 1..10 (Standby..Einschalten).
                $state = $this->readHolding($ip, $port, $unitId, 0, 1, 1.0);
                if ($state === null || $state[0] < 1 || $state[0] > 10) {
                    return false;
                }
                // Holding 32: Max. Strom EVSE, 0,1 A, plausibel 6..100 A.
                $maxA = $this->readHolding($ip, $port, $unitId, 32, 1, 1.0);
                return ($maxA !== null && $maxA[0] >= 60 && $maxA[0] <= 1000);

            case 'abl':
                // ABL spricht Modbus ASCII, nicht binäres Modbus TCP — eigene
                // kompakte Hilfsfunktion, siehe asciiReadHolding() unten.
                // Beide Register echoen laut PDF ihre eigene Nummer im
                // High-Byte des ersten Datenbytes zurück — ein exakter
                // Bit-Treffer statt nur eines Wertebereichs, also ein
                // stärkeres Kriterium als bei den binären Herstellern.
                $r1 = $this->asciiReadHolding($ip, $port, $unitId, 0x0001, 2, 1.5);
                if ($r1 === null || (($r1[0] >> 8) & 0xFF) !== 0x01) {
                    return false;
                }
                $r2 = $this->asciiReadHolding($ip, $port, $unitId, 0x0033, 3, 1.5);
                return ($r2 !== null && (($r2[0] >> 8) & 0xFF) === 0x33);

            case 'foxess':
                // Holding 0x1003: EVC Status, plausibel 0..8.
                $state = $this->readHolding($ip, $port, $unitId, 0x1003, 1, 1.0);
                if ($state === null || $state[0] > 8) {
                    return false;
                }
                // Holding 0x1013: Max Supported Current, 0,1 A, plausibel 6..100 A.
                $maxA = $this->readHolding($ip, $port, $unitId, 0x1013, 1, 1.0);
                return ($maxA !== null && $maxA[0] >= 60 && $maxA[0] <= 1000);

            case 'peblar':
                // Input-Register (FC 0x04) 30092: Phasenzahl 1..3, 30093:
                // unabhängiges Relais 0/1 — dazu 30110: CP-Zustand als
                // ASCII-Zeichencode 'A'..'F' (65..70). Registerkarte aus
                // Peblars offiziellem Beispielclient, gegen evcc gegengelesen.
                $pc = $this->readInput($ip, $port, $unitId, 30092, 2, 1.0);
                if ($pc === null || $pc[0] < 1 || $pc[0] > 3 || $pc[1] > 1) {
                    return false;
                }
                $cp = $this->readInput($ip, $port, $unitId, 30110, 1, 1.0);
                return ($cp !== null && $cp[0] >= 65 && $cp[0] <= 70);
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

    // Modbus ASCII über einen rohen TCP-Socket, nur für die ABL-Suche —
    // eigenständig gehalten (siehe Dateikopf: kein Zugriff auf die Klassen
    // aus dem ChargerHub-Modulordner), daher eine eigene, kompakte Kopie der
    // Framing-Logik statt eines Verweises auf CHUB_ModbusAsciiClient. Gibt
    // wie readHolding()/readInput() ein Array von 16-Bit-Registern zurück.
    private function asciiLrc(string $bytes): int
    {
        $sum = 0;
        for ($i = 0; $i < strlen($bytes); $i++) {
            $sum += ord($bytes[$i]);
        }
        return (0x100 - ($sum & 0xFF)) & 0xFF;
    }

    private function asciiReadHolding($host, $port, $unitId, $startReg, $count, $timeout)
    {
        $sock = $this->probeSock ?: @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($sock === false) {
            return null;
        }
        if ($this->probeSock === null) {
            stream_set_timeout($sock, $timeout);
        }

        $pdu   = chr($unitId) . chr(0x03) . pack('n', $startReg) . pack('n', $count);
        $frame = ':' . strtoupper(bin2hex($pdu)) . strtoupper(sprintf('%02X', $this->asciiLrc($pdu))) . "\r\n";
        @fwrite($sock, $frame);

        $buf      = '';
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $chunk = @fread($sock, 512);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buf .= $chunk;
            if (strpos($buf, "\r\n") !== false) {
                break;
            }
        }
        if ($this->probeSock === null) {
            fclose($sock);
            usleep(120000);
        }

        $pos = strpos($buf, "\r\n");
        if ($pos === false) {
            return null;
        }
        $line = ltrim(substr($buf, 0, $pos), ':>');
        if (strlen($line) < 6 || strlen($line) % 2 !== 0) {
            return null;
        }
        $bytes = @hex2bin($line);
        if ($bytes === false || strlen($bytes) < 3) {
            return null;
        }
        $lrcByte = ord(substr($bytes, -1));
        $payload = substr($bytes, 0, -1);
        if ($this->asciiLrc($payload) !== $lrcByte) {
            return null; // Prüfsumme falsch -> nicht raten
        }
        if (ord($payload[1]) & 0x80 || ord($payload[1]) !== 0x03) {
            return null;
        }
        $byteCount = ord($payload[2]);
        $data      = substr($payload, 3, $byteCount);
        $regs      = [];
        for ($i = 0; $i < $count && ($i * 2 + 1) < strlen($data); $i++) {
            $regs[$i] = (ord($data[$i * 2]) << 8) | ord($data[$i * 2 + 1]);
        }
        return $regs;
    }
}
