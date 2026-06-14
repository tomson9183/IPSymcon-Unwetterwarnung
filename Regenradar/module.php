<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/GemeindeData.php';

/**
 * Regenradar – DWD-Niederschlagsradar als animierte Kachel-Visualisierung.
 *
 * Nutzt den offenen DWD-GeoServer (maps.dwd.de, ohne Login). Der Radar-Layer ist
 * zeit-dimensioniert (5-Minuten-Schritte); die Kachel lädt mehrere Zeitschritte und
 * spielt sie als Schleife ab – so sieht man, wie die Regenwolken ziehen (wie in der
 * WarnWetter-App). Zentriert auf die gewählte Gemeinde.
 */
class UnwetterRegenradar extends IPSModule
{
    use GemeindeData;

    private const WMS = 'https://maps.dwd.de/geoserver/dwd/wms';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Suche', '');
        $this->RegisterPropertyString('Bundesland', '');
        $this->RegisterPropertyString('GemeindeARS', '');
        $this->RegisterPropertyInteger('Zoom', 2);       // 1=Stadt, 2=Region, 3=Land
        $this->RegisterPropertyInteger('Frames', 12);     // Anzahl Zeitschritte (à 5 min)
        $this->RegisterPropertyInteger('RefreshInterval', 300); // Sekunden

        $this->RegisterAttributeString('GemeindeName', '');
        $this->RegisterAttributeFloat('Lat', 0.0);
        $this->RegisterAttributeFloat('Lon', 0.0);

        $this->RegisterTimer('Refresh', 0, 'UWR_Refresh($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->SetVisualizationType(1);

        $ars = $this->ReadPropertyString('GemeindeARS');
        if ($ars === '') {
            $this->SetStatus(104);
            $this->SetTimerInterval('Refresh', 0);
            return;
        }
        $g = $this->GemeindeLookup($ars);
        if ($g !== null) {
            $this->WriteAttributeString('GemeindeName', (string) ($g['n'] ?? ''));
            $this->WriteAttributeFloat('Lat', (float) ($g['y'] ?? 0));
            $this->WriteAttributeFloat('Lon', (float) ($g['x'] ?? 0));
        }
        $this->SetStatus(102);

        $interval = max(60, $this->ReadPropertyInteger('RefreshInterval'));
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

    /** Bundesland-Auswahl -> Gemeinde-Liste füllen. */
    public function SetLand(string $Bundesland): void
    {
        $this->UpdateFormField('GemeindeARS', 'options', json_encode($this->GemeindeOptionsForLand($Bundesland)));
        $this->UpdateFormField('GemeindeARS', 'value', '');
    }

    /** Tastatur-Suche -> Gemeinde-Liste füllen. */
    public function Search(string $Suche): void
    {
        $this->UpdateFormField('GemeindeARS', 'options', json_encode($this->GemeindeOptionsBySearch($Suche)));
    }

    /** Aktualisiert die Kachel mit frischen Zeitschritten (neue Frame-URLs). */
    public function Refresh(): void
    {
        $this->UpdateVisualizationValue($this->BuildTilePayload());
    }

    /** Knopf „Radar jetzt laden / testen“ – prüft serverseitig einen Frame. */
    public function RefreshTest(): void
    {
        if ($this->ReadPropertyString('GemeindeARS') === '') {
            echo $this->Translate('Please select a municipality first.');
            return;
        }
        $frames = $this->BuildFrames();
        $url = $frames ? $frames[count($frames) - 1]['url'] : '';
        $png = $url !== '' ? $this->HttpGetBinary($url) : null;
        $this->UpdateVisualizationValue($this->BuildTilePayload());
        if ($png !== null) {
            echo sprintf($this->Translate('OK – radar loads (latest frame %d KB). Animation: %d frames.'),
                (int) round(strlen($png) / 1024), count($frames));
        } else {
            echo $this->Translate('Note: the server could not load the image, but the tile loads the frames directly in the browser. Test URL:') . "\n" . $url;
        }
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        return str_replace('/*INITIAL_DATA*/null', $this->BuildTilePayload(), $html);
    }

    private function BuildTilePayload(): string
    {
        return json_encode([
            'frames'     => $this->BuildFrames(),
            'place'      => $this->ReadAttributeString('GemeindeName'),
            'configured' => $this->ReadPropertyString('GemeindeARS') !== '',
        ]);
    }

    /**
     * Baut die Liste der Zeitschritt-Frames (älteste zuerst): je {url, label}.
     */
    private function BuildFrames(): array
    {
        $lat = $this->ReadAttributeFloat('Lat');
        $lon = $this->ReadAttributeFloat('Lon');
        if ($lat == 0.0 && $lon == 0.0) {
            return [];
        }

        $spanByZoom = [1 => 0.55, 2 => 1.30, 3 => 3.20];
        $latSpan = $spanByZoom[$this->ReadPropertyInteger('Zoom')] ?? 1.30;
        $cos     = max(0.3, cos(deg2rad($lat)));
        $lonSpan = $latSpan / $cos;

        $bbox = $this->Num($lat - $latSpan / 2) . ',' . $this->Num($lon - $lonSpan / 2)
            . ',' . $this->Num($lat + $latSpan / 2) . ',' . $this->Num($lon + $lonSpan / 2);

        $width  = 520;
        $height = (int) round($width * $cos);

        $base = [
            'service'     => 'WMS',
            'version'     => '1.3.0',
            'request'     => 'GetMap',
            'layers'      => 'dwd:Warngebiete_Kreise,dwd:Niederschlagsradar',
            'crs'         => 'EPSG:4326',
            'bbox'        => $bbox,
            'width'       => (string) $width,
            'height'      => (string) $height,
            'format'      => 'image/png',
            'transparent' => 'false',
            'bgcolor'     => '0xEAF1F5',
        ];

        $n = max(1, min(24, $this->ReadPropertyInteger('Frames')));
        // Letzter verfügbarer Zeitschritt: jetzt auf 5 min abrunden, 15 min Latenzpuffer.
        $end = (int) (floor(time() / 300) * 300) - 900;

        $frames = [];
        for ($k = $n - 1; $k >= 0; $k--) {
            $t = $end - $k * 300;
            $params = $base;
            $params['time'] = gmdate('Y-m-d\TH:i:00.000\Z', $t);
            $frames[] = [
                'url'   => self::WMS . '?' . http_build_query($params),
                'label' => date('H:i', $t),
            ];
        }
        return $frames;
    }

    /** Locale-sichere Zahl mit Punkt als Dezimaltrenner (gegen "47,6535"-Problem). */
    private function Num(float $v): string
    {
        return number_format($v, 4, '.', '');
    }

    /** Lädt eine URL als Binärdaten (nur für den Test-Knopf). */
    private function HttpGetBinary(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERAGENT      => 'IP-Symcon Regenradar Modul',
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        unset($ch);

        if ($body === false || $code < 200 || $code >= 300 || stripos($type, 'image') === false) {
            return null;
        }
        return (string) $body;
    }
}
