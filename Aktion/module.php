<?php

declare(strict_types=1);

/**
 * Unwetter Aktion – führt bei passenden Warnungen konfigurierbare Reaktionen aus.
 *
 * EINE Instanz, untergeordnet einer „Warnzentrale“. Enthält eine Tabelle mit
 * beliebig vielen Regeln. Jede Regel:
 *   Bedingung: Kategorie + ab Warnstufe + optional Stichwort
 *   Reaktion:  Zielvariable auf einen Wert setzen (z. B. Rollladen = 100) und/oder
 *              ein Skript ausführen. Optional ein „Entwarnung“-Wert, sobald keine
 *              passende Warnung mehr aktiv ist (z. B. Rollladen = 0).
 *
 * Reaktionen werden bei der Flanke ausgelöst (Bedingung wird erfüllt bzw. fällt
 * wieder weg), nicht bei jedem Abruf.
 */
class UnwetterAktion extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->ConnectParent('{A4A4B36B-D66C-44D7-AEC3-11AB352331F5}');

        $this->RegisterPropertyString('Rules', '[]');

        $this->RegisterVariableString('Letzte', $this->Translate('Last trigger'), '', 10);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->SetStatus($this->HasActiveParent() ? 102 : 104);

        // Eltern-Warnzentrale zum erneuten Senden anstoßen -> sofort aktuelle Daten.
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $conn = (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
            if ($conn > 0 && function_exists('UWZ_Update')) {
                @UWZ_Update($conn);
            }
        }
    }

    /** Empfängt die aktiven Warnungen von der Warnzentrale (Parent). */
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        $warnings = is_array($data) ? ($data['Warnings'] ?? []) : [];
        $this->Evaluate(is_array($warnings) ? $warnings : []);
    }

    /** Manuelles Auslösen zum Testen ist über die Warnzentrale (Update) abgedeckt. */
    public function RequestAction($Ident, $Value)
    {
        throw new Exception('Invalid Ident: ' . $Ident);
    }

    /**
     * Wertet alle Regeln gegen die aktuellen Warnungen aus (flankengesteuert).
     */
    private function Evaluate(array $warnings): void
    {
        $rules = json_decode($this->ReadPropertyString('Rules'), true);
        if (!is_array($rules)) {
            $rules = [];
        }

        $state = json_decode($this->GetBuffer('RuleState'), true);
        if (!is_array($state)) {
            $state = [];
        }

        $newState = [];
        foreach ($rules as $i => $rule) {
            $active = (bool) ($rule['Active'] ?? true);
            $minSev = (int) ($rule['MinSeverity'] ?? 1);
            $cat    = (string) ($rule['Category'] ?? 'any');
            $kw     = trim((string) ($rule['Keyword'] ?? ''));

            // passende Warnungen finden
            $match = null;
            if ($active) {
                foreach ($warnings as $w) {
                    if ((int) ($w['severity'] ?? 0) < $minSev) {
                        continue;
                    }
                    if ($cat !== 'any' && ($w['category'] ?? '') !== $cat) {
                        continue;
                    }
                    if ($kw !== '' && stripos((string) ($w['headline'] ?? ''), $kw) === false) {
                        continue;
                    }
                    // höchste passende Warnung merken
                    if ($match === null || (int) ($w['severity'] ?? 0) > (int) ($match['severity'] ?? 0)) {
                        $match = $w;
                    }
                }
            }

            $isOn  = $match !== null;
            $wasOn = (bool) ($state[$i] ?? false);

            if ($isOn && !$wasOn) {
                $this->FireAlert($rule, $match);
            } elseif (!$isOn && $wasOn) {
                $this->FireClear($rule);
            }

            $newState[$i] = $isOn;
        }

        $this->SetBuffer('RuleState', json_encode($newState));
    }

    /** Reaktion, wenn die Bedingung neu erfüllt ist. */
    private function FireAlert(array $rule, array $w): void
    {
        $varID = (int) ($rule['TargetVariable'] ?? 0);
        $valOn = (string) ($rule['ValueOn'] ?? '');
        if ($varID > 0 && $valOn !== '' && @IPS_VariableExists($varID)) {
            @RequestAction($varID, $this->Coerce($valOn));
        }

        $scriptID = (int) ($rule['ScriptID'] ?? 0);
        if ($scriptID > 0 && @IPS_ScriptExists($scriptID)) {
            @IPS_RunScriptEx($scriptID, [
                'SENDER'       => 'Unwetterwarnung',
                'INSTANCE'     => $this->InstanceID,
                'EVENT'        => 'alert',
                'WarnID'       => (string) ($w['id'] ?? ''),
                'Headline'     => (string) ($w['headline'] ?? ''),
                'Provider'     => (string) ($w['provider'] ?? ''),
                'Category'     => (string) ($w['category'] ?? ''),
                'Severity'     => (int) ($w['severity'] ?? 0),
            ]);
        }

        $this->SetValue('Letzte', date('d.m.Y H:i') . ' – ' . (string) ($w['headline'] ?? ''));
        $this->LogMessage('Aktion ausgelöst: ' . (string) ($w['headline'] ?? ''), KL_NOTIFY);
    }

    /** Reaktion bei Entwarnung (keine passende Warnung mehr). */
    private function FireClear(array $rule): void
    {
        $varID  = (int) ($rule['TargetVariable'] ?? 0);
        $valOff = (string) ($rule['ValueOff'] ?? '');
        if ($varID > 0 && $valOff !== '' && @IPS_VariableExists($varID)) {
            @RequestAction($varID, $this->Coerce($valOff));
        }

        $scriptID = (int) ($rule['ScriptID'] ?? 0);
        if ($scriptID > 0 && (string) ($rule['ValueOff'] ?? '') !== '' && @IPS_ScriptExists($scriptID)) {
            @IPS_RunScriptEx($scriptID, [
                'SENDER'   => 'Unwetterwarnung',
                'INSTANCE' => $this->InstanceID,
                'EVENT'    => 'clear',
            ]);
        }

        $this->SetValue('Letzte', date('d.m.Y H:i') . ' – ' . $this->Translate('all-clear'));
    }

    /** Wandelt den Text-Wert in den passenden Typ (bool / int / float / string). */
    private function Coerce(string $value)
    {
        $v = trim($value);
        $low = strtolower($v);
        if ($low === 'true' || $low === 'on' || $low === 'ein') {
            return true;
        }
        if ($low === 'false' || $low === 'off' || $low === 'aus') {
            return false;
        }
        if (is_numeric($v)) {
            return (strpos($v, '.') !== false) ? (float) $v : (int) $v;
        }
        return $v;
    }
}
