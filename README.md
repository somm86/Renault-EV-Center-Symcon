# Renault EV Center für IP-Symcon

**Version 0.1.1 (technische Testversion)** – Renault Megane E-Tech und andere Renault EV, *ohne Home Assistant*.  
**Ausschließlich Daten lesen und Statistiken bilden.** Es gibt **keine Ladefunktion, keine Ladeautomatik und keinen Fahrzeug- oder Wallbox-Steuerbefehl**.

> Status: Das Modul ist in seiner Ordner-/JSON-Struktur vorbereitet, mit PHP 8.4 syntaktisch geprüft und per Stub/Simulator getestet. **Noch nicht mit einer realen IP-Symcon-Installation, einem Renault-Konto oder der echten Renault Cloud verifiziert.** Besonders der Zugang zu Renaults nichtöffentlichen APIs kann sich ändern, bei MFA oder Fahrzeugverträgen können Einschränkungen auftreten. Dies ist bewusst **keine produktiv validierte 1.0-Version**.

## Inhalte

- Eigenständige Symcon-Modulbibliothek mit `library.json`, `RenaultEVCenter/module.json` und `module.php`.
- My-Renault-Anmeldung über **Gigya**, Login-Token, JWT-Erneuerung und Konto-/VIN-Ermittlung.
- Optionale E-Mail-Zwei-Faktor-Anmeldung (Gigya OTP); nach der Bestätigung erneute Anmeldung.
- **Nur lesende** Kamereon-Abfragen: Batteriestatus, Reichweite, Kilometerstand.
- Fallback mit bestehenden Symcon-Variablen für SoC, Reichweite, Kilometerstand und Ladestatus, falls Renault noch nicht erreichbar ist.
- Fahrterkennung aus Kilometerstandsänderungen, Fahrtenhistorie, Kilometerstatistik, grobe Verbrauchsschätzung anhand von SoC-Änderungen.
- Lesen bereits vorhandener **go-e**-Variablen: Ladezustand, Leistung, Session-/Gesamtzähler, daraus Ladevorgangshistorie, Energie und fixe Tarifkosten. **Keine go-e-Kommandos.**
- Lesen bereits vorhandener **SolarEdge**-Variablen: PV-Leistung, Netzleistung, Speicher-SoC und Speicherleistung. **Keine SolarEdge-Kommandos.**
- HTMLBox mit Kennzahlen; Fahrten-/Ladungslisten als JSON-Variablen (maximal je 500 Datensätze) persistiert in Modul-Attributen.

**Nicht enthalten:** PV-Ladeautomatik, Start/Stop der Fahrzeugladung, go-e-Stromlimit, Renault-Klimasteuerung, Steuerung des SolarEdge-Speichers, genaue Ermittlung des PV-Anteils an einer Ladung, vollständige Parität mit den 90+ Entitäten des Home-Assistant-Originals.

## Voraussetzungen

- IP-Symcon **ab 7.1** (Testumgebung für diese erste Version nicht verfügbar); PHP 8+ und `curl`-Erweiterung.
- Für Renault: My-Renault-Konto, Cloudzugriff, Mobilfunk-/Datenvertrag am Fahrzeug, Netzwerkzugriff des Symcon-Servers auf Renault-Endpunkte.
- Für go-e und SolarEdge: deren Werte müssen bereits als IP-Symcon-Variablen vorhanden sein. Es werden **keine neuen Verbindungen** zum lokalen Charger oder Wechselrichter aufgebaut.
- Empfehlung: Datenintervall **10 Minuten oder länger**. Das Modul erzwingt bei aktivierter Renault-Cloud mindestens 10 Minuten, um unnötige Sperren zu vermeiden.

## Installation über GitHub / Modulverwaltung

