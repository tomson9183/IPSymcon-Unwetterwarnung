<?php

declare(strict_types=1);

/**
 * Beispiel-Aktionsskript für das Modul "Unwetterwarnung".
 *
 * Lege dieses Skript in IP-Symcon an und wähle es in einer Regel der Modul-Instanz
 * als "Skript" aus. Es wird einmal ausgeführt, sobald eine passende Warnung neu
 * auftritt (z. B. ab Warnstufe 3 / "Markante Warnung").
 *
 * Beim Aufruf stehen die Warndaten in $_IPS bereit:
 *   $_IPS['SENDER']       = 'Unwetterwarnung'
 *   $_IPS['INSTANCE']     = InstanzID des Warnmoduls
 *   $_IPS['WarnID']       = eindeutige ID der Warnung
 *   $_IPS['Headline']     = Überschrift, z. B. "Amtliche WARNUNG vor STURMBÖEN"
 *   $_IPS['Provider']     = DWD | MOWAS | KATWARN | BIWAPP | LHP | POLICE
 *   $_IPS['Category']     = weather | civil | flood | police
 *   $_IPS['Severity']     = 1..4 (Minor, Moderate, Severe, Extreme)
 *   $_IPS['SeverityText'] = Minor | Moderate | Severe | Extreme
 */

// === Hier die zu schließenden Rollläden eintragen (Variablen-IDs der Position 0..100 %) ===
// 100 = komplett geschlossen. Passe die IDs an deine Installation an.
$rollladenVariablen = [
    // 12345,  // Wohnzimmer
    // 12346,  // Küche
];

// Nur bei Wetter- oder Hochwasserwarnungen reagieren (Beispiel-Logik):
$kategorie = $_IPS['Category'] ?? '';
if (!in_array($kategorie, ['weather', 'flood'], true)) {
    return;
}

foreach ($rollladenVariablen as $varID) {
    if (@IPS_VariableExists($varID)) {
        // RequestAction schließt den Rollladen über die jeweilige Geräte-Instanz.
        @RequestAction($varID, 100);
    }
}

// Optional: Logbuch-Eintrag
IPS_LogMessage('Unwetterwarnung', sprintf(
    'Rollläden geschlossen wegen: %s (Stufe %s)',
    $_IPS['Headline'] ?? '?',
    $_IPS['Severity'] ?? '?'
));
