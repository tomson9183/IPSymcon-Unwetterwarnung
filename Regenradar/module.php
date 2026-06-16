<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/GemeindeData.php';

/**
 * Regenradar – Karte mit Ortsnamen + DWD-Niederschlagsvorhersage (Wolkenverlauf).
 *
 * Der Symcon-SERVER setzt die Kachel zusammen (Anzeigegerät braucht KEINEN externen
 * Internetzugriff):
 *   - Basiskarte: OpenStreetMap-Kacheln (mit Orts-/Straßennamen), per GD zu einem
 *     JPEG zusammengesetzt und EINMAL eingebettet (gecacht).
 *   - Radar: DWD-Vorhersageradar (RV-Produkt, 1 km, 5-Minuten-Schritte) als kleine
 *     transparente PNG-Ebenen je Zeitschritt, deckungsgleich über die Karte gelegt.
 * Beim ▶-Knopf wird die Vorschau IMMER ab der aktuellen Zeit für 60 Minuten erzeugt
 * und abgespielt (wie die WarnWetter-App). Fokus: Gemeinde oder ein per Geocoding
 * ermittelter Ortsteil.
 */
class UnwetterRegenradar extends IPSModule
{
    use GemeindeData;

    private const WMS    = 'https://maps.dwd.de/geoserver/dwd/wms';
    private const RADAR  = 'dwd:Radar_rv_product_1x1km_ger'; // Vorhersage (RADVOR/RV)
    private const UA     = 'IP-Symcon Unwetter & Gefahren (github.com/tomson9183)';
    private const W      = 540;
    private const H      = 380;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Suche', '');
        $this->RegisterPropertyString('Bundesland', '');
        $this->RegisterPropertyString('GemeindeARS', '');
        $this->RegisterPropertyString('Ortsteil', '');
        $this->RegisterPropertyInteger('Zoom', 2);              // 1=Ortsteil/Stadt(13) 2=Gemeinde(11) 3=Region(9)
        $this->RegisterPropertyInteger('ForecastSteps', 12);    // Zeitschritte (à 5 min) -> 12 = 60 min
        $this->RegisterPropertyInteger('PlayDuration', 30);

        $this->RegisterAttributeFloat('Lat', 0.0);
        $this->RegisterAttributeFloat('Lon', 0.0);
        $this->RegisterAttributeString('FocusName', '');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->SetVisualizationType(1);
        $this->SetBuffer('BaseKey', '');
        $this->SetBuffer('Data', '');

        $ars = $this->ReadPropertyString('GemeindeARS');
        if ($ars === '') {
            $this->SetStatus(104);
            return;
        }

        $g = $this->GemeindeLookup($ars);
        $lat = $g ? (float) ($g['y'] ?? 0) : 0.0;
        $lon = $g ? (float) ($g['x'] ?? 0) : 0.0;
        $focus = $g ? $this->CleanName((string) ($g['n'] ?? '')) : '';

        $ortsteil = trim($this->ReadPropertyString('Ortsteil'));
        if ($ortsteil !== '' && $g) {
            $geo = $this->Geocode($ortsteil, $this->CleanName((string) ($g['n'] ?? '')), (string) ($g['k'] ?? ''), $lat, $lon);
            if ($geo !== null) {
                $lat = $geo[0];
                $lon = $geo[1];
                $focus = $ortsteil;
            }
        }

