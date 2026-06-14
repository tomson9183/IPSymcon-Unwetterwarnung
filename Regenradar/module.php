<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/GemeindeData.php';

/**
 * Regenradar – DWD-Niederschlagsradar als Kachel-Visualisierung.
 *
 * Nutzt den offenen DWD-GeoServer (maps.dwd.de, ohne Login) und zeigt das
 * Niederschlagsradar zentriert auf die gewählte Gemeinde. Das Radarbild wird
 * SERVERSEITIG geladen und als Data-URI in die Kachel eingebettet (zuverlässig
 * auch dort, wo das Tile externe Bild-URLs nicht laden darf). Aktualisierung per
 * Timer (DWD liefert ca. alle 5 Minuten ein neues Bild).
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
        $this->RegisterPropertyInteger('Zoom', 2); // 1=Stadt, 2=Region, 3=Land
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

    /** Wird bei Auswahl eines Bundeslandes aufgerufen und füllt die Gemeinde-Liste. */
    public function SetLand(string $Bundesland): void
    {
        $this->UpdateFormField('GemeindeARS', 'options', json_encode($this->GemeindeOptionsForLand($Bundesland)));
        $this->UpdateFormField('GemeindeARS', 'value', '');
    }

    /** Tastatur-Suche: füllt die Gemeinde-Liste mit den Treffern zum Suchtext. */
    public function Search(string $Suche): void
    {
        $this->UpdateFormField('GemeindeARS', 'options', json_encode($this->GemeindeOptionsBySearch($Suche)));
    }

    /**
     * Lädt das aktuelle Radarbild vom DWD (serverseitig) und schiebt es in die Kachel.
     * Schlägt der Server-Abruf fehl, nutzt die Kachel die externe URL als Fallback.
     */
    public function Refresh(): void
    {
        $url = $this->BuildRadarURL();
        if ($url === '') {
            return;
        }
        $png = $this->HttpGetBinary($url);
        if ($png !== null) {
            $this->SetBuffer('Img', 'data:image/png;base64,' . base64_encode($png));
            $this->SetBuffer('ImgTime', date('H:i'));
        }
        $this->UpdateVisualizationValue($this->BuildTilePayload());
    }

    /** Knopf „Radar jetzt laden / testen“ mit Klartext-Rückmeldung. */
    public function RefreshTest(): void
    {
        if ($this->ReadPropertyString('GemeindeARS') === '') {
            echo $this->Translate('Please select a municipality first.');
            return;
        }
        $url = $this->BuildRadarURL();
        $png = $this->HttpGetBinary($url);
        if ($png !== null) {
            $this->SetBuffer('Img', 'data:image/png;base64,' . base64_encode($png));
            $this->SetBuffer('ImgTime', date('H:i'));
            $this->UpdateVisualizationValue($this->BuildTilePayload());
            echo sprintf($this->Translate('OK – radar image loaded (%d KB).'), (int) round(strlen($png) / 1024));
        } else {
            $this->UpdateVisualizationValue($this->BuildTilePayload());
            echo $this->Translate('Server could not load the image — the tile falls back to the direct DWD URL. Test URL:') . "\n" . $url;
        }
    }

    private function BuildTilePayload(): string
    {
        return json_encode([
            'img'        => $this->GetBuffer('Img'),
            'url'        => $this->BuildRadarURL(),
            'place'      => $this->ReadAttributeString('GemeindeName'),
            'configured' => $this->ReadPropertyString('GemeindeARS') !== '',
            'updated'    => $this->GetBuffer('ImgTime'),
        ]);
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        // Beim ersten Öffnen serverseitig ein Bild versuchen (Fallback-URL ist ohnehin dabei).
        if ($this->GetBuffer('Img') === '' && IPS_GetKernelRunlevel() === KR_READY
            && $this->ReadPropertyString('GemeindeARS') !== '') {
            $this->Refresh();
        }
        return str_replace('/*INITIAL_DATA*/null', $this->BuildTilePayload(), $html);
    }

    /**
     * Baut die WMS-GetMap-URL des DWD-GeoServers, zentriert auf die Gemeinde.
     * Layer-Reihenfolge: Kreise (Landfläche + Grenzen) unten, Radar oben.
     */
    private function BuildRadarURL(): string
    {
        $lat = $this->ReadAttributeFloat('Lat');
        $lon = $this->ReadAttributeFloat('Lon');
        if ($lat == 0.0 && $lon == 0.0) {
            return '';
        }

        $spanByZoom = [1 => 0.55, 2 => 1.30, 3 => 3.20];
        $latSpan = $spanByZoom[$this->ReadPropertyInteger('Zoom')] ?? 1.30;

        $cos     = max(0.3, cos(deg2rad($lat)));
        $lonSpan = $latSpan / $cos;

        $minLat = $lat - $latSpan / 2;
        $maxLat = $lat + $latSpan / 2;
        $minLon = $lon - $lonSpan / 2;
        $maxLon = $lon + $lonSpan / 2;

        $width  = 760;
        $height = (int) round($width * $cos);

        $params = [
            'service'     => 'WMS',
            'version'     => '1.3.0',
            'request'     => 'GetMap',
            'layers'      => 'dwd:Warngebiete_Kreise,dwd:Niederschlagsradar',
            'crs'         => 'EPSG:4326',
            'bbox'        => sprintf('%.4f,%.4f,%.4f,%.4f', $minLat, $minLon, $maxLat, $maxLon),
            'width'       => (string) $width,
            'height'      => (string) $height,
            'format'      => 'image/png',
            'transparent' => 'false',
            'bgcolor'     => '0xEAF1F5',
        ];
        return self::WMS . '?' . http_build_query($params);
    }

    /** Lädt eine URL als Binärdaten (für das Radarbild). */
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
        $err  = curl_error($ch);
        unset($ch);

        if ($body === false || $err !== '' || $code < 200 || $code >= 300) {
            $this->SendDebug('Radar', 'Fehler HTTP ' . $code . ' ' . $err, 0);
            return null;
        }
        if (stripos($type, 'image') === false) {
            $this->SendDebug('Radar', 'Keine Bilddaten (' . $type . '): ' . substr((string) $body, 0, 200), 0);
            return null;
        }
        return (string) $body;
    }
}
