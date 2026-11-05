<?php
/** Renault EV Center for IP-Symcon – read-only prototype v0.1.1 */
declare(strict_types=1);
require_once __DIR__ . '/../libs/RenaultApiClient.php';
require_once __DIR__ . '/../libs/Analytics.php';

class RenaultEVCenter extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $strings = [
            'AccountEmail' => '', 'AccountPassword' => '', 'Country' => 'AT',
            'VehicleVIN' => '', 'GigyaAPIKey' => RenaultEVApiClient::DEFAULT_GIGYA_KEY,
            'KamereonAPIKey' => RenaultEVApiClient::DEFAULT_KAMEREON_KEY,
            'TfaEmailID' => '', 'GoEPowerUnit' => 'W', 'SolarPowerUnit' => 'W',
            'GoEEnergyUnit' => 'kWh',
        ];
        foreach ($strings as $k => $v) $this->RegisterPropertyString($k, $v);
        $ints = [
            'PollMinutes' => 10, 'TripIdleMinutes' => 15,
            'SourceSOC' => 0, 'SourceRange' => 0, 'SourceMileage' => 0, 'SourceCharging' => 0,
            'GoEChargingVar' => 0, 'GoEPowerVar' => 0, 'GoESessionKwhVar' => 0,
            'GoETotalKwhVar' => 0, 'SolarPVVar' => 0, 'SolarGridVar' => 0,
            'SolarBatterySOCVar' => 0, 'SolarBatteryPowerVar' => 0,
        ];
        foreach ($ints as $k => $v) $this->RegisterPropertyInteger($k, $v);
        $this->RegisterPropertyBoolean('UseRenaultCloud', true);
        $this->RegisterPropertyBoolean('GoEStateIsCarState', false);
        $this->RegisterPropertyBoolean('GridPositiveImport', true);
        $this->RegisterPropertyFloat('BatteryCapacityKwh', 60.0);
        $this->RegisterPropertyFloat('PricePerKwh', 0.25);
        foreach ([
            'LoginToken', 'DeviceCookies', 'JWT', 'JWTExpiry', 'PersonID', 'AccountID',
            'ResolvedVIN', 'TfaRegToken', 'TfaAssertion', 'TfaPhvToken', 'TfaEmailChoices',
        ] as $k) $this->RegisterAttributeString($k, '');
        $this->RegisterAttributeString('StateJSON', '{}');
        $this->RegisterAttributeString('LastSnapshot', '{}');
        $this->RegisterTimer('Update', 0, 'REVC_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $variables = [
            ['BatteryLevel', 'Batteriestand', 'float', '~Battery.100'],
            ['BatteryAutonomy', 'Reichweite (km)', 'float', ''],
            ['BatteryEnergy', 'Geschätzte Batterieenergie (kWh)', 'float', ''],
            ['Odometer', 'Kilometerstand (km)', 'float', ''],
            ['Charging', 'Fahrzeug lädt (nur Status)', 'bool', ''],
            ['PluggedIn', 'Ladekabel eingesteckt', 'bool', ''],
            ['VehicleVIN', 'Fahrgestellnummer', 'string', ''],
            ['GoEPower', 'go-e Ladeleistung (kW)', 'float', ''],
            ['PVPower', 'PV-Erzeugung (kW)', 'float', ''],
            ['GridPower', 'Netzbezug (kW, positiv)', 'float', ''],
            ['HomeBatterySOC', 'Hausbatterie SOC (%)', 'float', ''],
            ['HomeBatteryPower', 'Hausbatterie Leistung (kW)', 'float', ''],
            ['TripActive', 'Fahrt aktiv', 'bool', ''],
            ['CurrentTripKM', 'Aktuelle Fahrt (km)', 'float', ''],
            ['LastTripKM', 'Letzte Fahrt (km)', 'float', ''],
            ['KmToday', 'Fahrstrecke heute (km)', 'float', ''],
            ['KmMonth', 'Fahrstrecke Monat (km)', 'float', ''],
            ['KmRecorded', 'Erfasste Fahrstrecke gesamt (km)', 'float', ''],
            ['TripCount', 'Erfasste Fahrten', 'int', ''],
            ['ConsumptionEstimate', 'Geschätzter Verbrauch (kWh/100 km)', 'float', ''],
            ['ChargeSessionActive', 'Ladevorgang erkannt (nur Status)', 'bool', ''],
            ['CurrentChargeKwh', 'Aktuelle Ladeenergie (kWh)', 'float', ''],
            ['LastChargeKwh', 'Letzte Ladeenergie (kWh)', 'float', ''],
            ['ChargedTotalKwh', 'Erfasste Ladeenergie gesamt (kWh)', 'float', ''],
            ['ChargedMonthKwh', 'Erfasste Ladeenergie Monat (kWh)', 'float', ''],
            ['ChargeCostTotal', 'Erfasste Ladekosten gesamt (EUR)', 'float', ''],
            ['ChargeCount', 'Erfasste Ladevorgänge', 'int', ''],
            ['TripHistory', 'Fahrten – JSON', 'string', ''],
            ['ChargeHistory', 'Ladungen – JSON', 'string', ''],
            ['LastUpdate', 'Letzte Abfrage', 'int', '~UnixTimestamp'],
            ['CloudConnected', 'Renault-Cloud verbunden', 'bool', ''],
            ['Message', 'Status / Hinweise', 'string', ''],
            ['Dashboard', 'Renault EV Center', 'string', '~HTMLBox'],
        ];
        $pos = 1;
        foreach ($variables as [$ident, $label, $type, $profile]) {
            switch ($type) {
                case 'float': $this->RegisterVariableFloat($ident, $label, $profile, $pos++); break;
                case 'int': $this->RegisterVariableInteger($ident, $label, $profile, $pos++); break;
                case 'bool': $this->RegisterVariableBoolean($ident, $label, $profile, $pos++); break;
                default: $this->RegisterVariableString($ident, $label, $profile, $pos++); break;
            }
        }
        $this->SetTimerInterval('Update', max($this->ReadPropertyBoolean('UseRenaultCloud') ? 10 : 1, $this->ReadPropertyInteger('PollMinutes')) * 60000);
        $this->SetStatus(102);
        $this->renderDashboard();
    }

    public function GetConfigurationForm(): string
    {
        $elements = [
            ['type' => 'Label', 'caption' => 'Renault EV Center – nur LESEN. Keine Ladebefehle, keine Wallbox-Steuerung.'],
            ['type' => 'ExpansionPanel', 'caption' => 'My Renault / Cloud', 'items' => [
                ['type' => 'CheckBox', 'name' => 'UseRenaultCloud', 'caption' => 'Renault Cloud verwenden'],
                ['type' => 'ValidationTextBox', 'name' => 'AccountEmail', 'caption' => 'My Renault E-Mail'],
                ['type' => 'PasswordTextBox', 'name' => 'AccountPassword', 'caption' => 'My Renault Passwort'],
                ['type' => 'Select', 'name' => 'Country', 'caption' => 'Land', 'options' => [
                    ['caption' => 'Österreich', 'value' => 'AT'],
                    ['caption' => 'Deutschland', 'value' => 'DE'],
                    ['caption' => 'Schweiz', 'value' => 'CH'],
                    ['caption' => 'Frankreich', 'value' => 'FR'],
                    ['caption' => 'Niederlande', 'value' => 'NL'],
                ]],
                ['type' => 'ValidationTextBox', 'name' => 'VehicleVIN', 'caption' => 'VIN (optional, sonst automatisch)'],
                ['type' => 'ValidationTextBox', 'name' => 'GigyaAPIKey', 'caption' => 'Gigya API-Key (Experten)'],
                ['type' => 'ValidationTextBox', 'name' => 'KamereonAPIKey', 'caption' => 'Kamereon API-Key (Experten)'],
                ['type' => 'ValidationTextBox', 'name' => 'TfaEmailID', 'caption' => '2FA E-Mail-ID (nur bei mehreren Adressen)'],
            ]],
            ['type' => 'ExpansionPanel', 'caption' => 'Vorhandene Fahrzeugvariablen (Fallback / Offline)', 'items' => [
                ['type' => 'SelectVariable', 'name' => 'SourceSOC', 'caption' => 'Batteriestand (%)', 'validVariableTypes' => [1, 2]],
                ['type' => 'SelectVariable', 'name' => 'SourceRange', 'caption' => 'Reichweite (km)', 'validVariableTypes' => [1, 2]],
                ['type' => 'SelectVariable', 'name' => 'SourceMileage', 'caption' => 'Kilometerstand (km)', 'validVariableTypes' => [1, 2]],
                ['type' => 'SelectVariable', 'name' => 'SourceCharging', 'caption' => 'Fahrzeug lädt (bool)', 'validVariableTypes' => [0, 1, 2, 3]],
            ]],
            ['type' => 'ExpansionPanel', 'caption' => 'go-e (ausschließlich Auslesen!)', 'items' => [
                ['type' => 'SelectVariable', 'name' => 'GoEChargingVar', 'caption' => 'go-e Ladestatus', 'validVariableTypes' => [0, 1, 2, 3]],
                ['type' => 'CheckBox', 'name' => 'GoEStateIsCarState', 'caption' => 'Ladestatus ist go-e car state (2 = lädt)'],
                ['type' => 'SelectVariable', 'name' => 'GoEPowerVar', 'caption' => 'Ladeleistung', 'validVariableTypes' => [1, 2]],
                ['type' => 'Select', 'name' => 'GoEPowerUnit', 'caption' => 'Leistungseinheit', 'options' => [
                    ['caption' => 'W', 'value' => 'W'], ['caption' => 'kW', 'value' => 'kW'],
                ]],
                ['type' => 'SelectVariable', 'name' => 'GoESessionKwhVar', 'caption' => 'Ladeenergie laufende Session', 'validVariableTypes' => [1, 2]],
                ['type' => 'SelectVariable', 'name' => 'GoETotalKwhVar', 'caption' => 'Ladeenergie Gesamtzähler', 'validVariableTypes' => [1, 2]],
                ['type' => 'Select', 'name' => 'GoEEnergyUnit', 'caption' => 'Einheit Energiezähler', 'options' => [
                    ['caption' => 'kWh', 'value' => 'kWh'], ['caption' => 'Wh', 'value' => 'Wh'],
                ]],
            ]],
            ['type' => 'ExpansionPanel', 'caption' => 'SolarEdge (ausschließlich Auslesen!)', 'items' => [
                ['type' => 'SelectVariable', 'name' => 'SolarPVVar', 'caption' => 'PV-Leistung', 'validVariableTypes' => [1, 2]],
                ['type' => 'SelectVariable', 'name' => 'SolarGridVar', 'caption' => 'Netzleistung', 'validVariableTypes' => [1, 2]],
                ['type' => 'CheckBox', 'name' => 'GridPositiveImport', 'caption' => 'Netzleistung: positives Vorzeichen = Netzbezug'],
                ['type' => 'SelectVariable', 'name' => 'SolarBatterySOCVar', 'caption' => 'Speicher SOC (%)', 'validVariableTypes' => [1, 2]],
                ['type' => 'SelectVariable', 'name' => 'SolarBatteryPowerVar', 'caption' => 'Speicherleistung', 'validVariableTypes' => [1, 2]],
                ['type' => 'Select', 'name' => 'SolarPowerUnit', 'caption' => 'Leistungseinheit', 'options' => [
                    ['caption' => 'W', 'value' => 'W'], ['caption' => 'kW', 'value' => 'kW'],
                ]],
            ]],
            ['type' => 'ExpansionPanel', 'caption' => 'Statistik / Aktualisierung', 'items' => [
                ['type' => 'NumberSpinner', 'name' => 'PollMinutes', 'caption' => 'Intervall Minuten (mind. 10 empfohlen)', 'minimum' => 10],
                ['type' => 'NumberSpinner', 'name' => 'TripIdleMinutes', 'caption' => 'Fahrtende nach Stillstand (Minuten)', 'minimum' => 1],
                ['type' => 'NumberSpinner', 'name' => 'BatteryCapacityKwh', 'caption' => 'Batteriekapazität in kWh (für Schätzung)', 'minimum' => 1, 'digits' => 1],
                ['type' => 'NumberSpinner', 'name' => 'PricePerKwh', 'caption' => 'Strompreis EUR/kWh', 'minimum' => 0, 'digits' => 3],
            ]],
        ];
        $choices = json_decode($this->ReadAttributeString('TfaEmailChoices'), true) ?: [];
        $labels = [];
        foreach ($choices as $choice) $labels[] = (string) ($choice['masked'] ?? '?') . ' (ID ' . (string) ($choice['id'] ?? '?') . ')';
        if ($labels) $elements[] = ['type' => 'Label', 'caption' => '2FA-Adressen: ' . implode(' / ', $labels)];
        $actions = [
            ['type' => 'Label', 'caption' => 'Anmelden und 2FA nur über My Renault – Cloud-Zugriff bleibt ungetestet, bis ein echtes Konto verwendet wird.'],
            ['type' => 'Button', 'caption' => '1. Bei Renault anmelden', 'onClick' => 'REVC_Login($id);'],
            ['type' => 'Button', 'caption' => '2. 2FA-Code per E-Mail anfordern (falls erforderlich)', 'onClick' => 'REVC_SendTwoFactorCode($id);'],
            ['type' => 'ValidationTextBox', 'name' => 'OTPCode', 'caption' => 'Einmalcode (nicht speichern)'],
            ['type' => 'Button', 'caption' => '3. Einmalcode bestätigen', 'onClick' => 'REVC_CompleteTwoFactor($id, $OTPCode);'],
            ['type' => 'Button', 'caption' => 'Werte jetzt aktualisieren', 'onClick' => 'REVC_Update($id);'],
            ['type' => 'Button', 'caption' => 'Aktuelle Fahrt beenden', 'onClick' => 'REVC_FinishTrip($id);'],
            ['type' => 'Label', 'caption' => 'Hinweis: Keine Funktion zum Starten/Stoppen des Ladevorgangs enthalten.'],
        ];
        return json_encode(['elements' => $elements, 'actions' => $actions,
            'status' => [['code' => 102, 'icon' => 'active', 'caption' => 'Modul läuft']]], JSON_THROW_ON_ERROR);
    }

    private function api(): RenaultEVApiClient
    {
        return new RenaultEVApiClient(
            fn(string $name): string => $this->ReadAttributeString($name),
            function (string $name, string $value): void { $this->WriteAttributeString($name, $value); },
            fn(string $name): string => $this->ReadPropertyString($name)
        );
    }

    private function message(string $text): void
    {
        $this->SetValue('Message', $text);
        $this->SendDebug('Status', $text, 0);
    }

    public function Login(): bool
    {
        try {
            $outcome = $this->api()->login();
            if ($outcome === '2FA_REQUIRED') {
                $this->message('Renault benötigt E-Mail-Zwei-Faktor-Bestätigung. Bitte Code anfordern.');
                return false;
            }
            $this->SetValue('CloudConnected', true);
            $this->message('Renault Login erfolgreich; Konto und Fahrzeug zugeordnet.');
            return true;
        } catch (Throwable $e) {
            $this->SetValue('CloudConnected', false);
            $this->message('Renault Login: ' . $e->getMessage());
            return false;
        }
    }

    public function SendTwoFactorCode(): bool
    {
        try {
            $recipient = $this->api()->sendTwoFactorCode();
            $this->message('2FA-Code wurde von Gigya für ' . $recipient . ' angefordert.');
            $this->ReloadForm();
            return true;
        } catch (Throwable $e) {
            $this->message('2FA: ' . $e->getMessage());
            $this->ReloadForm();
            return false;
        }
    }

    public function CompleteTwoFactor(string $code): bool
    {
        try {
            $this->api()->completeTwoFactor($code);
            $this->SetValue('CloudConnected', true);
            $this->message('Renault-Anmeldung mit 2FA abgeschlossen.');
            return true;
        } catch (Throwable $e) {
            $this->message('2FA: ' . $e->getMessage());
            return false;
        }
    }

    /** Only read APIs. No start-charge, stop-charge, climate or wallbox commands. */
    public function Update(): bool
    {
        $lock = 'REVC_Update_' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 1500)) return false;
        try {
            $now = time();
            $state = json_decode($this->ReadAttributeString('StateJSON'), true);
            $state = RenaultEVAnalytics::normalize(is_array($state) ? $state : []);
            $snapshot = json_decode($this->ReadAttributeString('LastSnapshot'), true);
            if (!is_array($snapshot)) $snapshot = [];
            $cloudError = '';
            $cloudData = [];
            if ($this->ReadPropertyBoolean('UseRenaultCloud') && $this->ReadAttributeString('LoginToken') !== '') {
                try {
                    $cloudData = $this->api()->snapshot();
                    $this->SetValue('CloudConnected', true);
                    $snapshot = array_replace($snapshot, $cloudData);
                } catch (Throwable $e) {
                    $cloudError = $e->getMessage();
                    $this->SetValue('CloudConnected', false);
                }
            } else {
                $this->SetValue('CloudConnected', false);
            }
            $soc = $this->inputFloat('SourceSOC');
            if ($soc === null && isset($cloudData['soc']) && is_numeric($cloudData['soc'])) $soc = (float) $cloudData['soc'];
            if ($soc === null && isset($snapshot['soc']) && is_numeric($snapshot['soc'])) $soc = (float) $snapshot['soc'];
            if ($soc !== null && ($soc < 0 || $soc > 100)) $soc = null;
            $range = $this->inputFloat('SourceRange');
            if ($range === null && isset($cloudData['range']) && is_numeric($cloudData['range'])) $range = (float) $cloudData['range'];
            if ($range === null && isset($snapshot['range']) && is_numeric($snapshot['range'])) $range = (float) $snapshot['range'];
            $odo = $this->inputFloat('SourceMileage');
            if ($odo === null && isset($cloudData['mileage']) && is_numeric($cloudData['mileage'])) $odo = (float) $cloudData['mileage'];
            if ($odo === null && isset($snapshot['mileage']) && is_numeric($snapshot['mileage'])) $odo = (float) $snapshot['mileage'];
            $chargeSource = $this->inputBool('SourceCharging');
            $chargeCar = $chargeSource ?? (isset($cloudData['chargeStatus']) ? ((float) $cloudData['chargeStatus'] >= 0.99) : null);
            $plug = isset($cloudData['plug']) && is_numeric($cloudData['plug']) ? ((float) $cloudData['plug'] >= 0.99) : null;
            $power = $this->readPower('GoEPowerVar', $this->ReadPropertyString('GoEPowerUnit'));
            $goeCharging = $this->inputBool('GoEChargingVar', $this->ReadPropertyBoolean('GoEStateIsCarState'));
            $chargeDetected = $goeCharging ?? ($power !== null ? $power > 0.15 : ($chargeCar ?? false));
            $counterDivisor = $this->ReadPropertyString('GoEEnergyUnit') === 'Wh' ? 1000.0 : 1.0;
            $totalMeter = $this->inputFloat('GoETotalKwhVar');
            $sessionMeter = $this->inputFloat('GoESessionKwhVar');
            if ($totalMeter !== null) $totalMeter /= $counterDivisor;
            if ($sessionMeter !== null) $sessionMeter /= $counterDivisor;
            $batteryCap = max(1.0, $this->ReadPropertyFloat('BatteryCapacityKwh'));
            $price = max(0.0, $this->ReadPropertyFloat('PricePerKwh'));
            $state = RenaultEVAnalytics::updateTrip($state, $odo, $soc, $now,
                max(1, $this->ReadPropertyInteger('TripIdleMinutes')), $batteryCap);
            $state = RenaultEVAnalytics::updateCharge($state, $chargeDetected,
                max(0, $power ?? 0.0), $totalMeter, $sessionMeter, $soc, $now, $price);
            $summary = RenaultEVAnalytics::summary($state, $now);
            $this->WriteAttributeString('StateJSON', json_encode($state, JSON_THROW_ON_ERROR));
            if ($cloudData) $this->WriteAttributeString('LastSnapshot', json_encode($snapshot, JSON_THROW_ON_ERROR));
            if ($soc !== null) {
                $this->SetValue('BatteryLevel', $soc);
                $this->SetValue('BatteryEnergy', round($soc * $batteryCap / 100.0, 2));
            }
            if ($range !== null) $this->SetValue('BatteryAutonomy', max(0, $range));
            if ($odo !== null) $this->SetValue('Odometer', max(0, $odo));
            if ($chargeCar !== null) $this->SetValue('Charging', $chargeCar);
            if ($plug !== null) $this->SetValue('PluggedIn', $plug);
            $vin = $this->ReadPropertyString('VehicleVIN') ?: $this->ReadAttributeString('ResolvedVIN');
            if ($vin !== '') $this->SetValue('VehicleVIN', $vin);
            if ($power !== null) $this->SetValue('GoEPower', $power);
            $pvPower = $this->readPower('SolarPVVar', $this->ReadPropertyString('SolarPowerUnit'));
            $gridPower = $this->readPower('SolarGridVar', $this->ReadPropertyString('SolarPowerUnit'));
            $batteryPower = $this->readPower('SolarBatteryPowerVar', $this->ReadPropertyString('SolarPowerUnit'));
            $houseSoc = $this->inputFloat('SolarBatterySOCVar');
            if ($pvPower !== null) $this->SetValue('PVPower', $pvPower);
            if ($gridPower !== null) $this->SetValue('GridPower', $gridPower * ($this->ReadPropertyBoolean('GridPositiveImport') ? 1 : -1));
            if ($batteryPower !== null) $this->SetValue('HomeBatteryPower', $batteryPower);
            if ($houseSoc !== null) $this->SetValue('HomeBatterySOC', $houseSoc);
            $this->SetValue('TripActive', is_array($state['trip']));
            $this->SetValue('ChargeSessionActive', $chargeDetected);
            $this->SetValue('CurrentTripKM', $summary['currentTripKM']);
            $this->SetValue('LastTripKM', $summary['lastTripKM']);
            $this->SetValue('KmToday', $summary['kmToday']);
            $this->SetValue('KmMonth', $summary['kmMonth']);
            $this->SetValue('KmRecorded', $summary['kmRecorded']);
            $this->SetValue('TripCount', $summary['tripCount']);
            $this->SetValue('ConsumptionEstimate', $summary['tripKwh100Estimated']);
            $this->SetValue('CurrentChargeKwh', $summary['currentChargeKwh']);
            $this->SetValue('LastChargeKwh', $summary['lastChargeKwh']);
            $this->SetValue('ChargedTotalKwh', $summary['chargedTotalKwh']);
            $this->SetValue('ChargedMonthKwh', $summary['chargedMonthKwh']);
            $this->SetValue('ChargeCostTotal', $summary['chargeCostTotalEUR']);
            $this->SetValue('ChargeCount', $summary['chargeCount']);
            $this->SetValue('TripHistory', json_encode($state['trips'], JSON_THROW_ON_ERROR));
            $this->SetValue('ChargeHistory', json_encode($state['charges'], JSON_THROW_ON_ERROR));
            $this->SetValue('LastUpdate', $now);
            $this->message($cloudError !== '' ? 'Lokale Daten OK / Renault: ' . $cloudError
                : ($cloudData ? 'Renault und lokale Sensoren aktualisiert.' : 'Lokale Sensoren aktualisiert; Renault Cloud nicht angemeldet/ausgeschaltet.'));
            $this->renderDashboard();
            return true;
        } catch (Throwable $e) {
            $this->message('Fehler beim Aktualisieren: ' . $e->getMessage());
            return false;
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    public function FinishTrip(): bool
    {
        try {
            $s = json_decode($this->ReadAttributeString('StateJSON'), true);
            $s = RenaultEVAnalytics::finishTrip(is_array($s) ? $s : [], time(),
                max(1, $this->ReadPropertyFloat('BatteryCapacityKwh')));
            $this->WriteAttributeString('StateJSON', json_encode($s, JSON_THROW_ON_ERROR));
            return $this->Update();
        } catch (Throwable $e) {
            $this->message('Fahrt konnte nicht beendet werden: ' . $e->getMessage());
            return false;
        }
    }

    private function inputFloat(string $property): ?float
    {
        $id = $this->ReadPropertyInteger($property);
        if ($id <= 0 || !IPS_VariableExists($id)) return null;
        $raw = GetValue($id);
        return is_numeric($raw) ? (float) $raw : null;
    }

    private function inputBool(string $property, bool $goeCarState = false): ?bool
    {
        $id = $this->ReadPropertyInteger($property);
        if ($id <= 0 || !IPS_VariableExists($id)) return null;
        $raw = GetValue($id);
        if ($goeCarState) return is_numeric($raw) ? ((int) $raw === 2) : null;
        if (is_bool($raw)) return $raw;
        if (is_numeric($raw)) return (float) $raw > 0.5;
        if (is_string($raw)) return in_array(strtolower(trim($raw)), ['true', 'on', 'charging', '1'], true);
        return null;
    }

    private function readPower(string $property, string $unit): ?float
    {
        $value = $this->inputFloat($property);
        return $value === null ? null : round($value / ($unit === 'kW' ? 1.0 : 1000.0), 4);
    }

    private function renderDashboard(): void
    {
        $v = function (string $ident): string {
            try {
                $id = $this->GetIDForIdent($ident);
                $value = GetValue($id);
                return is_numeric($value) ? number_format((float) $value, 1, ',', '.')
                    : htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            } catch (Throwable $e) { return '–'; }
        };
        $cards = [
            ['Batterie', $v('BatteryLevel') . ' %'],
            ['Reichweite', $v('BatteryAutonomy') . ' km'],
            ['Kilometerstand', $v('Odometer') . ' km'],
            ['Fahrten', $v('TripCount')],
            ['Heute gefahren', $v('KmToday') . ' km'],
            ['Erfasste Ladung', $v('ChargedTotalKwh') . ' kWh'],
            ['go-e Ladeleistung', $v('GoEPower') . ' kW'],
            ['SolarEdge PV', $v('PVPower') . ' kW'],
            ['Netzbezug', $v('GridPower') . ' kW'],
            ['Speicher-SOC', $v('HomeBatterySOC') . ' %'],
        ];
        $html = '<div style="font-family:system-ui,-apple-system,sans-serif;padding:16px;background:#111c29;color:#edf5ff;border-radius:18px">'
            . '<div style="font-size:22px;font-weight:700;margin-bottom:6px">Renault EV Center</div>'
            . '<div style="color:#86acc3;font-size:12px;margin-bottom:14px">Megane E-Tech · Nur Monitoring / keine Ladesteuerung</div>'
            . '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(125px,1fr));gap:9px">';
        foreach ($cards as [$label, $value]) {
            $html .= '<div style="border:1px solid #30465d;border-radius:12px;padding:12px;background:#1c2c3d">'
                . '<div style="font-size:12px;color:#9eb5ca">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</div>'
                . '<div style="font-size:19px;font-weight:700;padding-top:5px">' . $value . '</div></div>';
        }
        $html .= '</div><div style="font-size:11px;color:#93a9bc;margin-top:14px">Fahrtverbrauch aus SOC-Änderung geschätzt. Ladeenergie bevorzugt aus dem go-e Zähler. Kein Steuerbefehl vorhanden.</div></div>';
        $this->SetValue('Dashboard', $html);
    }
}
