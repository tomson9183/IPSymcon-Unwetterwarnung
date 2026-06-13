<?php

declare(strict_types=1);

/**
 * NinaClient – Zugriff auf die offene Warn-API des Bundes (NINA / warnung.bund.de).
 *
 * Die API des Bundesamtes für Bevölkerungsschutz und Katastrophenhilfe (BBK)
 * bündelt sämtliche amtlichen Warnungen, die auch in den Apps NINA, KATWARN und
 * DWD WarnWetter erscheinen:
 *   - DWD   (Deutscher Wetterdienst, Unwetterwarnungen)
 *   - MOWAS (Modulares Warnsystem, Zivil-/Katastrophenschutz)
 *   - KATWARN, BIWAPP (kommunale Warnsysteme)
 *   - LHP   (Länderübergreifendes Hochwasserportal)
 *   - POLICE (Polizeimeldungen)
 *
 * Es wird kein API-Key benötigt. Abgefragt wird kreisgenau über den
 * Amtlichen Regionalschlüssel (ARS, 12-stellig, letzte 7 Stellen = 0).
 *
 * Basis-URL: https://warnung.bund.de/api31
 */
trait NinaClient
{
    private const NINA_BASE = 'https://warnung.bund.de/api31';

    /** Kategorie-Schlüssel für die vier vom Modul unterstützten Quellgruppen. */
    private const CAT_WEATHER = 'weather'; // DWD
    private const CAT_CIVIL   = 'civil';   // MOWAS / KATWARN / BIWAPP
    private const CAT_FLOOD   = 'flood';   // LHP
    private const CAT_POLICE  = 'police';  // POLICE

    /**
     * Liefert die aktuelle Warnübersicht (Dashboard) für eine Region.
     *
     * @return array|null Array von Roh-Warnmeldungen oder null bei Fehler.
     */
    private function NinaGetDashboard(string $ars): ?array
    {
        $data = $this->NinaHttpGet(self::NINA_BASE . '/dashboard/' . rawurlencode($ars) . '.json');
        if ($data === null) {
            return null;
        }
        $json = json_decode($data, true);
        return is_array($json) ? $json : null;
    }

    /**
     * Liefert die Detailinformationen (Beschreibung, Handlungshinweise) zu einer Warnung.
     */
    private function NinaGetWarningDetail(string $identifier): ?array
    {
        $data = $this->NinaHttpGet(self::NINA_BASE . '/warnings/' . rawurlencode($identifier) . '.json');
        if ($data === null) {
            return null;
        }
        $json = json_decode($data, true);
        return is_array($json) ? $json : null;
    }

    /**
     * Lädt die Geometrie (GeoJSON) einer Warnung – für gemeinde-genaue Filterung.
     */
    private function NinaGetWarningGeoJson(string $identifier): ?array
    {
        $data = $this->NinaHttpGet(self::NINA_BASE . '/warnings/' . rawurlencode($identifier) . '.geojson');
        if ($data === null) {
            return null;
        }
        $json = json_decode($data, true);
        return is_array($json) ? $json : null;
    }

