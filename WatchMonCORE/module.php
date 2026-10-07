<?php

/*
 Batrium WatchMon CORE - IP-Symcon Modul
 Wertet die UDP-Broadcasts (Port 18542) des WatchMon CORE aus.
 Format: Batrium "WatchMon Wifi UDP protocol sw1.0.30", geprueft an einem Mitschnitt.

 Ausgewertete Nachrichten:
   0x415A  Zellen Kurzstatus (je Paket bis zu 16 Zellen)
   0x3E33  Systemuebersicht (Min/Max/Avg Zellspannung, Temperaturen, Zellzahlen)
   0x5732  System Discovery (SoC, Shunt-Spannung, Strom, Betriebsstatus)
*/

class BatriumWatchMonCORE extends IPSModule
{
    private const PARENT_UDP = '{82347F20-F541-41E1-AC5B-A636FD3AE2D8}';
    private const MAX_NODE   = 250;
    private const MAX_BANKS  = 16;

    public function Create()
    {
        parent::Create();

        $this->ConnectParent(self::PARENT_UDP);

        $this->RegisterPropertyInteger('Banks', 4);
        $this->RegisterPropertyInteger('CellsPerBank', 16);
        $this->RegisterPropertyBoolean('CreateCellVars', true);
        $this->RegisterPropertyString('SystemID', '');
        $this->RegisterPropertyInteger('WatchdogSec', 30);
        $this->RegisterPropertyBoolean('Debug', false);

        $this->RegisterTimer('Watchdog', 0, 'BATWM_Watchdog($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->CreateProfiles();

        $banks    = $this->Banks();
        $cpb      = $this->CellsPerBank();
        $withCell = $this->ReadPropertyBoolean('CreateCellVars');

        // ---- Systemvariablen ----
        $this->RegisterVariableBoolean('Online', 'Verbindung', 'BATWM.Online', 1);
        $this->RegisterVariableString('SystemKennung', 'System-Kennung', '', 2);

        $this->RegisterVariableFloat('SoC', 'Ladezustand (SoC)', 'BATWM.SoC', 10);
        $this->RegisterVariableFloat('ShuntVolt', 'Batteriespannung (Shunt)', 'BATWM.Volt2', 11);
        $this->RegisterVariableFloat('ShuntAmp', 'Batteriestrom (+Laden / -Entladen)', 'BATWM.Amp', 12);
        $this->RegisterVariableFloat('ShuntWatt', 'Batterieleistung (+Laden / -Entladen)', 'BATWM.Watt', 13);
        $this->RegisterVariableInteger('ShuntStatus', 'Shunt Status', 'BATWM.ShuntStatus', 14);
        $this->RegisterVariableInteger('OpStatus', 'Systemstatus', 'BATWM.OpStatus', 15);
        $this->RegisterVariableBoolean('BattOk', 'Critical Battery OK', '', 16);
        $this->RegisterVariableInteger('ChargeRate', 'Laden erlaubt', 'BATWM.PowerRate', 17);
        $this->RegisterVariableInteger('DischargeRate', 'Entladen erlaubt', 'BATWM.PowerRate', 18);
        $this->RegisterVariableInteger('DeviceTime', 'Geraetezeit (UTC)', '~UnixTimestamp', 19);

        $this->RegisterVariableFloat('VMin', 'Zellspannung min', 'BATWM.Volt', 30);
        $this->RegisterVariableFloat('VMax', 'Zellspannung max', 'BATWM.Volt', 31);
        $this->RegisterVariableFloat('VAvg', 'Zellspannung Durchschnitt', 'BATWM.Volt', 32);
        $this->RegisterVariableInteger('VDiff', 'Zellspreizung', 'BATWM.mV', 33);
        $this->RegisterVariableInteger('VMinNode', 'Zelle mit Min-Spannung (Node)', '', 34);
        $this->RegisterVariableInteger('VMaxNode', 'Zelle mit Max-Spannung (Node)', '', 35);
        $this->RegisterVariableFloat('TMin', 'Zelltemperatur min', '~Temperature', 36);
        $this->RegisterVariableFloat('TMax', 'Zelltemperatur max', '~Temperature', 37);
        $this->RegisterVariableFloat('TAvg', 'Zelltemperatur Durchschnitt', '~Temperature', 38);
        $this->RegisterVariableInteger('CntInitBypass', 'Zellen ueber Start-Bypass', '', 40);
        $this->RegisterVariableInteger('CntFinalBypass', 'Zellen ueber End-Bypass', '', 41);
        $this->RegisterVariableInteger('CntBypass', 'Zellen im Bypass', '', 42);
        $this->RegisterVariableInteger('CntOverdue', 'Zellen ueberfaellig', '', 43);
        $this->RegisterVariableInteger('CntActive', 'Zellen aktiv', '', 44);
        $this->RegisterVariableInteger('CntSystem', 'Zellen im System', '', 45);

        // ---- Bank-Statistik ----
        for ($b = 1; $b <= self::MAX_BANKS; $b++) {
            $defs = [
                ['Sum', 'Float', 'BATWM.Volt', 'Summe Zellen'],
                ['Min', 'Float', 'BATWM.Volt', 'Zelle min'],
                ['Max', 'Float', 'BATWM.Volt', 'Zelle max'],
                ['Diff', 'Integer', 'BATWM.mV', 'Zellspreizung']
            ];
            foreach ($defs as $i => $d) {
                $ident = 'B' . $b . $d[0];
                if ($b <= $banks) {
                    $this->{'RegisterVariable' . $d[1]}($ident, sprintf('Bank %d %s', $b, $d[3]), $d[2], 100 + $b * 10 + $i);
                } else {
                    $this->DropVariable($ident);
                }
            }
        }

        // ---- Einzelzellen ----
        for ($node = 1; $node <= self::MAX_NODE; $node++) {
            $keep = $withCell && $node <= $banks * $cpb;
            $bank = intdiv($node - 1, $cpb) + 1;
            $cell = ($node - 1) % $cpb + 1;
            $pos  = 1000 + $node * 3;
            if ($keep) {
                $this->RegisterVariableFloat($this->Ident($node, 'V'), sprintf('B%d Z%02d Spannung', $bank, $cell), 'BATWM.Volt', $pos);
                $this->RegisterVariableFloat($this->Ident($node, 'T'), sprintf('B%d Z%02d Temperatur', $bank, $cell), '~Temperature', $pos + 1);
                $this->RegisterVariableInteger($this->Ident($node, 'S'), sprintf('B%d Z%02d Status', $bank, $cell), 'BATWM.CellStatus', $pos + 2);
            } else {
                foreach (['V', 'T', 'S'] as $k) {
                    $this->DropVariable($this->Ident($node, $k));
                }
            }
        }

        $this->SetTimerInterval('Watchdog', 10000);
        $this->SetStatus(102);
    }

    /**
     * Watchdog: setzt "Verbindung" auf false, wenn laenger keine Daten kamen.
     */
    public function Watchdog()
    {
        $last  = (int) $this->GetBuffer('LastRx');
        $limit = max(5, $this->ReadPropertyInteger('WatchdogSec'));
        $this->Put('Online', $last > 0 && (time() - $last) <= $limit);
    }

    /**
     * Datenempfang vom UDP Socket.
     */
    public function ReceiveData($JSONString)
    {
        $j = json_decode($JSONString, true);
        if (!is_array($j) || !isset($j['Buffer'])) {
            return '';
        }
        // IO-Daten sind UTF-8-kodiert -> zurueck in Rohbytes
        $d = mb_convert_encoding($j['Buffer'], 'ISO-8859-1', 'UTF-8');
        $len = strlen($d);

        // Kopf pruefen: ':' + Typ(2) + ',' + SystemID(4)
        if ($len < 12 || $d[0] !== ':' || $d[3] !== ',') {
            return '';
        }
        $type = ord($d[1]) | (ord($d[2]) << 8);
        if ($type !== 0x415A && $type !== 0x3E33 && $type !== 0x5732) {
            return '';
        }

        // optionaler System-Filter
        $sysId  = strtoupper(bin2hex(substr($d, 4, 4)));
        $filter = strtoupper(trim($this->ReadPropertyString('SystemID')));
        if ($filter !== '' && $filter !== $sysId) {
            return '';
        }

        if ($this->ReadPropertyBoolean('Debug')) {
            $this->SendDebug(sprintf('0x%04X (%d Byte)', $type, $len), bin2hex($d), 0);
        }

        // Verbindung / Zeitstempel
        $now = time();
        if ((int) $this->GetBuffer('LastRx') !== $now) {
            $this->SetBuffer('LastRx', (string) $now);
        }
        $this->Put('Online', true);
        $this->Put('SystemKennung', $sysId);

        switch ($type) {
            case 0x415A:
                $this->HandleCells($d, $len);
                break;
            case 0x3E33:
                $this->HandleRapid($d, $len);
                break;
            case 0x5732:
                $this->HandleDiscovery($d, $len);
                break;
        }
        return '';
    }

    // ------------------------------------------------------------------
    // Nachrichten
    // ------------------------------------------------------------------

    // 0x415A: Offset 8 = CMU-Port RX Node-ID, 9 = Anzahl Records, Records ab 12, je 11 Byte
    private function HandleCells(string $d, int $len): void
    {
        $recLen = 11;
        $count  = ord($d[9]);
        if ($len < 12 + $count * $recLen) {
            $this->Dbg("0x415A unvollstaendig: Laenge $len, Records $count");
            return;
        }
        $cpb      = $this->CellsPerBank();
        $maxNode  = $this->Banks() * $cpb;
        $withCell = $this->ReadPropertyBoolean('CreateCellVars');
        $bankV    = [];

        for ($i = 0; $i < $count; $i++) {
            $o    = 12 + $i * $recLen;
            $node = ord($d[$o]);
            if ($node < 1 || $node > $maxNode) {
                continue;
            }
            $vMax = $this->U16($d, $o + 4);
            $temp = ord($d[$o + 6]) - 40;
            $stat = ord($d[$o + 10]);

            if ($withCell) {
                $this->Put($this->Ident($node, 'V'), round($vMax / 1000, 3));
                $this->Put($this->Ident($node, 'T'), (float) $temp);
                $this->Put($this->Ident($node, 'S'), $stat);
            }
            $bankV[intdiv($node - 1, $cpb) + 1][$node] = $vMax;
        }

        // Bank-Statistik, sobald alle Zellen einer Bank im Paket waren
        foreach ($bankV as $bank => $cells) {
            if (count($cells) < $cpb) {
                continue;
            }
            $min = min($cells);
            $max = max($cells);
            $this->Put('B' . $bank . 'Sum', round(array_sum($cells) / 1000, 3));
            $this->Put('B' . $bank . 'Min', round($min / 1000, 3));
            $this->Put('B' . $bank . 'Max', round($max / 1000, 3));
            $this->Put('B' . $bank . 'Diff', $max - $min);
        }
    }

    // 0x3E33: Systemuebersicht
    private function HandleRapid(string $d, int $len): void
    {
        if ($len < 37) {
            $this->Dbg("0x3E33 zu kurz: $len");
            return;
        }
        $vMin = $this->U16($d, 8);
        $vMax = $this->U16($d, 10);
        $vAvg = $this->U16($d, 28);

        $this->Put('VMin', round($vMin / 1000, 3));
        $this->Put('VMax', round($vMax / 1000, 3));
        $this->Put('VAvg', round($vAvg / 1000, 3));
        $this->Put('VDiff', $vMax - $vMin);
        $this->Put('VMinNode', ord($d[12]));
        $this->Put('VMaxNode', ord($d[13]));
        $this->Put('TMin', (float) (ord($d[14]) - 40));
        $this->Put('TMax', (float) (ord($d[15]) - 40));
        $this->Put('TAvg', (float) (ord($d[30]) - 40));
        $this->Put('CntInitBypass', ord($d[31]));
        $this->Put('CntFinalBypass', ord($d[32]));
        $this->Put('CntBypass', ord($d[33]));
        $this->Put('CntOverdue', ord($d[34]));
        $this->Put('CntActive', ord($d[35]));
        $this->Put('CntSystem', ord($d[36]));
    }

    // 0x5732: System Discovery
    private function HandleDiscovery(string $d, int $len): void
    {
        if ($len < 50) {
            $this->Dbg("0x5732 zu kurz: $len");
            return;
        }
        $soc  = ord($d[41]) * 0.5 - 5;            // 0,5 %/Bit, Offset -5 %
        $volt = $this->U16($d, 42) / 100;         // Faktor 100 laut Shunt-Setup
        $f    = unpack('g', substr($d, 44, 4));   // mA, + Laden / - Entladen
        $amp  = $f[1] / 1000;
        $t    = unpack('V', substr($d, 20, 4));

        $this->Put('SoC', round($soc, 1));
        $this->Put('ShuntVolt', round($volt, 2));
        $this->Put('ShuntAmp', round($amp, 2));
        $this->Put('ShuntWatt', round($volt * $amp, 0));
        $this->Put('ShuntStatus', ord($d[48]));
        $this->Put('OpStatus', ord($d[24]));
        $this->Put('BattOk', ord($d[26]) === 1);
        $this->Put('ChargeRate', ord($d[27]));
        $this->Put('DischargeRate', ord($d[28]));
        $this->Put('DeviceTime', (int) $t[1]);
    }

    // ------------------------------------------------------------------
    // Hilfsfunktionen
    // ------------------------------------------------------------------

    private function U16(string $d, int $o): int
    {
        return ord($d[$o]) | (ord($d[$o + 1]) << 8);
    }

    private function Ident(int $node, string $kind): string
    {
        return sprintf('N%02d_%s', $node, $kind);
    }

    private function Banks(): int
    {
        return max(1, min(self::MAX_BANKS, $this->ReadPropertyInteger('Banks')));
    }

    private function CellsPerBank(): int
    {
        return max(1, min(32, $this->ReadPropertyInteger('CellsPerBank')));
    }

    private function Dbg(string $msg): void
    {
        if ($this->ReadPropertyBoolean('Debug')) {
            $this->SendDebug('Info', $msg, 0);
        }
    }

    // Wert nur schreiben, wenn er sich geaendert hat
    private function Put(string $ident, $value): void
    {
        $id = @$this->GetIDForIdent($ident);
        if (!$id) {
            return;
        }
        if (GetValue($id) != $value) {
            SetValue($id, $value);
        }
    }

    private function DropVariable(string $ident): void
    {
        if (@$this->GetIDForIdent($ident)) {
            $this->UnregisterVariable($ident);
        }
    }

    private function CreateProfiles(): void
    {
        $this->Profile('BATWM.Volt', 2, ' V', 3);
        $this->Profile('BATWM.Volt2', 2, ' V', 2);
        $this->Profile('BATWM.Amp', 2, ' A', 2);
        $this->Profile('BATWM.Watt', 2, ' W', 0);
        $this->Profile('BATWM.mV', 1, ' mV', 0);
        $this->Profile('BATWM.SoC', 2, ' %', 1, [], [0, 100, 0.5]);
        $this->Profile('BATWM.Online', 0, '', 0, [[false, 'Offline'], [true, 'Online']]);
        $this->Profile('BATWM.CellStatus', 1, '', 0, [
            [0, 'Keiner'], [1, 'Spannung zu hoch'], [2, 'Temperatur zu hoch'], [3, 'OK'],
            [4, 'Timeout'], [5, 'Spannung zu niedrig'], [6, 'Deaktiviert'], [7, 'Im Bypass'],
            [8, 'Bypass Start'], [9, 'Bypass Ende'], [10, 'Setup fehlt'], [11, 'Keine Konfig'],
            [12, 'Ausserhalb Limits'], [255, 'Undefiniert']
        ]);
        $this->Profile('BATWM.OpStatus', 1, '', 0, [
            [0, 'Timeout'], [1, 'Leerlauf'], [2, 'Laden'], [3, 'Entladen'], [4, 'Voll'],
            [5, 'Leer'], [6, 'Simulator'], [7, 'Critical (wartend)'], [8, 'Critical (aus)'],
            [9, 'MQTT offline'], [10, 'Auth Setup']
        ]);
        $this->Profile('BATWM.ShuntStatus', 1, '', 0, [
            [0, 'Timeout'], [1, 'Entladen'], [2, 'Leerlauf'], [4, 'Laden']
        ]);
        $this->Profile('BATWM.PowerRate', 1, '', 0, [[0, 'Aus'], [2, 'Begrenzt'], [4, 'Normal']]);
    }

    private function Profile(string $name, int $type, string $suffix, int $digits, array $assoc = [], array $range = []): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, $type);
        }
        IPS_SetVariableProfileText($name, '', $suffix);
        if ($type === 2) {
            IPS_SetVariableProfileDigits($name, $digits);
        }
        if (count($range) === 3) {
            IPS_SetVariableProfileValues($name, $range[0], $range[1], $range[2]);
        }
        foreach ($assoc as $a) {
            IPS_SetVariableProfileAssociation($name, $a[0], $a[1], '', -1);
        }
    }
}
