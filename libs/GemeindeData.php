<?php

declare(strict_types=1);

/**
 * GemeindeData – gemeinsame Helfer für die Gemeinde-Auswahl.
 *
 * Die Datei libs/gemeinden.json enthält alle ~11.000 deutschen Gemeinden mit:
 *   a = ARS (12-stellig, amtlicher Regionalschlüssel der Gemeinde)
 *   n = Name, k = Kreis, l = Bundesland, y = Breite (lat), x = Länge (lon)
 *
 * Wird von der Warnzentrale und vom Regenradar genutzt.
 */
trait GemeindeData
{
    /** Lädt die Gemeinde-Liste (gecacht über statische Variable). */
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

    /** Sucht eine Gemeinde anhand ihres ARS. */
    private function GemeindeLookup(string $ars): ?array
    {
        foreach ($this->GemeindenAll() as $g) {
            if (($g['a'] ?? '') === $ars) {
                return $g;
            }
        }
        return null;
    }

    /** Baut die Optionsliste für ein Select-Formularelement. */
    private function GemeindeOptions(): array
    {
        $options = [['caption' => $this->Translate('Please select…'), 'value' => '']];
        foreach ($this->GemeindenAll() as $g) {
            $options[] = [
                'caption' => ($g['l'] ?? '') . ' – ' . ($g['n'] ?? '') . ' (' . ($g['k'] ?? '') . ')',
                'value'   => $g['a'] ?? '',
            ];
        }
        return $options;
    }

    /** Setzt die Gemeinde-Optionen in ein Select-Element des Formulars ein. */
    private function InjectGemeindeOptions(array $form, string $elementName): array
    {
        $options = $this->GemeindeOptions();
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === $elementName) {
                $element['options'] = $options;
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