1. Ein **öffentliches, leeres GitHub-Repository** anlegen, z. B. `Renault-EV-Center-Symcon` im eigenen Konto.
2. **Inhalt des ZIPs in die Wurzel** dieses Repositorys hochladen (`library.json` muss direkt im Root liegen, nicht in einem zusätzlichen übergeordneten Ordner).
3. In IP-Symcon **Kern-Instanzen → Module Control / Modulverwaltung → Hinzufügen** aufrufen und die vollständige Repository-URL hinzufügen, z. B. `https://github.com/<dein-account>/Renault-EV-Center-Symcon` **erst nachdem das Repository existiert**.
4. Über **Instanz hinzufügen → Renault → Renault EV Center** eine Instanz anlegen.

**Wichtig:** Der **Module Store** (offizieller Katalog) ist nicht mit **Module Control** (GitHub-URL direkt hinzufügen) identisch. Der Store erfordert eine gesonderte Einreichung; das ist hier nicht erfolgt. Das ZIP allein ist kein Klick-Import in Module Control.

## Konfiguration

### 1. Renault-Cloud

- Unter **My Renault / Cloud** E-Mail, Passwort, Land `AT` und optional VIN setzen; **Übernehmen** klicken.
- **1. Bei Renault anmelden** wählen. Das Modul verwendet `accounts.login` und anschließend `accounts.getJWT` und die Kamereon-Kontoerkennung.
- Wenn **E-Mail-2FA** verlangt wird: **2. 2FA-Code ... anfordern**, empfangenen Einmalcode ins Aktionsfeld eintragen, **3. Einmalcode bestätigen**. Falls mehrere E-Mail-Adressen existieren, nach dem ersten Versuch die gelistete E-Mail-ID unter `TfaEmailID` einstellen, Übernehmen klicken und erneut anfordern.
- **Werte jetzt aktualisieren** wählen. In den Instanzvariablen `CloudConnected`, `Message`, `LastUpdate`, `BatteryLevel`, `BatteryAutonomy`, `Odometer` prüfen.
- Der JWT wird bei Bedarf automatisch erneuert. Falls die Gigya-Sitzung abläuft oder die Cloud HTTP 403 meldet, ist ggf. eine erneute manuelle Anmeldung nötig. Es wird **keine Endlosschleife mit Passwort-Login** durchgeführt.

**Achtung:** `PasswordTextBox` maskiert den Eingabewert, ist aber kein Nachweis verschlüsselter Geheimnisspeicherung durch Symcon. Konfigurations-Backups absichern. **Keine Passwort-, Token- oder privaten Fahrzeugdaten in öffentliche GitHub-Repositories hochladen**. Zugangsdaten werden in der Instanzkonfiguration eingegeben, **nicht** in den ZIP-Dateien gespeichert.

### 2. Bestehende Renault-Variablen

Bei `SourceSOC`, `SourceRange`, `SourceMileage`, `SourceCharging` optional vorhandene Symcon-Variablen über **Auswählen (Baumstruktur)** zuordnen. Es muss keine ID mehr manuell eingegeben werden. Gemappte Werte haben Vorrang gegenüber Cloudwerten. So lässt sich die Statistik auch **ohne Login** lokal testen.

### 3. go-e Charger

Im Abschnitt **go-e (ausschließlich Auslesen)** die vorhandenen Variablen direkt über den **Symcon-Variablenbaum** auswählen:

| Eingabe | Bedeutung |
|---|---|
| `GoEChargingVar` | Boolean "lädt"; bei numerischem go-e `car state` die Option "2 = lädt" wählen |
| `GoEPowerVar` | Aktuelle Ladeleistung; richtige Einheit **W** oder **kW** angeben |
| `GoESessionKwhVar` | Laufender Sitzungszähler, falls vorhanden |
| `GoETotalKwhVar` | Kumulativer Energiezähler; bevorzugte Berechnungsquelle |
| `GoEEnergyUnit` | **Wh** oder **kWh** exakt passend zur Quelle |

Anfang und Ende einer Ladesitzung werden aus Statuswechseln erkannt. Priorität der kWh-Ermittlung: Gesamtzähler-Differenz → Sessionzähler-Differenz → Schätzung durch Leistungsintegration. Bei Ausfällen, fehlenden Werten und langen Datenlücken sind Werte ungenau. Beim ersten Einrichten wird **kein zurückliegender Ladevorgang** rekonstruiert.

