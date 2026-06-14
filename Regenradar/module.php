<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/GemeindeData.php';

/**
 * Regenradar – animiertes DWD-Niederschlagsradar als Kachel.
 *
 * Die Radarbilder werden SERVERSEITIG vom offenen DWD-GeoServer (maps.dwd.de) geladen
 * und als Data-URI direkt in die Kachel eingebettet. Dadurch funktioniert die Anzeige
 * auch dort, wo das Anzeigegerät selbst keine externen Inhalte laden darf. Der Radar-
 * Layer ist zeit-dimensioniert (5-Minuten-Schritte) – mehrere Schritte ergeben die
 * Animation (Verlauf), die per ▶-Knopf für eine einstellbare Dauer abgespielt wird.
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
        $this->RegisterPropertyInteger('Zoom', 2);          // 1=Stadt, 2=Region, 3=Land
        $this->RegisterPropertyInteger('Frames', 8);        // Zeitschritte (à 5 min)
        $this->RegisterPropertyInteger('PlayDuration', 30); // Abspieldauer (s)
        $this->RegisterPropertyInteger('RefreshInterval', 600); // Bilder neu laden (s)

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

    /**
     * Lädt alle Animations-Frames serverseitig und bettet sie in die Kachel ein.
     */
    public function Refresh(): void
    {
        if ($this->ReadPropertyString('GemeindeARS') === '') {
            return;
        }
        $frames = $this->LoadFrames();
        $this->SetBuffer('Frames', json_encode($frames));
        $this->SetBuffer('FramesTime', (string) time());
        $this->UpdateVisualizationValue($this->BuildTilePayload($frames));
    }

    /** Knopf „Radar jetzt laden / testen“ mit Klartext-Rückmeldung. */
    public function RefreshTest(): void
    {
        if ($this->ReadPropertyString('GemeindeARS') === '') {
            echo $this->Translate('Please select a municipality first.');
            return;
        }
        $frames = $this->LoadFrames();
        $this->SetBuffer('Frames', json_encode($frames));
        $this->SetBuffer('FramesTime', (string) time());
        $this->UpdateVisualizationValue($this->BuildTilePayload($frames));

        if (count($frames) > 0) {
            $kb = (int) round(strlen((string) ($frames[count($frames) - 1]['img'] ?? '')) / 1024);
            echo sprintf($this->Translate('OK – %d radar images loaded (server-side). Latest ~%d KB.'), count($frames), $kb);
        } else {
            echo $this->Translate('Error: the server could not load the radar images from the DWD. Test URL:') . "\n" . $this->FrameURL(time() - 900);
        }
    }

    public function GetVisualizationTile()
    {
        $html  = file_get_contents(__DIR__ . '/module.html');
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
            'frames'     => $frames, // [{img:data-uri, label:"HH:MM"}]
            'play'       => max(5, $this->ReadPropertyInteger('PlayDuration')),
            'place'      => $this->ReadAttributeString('GemeindeName'),
            'configured' => $this->ReadPropertyString('GemeindeARS') !== '',
        ]);
    }

    /** Lädt alle Zeitschritte als Data-URI (älteste zuerst). */
    private function LoadFrames(): array
    {
        $lat = $this->ReadAttributeFloat('Lat');
        $lon = $this->ReadAttributeFloat('Lon');
        if ($lat == 0.0 && $lon == 0.0) {
            return [];
        }
        $n   = max(1, min(16, $this->ReadPropertyInteger('Frames')));
        $end = (int) (floor(time() / 300) * 300) - 900; // 15 min Latenzpuffer

        $frames = [];
        for ($k = $n - 1; $k >= 0; $k--) {
            $t   = $end - $k * 300;
            $png = $this->HttpGetBinary($this->FrameURL($t));
            if ($png !== null) {
                $frames[] = [
                    'img'   => 'data:image/png;base64,' . base64_encode($png),
                    'label' => date('H:i', $t),
                ];
            }
        }
        return $frames;
    }

    /** WMS-GetMap-URL eines Zeitschritts (locale-sichere bbox mit Punkt!). */
    private function FrameURL(int $timestamp): string
    {
        $lat = $this->ReadAttributeFloat('Lat');
        $lon = $this->ReadAttributeFloat('Lon');

        $spanByZoom = [1 => 0.55, 2 => 1.30, 3 => 3.20];
        $latSpan = $spanByZoom[$this->ReadPropertyInteger('Zoom')] ?? 1.30;
        $cos     = max(0.3, cos(deg2rad($lat)));
        $lonSpan = $latSpan / $cos;

        $bbox = $this->Num($lat - $latSpan / 2) . ',' . $this->Num($lon - $lonSpan / 2)
            . ',' . $this->Num($lat + $latSpan / 2) . ',' . $this->Num($lon + $lonSpan / 2);

        $width  = 500;
        $height = (int) round($width * $cos);

        $params = [
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
            'time'        => gmdate('Y-m-d\TH:i:00.000\Z', $timestamp),
        ];
        return self::WMS . '?' . http_build_query($params);
    }

    /** Locale-sichere Zahl mit Punkt (gegen "47,6535"-Problem auf deutschem System). */
    private function Num(float $v): string
    {
        return number_format($v, 4, '.', '');
    }

    private function HttpGetBinary(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 25,
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