        $this->WriteAttributeFloat('Lat', $lat);
        $this->WriteAttributeFloat('Lon', $lon);
        $this->WriteAttributeString('FocusName', $focus);
        $this->SetStatus(102);

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->BuildAndPush(false);
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $form = $this->PrepareGemeindeForm($form, 'Bundesland', 'GemeindeARS');
        return json_encode($form);
    }

    public function SetLand(string $Bundesland): void
    {
        $this->UpdateFormField('GemeindeARS', 'options', json_encode($this->GemeindeOptionsForLand($Bundesland)));
        $this->UpdateFormField('GemeindeARS', 'value', '');
    }

    public function Search(string $Suche): void
    {
        $this->UpdateFormField('GemeindeARS', 'options', json_encode($this->GemeindeOptionsBySearch($Suche)));
    }

    /** ▶-Knopf in der Kachel: Vorschau ab JETZT neu erzeugen und abspielen. */
    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Play') {
            $this->BuildAndPush(true);
            return;
        }
        throw new Exception('Invalid Ident: ' . $Ident);
    }

    /** Knopf „Karte jetzt bauen / testen“. */
    public function RefreshTest(): void
    {
        if ($this->ReadPropertyString('GemeindeARS') === '') {
            echo $this->Translate('Please select a municipality first.');
            return;
        }
        $data = $this->BuildAndPush(false);
        if ($data !== null && count($data['frames']) > 0) {
            echo sprintf($this->Translate('OK – map + %d forecast frames (server-side). Focus: %s'),
                count($data['frames']), $this->ReadAttributeString('FocusName'));
        } else {
            echo $this->Translate('Error: the server could not build the map/radar (no internet on the Symcon server?).');
        }
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $data = json_decode($this->GetBuffer('Data'), true);
        if (!is_array($data) && IPS_GetKernelRunlevel() === KR_READY
            && $this->ReadPropertyString('GemeindeARS') !== '') {
            $data = $this->BuildData();
            $this->SetBuffer('Data', json_encode($data));
        }
        if (!is_array($data)) {
            $data = ['base' => '', 'frames' => []];
        }
        return str_replace('/*INITIAL_DATA*/null', $this->Payload($data, false), $html);
    }

    private function BuildAndPush(bool $autoplay): ?array
    {
        if ($this->ReadPropertyString('GemeindeARS') === '') {
            return null;
        }
        $data = $this->BuildData();
        $this->SetBuffer('Data', json_encode($data));
        $this->UpdateVisualizationValue($this->Payload($data, $autoplay));
        return $data;
    }

    private function Payload(array $data, bool $autoplay): string
    {
        return json_encode([
            'base'       => $data['base'] ?? '',
            'frames'     => $data['frames'] ?? [],
            'play'       => max(5, $this->ReadPropertyInteger('PlayDuration')),
            'autoplay'   => $autoplay,
            'place'      => $this->ReadAttributeString('FocusName'),
            'configured' => $this->ReadPropertyString('GemeindeARS') !== '',
        ]);
    }

    // ---------------------------------------------------------------------

    /** Basiskarte (gecacht) + Vorhersage-Radar-Overlays ab der aktuellen Zeit. */
    private function BuildData(): array
    {
        $lat = $this->ReadAttributeFloat('Lat');
        $lon = $this->ReadAttributeFloat('Lon');
        if (($lat == 0.0 && $lon == 0.0) || !function_exists('imagecreatetruecolor')) {
            return ['base' => '', 'frames' => []];
        }

        $zoomMap = [1 => 13, 2 => 11, 3 => 9];
        $z = $zoomMap[$this->ReadPropertyInteger('Zoom')] ?? 11;
        $W = self::W;
        $H = self::H;

        [$gx, $gy] = $this->LonLatToGlobal($lat, $lon, $z);
        $left = $gx - $W / 2;
        $top  = $gy - $H / 2;

        // Basiskarte cachen (Schlüssel = Mitte + Zoom)
        $key = sprintf('%.4f|%.4f|%d', $lat, $lon, $z);
        $base = ($this->GetBuffer('BaseKey') === $key) ? $this->GetBuffer('Base') : '';
        if ($base === '') {
            $png = $this->BuildBaseMap($z, $left, $top, $W, $H);
            if ($png === null) {
                return ['base' => '', 'frames' => []];
            }
            $base = 'data:image/jpeg;base64,' . base64_encode($png);
            $this->SetBuffer('Base', $base);
            $this->SetBuffer('BaseKey', $key);
        }

        // Canvas-bbox (für DWD-Radar in EPSG:4326)
        [$lonL, $latT] = $this->GlobalToLonLat($left, $top, $z);
        [$lonR, $latB] = $this->GlobalToLonLat($left + $W, $top + $H, $z);
        $bbox = $this->Num($latB) . ',' . $this->Num($lonL) . ',' . $this->Num($latT) . ',' . $this->Num($lonR);

        // Vorhersage AB JETZT: aktueller 5-Minuten-Slot + N Schritte in die Zukunft.
        $steps = max(1, min(15, $this->ReadPropertyInteger('ForecastSteps')));
        $start = (int) (floor(time() / 300) * 300);

        $frames = [];
        for ($k = 0; $k <= $steps; $k++) {
            $t   = $start + $k * 300;
            $png = $this->FetchRadar($bbox, $W, $H, $t);
            if ($png !== null) {
                $frames[] = [
                    'ov'    => 'data:image/png;base64,' . base64_encode($png),
                    'label' => ($k === 0 ? ($this->Translate('now') . ' ' . date('H:i', $t)) : ('+' . ($k * 5) . ' min')),
                ];
            }
        }
        return ['base' => $base, 'frames' => $frames];
    }

    private function BuildBaseMap(int $z, float $left, float $top, int $W, int $H): ?string
    {
        $canvas = imagecreatetruecolor($W, $H);
        imagefilledrectangle($canvas, 0, 0, $W, $H, imagecolorallocate($canvas, 233, 239, 243));

        $tx0 = (int) floor($left / 256);
        $tx1 = (int) floor(($left + $W - 1) / 256);
        $ty0 = (int) floor($top / 256);
        $ty1 = (int) floor(($top + $H - 1) / 256);

        $any = false;
        for ($tx = $tx0; $tx <= $tx1; $tx++) {
            for ($ty = $ty0; $ty <= $ty1; $ty++) {
                $im = $this->FetchImage('https://tile.openstreetmap.org/' . $z . '/' . $tx . '/' . $ty . '.png');
                if ($im) {
                    imagecopy($canvas, $im, (int) round($tx * 256 - $left), (int) round($ty * 256 - $top), 0, 0, 256, 256);
                    $any = true;
                }
            }
        }
        if (!$any) {
            return null;
        }
        ob_start();
        imagejpeg($canvas, null, 80);
        return ob_get_clean();
    }

    /** Transparentes DWD-Vorhersageradar (PNG-Bytes) für einen Zeitschritt. */
    private function FetchRadar(string $bbox, int $W, int $H, int $timestamp): ?string
    {
        $params = [
            'service' => 'WMS', 'version' => '1.3.0', 'request' => 'GetMap',
            'layers' => self::RADAR, 'crs' => 'EPSG:4326',
            'bbox' => $bbox, 'width' => (string) $W, 'height' => (string) $H,
            'format' => 'image/png', 'transparent' => 'true',
            'time' => gmdate('Y-m-d\TH:i:00.000\Z', $timestamp),
        ];
        return $this->FetchRaw(self::WMS . '?' . http_build_query($params), true);
    }

    /** Holt rohe Bytes (optional nur Bilder). */
    private function FetchRaw(string $url, bool $imageOnly = false): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_USERAGENT      => self::UA,
        ]);
        $data = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        unset($ch);
        if ($data === false || $code < 200 || $code >= 300) {
            return null;
        }
        if ($imageOnly && stripos($type, 'image') === false) {
            return null;
        }
        return (string) $data;
    }

    private function FetchImage(string $url)
    {
        $data = $this->FetchRaw($url, true);
        if ($data === null) {
            return null;
        }
        $im = @imagecreatefromstring($data);
        return $im ?: null;
    }

    /** Geocoding eines Ortsteils via Nominatim; Plausibilität: nahe der Gemeinde. */
    private function Geocode(string $ortsteil, string $gemeinde, string $kreis, float $gemLat, float $gemLon): ?array
    {
        $queries = [
            $ortsteil . ', ' . $gemeinde . ', ' . $kreis . ', Deutschland',
            $ortsteil . ', ' . $gemeinde . ', Deutschland',
            $ortsteil . ', ' . $kreis . ', Deutschland',
        ];
        foreach ($queries as $q) {
            $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
                'format' => 'jsonv2', 'limit' => 1, 'countrycodes' => 'de', 'q' => $q,
            ]);
            $data = $this->FetchRaw($url, false);
            if ($data === null) {
                continue;
            }
            $json = json_decode($data, true);
            if (is_array($json) && isset($json[0]['lat'], $json[0]['lon'])) {
                $lat = (float) $json[0]['lat'];
                $lon = (float) $json[0]['lon'];
                // Plausibilität: max ~40 km von der Gemeinde entfernt (sonst falscher Treffer).
                if ($this->DistanceKm($lat, $lon, $gemLat, $gemLon) <= 40) {
                    return [$lat, $lon];
                }
            }
        }
        return null;
    }

    private function DistanceKm(float $la1, float $lo1, float $la2, float $lo2): float
    {
        $r = 6371;
        $dLa = deg2rad($la2 - $la1);
        $dLo = deg2rad($lo2 - $lo1);
        $a = sin($dLa / 2) ** 2 + cos(deg2rad($la1)) * cos(deg2rad($la2)) * sin($dLo / 2) ** 2;
        return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /** Entfernt Gemeinde-/Stadt-Präfixe (sonst findet Nominatim nichts). */
    private function CleanName(string $name): string
    {
        return trim(preg_replace('/^(Gemeinde|Stadt|Markt|Flecken|Kreisfreie Stadt|Landeshauptstadt|Hansestadt|Große Kreisstadt)\s+/u', '', $name));
    }

    private function LonLatToGlobal(float $lat, float $lon, int $z): array
    {
        $n  = pow(2, $z);
        $gx = ($lon + 180) / 360 * $n * 256;
        $lr = deg2rad($lat);
        $gy = (1 - log(tan($lr) + 1 / cos($lr)) / M_PI) / 2 * $n * 256;
        return [$gx, $gy];
    }

    private function GlobalToLonLat(float $px, float $py, int $z): array
    {
        $n   = pow(2, $z);
        $lon = $px / (256 * $n) * 360 - 180;
        $lat = rad2deg(atan(sinh(M_PI * (1 - 2 * $py / (256 * $n)))));
        return [$lon, $lat];
    }

    private function Num(float $v): string
    {
        return number_format($v, 4, '.', '');
    }
}