### 4. SolarEdge

`SolarPVVar`, `SolarGridVar`, `SolarBatterySOCVar`, `SolarBatteryPowerVar` über den **Symcon-Variablenbaum** mit den bestehenden Modbus-Variablen verknüpfen, Einheit W/kW richtig setzen. `GridPositiveImport` legt das Vorzeichen für den Netzbezug fest. Es werden **nur Anzeigen** erzeugt – keine Register beschrieben, kein Remote Control aktiviert.

### 5. Statistik

Die Batteriegröße (für die erste Version meist **60 kWh** beim Megane EV60, exakt nach Fahrzeug prüfen), den eigenen fixen Strompreis in EUR/kWh und das Stillstandsintervall konfigurieren.

Fahrten beginnen erst nach einer echten Kilometerstandsänderung **nach Inbetriebnahme**. Ihr Ende wird mit Stillstandszeit erkannt. Die kWh/100-km-Berechnung ist eine **SoC-basierte Näherung**, kein Bordcomputer-Messwert. Von der go-e Wallbox gemessene Lade-kWh sind nicht automatisch gleich der im Fahrzeugakku gespeicherten Energie (Ladeverluste).

In `TripHistory` und `ChargeHistory` werden maximal 500 Einträge je Typ gehalten. Historie in den Modul-Attributen wird bei Entfernung der Instanz gelöscht; vor der Deinstallation exportieren oder anderweitig sichern. Für Langzeitdiagramme können die numerischen Symcon-Variablen im Symcon-Archiv zusätzlich protokolliert werden.

## Testen

```bash
php -l RenaultEVCenter/module.php
php -l libs/RenaultApiClient.php
php -l libs/Analytics.php
php tests/run.php
php tests/symcon_stub.php
```

Die Tests simulieren die Anmeldung und Zwei-Faktor-Bestätigung, prüfen Trip-/Ladeberechnung und testen das Modul mit einem Symcon-Stub ohne echte Cloud oder Wallbox.

## Hinweise / Anerkennung

Fachliche Grundlage und Funktionsideen stammen aus dem Originalprojekt [Redrex85/Renault-EV-Center-Home-Assistant](https://github.com/Redrex85/Renault-EV-Center-Home-Assistant), Lizenz MIT. Hinweise zum Symcon-Modul-Layout und historischen Gigya/Kamereon-Endpunkten aus [lorbetzki/net.lorbetzki.zoe](https://github.com/lorbetzki/net.lorbetzki.zoe) (eingestellt). Der modernere API-Ablauf wurde mit [hacf-fr/renault-api](https://github.com/hacf-fr/renault-api) abgeglichen. Die vorliegende PHP-Implementierung ist eigenständig neu geschrieben, kein Kopieren der Home-Assistant-Komponente. Lizenzinformationen siehe `LICENSE` und `docs/LICENSE-Redrex.txt`.

## Datenschutz & Betrieb

- Die integrierten HTTP-Clients haben ausschließlich die Endpunkte für Login, Account, Fahrzeuge, Batteriestatus und Cockpit; **keinen einzigen Aktionsendpunkt für Fahrzeuge oder Ladegeräte**.
- Authentifizierungsdaten werden nicht in `SendDebug` ausgegeben und nicht mit dem Modul verteilt; lokale Instanzattribute enthalten zur Laufzeit Login- und JWT-Token.
- Die API-Nutzung erfolgt über inoffizielle bzw. nicht für dieses Modul garantierte Schnittstellen; Renault kann Versionen, Anforderungen, Zugriffsrechte und API-Schlüssel ändern.
- Fehlermeldungen sind unter `Message` sichtbar, `CloudConnected = false` bedeutet, dass die letzte Cloud-Abfrage nicht erfolgreich war.

**Freigabestatus:** `v0.1.1 test/beta` – vor Einsatz am echten Fahrzeug überprüfen. Ein offizieller Eintrag im IP-Symcon Module Store existiert nicht.
