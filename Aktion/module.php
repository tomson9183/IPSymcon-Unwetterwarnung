<?php

declare(strict_types=1);

/**
 * Unwetter Aktion – führt bei passenden Warnungen eine konfigurierbare Reaktion aus.
 *
 * Untergeordnete Instanz einer „Warnzentrale“. Definiert genau eine Regel:
 *   Bedingung (Kategorie + ab Warnstufe + optional Stichwort)
 *   → Reaktion (frei wählbare Symcon-Aktion, z. B. Rollladen schließen, Push, Szene).
 * Optional eine zweite Reaktion bei Entwarnung (alle passenden Warnungen vorbei).
 */
class UnwetterAktion extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->ConnectParent('{A4A4B36B-D66C-44D7-AEC3-11AB352331F5}');

        $this->RegisterPropertyString('Category', 'any');
        $this->RegisterPropertyInteger('MinSeverity', 3);
        $this->RegisterPropertyString('Keyword', '');
        $this->RegisterPropertyString('OnAlert', '{}');
        $this->RegisterPropertyBoolean('UseOnClear', false);
        $this->RegisterPropertyString('OnClear', '{}');

        $this->RegisterVariableBoolean('Bedingung', $this->Translate('Condition met'), '~Alert', 10);
        $this->RegisterVariableString('Letzte', $this->Translate('Last trigger'), '', 20);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if (!$this->HasActiveParent()) {
            $this->SetStatus(104);
        } else {
            $this->SetStatus(102);
        }

        // Eltern-Warnzentrale zum erneuten Senden anstoßen, damit diese Instanz
        // sofort aktuelle Warnungen erhält.
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $conn = (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
            if ($conn > 0 && function_exists('UWZ_Update')) {
                @UWZ_Update($conn);
            }
        }
    }

    /**
     * Empfängt die aktiven Warnungen von der Warnzentrale (Parent).
     */
    public function ReceiveData($JSONString)
    {
        $data = json_decode($JSONString, true);
        $warnings = is_array($data) ? ($data['Warnings'] ?? []) : [];
        $this->Evaluate(is_array($warnings) ? $warnings : []);
    }

    /**
     * Wertet die Bedingung aus und löst Reaktionen aus.
     */
    private function Evaluate(array $warnings): void
    {
        $cat       = $this->ReadPropertyString('Category');
        $minSev    = $this->ReadPropertyInteger('MinSeverity');
        $keyword   = trim($this->ReadPropertyString('Keyword'));

        $matched = [];
        foreach ($warnings as $w) {
            if ((int) ($w['severity'] ?? 0) < $minSev) {
                continue;
            }
            if ($cat !== 'any' && ($w['category'] ?? '') !== $cat) {
                continue;
            }
            if ($keyword !== '' && stripos((string) ($w['headline'] ?? ''), $keyword) === false) {
                continue;
            }
            $matched[(string) ($w['id'] ?? '')] = $w;
        }

        $fired = json_decode($this->GetBuffer('Fired'), true);
        if (!is_array($fired)) {
            $fired = [];
        }
        $wasActive = $this->GetBuffer('Active') === '1';

        // Neue passende Warnungen -> Alarm-Reaktion (einmal pro Warnung).
        foreach ($matched as $id => $w) {
            if (!isset($fired[$id])) {
                $this->RunStoredAction('OnAlert');
                $this->SetValue('Letzte', date('d.m.Y H:i') . ' – ' . (string) ($w['headline'] ?? ''));
                $this->LogMessage('Aktion ausgelöst: ' . (string) ($w['headline'] ?? ''), KL_NOTIFY);
            }
        }

        $isActive = count($matched) > 0;
        $this->SetValue('Bedingung', $isActive);

        // Entwarnung: vorher aktiv, jetzt nichts mehr passend.
        if ($wasActive && !$isActive && $this->ReadPropertyBoolean('UseOnClear')) {
            $this->RunStoredAction('OnClear');
            $this->SetValue('Letzte', date('d.m.Y H:i') . ' – ' . $this->Translate('all-clear'));
        }

        $this->SetBuffer('Fired', json_encode($matched ? array_fill_keys(array_keys($matched), true) : []));
        $this->SetBuffer('Active', $isActive ? '1' : '0');
    }

    /** Führt die in einer SelectAction-Property gespeicherte Symcon-Aktion aus. */
    private function RunStoredAction(string $property): void
    {
        $action = json_decode($this->ReadPropertyString($property), true);
        if (is_array($action) && (int) ($action['actionID'] ?? 0) > 0) {
            @IPS_RunAction($action['actionID'], $action['parameters'] ?? []);
        }
    }
}
