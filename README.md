# Unwetter & Gefahren – IP-Symcon Modul

Zeigt **amtliche Unwetter- und Gefahrenwarnungen** für eine frei wählbare Region in
IP-Symcon an und kann bei bestimmten Gefahrenlagen automatisch **Aktionen auslösen**
(z. B. Rollläden schließen). Inklusive **Kachel-Visualisierung**.

Grundlage ist die offene Warn-API des Bundes (**NINA / `warnung.bund.de`** des
Bundesamtes für Bevölkerungsschutz und Katastrophenhilfe, BBK). Diese bündelt genau
die Quellen, die auch in den Apps **NINA**, **KATWARN** und **DWD WarnWetter**
erscheinen:

| Quelle | Inhalt |
|---|---|
| **DWD** | Wetter- und Unwetterwarnungen des Deutschen Wetterdienstes (Sturm, Gewitter, Glätte, Hitze, Starkregen …) |
| **MoWaS / KATWARN / BIWAPP** | Zivil- und Katastrophenschutz (Gefahrstoffe, Großbrände, Bombenfunde, Sirenenwarnungen …) |
| **LHP** | Länderübergreifendes Hochwasserportal |
| **Polizei** | Polizeiliche Gefahrenmeldungen |

> Kein API-Key nötig. Kompatibel mit **IP-Symcon 7.0 – 9.0**.

## Funktionen

- **Regionsgenau** über den Amtlichen Regionalschlüssel (ARS) – bequem aus einer
  Auswahlliste aller ~400 deutschen Land- und Stadtkreise.
- **Quellen einzeln** aktivierbar (Wetter, Zivilschutz, Hochwasser, Polizei).
- **Status-Variablen**: Warnung aktiv, höchste Warnstufe (0–4, farbig), Anzahl,
  je Kategorie ein Status, HTML-Meldungsliste, Zeitpunkt der letzten Aktualisierung.
- **Kachel-Visualisierung** mit farbiger Warnstufe und Meldungsliste.
- **Regelbasierte Aktionen**: pro Regel ein Skript ausführen, wenn eine Warnung
  ab einer bestimmten Stufe / Kategorie / Stichwort neu auftritt.

## Installation

1. In IP-Symcon den **Module-Store** öffnen → **„Modul über URL hinzufügen“**.
2. URL eintragen: `https://github.com/tomson9183/IPSymcon-Unwetterwarnung`
3. Eine Instanz **„Unwetterwarnung“** anlegen.

## Einrichtung

1. **Region** aus der Liste wählen (z. B. *Nordrhein-Westfalen – Kreisfreie Stadt Köln*).
2. Gewünschte **Warnquellen** an-/abwählen und das **Aktualisierungsintervall**
   setzen (Standard 600 s = 10 min).
3. Optional **Regeln** anlegen (siehe unten). Mit **„Jetzt aktualisieren“** sofort
   abfragen.

## Status-Variablen

| Variable | Typ | Bedeutung |
|---|---|---|
| `Warnung aktiv` | Bool | Mindestens eine Warnung aktiv |
| `Warnstufe` | Integer | Höchste Stufe 0 (keine) … 4 (extrem), farbig |
| `Anzahl Warnungen` | Integer | Anzahl aktiver Warnungen |
| `Wetter` / `Zivilschutz` / `Hochwasser` / `Polizei` | Bool | Aktive Warnung je Kategorie |
| `Meldungen` | HTML | Liste der aktuellen Warnungen |
| `Letzte Aktualisierung` | Integer | Zeitpunkt des letzten Abrufs |

## Aktionen / Regeln

In der Instanzkonfiguration unter **„Aktionen bei Warnungen (Regeln)“** lassen sich
Regeln anlegen. Jede Regel führt ein Skript **einmal** aus, sobald eine passende
Warnung neu auftritt:

| Spalte | Bedeutung |
|---|---|
| Aktiv | Regel ein-/ausschalten |
| Ab Stufe | Mindest-Warnstufe (1–4) |
| Kategorie | Alle / Wetter / Zivilschutz / Hochwasser / Polizei |
| Stichwort | optional: nur wenn die Überschrift diesen Text enthält (z. B. „STURM“) |
| Skript | das auszuführende IP-Symcon-Skript |

Im Skript stehen die Warndaten unter `$_IPS` zur Verfügung
(`Headline`, `Provider`, `Category`, `Severity`, `SeverityText`, `WarnID`).
Ein vollständiges Beispiel zum **Rollläden schließen** liegt unter
[`beispiele/Rollladen_zu.php`](beispiele/Rollladen_zu.php).

## Warnstufen

| Stufe | Bedeutung | Farbe |
|---|---|---|
| 0 | Keine Warnung | grün |
| 1 | Vorinformation (Minor) | gelb |
| 2 | Wetterwarnung (Moderate) | orange |
| 3 | Markante Warnung (Severe) | rot |
| 4 | Extreme Gefahr (Extreme) | violett |

## Verwendete API

| Zweck | Pfad |
|---|---|
| Warnübersicht je Region | `GET https://warnung.bund.de/api31/dashboard/{ARS}.json` |
| Detail zu einer Warnung | `GET https://warnung.bund.de/api31/warnings/{id}.json` |

Der ARS ist 12-stellig; abgefragt wird kreisgenau (die letzten 7 Stellen sind 0).
Doku der API: [nina.api.bund.dev](https://nina.api.bund.dev/) ·
[bundesAPI/nina-api](https://github.com/bundesAPI/nina-api).

## Hinweise & Grenzen

- Die API liefert Daten **auf Kreisebene** – nicht gemeindescharf.
- Warnungen erscheinen erst beim nächsten Abruf (Intervall entsprechend wählen).
- Die API ist ein kostenloser Dienst des Bundes; bei Ausfällen meldet die Instanz
  Status „Warn-API nicht erreichbar“.
- Verlasse dich für lebenswichtige Entscheidungen **nicht allein** auf dieses Modul –
  es ergänzt, ersetzt aber nicht die offiziellen Warn-Apps und -Kanäle.

## Lizenz

[MIT](LICENSE)

---
*Dieses Projekt steht in keiner Verbindung zum BBK, DWD oder zu KATWARN. Es nutzt die
öffentlich bereitgestellte Warn-API des Bundes. „NINA“, „KATWARN“ und „WarnWetter“
sind Marken der jeweiligen Inhaber.*
