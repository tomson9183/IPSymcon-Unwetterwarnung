# Unwetter & Gefahren – IP-Symcon Modul

Zeigt **amtliche Unwetter- und Gefahrenwarnungen** **gemeinde-genau** in IP-Symcon an,
löst bei wählbaren Gefahrenlagen **konfigurierbare Aktionen** aus (z. B. Rollläden
schließen) und bringt das **DWD-Regenradar** als eigene Kachel.

Grundlage ist die offene Warn-API des Bundes (**NINA / `warnung.bund.de`** des
Bundesamtes für Bevölkerungsschutz und Katastrophenhilfe, BBK) und der offene
**DWD-GeoServer** (`maps.dwd.de`). Die NINA-API bündelt genau die Quellen, die auch
in den Apps **NINA**, **KATWARN** und **DWD WarnWetter** erscheinen:

| Quelle | Inhalt |
|---|---|
| **DWD** | Wetter- und Unwetterwarnungen (Sturm, Gewitter, Glätte, Hitze, Starkregen …) |
| **MoWaS / KATWARN / BIWAPP** | Zivil- und Katastrophenschutz (Gefahrstoffe, Großbrände, Bombenfunde, Sirenen …) |
| **LHP** | Länderübergreifendes Hochwasserportal |
| **Polizei** | Polizeiliche Gefahrenmeldungen |

> Kein API-Key, kein Login nötig. Kompatibel mit **IP-Symcon 7.0 – 9.0**.

## Aufbau: drei Modultypen, sehr übersichtlich

```
🛡️  Warnzentrale            (1×)  – Gemeinde wählen, zeigt ALLE Warnungen + Warn-Kachel
     └── ⚡ Unwetter Aktion  (n×)  – „was passiert wie“: Bedingung → Reaktion
🌧️  Regenradar              (1×)  – DWD-Niederschlagsradar als eigene Kachel
```

- **🛡️ Warnzentrale** – die zentrale Daten-Instanz. Hier wählst du **eine Gemeinde**
  und siehst **alle** Warnungen (gemeinde-genau gefiltert) als Status-Variablen und in
  einer **Kachel**.
- **⚡ Unwetter Aktion** – beliebig viele Instanzen, die **unterhalb** der Warnzentrale
  hängen. Jede ist genau eine Regel: **Bedingung** (Kategorie + ab Warnstufe + optional
  Stichwort) → **Reaktion** (frei wählbare Symcon-Aktion). Eigene Namen wie
  *„Rollläden zu bei Sturm“* oder *„Push bei extremem Gewitter“*.
- **🌧️ Regenradar** – zeigt das DWD-Niederschlagsradar zentriert auf eine Gemeinde,
  aktualisiert sich automatisch.

## Installation

1. In IP-Symcon den **Module-Store** öffnen → **„Modul über URL hinzufügen“**.
2. URL: `https://github.com/tomson9183/IPSymcon-Unwetterwarnung`
3. Instanzen anlegen (siehe unten).

## Einrichtung

### 1. Warnzentrale
- Instanz **„Unwetter & Gefahren Warnzentrale“** anlegen.
- **Gemeinde** aus der Liste wählen (alle ~11.000 deutschen Gemeinden).
- Optional Quellen, Mindest-Warnstufe und Intervall anpassen.
- *Gemeinde-genau filtern* (Standard an): nur Warnungen, deren Warngebiet die Gemeinde
  tatsächlich abdeckt (Punkt-in-Polygon gegen die amtliche Geometrie).

### 2. Aktionen (optional, beliebig viele)
- **Unterhalb** der Warnzentrale eine Instanz **„Unwetter Aktion“** anlegen.
- **Bedingung** wählen: Kategorie, ab Warnstufe (z. B. 4 = extrem), optional Stichwort
  (z. B. `GEWITTER`).
- **Reaktion** über die Symcon-Aktionsauswahl festlegen – z. B.:
  - Rollladen-/Geräte-Variable schalten (Position 100 % = zu)
  - Szene aktivieren
  - Push-/WebFront-Benachrichtigung senden
  - HTTP-Request auslösen
  - Skript ausführen
- Optional: **Entwarnung** – eine zweite Aktion, wenn alle passenden Warnungen vorbei
  sind (z. B. Rollläden wieder öffnen).

### 3. Regenradar (optional)
- Instanz **„Regenradar“** anlegen, Gemeinde + Kartenausschnitt wählen. Fertig.

## Status-Variablen (Warnzentrale)

| Variable | Typ | Bedeutung |
|---|---|---|
| `Warnung aktiv` | Bool | Mindestens eine Warnung aktiv |
| `Warnstufe` | Integer | Höchste Stufe 0 (keine) … 4 (extrem), farbig |
| `Anzahl Warnungen` | Integer | Anzahl aktiver Warnungen |
| `Wetter` / `Zivilschutz` / `Hochwasser` / `Polizei` | Bool | Aktive Warnung je Kategorie |
| `Meldungen` | HTML | Liste der aktuellen Warnungen |
| `Letzte Aktualisierung` | Integer | Zeitpunkt des letzten Abrufs |

## Warnstufen

| Stufe | Bedeutung | Farbe |
|---|---|---|
| 0 | Keine Warnung | grün |
| 1 | Vorinformation (Minor) | gelb |
| 2 | Wetterwarnung (Moderate) | orange |
| 3 | Markante Warnung (Severe) | rot |
| 4 | Extreme Gefahr (Extreme) | violett |

## Verwendete APIs

| Zweck | Endpunkt |
|---|---|
| Warnübersicht je Kreis | `GET https://warnung.bund.de/api31/dashboard/{ARS}.json` |
| Warngeometrie (gemeinde-genau) | `GET https://warnung.bund.de/api31/warnings/{id}.geojson` |
| DWD-Niederschlagsradar | `https://maps.dwd.de/geoserver/dwd/wms` (Layer `dwd:Niederschlagsradar`) |

Die Gemeinde-Liste (ARS + Koordinaten) stammt aus dem öffentlichen Datensatz
*georef-germany-gemeinde*. Doku der NINA-API: [nina.api.bund.dev](https://nina.api.bund.dev/).

## Hinweise & Grenzen

- **DWD-WarnWetter-Account:** Es gibt **keine** öffentliche Login-API der WarnWetter-App
  (der App-Premium-Login ist ein In-App-Kauf für app-interne Karten). Er wird hier auch
  **nicht benötigt** – Warnungen **und** Radar liefert der DWD/Bund offen und kostenlos.
- Warnungen erscheinen erst beim nächsten Abruf (Intervall entsprechend wählen).
- Verlasse dich für lebenswichtige Entscheidungen **nicht allein** auf dieses Modul –
  es ergänzt, ersetzt aber nicht die offiziellen Warn-Apps und -Kanäle.

## Lizenz

[MIT](LICENSE)

---
*Dieses Projekt steht in keiner Verbindung zum BBK, DWD oder zu KATWARN. Es nutzt die
öffentlich bereitgestellten Dienste des Bundes/DWD. „NINA“, „KATWARN“ und „WarnWetter“
sind Marken der jeweiligen Inhaber.*
