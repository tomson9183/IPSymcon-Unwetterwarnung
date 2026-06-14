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
    private const WZ_MODULE = '{A4A4B36B-D66C-44D7-AEC3-11AB352331F5}';

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('WarnzentraleID', 0);
        $this->RegisterPropertyString('Rules', '[]');

        $this->RegisterVariableString('Letzte', $this->Translate('Last trigger'), '', 10);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // Alte Nachrichten-Registrierungen entfernen.
        foreach ($this->GetMessageList() as $senderID => $messages) {
            foreach ($messages as $message) {
                $this->UnregisterMessage($senderID, $message);
            }
        }

        $wzID = $this->ResolveWarnzentrale();
        if ($wzID <= 0) {
            $this->SetStatus(104);
            return;
        }
        $this->SetStatus(102);

        // Auf Aktualisierungen der Warnzentrale lauschen (Variable „Letzte Aktualisierung“).
        $varID = @IPS_GetObjectIDByIdent('LetzteAktualisierung', $wzID);
        if ($varID) {
            $this->RegisterMessage($varID, VM_UPDATE);
        }

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->PullAndEvaluate();
        }
    }

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        // Falls genau EINE Warnzentrale existiert, im Feld vorbelegen.
        if ($this->ReadPropertyInteger('WarnzentraleID') === 0) {
            $list = IPS_GetInstanceListByModuleID(self::WZ_MODULE);
            if (count($list) === 1) {
                foreach ($form['elements'] as &$el) {
                    if (($el['name'] ?? '') === 'WarnzentraleID') {
                        $el['value'] = $list[0];
                    }
                }
                unset($el);
            }
        }
        return json_encode($form);
    }

    /** Reagiert auf Aktualisierungen der Warnzentrale. */
    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        if ($message === VM_UPDATE) {
            $this->PullAndEvaluate();
        }
    }

    public function RequestAction($Ident, $Value)
    {
        throw new Exception('Invalid Ident: ' . $Ident);
    }

    /**
     * Test: simuliert eine Warnung und löst die „Warnung“-Reaktion JEDER aktiven Regel
     * aus (unabhängig von der echten Bedingung), damit man die Reaktionen prüfen kann.
     */
    public function Test(): void
    {
        $rules = json_decode($this->ReadPropertyString('Rules'), true);
        if (!is_array($rules) || count($rules) === 0) {
            echo $this->Translate('No rules defined.');
            return;
        }
        $n = 0;
        foreach ($rules as $rule) {
            if (!($rule['Active'] ?? true)) {
                continue;
            }
            $cat = (string) ($rule['Category'] ?? 'any');
            $fake = [
                'id'       => 'TEST-' . time() . '-' . $n,
                'headline' => $this->Translate('TEST: simulated warning'),
                'provider' => 'TEST',
                'category' => $cat === 'any' ? 'weather' : $cat,
                'severity' => max(1, (int) ($rule['MinSeverity'] ?? 4)),
            ];
            $this->FireAlert($rule, $fake);
            $n++;
        }
        echo sprintf($this->Translate('%d rule(s) triggered (test).'), $n);
    }

    /** Test: löst die „Entwarnung“-Reaktion jeder aktiven Regel aus. */
    public function TestClear(): void
    {
        $rules = json_decode($this->ReadPropertyString('Rules'), true);
        if (!is_array($rules)) {
            $rules = [];
        }
        $n = 0;
        foreach ($rules as $rule) {
            if (!($rule['Active'] ?? true)) {
                continue;
            }
            $this->FireClear($rule);
            $n++;
        }
        // Zustände zurücksetzen, damit die nächste echte Warnung wieder auslöst.
        $this->SetBuffer('RuleState', json_encode([]));
        echo sprintf($this->Translate('%d rule(s) triggered (all-clear test).'), $n);
    }

    /** Ermittelt die zu überwachende Warnzentrale (gewählt oder die einzige vorhandene). */
    private function ResolveWarnzentrale(): int
    {
        $wzID = $this->ReadPropertyInteger('WarnzentraleID');
        if ($wzID > 0 && @IPS_InstanceExists($wzID)) {
            return $wzID;
        }
        $list = IPS_GetInstanceListByModuleID(self::WZ_MODULE);
        return count($list) === 1 ? (int) $list[0] : 0;
    }

    /** Holt die aktiven Warnungen von der Warnzentrale und wertet die Regeln aus. */
    private function PullAndEvaluate(): void
    {
        $wzID = $this->ResolveWarnzentrale();
        if ($wzID <= 0 || !function_exists('UWZ_GetWarningsJSON')) {
            return;
        }
        $json = @UWZ_GetWarningsJSON($wzID);
        $warnings = json_decode((string) $json, true);
        $this->Evaluate(is_array($warnings) ? $warnings : []);
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
