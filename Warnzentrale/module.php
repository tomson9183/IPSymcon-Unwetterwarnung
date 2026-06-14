<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/NinaClient.php';
require_once __DIR__ . '/../libs/GemeindeData.php';

/**
 * Unwetter & Gefahren – Warnzentrale.
 *
 * Holt gemeinde-genau alle amtlichen Warnungen (DWD, MoWaS/KATWARN/BIWAPP,
 * Hochwasser, Polizei) über die offene NINA-API des Bundes und stellt sie als
 * Status-Variablen und Kachel-Visualisierung dar. Verteilt die aktiven Warnungen
 * zusätzlich an untergeordnete „Aktion“-Instanzen (Parent/Child).
 */
class UnwetterWarnzentrale extends IPSModule
{
    use NinaClient;
    use GemeindeData;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Bundesland', '');
        $this->RegisterPropertyString('GemeindeARS', '');
        $this->RegisterPropertyBoolean('GemeindeGenau', true);
        $this->RegisterPropertyBoolean('SourceWeather', true);
        $this->RegisterPropertyBoolean('SourceCivil', true);
        $this->RegisterPropertyBoolean('SourceFlood', true);
        $this->RegisterPropertyBoolean('SourcePolice', true);
        $this->RegisterPropertyInteger('MinSeverity', 1);
        $this->RegisterPropertyInteger('UpdateInterval', 600);

        $this->RegisterAttributeString('KreisARS', '');
        $this->RegisterAttributeString('GemeindeName', '');
        $this->RegisterAttributeFloat('Lat', 0.0);
        $this->RegisterAttributeFloat('Lon', 0.0);

        $this->RegisterProfiles();

        $this->RegisterVariableBoolean('WarnungAktiv', $this->Translate('Warning active'), '~Alert', 10);
        $this->RegisterVariableInteger('Warnstufe', $this->Translate('Warning level'), 'UWZ.Stufe', 20);
        $this->RegisterVariableInteger('Anzahl', $this->Translate('Number of warnings'), '', 30);
        $this->RegisterVariableBoolean('Wetter', $this->Translate('Weather'), '~Alert', 40);
        $this->RegisterVariableBoolean('Zivilschutz', $this->Translate('Civil protection'), '~Alert', 50);
        $this->RegisterVariableBoolean('Hochwasser', $this->Translate('Flood'), '~Alert', 60);
        $this->RegisterVariableBoolean('Polizei', $this->Translate('Police'), '~Alert', 70);
        $this->RegisterVariableString('Meldungen', $this->Translate('Messages'), '~HTMLBox', 80);
        $this->RegisterVariableInteger('LetzteAktualisierung', $this->Translate('Last update'), '~UnixTimestamp', 90);

