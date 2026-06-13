<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/NinaClient.php';

/**
 * Unwetter & Gefahren – amtliche Warnungen für IP-Symcon.
 *
 * Fragt kreisgenau die offene Warn-API des Bundes (NINA / warnung.bund.de) ab und
 * vereint die Meldungen aus DWD (Wetter), MoWaS/KATWARN/BIWAPP (Zivilschutz),
 * LHP (Hochwasser) und Polizei. Stellt Status-Variablen und eine Kachel-
 * Visualisierung bereit und kann bei konfigurierbaren Bedingungen Skripte
 * ausführen (z. B. Rollläden schließen).
 */
class Unwetterwarnung extends IPSModule
{
    use NinaClient;

    public function Create()
    {
        parent::Create();

        // --- Konfiguration ---
        $this->RegisterPropertyString('ARS', '');
        $this->RegisterPropertyBoolean('SourceWeather', true);
        $this->RegisterPropertyBoolean('SourceCivil', true);
        $this->RegisterPropertyBoolean('SourceFlood', true);
        $this->RegisterPropertyBoolean('SourcePolice', true);
        $this->RegisterPropertyInteger('MinSeverity', 1); // 1=Minor … 4=Extreme
        $this->RegisterPropertyInteger('UpdateInterval', 600); // Sekunden
        $this->RegisterPropertyString('Rules', '[]');

        // --- Profile ---
        $this->RegisterProfiles();

        // --- Status-Variablen ---
        $this->RegisterVariableBoolean('WarnungAktiv', $this->Translate('Warning active'), '~Alert', 10);
        $this->RegisterVariableInteger('Warnstufe', $this->Translate('Warning level'), 'UWWARN.Stufe', 20);
        $this->RegisterVariableInteger('Anzahl', $this->Translate('Number of warnings'), '', 30);
        $this->RegisterVariableBoolean('Wetter', $this->Translate('Weather'), '~Alert', 40);
        $this->RegisterVariableBoolean('Zivilschutz', $this->Translate('Civil protection'), '~Alert', 50);
        $this->RegisterVariableBoolean('Hochwasser', $this->Translate('Flood'), '~Alert', 60);
        $this->RegisterVariableBoolean('Polizei', $this->Translate('Police'), '~Alert', 70);
        $this->RegisterVariableString('Meldungen', $this->Translate('Messages'), '~HTMLBox', 80);
        $this->RegisterVariableInteger('LetzteAktualisierung', $this->Translate('Last update'), '~UnixTimestamp', 90);

        $this->RegisterTimer('Update', 0, 'UWWARN_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();

        if ($this->ReadPropertyString('ARS') === '') {
            $this->SetStatus(104);
            $this->SetTimerInterval('Update', 0);
            return;
        }

        $interval = $this->ReadPropertyInteger('UpdateInterval');
        $this->SetTimerInterval('Update', $interval > 0 ? $interval * 1000 : 0);
        $this->SetStatus(102);

        // Direkt nach dem Speichern einmal abfragen (verzögert, damit die Instanz bereit ist).
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->Update();
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        // Kreis-Auswahlliste dynamisch aus der gebündelten Datendatei aufbauen.
        $options = [['caption' => $this->Translate('Please select…'), 'value' => '']];
        $kreise  = json_decode((string) @file_get_contents(__DIR__ . '/../libs/kreise.json'), true);
        if (is_array($kreise)) {
            foreach ($kreise as $k) {
                $options[] = [
                    'caption' => ($k['land'] ?? '') . ' – ' . ($k['name'] ?? ''),
                    'value'   => $k['ars'] ?? '',
                ];
            }
        }
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'ARS') {
                $element['options'] = $options;
            }
        }
        unset($element);

        return json_encode($form);
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
     * Hauptroutine: Warnungen abrufen, Variablen + Kachel aktualisieren, Regeln auslösen.
     */
    public function Update(): void
    {
        $ars = $this->ReadPropertyString('ARS');
        if ($ars === '') {
            $this->SetStatus(104);
            return;
        }

        $raw = $this->NinaGetDashboard($ars);
        if ($raw === null) {
            $this->SetStatus(201);
            return;
        }
        $this->SetStatus(102);

        $minSeverity = $this->ReadPropertyInteger('MinSeverity');
        $enabled     = $this->EnabledCategories();

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
            $warnings[] = $w;
        }

        // Nach Schwere absteigend sortieren.
        usort($warnings, fn ($a, $b) => $b['severity'] <=> $a['severity']);

        // --- Aggregate ---
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

        // --- Kachel-Visualisierung aktualisieren ---
        $tileData = $this->BuildTileData($warnings, $maxLevel);
        $this->SetBuffer('TileData', $tileData);
        $this->UpdateVisualizationValue($tileData);

