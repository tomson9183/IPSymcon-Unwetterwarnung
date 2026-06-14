<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/GemeindeData.php';

/**
 * Regenradar – animierte Regenradar-Kachel auf echter Karte.
 *
 * Zeigt eine OpenStreetMap-Karte (mit Orten/Ortsteilen) zentriert auf die gewählte
 * Gemeinde und darüber das animierte Niederschlagsradar (RainViewer, Verlauf der
 * letzten ~2 Stunden) – so sieht man, wie die Regenwolken ziehen, ähnlich der
 * WarnWetter-App. Karte und Radar werden direkt im Browser geladen.
 */
class UnwetterRegenradar extends IPSModule
{
    use GemeindeData;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Suche', '');
        $this->RegisterPropertyString('Bundesland', '');
        $this->RegisterPropertyString('GemeindeARS', '');
        $this->RegisterPropertyInteger('Zoom', 11); // Leaflet-Zoom: 9=weit … 13=nah
        $this->RegisterPropertyInteger('PlayDuration', 30); // Sekunden Abspieldauer je Knopfdruck

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

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->UpdateVisualizationValue($this->BuildTilePayload());
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

    /** Knopf „Vorschau aktualisieren“ (z. B. nach Gemeinde-Wechsel). */
    public function Reload(): void
    {
        $this->UpdateVisualizationValue($this->BuildTilePayload());
        echo $this->ReadPropertyString('GemeindeARS') === ''
            ? $this->Translate('Please select a municipality first.')
            : $this->Translate('Map updated.');
    }

    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        return str_replace('/*INITIAL_DATA*/null', $this->BuildTilePayload(), $html);
    }

    private function BuildTilePayload(): string
    {
        return json_encode([
            'lat'        => $this->ReadAttributeFloat('Lat'),
            'lon'        => $this->ReadAttributeFloat('Lon'),
            'zoom'       => $this->ReadPropertyInteger('Zoom'),
            'play'       => max(5, $this->ReadPropertyInteger('PlayDuration')),
            'place'      => $this->ReadAttributeString('GemeindeName'),
            'configured' => $this->ReadPropertyString('GemeindeARS') !== '',
        ]);
    }
}
