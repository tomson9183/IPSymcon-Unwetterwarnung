<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/GemeindeData.php';

/**
 * Regenradar – schöne Karte mit Ortsnamen + DWD-Niederschlagsradar als Animation.
 *
 * Der Symcon-SERVER setzt die Kachelbilder selbst zusammen und bettet sie ein
 * (das Anzeigegerät braucht KEINEN externen Internetzugriff):
 *   - OpenStreetMap-Kartenkacheln (mit Orts-/Straßennamen) als Basiskarte
 *   - DWD-Niederschlagsradar (maps.dwd.de, 1 km, zeit-dimensioniert) deckungsgleich
 *     darüber – mehrere Zeitschritte ergeben die Animation (wie WarnWetter-App).
 * Zentriert auf die Gemeinde oder – wenn angegeben – auf einen per Geocoding
 * ermittelten Ortsteil. Abspielen per ▶-Knopf für eine einstellbare Dauer.
 */
class UnwetterRegenradar extends IPSModule
{
    use GemeindeData;

    private const WMS = 'https://maps.dwd.de/geoserver/dwd/wms';
    private const UA  = 'IP-Symcon Unwetter & Gefahren (github.com/tomson9183)';
    private const W   = 540;
    private const H   = 380;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Suche', '');
        $this->RegisterPropertyString('Bundesland', '');
        $this->RegisterPropertyString('GemeindeARS', '');
        $this->RegisterPropertyString('Ortsteil', '');     // optionaler Fokus innerhalb der Gemeinde
        $this->RegisterPropertyInteger('Zoom', 2);          // 1=Ortsteil/Stadt(13) 2=Gemeinde(11) 3=Region(9)
        $this->RegisterPropertyInteger('Frames', 6);        // Zeitschritte (à 5 min)
        $this->RegisterPropertyInteger('PlayDuration', 30); // Abspieldauer (s)
        $this->RegisterPropertyInteger('RefreshInterval', 600);

        $this->RegisterAttributeFloat('Lat', 0.0);
        $this->RegisterAttributeFloat('Lon', 0.0);
        $this->RegisterAttributeString('FocusName', '');