        // --- Regeln auswerten ---
        $this->EvaluateRules($warnings);
    }

    /**
     * Kachel-Visualisierung (HTML-SDK).
     */
    public function GetVisualizationTile()
    {
        $html = file_get_contents(__DIR__ . '/module.html');
        $data = $this->GetBuffer('TileData');
        if ($data === '') {
            $data = json_encode(['level' => 0, 'count' => 0, 'updated' => '', 'warnings' => []]);
        }
        // Initialzustand in das HTML einsetzen.
        return str_replace('/*INITIAL_DATA*/null', $data, $html);
    }

    /** Liste der aktivierten Kategorien gemäß Konfiguration. */
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

    /**
     * Wertet die konfigurierten Aktions-Regeln aus und führt passende Skripte
     * genau einmal pro Warnung aus (solange die Warnung aktiv ist).
     */
    private function EvaluateRules(array $warnings): void
    {
        $rules = json_decode($this->ReadPropertyString('Rules'), true);
        if (!is_array($rules) || count($rules) === 0) {
            return;
        }

        $fired = json_decode($this->GetBuffer('FiredRules'), true);
        if (!is_array($fired)) {
            $fired = [];
        }

        $activeIds = array_column($warnings, 'id');
        $newFired  = [];

        foreach ($warnings as $w) {
            foreach ($rules as $index => $rule) {
                if (!($rule['Active'] ?? true)) {
                    continue;
                }
                if ($w['severity'] < (int) ($rule['MinSeverity'] ?? 1)) {
                    continue;
                }
                $cat = (string) ($rule['Category'] ?? 'any');
                if ($cat !== 'any' && $cat !== $w['category']) {
                    continue;
                }
                $keyword = trim((string) ($rule['Keyword'] ?? ''));
                if ($keyword !== '' && stripos($w['headline'], $keyword) === false) {
                    continue;
                }
                $scriptID = (int) ($rule['ScriptID'] ?? 0);

                $key = $w['id'] . '|' . $index;
                if (isset($fired[$key])) {
                    $newFired[$key] = true; // schon ausgelöst, Warnung noch aktiv -> merken
                    continue;
                }

                if ($scriptID > 0 && @IPS_ScriptExists($scriptID)) {
                    @IPS_RunScriptEx($scriptID, [
                        'SENDER'       => 'Unwetterwarnung',
                        'INSTANCE'     => $this->InstanceID,
                        'WarnID'       => $w['id'],
                        'Headline'     => $w['headline'],
                        'Provider'     => $w['provider'],
                        'Category'     => $w['category'],
                        'Severity'     => $w['severity'],
                        'SeverityText' => $w['severityText'],
                    ]);
                    $this->LogMessage(sprintf(
                        'Regel %d ausgelöst (%s, Stufe %d): %s',
                        $index, $w['provider'], $w['severity'], $w['headline']
                    ), KL_NOTIFY);
                }
                $newFired[$key] = true;
            }
        }

        // Nur Einträge behalten, deren Warnung noch aktiv ist (Aufräumen).
        $cleaned = [];
        foreach ($newFired as $key => $v) {
            $warnId = explode('|', $key)[0];
            if (in_array($warnId, $activeIds, true)) {
                $cleaned[$key] = true;
            }
        }
        $this->SetBuffer('FiredRules', json_encode($cleaned));
    }

    /** Baut die HTML-Darstellung für die ~HTMLBox-Statusvariable. */
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

    /** Erzeugt das JSON für die Kachel-Visualisierung. */
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
        $map = [
            0 => 'No warning',
            1 => 'Information',
            2 => 'Weather warning',
            3 => 'Severe warning',
            4 => 'Extreme danger',
        ];
        return $this->Translate($map[$level] ?? 'Warning');
    }

    private function CategoryLabel(string $cat): string
    {
        return ['weather' => 'Weather', 'civil' => 'Civil protection', 'flood' => 'Flood', 'police' => 'Police'][$cat] ?? $cat;
    }

    /** Legt die benötigten Variablenprofile an. */
    private function RegisterProfiles(): void
    {
        if (!IPS_VariableProfileExists('UWWARN.Stufe')) {
            IPS_CreateVariableProfile('UWWARN.Stufe', VARIABLETYPE_INTEGER);
        }
        IPS_SetVariableProfileValues('UWWARN.Stufe', 0, 4, 1);
        IPS_SetVariableProfileAssociation('UWWARN.Stufe', 0, $this->Translate('No warning'), 'Ok', 0x2ECC71);
        IPS_SetVariableProfileAssociation('UWWARN.Stufe', 1, $this->Translate('Information'), 'Information', 0xF1C40F);
        IPS_SetVariableProfileAssociation('UWWARN.Stufe', 2, $this->Translate('Weather warning'), 'Cloud', 0xE67E22);
        IPS_SetVariableProfileAssociation('UWWARN.Stufe', 3, $this->Translate('Severe warning'), 'Warning', 0xE74C3C);
        IPS_SetVariableProfileAssociation('UWWARN.Stufe', 4, $this->Translate('Extreme danger'), 'Alert', 0x8E44AD);
    }
}