    /**
     * Prüft, ob ein Punkt (lat/lon) innerhalb der Warn-Geometrie liegt.
     * Unterstützt Polygon und MultiPolygon. Gibt true zurück, wenn der Punkt in
     * mindestens einer Fläche liegt.
     */
    private function NinaPointInGeoJson(float $lat, float $lon, array $geojson): bool
    {
        $features = $geojson['features'] ?? [];
        foreach ($features as $feature) {
            $geom = $feature['geometry'] ?? [];
            $type = $geom['type'] ?? '';
            $coords = $geom['coordinates'] ?? [];
            if ($type === 'Polygon') {
                if ($this->PointInPolygonRings($lat, $lon, $coords)) {
                    return true;
                }
            } elseif ($type === 'MultiPolygon') {
                foreach ($coords as $polygon) {
                    if ($this->PointInPolygonRings($lat, $lon, $polygon)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /**
     * Ein Polygon = äußerer Ring + optionale Löcher. Punkt ist drin, wenn er im
     * äußeren Ring und in keinem Loch liegt.
     */
    private function PointInPolygonRings(float $lat, float $lon, array $rings): bool
    {
        if (count($rings) === 0) {
            return false;
        }
        if (!$this->PointInRing($lat, $lon, $rings[0])) {
            return false;
        }
        for ($i = 1; $i < count($rings); $i++) {
            if ($this->PointInRing($lat, $lon, $rings[$i])) {
                return false; // im Loch
            }
        }
        return true;
    }

    /** Ray-Casting für einen einzelnen Ring. Koordinaten als [lon, lat]. */
    private function PointInRing(float $lat, float $lon, array $ring): bool
    {
        $inside = false;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            $xi = (float) ($ring[$i][0] ?? 0); // lon
            $yi = (float) ($ring[$i][1] ?? 0); // lat
            $xj = (float) ($ring[$j][0] ?? 0);
            $yj = (float) ($ring[$j][1] ?? 0);
            $intersect = (($yi > $lat) !== ($yj > $lat))
                && ($lon < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi);
            if ($intersect) {
                $inside = !$inside;
            }
        }
        return $inside;
    }

    /**
     * Wandelt eine Roh-Dashboard-Meldung in eine flache, einheitliche Struktur um.
     *
     * @return array{id:string,headline:string,provider:string,category:string,severity:int,severityText:string,msgType:string,sent:string}
     */
    private function NinaNormalize(array $item): array
    {
        $payload  = $item['payload']['data'] ?? [];
        $provider = (string) ($payload['provider'] ?? ($this->NinaGuessProvider((string) ($item['id'] ?? ''))));
        $headline = (string) ($payload['headline'] ?? ($item['i18nTitle']['de'] ?? ($item['i18nTitle']['en'] ?? 'Warnung')));
        $sevText  = (string) ($payload['severity'] ?? $item['severity'] ?? 'Minor');

        return [
            'id'           => (string) ($item['id'] ?? ''),
            'headline'     => trim($headline),
            'provider'     => $provider,
            'category'     => $this->NinaProviderCategory($provider),
            'severity'     => $this->NinaSeverityToInt($sevText),
            'severityText' => $sevText,
            'msgType'      => (string) ($payload['msgType'] ?? $item['type'] ?? 'Alert'),
            'sent'         => (string) ($item['sent'] ?? $item['startDate'] ?? ''),
        ];
    }

    /** Versucht aus der Meldungs-ID den Anbieter zu erraten (z. B. "dwd.…", "mow.…"). */
    private function NinaGuessProvider(string $id): string
    {
        $id = strtolower($id);
        if (strpos($id, 'dwd') === 0 || strpos($id, 'dwd') !== false && strpos($id, '.dwd.') !== false) {
            return 'DWD';
        }
        if (strpos($id, 'mow') === 0) {
            return 'MOWAS';
        }
        if (strpos($id, 'kat') === 0) {
            return 'KATWARN';
        }
        if (strpos($id, 'biwapp') === 0 || strpos($id, 'bwp') === 0) {
            return 'BIWAPP';
        }
        if (strpos($id, 'lhp') === 0 || strpos($id, 'hochwasser') !== false) {
            return 'LHP';
        }
        if (strpos($id, 'police') === 0 || strpos($id, 'pol') === 0) {
            return 'POLICE';
        }
        return 'DWD';
    }

    /** Ordnet einen Anbieter einer der vier Kategorien zu. */
    private function NinaProviderCategory(string $provider): string
    {
        switch (strtoupper($provider)) {
            case 'DWD':
                return self::CAT_WEATHER;
            case 'LHP':
                return self::CAT_FLOOD;
            case 'POLICE':
                return self::CAT_POLICE;
            case 'MOWAS':
            case 'KATWARN':
            case 'BIWAPP':
            default:
                return self::CAT_CIVIL;
        }
    }

    /** Severity-Text (CAP) -> Stufe 1..4 (Minor..Extreme). */
    private function NinaSeverityToInt(string $severity): int
    {
        switch (strtolower($severity)) {
            case 'extreme':
                return 4;
            case 'severe':
                return 3;
            case 'moderate':
                return 2;
            case 'minor':
            default:
                return 1;
        }
    }

    /**
     * Einfacher HTTP-GET über cURL. Gibt den Body als String zurück oder null bei Fehler.
     */
    private function NinaHttpGet(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => 'IP-Symcon Unwetterwarnung Modul',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        unset($ch);

        if ($body === false || $err !== '') {
            $this->SendDebug('NINA HTTP', 'Fehler bei ' . $url . ': ' . $err, 0);
            return null;
        }
        if ($code < 200 || $code >= 300) {
            $this->SendDebug('NINA HTTP', 'HTTP ' . $code . ' bei ' . $url, 0);
            return null;
        }
        return (string) $body;
    }
}
