# Changelog

## 0.1.4 – 2026-11-05
- Profilfehler beim Batteriestand (Float vs. Integer-Standardprofil) behoben.
- Automatische Registrierung des Float-Profils `REVC.BatteryPercent` beim Anwenden der Instanz.
- Stub-Test für den Profiltyp ergänzt; bestehende Statusvariable bleibt unverändert.

## 0.1.3 – 2026-11-05
- Laufende Fahrstrecke in den aktuellen Summen und zusätzliche Diagnosemeldungen.

## 0.1.2 – 2026-11-05
- Überflüssige Modul-Aliases entfernt.

## 0.1.1 – 2026-11-05
- Variablenzuordnung über `SelectVariable` aus dem Symcon-Objektbaum.


## 0.1.0 – 2026-11-04
- Eigenständiges Symcon Modul, PHP 8.
- Gigya-Login, JWT, Kontoauflösung, OTP per E-Mail und reine Renault-Datenabfrage.
- IP-Symcon Variablenquellen, go-e Leistungs- und Zählerüberwachung, SolarEdge-Monitoring.
- Lokale Fahrt- und Ladehistorie mit persistenter Auswertung und HTMLBox.
- Keine Lade-/Fahrzeug-Steuerbefehle.
- Offline-Tests und Stub-Integration. Reale Cloud noch nicht verifiziert.
