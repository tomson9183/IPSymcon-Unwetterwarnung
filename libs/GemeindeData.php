<?php

declare(strict_types=1);

/**
 * GemeindeData – gemeinsame Helfer für die Gemeinde-Auswahl.
 *
 * libs/gemeinden.json: alle ~11.000 Gemeinden mit
 *   a = ARS (12-stellig), n = Name, k = Kreis, l = Bundesland, y = lat, x = lon.
 *
 * Wegen des 1-MB-Limits für Konfigurationsformulare wird die Auswahl als
 * Kaskade Bundesland -> Gemeinde umgesetzt (per UpdateFormField), damit nie alle
 * Gemeinden auf einmal ins Formular geschrieben werden.
 *
 * Genutzt von Warnzentrale und Regenradar.
 */
trait GemeindeData
{
    private function GemeindenAll(): array
    {
        static $cache = null;
        if ($cache === null) {
            $cache = json_decode((string) @file_get_contents(__DIR__ . '/gemeinden.json'), true);
            if (!is_array($cache)) {
                $cache = [];
            }
        }
        return $cache;
    }

    private function GemeindeLookup(string $ars): ?array
    {
        foreach ($this->GemeindenAll() as $g) {
            if (($g['a'] ?? '') === $ars) {
                return $g;
            }
        }
        return null;
    }

    /** Optionsliste aller Bundesländer (klein, 16 Einträge). */
    private function BundeslandOptions(): array
    {
        $set = [];
        foreach ($this->GemeindenAll() as $g) {
            $l = (string) ($g['l'] ?? '');
            if ($l !== '') {
                $set[$l] = true;
            }
        }
        $laender = array_keys($set);
        sort($laender);

        $options = [['caption' => $this->Translate('Please select…'), 'value' => '']];
        foreach ($laender as $l) {
            $options[] = ['caption' => $l, 'value' => $l];
        }
        return $options;
    }

    /** Gemeinden eines Bundeslandes als Optionsliste (für UpdateFormField). */
    private function GemeindeOptionsForLand(string $land): array
    {
        $options = [['caption' => $this->Translate('Please select…'), 'value' => '']];
        if ($land === '') {
            return $options;
        }
        $rows = [];
        foreach ($this->GemeindenAll() as $g) {
            if (($g['l'] ?? '') === $land) {
                $rows[] = $g;
            }
        }
        usort($rows, fn ($a, $b) => strcmp((string) ($a['n'] ?? ''), (string) ($b['n'] ?? '')));
        foreach ($rows as $g) {
            $options[] = [
                'caption' => ($g['n'] ?? '') . ' (' . ($g['k'] ?? '') . ')',
                'value'   => $g['a'] ?? '',
            ];
        }
        return $options;
    }

    /**
     * Gemeinden, deren Name (oder Kreis) den Suchtext enthält – für die Tastatur-Suche.
     * Begrenzt die Trefferzahl, damit das Formular klein bleibt.
     */
    private function GemeindeOptionsBySearch(string $query, int $limit = 300): array
    {
        $q = trim($query);
        $options = [['caption' => $this->Translate('Please type at least 2 characters…'), 'value' => '']];
        if (mb_strlen($q) < 2) {
            return $options;
        }
        $options = [];
        $count = 0;
        foreach ($this->GemeindenAll() as $g) {
            $name = (string) ($g['n'] ?? '');
            $kreis = (string) ($g['k'] ?? '');
            if (stripos($name, $q) !== false || stripos($kreis, $q) !== false) {
                $options[] = [
                    'caption' => $name . ' (' . $kreis . ', ' . ($g['l'] ?? '') . ')',
                    'value'   => $g['a'] ?? '',
                ];
                if (++$count >= $limit) {
                    break;
                }
            }
        }
        if ($count === 0) {
            $options[] = ['caption' => $this->Translate('No match'), 'value' => ''];
        }
        return $options;
    }

    /** Bundesland einer Gemeinde (zum Vorbelegen beim Öffnen des Formulars). */
    private function LandOfGemeinde(string $ars): string
    {
        $g = $this->GemeindeLookup($ars);
        return $g ? (string) ($g['l'] ?? '') : '';
    }

    /**
     * Bereitet das Formular vor: Bundesland-Optionen setzen und – sofern ein
     * Bundesland (oder eine gespeicherte Gemeinde) bekannt ist – die passenden
     * Gemeinde-Optionen einsetzen (klein gehalten).
     */
    private function PrepareGemeindeForm(array $form, string $landProp, string $gemeindeProp): array
    {
        $savedLand = $this->ReadPropertyString($landProp);
        $savedArs  = $this->ReadPropertyString($gemeindeProp);
        if ($savedLand === '' && $savedArs !== '') {
            $savedLand = $this->LandOfGemeinde($savedArs);
        }

        $bundesOpts   = $this->BundeslandOptions();
        $gemeindeOpts = $this->GemeindeOptionsForLand($savedLand);

        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === $landProp) {
                $element['options'] = $bundesOpts;
            } elseif (($element['name'] ?? '') === $gemeindeProp) {
                $element['options'] = $gemeindeOpts;
            }
        }
        unset($element);
        return $form;
    }

    /** Kreis-ARS (für die NINA-Dashboard-Abfrage) aus dem Gemeinde-ARS ableiten. */
    private function KreisARSFromGemeinde(string $gemeindeARS): string
    {
        return substr($gemeindeARS, 0, 5) . '0000000';
    }
}
