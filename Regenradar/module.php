<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/GemeindeData.php';

/**
 * Regenradar – DWD-Niederschlagsradar als Kachel-Visualisierung.
 *
 * Nutzt den offenen DWD-GeoServer (maps.dwd.de, ohne Login) und zeigt das
 * Niederschlagsradar zentriert auf die gewählte Gemeinde. Aktualisiert sich
 * automatisch (DWD liefert ca. alle 5 Minuten ein neues Radarbild).
 */
class UnwetterRegenradar extends IPSModule
{
    use GemeindeData;

    private const WMS = 'https://maps.dwd.de/geoserver/dwd/wms';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('GemeindeARS', '');
        $this->RegisterPropertyInteger('Zoom', 2); // 1=Stadt, 2=Region, 3=Land
        $this->RegisterPropertyInteger('RefreshInterval', 300); // Sekunden

        $this->RegisterAttributeString('GemeindeName', '');
        $this->RegisterAttributeFloat('Lat', 0.0);
        $this->RegisterAttributeFloat('Lon', 0.0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->SetVisualizationType(1);

        $ars = $this->ReadPropertyString('GemeindeARS');
        if ($ars === '') {
            $this->SetStatus(104);
            return;
        }
        $g = $this->GemeindeLookup($ars);
        if ($g !== null) {
            $this->WriteAttributeString('GemeindeName', (string) ($g['n'] ?? ''));
            $this->WriteAttributeFloat('Lat', (float) ($g['y'] ?? 0));
            $this->WriteAttributeFloat('Lon', (float) ($g['x'] ?? 0));
        }
        $this->SetStatus(102);
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $form = $this->InjectGemeindeOptions($form, 'GemeindeARS');
        return json_encode($form);
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $data = json_encode([
            'url'     => $this->BuildRadarURL(),
            'place'   => $this->ReadAttributeString('GemeindeName'),
            'refresh' => max(60, $this->ReadPropertyInteger('RefreshInterval')),
        ]);
        return str_replace('/*INITIAL_DATA*/null', $data, $html);
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

        // Halbe Breitenspanne je Zoomstufe (Grad).
        $spanByZoom = [1 => 0.55, 2 => 1.30, 3 => 3.20];
        $latSpan = $spanByZoom[$this->ReadPropertyInteger('Zoom')] ?? 1.30;

        // Längenspanne so wählen, dass die Geometrie unverzerrt bleibt.
        $cos     = max(0.3, cos(deg2rad($lat)));
        $lonSpan = $latSpan / $cos;

        $minLat = $lat - $latSpan / 2;
        $maxLat = $lat + $latSpan / 2;
        $minLon = $lon - $lonSpan / 2;
        $maxLon = $lon + $lonSpan / 2;

        // Bildgröße passend zum Seitenverhältnis (height = width * cos(lat)).
        $width  = 760;
        $height = (int) round($width * $cos);

        $params = [
            'service'     => 'WMS',
            'version'     => '1.3.0',
            'request'     => 'GetMap',
            'layers'      => 'dwd:Warngebiete_Kreise,dwd:Niederschlagsradar',
            'crs'         => 'EPSG:4326',
            // WMS 1.3.0 / EPSG:4326 -> Achsenreihenfolge lat,lon
            'bbox'        => sprintf('%.4f,%.4f,%.4f,%.4f', $minLat, $minLon, $maxLat, $maxLon),
            'width'       => (string) $width,
            'height'      => (string) $height,
            'format'      => 'image/png',
            'transparent' => 'false',
            'bgcolor'     => '0xEAF1F5',
        ];
        return self::WMS . '?' . http_build_query($params);
    }
}