        $this->RegisterTimer('Update', 0, 'UWZ_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();
        $this->SetVisualizationType(1);

        $ars = $this->ReadPropertyString('GemeindeARS');
        if ($ars === '') {
            $this->WriteAttributeString('KreisARS', '');
            $this->SetStatus(104);
            $this->SetTimerInterval('Update', 0);
            return;
        }

        // Gemeinde-Stammdaten (Koordinaten, Kreis) einmalig cachen.
        $g = $this->GemeindeLookup($ars);
        if ($g !== null) {
            $this->WriteAttributeString('KreisARS', $this->KreisARSFromGemeinde($ars));
            $this->WriteAttributeString('GemeindeName', (string) ($g['n'] ?? ''));
            $this->WriteAttributeFloat('Lat', (float) ($g['y'] ?? 0));
            $this->WriteAttributeFloat('Lon', (float) ($g['x'] ?? 0));
        }

        $interval = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('Update', $interval > 0 ? $interval * 1000 : 0);
        $this->SetStatus(102);

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->Update();
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

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Update') {
            $this->Update();
            return;
        }
        throw new Exception('Invalid Ident: ' . $Ident);
    }

    /**
     * Liefert die zuletzt ermittelten aktiven Warnungen als JSON (für Aktion-Childs).
     */
    public function GetWarningsJSON(): string
    {
        $b = $this->GetBuffer('Warnings');
        return $b === '' ? '[]' : $b;
    }

    /**
     * Hauptroutine: Warnungen abrufen, gemeinde-genau filtern, Variablen + Kachel
     * setzen, an Aktion-Childs verteilen.
     */
    public function Update(): void
    {
        $ars = $this->ReadPropertyString('GemeindeARS');
        if ($ars === '') {
            $this->SetStatus(104);
            return;
        }

        $kreisARS = $this->ReadAttributeString('KreisARS');
        if ($kreisARS === '') {
            $kreisARS = $this->KreisARSFromGemeinde($ars);
        }

        $raw = $this->NinaGetDashboard($kreisARS);
        if ($raw === null) {
            $this->SetStatus(201);
            return;
        }
        $this->SetStatus(102);

        $minSeverity = $this->ReadPropertyInteger('MinSeverity');
        $enabled     = $this->EnabledCategories();
        $gemeinde    = $this->ReadPropertyBoolean('GemeindeGenau');
        $lat         = $this->ReadAttributeFloat('Lat');
        $lon         = $this->ReadAttributeFloat('Lon');

        $warnings = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $w = $this->NinaNormalize($item);
            if (!in_array($w['category'], $enabled, true)) {
                continue;
            }
            if ($w['severity'] < $minSeverity) {
                continue;
            }
            // Gemeinde-genau: Punkt-in-Polygon gegen die Geometrie der Warnung.
            if ($gemeinde && $lat != 0.0 && $lon != 0.0) {
                $geo = $this->NinaGetWarningGeoJson($w['id']);
                if ($geo !== null && !$this->NinaPointInGeoJson($lat, $lon, $geo)) {
                    continue; // Gemeinde nicht betroffen
                }
                // Geometrie nicht abrufbar -> sicherheitshalber behalten.
            }
            $warnings[] = $w;
        }

        usort($warnings, fn ($a, $b) => $b['severity'] <=> $a['severity']);

        $maxLevel = 0;
        $cats     = ['weather' => false, 'civil' => false, 'flood' => false, 'police' => false];
        foreach ($warnings as $w) {
            $maxLevel = max($maxLevel, $w['severity']);
            $cats[$w['category']] = true;
        }

        $this->SetValue('WarnungAktiv', count($warnings) > 0);
        $this->SetValue('Warnstufe', $maxLevel);
        $this->SetValue('Anzahl', count($warnings));
        $this->SetValue('Wetter', $cats['weather']);
        $this->SetValue('Zivilschutz', $cats['civil']);
        $this->SetValue('Hochwasser', $cats['flood']);
        $this->SetValue('Polizei', $cats['police']);
        $this->SetValue('Meldungen', $this->BuildHtml($warnings));
        $this->SetValue('LetzteAktualisierung', time());

        // Kachel
        $tileData = $this->BuildTileData($warnings, $maxLevel);
        $this->SetBuffer('TileData', $tileData);
        $this->UpdateVisualizationValue($tileData);

        // Aktive Warnungen puffern – „Unwetter Aktionen“-Instanzen holen sie hier ab
        // (sie lauschen per Nachricht auf die Variable „Letzte Aktualisierung“).
        $this->SetBuffer('Warnings', json_encode($warnings));
    }

    public function GetVisualizationTile()
    {
        // Bewusst KEIN Update() hier (gemeinde-genau = mehrere HTTP-Abrufe).
        // Die Kachel zeigt den gepufferten Stand; der Timer hält ihn aktuell.
        $html = file_get_contents(__DIR__ . '/module.html');
        $data = $this->GetBuffer('TileData');
        if ($data === '') {
            $data = json_encode(['level' => 0, 'count' => 0, 'updated' => '', 'warnings' => []]);
        }
        return str_replace('/*INITIAL_DATA*/null', $data, $html);
    }

    private function EnabledCategories(): array
    {
        $list = [];
        if ($this->ReadPropertyBoolean('SourceWeather')) {
            $list[] = 'weather';
        }
        if ($this->ReadPropertyBoolean('SourceCivil')) {
            $list[] = 'civil';
        }
        if ($this->ReadPropertyBoolean('SourceFlood')) {
            $list[] = 'flood';
        }
        if ($this->ReadPropertyBoolean('SourcePolice')) {
            $list[] = 'police';
        }
        return $list;
    }

    private function BuildHtml(array $warnings): string
    {
        if (count($warnings) === 0) {
            return '<div style="padding:8px;color:#2ecc71;">'
                . $this->Translate('No current warnings.') . '</div>';
        }
        $html = '<div style="font-family:sans-serif;">';
        foreach ($warnings as $w) {
            $color = $this->LevelColor($w['severity']);
            $html .= '<div style="border-left:5px solid ' . $color . ';padding:6px 10px;margin:4px 0;background:rgba(0,0,0,0.03);">'
                . '<b>' . htmlspecialchars($w['headline']) . '</b><br>'
                . '<span style="font-size:0.85em;color:#888;">' . htmlspecialchars($w['provider'])
                . ' · ' . htmlspecialchars($this->Translate($this->CategoryLabel($w['category'])))
                . ' · ' . htmlspecialchars($this->LevelLabel($w['severity'])) . '</span>'
                . '</div>';
        }
        $html .= '</div>';
        return $html;
    }

    private function BuildTileData(array $warnings, int $maxLevel): string
    {
        $list = [];
        foreach ($warnings as $w) {
            $list[] = [
                'headline'  => $w['headline'],
                'provider'  => $w['provider'],
                'category'  => $w['category'],
                'severity'  => $w['severity'],
                'levelText' => $this->LevelLabel($w['severity']),
                'time'      => $this->FormatTime($w['sent']),
            ];
        }
        return json_encode([
            'level'     => $maxLevel,
            'levelText' => $this->LevelLabel($maxLevel),
            'count'     => count($warnings),
            'place'     => $this->ReadAttributeString('GemeindeName'),
            'updated'   => date('d.m.Y H:i'),
            'warnings'  => $list,
        ]);
    }

    private function FormatTime(string $iso): string
    {
        if ($iso === '') {
            return '';
        }
        $ts = strtotime($iso);
        return $ts ? date('d.m. H:i', $ts) : '';
    }

    private function LevelColor(int $level): string
    {
        return [0 => '#2ecc71', 1 => '#f1c40f', 2 => '#e67e22', 3 => '#e74c3c', 4 => '#8e44ad'][$level] ?? '#888888';
    }

    private function LevelLabel(int $level): string
    {
        $map = [0 => 'No warning', 1 => 'Information', 2 => 'Weather warning', 3 => 'Severe warning', 4 => 'Extreme danger'];
        return $this->Translate($map[$level] ?? 'Warning');
    }

    private function CategoryLabel(string $cat): string
    {
        return ['weather' => 'Weather', 'civil' => 'Civil protection', 'flood' => 'Flood', 'police' => 'Police'][$cat] ?? $cat;
    }

    private function RegisterProfiles(): void
    {
        if (!IPS_VariableProfileExists('UWZ.Stufe')) {
            IPS_CreateVariableProfile('UWZ.Stufe', VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileValues('UWZ.Stufe', 0, 4, 1);
        IPS_SetVariableProfileAssociation('UWZ.Stufe', 0, $this->Translate('No warning'), 'Ok', 0x2ECC71);
        IPS_SetVariableProfileAssociation('UWZ.Stufe', 1, $this->Translate('Information'), 'Information', 0xF1C40F);
        IPS_SetVariableProfileAssociation('UWZ.Stufe', 2, $this->Translate('Weather warning'), 'Cloud', 0xE67E22);
        IPS_SetVariableProfileAssociation('UWZ.Stufe', 3, $this->Translate('Severe warning'), 'Warning', 0xE74C3C);
        IPS_SetVariableProfileAssociation('UWZ.Stufe', 4, $this->Translate('Extreme danger'), 'Alert', 0x8E44AD);
    }
}