        $this->RegisterTimer('Refresh', 0, 'UWR_Refresh($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->SetVisualizationType(1);
        $this->SetBuffer('BaseKey', ''); // Basiskarte bei Konfig-Änderung neu bauen

        $ars = $this->ReadPropertyString('GemeindeARS');
        if ($ars === '') {
            $this->SetStatus(104);
            $this->SetTimerInterval('Refresh', 0);
            return;
        }

        $g = $this->GemeindeLookup($ars);
        $lat = $g ? (float) ($g['y'] ?? 0) : 0.0;
        $lon = $g ? (float) ($g['x'] ?? 0) : 0.0;
        $focus = $g ? (string) ($g['n'] ?? '') : '';

        // Optional: Ortsteil per Geocoding als Fokus.
        $ortsteil = trim($this->ReadPropertyString('Ortsteil'));
        if ($ortsteil !== '' && $g) {
            $geo = $this->Geocode($ortsteil, (string) ($g['n'] ?? ''), (string) ($g['k'] ?? ''));
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

        $interval = max(120, $this->ReadPropertyInteger('RefreshInterval'));
        $this->SetTimerInterval('Refresh', $interval * 1000);

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->Refresh();
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

    public function Refresh(): void
    {
        if ($this->ReadPropertyString('GemeindeARS') === '') {
            return;
        }
        $frames = $this->LoadFrames();
        $this->SetBuffer('Frames', json_encode($frames));
        $this->UpdateVisualizationValue($this->BuildTilePayload($frames));
    }

    public function RefreshTest(): void
    {
        if ($this->ReadPropertyString('GemeindeARS') === '') {
            echo $this->Translate('Please select a municipality first.');
            return;
        }
        $frames = $this->LoadFrames();
        $this->SetBuffer('Frames', json_encode($frames));
        $this->UpdateVisualizationValue($this->BuildTilePayload($frames));
        if (count($frames) > 0) {
            echo sprintf($this->Translate('OK – map + %d radar frames built (server-side). Focus: %s'),
                count($frames), $this->ReadAttributeString('FocusName'));
        } else {
            echo $this->Translate('Error: the server could not build the map/radar (no internet on the Symcon server?).');
        }
    }

    public function GetVisualizationTile()
    {
        $html   = file_get_contents(__DIR__ . '/module.html');
        $frames = json_decode($this->GetBuffer('Frames'), true);
        if (!is_array($frames)) {
            $frames = [];
        }
        if (count($frames) === 0 && IPS_GetKernelRunlevel() === KR_READY
            && $this->ReadPropertyString('GemeindeARS') !== '') {
            $frames = $this->LoadFrames();
            $this->SetBuffer('Frames', json_encode($frames));
        }
        return str_replace('/*INITIAL_DATA*/null', $this->BuildTilePayload($frames), $html);
    }

    private function BuildTilePayload(array $frames): string
    {
        return json_encode([
            'frames'     => $frames,
            'play'       => max(5, $this->ReadPropertyInteger('PlayDuration')),
            'place'      => $this->ReadAttributeString('FocusName'),
            'configured' => $this->ReadPropertyString('GemeindeARS') !== '',
        ]);
    }

    // ---------------------------------------------------------------------
    //  Kartenaufbau (serverseitig)
    // ---------------------------------------------------------------------

    /** Baut Basiskarte (einmal, gecacht) + je Zeitschritt das Radar-Overlay (JPEG-data-URI). */
    private function LoadFrames(): array
    {
        $lat = $this->ReadAttributeFloat('Lat');
        $lon = $this->ReadAttributeFloat('Lon');
        if ($lat == 0.0 && $lon == 0.0 || !function_exists('imagecreatetruecolor')) {
            return [];
        }

        $zoomMap = [1 => 13, 2 => 11, 3 => 9];
        $z = $zoomMap[$this->ReadPropertyInteger('Zoom')] ?? 11;
        $W = self::W;
        $H = self::H;

        [$gx, $gy] = $this->LonLatToGlobal($lat, $lon, $z);
        $left = $gx - $W / 2;
        $top  = $gy - $H / 2;

        // Basiskarte (OSM-Mosaik) bauen oder aus Cache holen
        $key  = sprintf('%.4f|%.4f|%d', $lat, $lon, $z);
        $baseB64 = '';
        if ($this->GetBuffer('BaseKey') === $key) {
            $baseB64 = $this->GetBuffer('BaseImg');
        }
        if ($baseB64 === '') {
            $basePng = $this->BuildBaseMap($z, $left, $top, $W, $H);
            if ($basePng === null) {
                return [];
            }
            $baseB64 = base64_encode($basePng);
            $this->SetBuffer('BaseImg', $baseB64);
            $this->SetBuffer('BaseKey', $key);
        }

        // Geo-bbox des Canvas (für DWD-Radar-Anfrage)
        [$lonL, $latT] = $this->GlobalToLonLat($left, $top, $z);
        [$lonR, $latB] = $this->GlobalToLonLat($left + $W, $top + $H, $z);
        $bbox = $this->Num($latB) . ',' . $this->Num($lonL) . ',' . $this->Num($latT) . ',' . $this->Num($lonR);

        $n   = max(1, min(16, $this->ReadPropertyInteger('Frames')));
        $end = (int) (floor(time() / 300) * 300) - 900;

        $frames = [];
        for ($k = $n - 1; $k >= 0; $k--) {
            $t   = $end - $k * 300;
            $jpg = $this->ComposeFrame($baseB64, $bbox, $W, $H, $t);
            if ($jpg !== null) {
                $frames[] = [
                    'img'   => 'data:image/jpeg;base64,' . base64_encode($jpg),
                    'label' => date('H:i', $t),
                ];
            }
        }
        return $frames;
    }

    /** OSM-Kachelmosaik als PNG-Bytes. */
    private function BuildBaseMap(int $z, float $left, float $top, int $W, int $H): ?string
    {
        $canvas = imagecreatetruecolor($W, $H);
        $bg = imagecolorallocate($canvas, 233, 239, 243);
        imagefilledrectangle($canvas, 0, 0, $W, $H, $bg);

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
        imagepng($canvas);
        return ob_get_clean();
    }

    /** Basiskarte + DWD-Radar (transparent) eines Zeitschritts -> JPEG-Bytes. */
    private function ComposeFrame(string $baseB64, string $bbox, int $W, int $H, int $timestamp): ?string
    {
        $base = @imagecreatefromstring(base64_decode($baseB64));
        if (!$base) {
            return null;
        }
        $params = [
            'service' => 'WMS', 'version' => '1.3.0', 'request' => 'GetMap',
            'layers' => 'dwd:Niederschlagsradar', 'crs' => 'EPSG:4326',
            'bbox' => $bbox, 'width' => (string) $W, 'height' => (string) $H,
            'format' => 'image/png', 'transparent' => 'true',
            'time' => gmdate('Y-m-d\TH:i:00.000\Z', $timestamp),
        ];
        $radar = $this->FetchImage(self::WMS . '?' . http_build_query($params));
        if ($radar) {
            imagealphablending($base, true);
            imagecopy($base, $radar, 0, 0, 0, 0, $W, $H);
        }
        ob_start();
        imagejpeg($base, null, 78);
        return ob_get_clean();
    }

    /** Lädt eine URL und gibt ein GD-Bild zurück (oder null). */
    private function FetchImage(string $url)
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
        unset($ch);
        if ($data === false || $code < 200 || $code >= 300) {
            return null;
        }
        $im = @imagecreatefromstring((string) $data);
        return $im ?: null;
    }

    /** Geocoding eines Ortsteils via Nominatim (OSM). */
    private function Geocode(string $ortsteil, string $gemeinde, string $kreis): ?array
    {
        $q = $ortsteil . ', ' . $gemeinde . ', ' . $kreis . ', Deutschland';
        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'format' => 'jsonv2', 'limit' => 1, 'countrycodes' => 'de', 'q' => $q,
        ]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_USERAGENT      => self::UA,
        ]);
        $data = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        if ($data === false || $code !== 200) {
            return null;
        }
        $json = json_decode((string) $data, true);
        if (is_array($json) && isset($json[0]['lat'], $json[0]['lon'])) {
            return [(float) $json[0]['lat'], (float) $json[0]['lon']];
        }
        return null;
    }

    // --- Slippy-Map-Mathematik (Web Mercator) ---

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

    /** Locale-sichere Zahl mit Punkt (gegen "47,6535"-Problem). */
    private function Num(float $v): string
    {
        return number_format($v, 4, '.', '');
    }
}
