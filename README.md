# Batrium WatchMon CORE – IP-Symcon Modul

Wertet die UDP-Broadcasts (Port **18542**) des Batrium WatchMon CORE in IP-Symcon aus.

Protokoll: Batrium „WatchMon Wifi UDP protocol sw1.0.30“. Getestet wurde nur gegen einen
Mitschnitt (WatchMon CORE, ShuntMon 500A, 4 Bänke × 16 Zellen mit CellMate K9), nicht im
Dauerbetrieb.

## Funktionen

- Einzelzellen (je Node): Spannung, Temperatur, Status
- Bank-Statistik: Summe der Zellspannungen, Min, Max, Spreizung
- System: Zellspannung min/max/Durchschnitt, Spreizung, Zelltemperaturen, Anzahl Zellen
  (aktiv, im Bypass, überfällig, im System)
- Shunt: Ladezustand (SoC), Batteriespannung, Strom (+ Laden / – Entladen), Leistung,
  Shunt-Status
- Systemstatus, „Critical Battery OK“, Laden/Entladen erlaubt, Geräte-Uhrzeit (UTC)
- „Verbindung“ (online/offline per Watchdog) und „System-Kennung“

Ausgewertete Nachrichten:

| Typ | Inhalt |
|---|---|
| 0x415A | Zellen-Kurzstatus (bis zu 16 Zellen je Paket) |
| 0x3E33 | Systemübersicht |
| 0x5732 | System Discovery (SoC, Shunt, Status) |

## Voraussetzungen

- IP-Symcon 6.0 oder neuer
- WatchMon CORE mit aktiviertem WiFi-UDP-Broadcast (Modus „Verbose“)
- IP-Symcon und WatchMon im selben Netz, Broadcasts dürfen nicht gefiltert werden

## Installation

1. Den Ordner `BatriumWatchMon` (mit `library.json` und `WatchMonCORE/`) in das
   Modulverzeichnis von IP-Symcon kopieren:
   - Linux: `/var/lib/symcon/modules/`
   - Windows: `C:\ProgramData\Symcon\modules\`
   
   Alternativ den Inhalt in ein Git-Repository legen und die URL in der Modulverwaltung
   (Kern-Instanzen → Modules) hinzufügen.
2. Modulverwaltung neu laden bzw. IP-Symcon neu starten.
3. Instanz **Batrium WatchMon CORE** anlegen. Dabei wird ein UDP Socket als Parent angelegt
   oder ein vorhandener ausgewählt.
4. UDP Socket: Port **18542**, „Lauschen auf“ 0.0.0.0, Socket öffnen.
5. In der Instanz Anzahl Bänke und Zellen je Bank prüfen und „Änderungen übernehmen“.

## Einstellungen

| Einstellung | Standard | Bedeutung |
|---|---|---|
| Anzahl Batteriebänke | 4 | Bänke (1–16); bestimmt die Bank-Statistik und die Zahl der Zellvariablen |
| Zellen je Bank | 16 | Zellen je Bank (1–32) |
| Variablen für Einzelzellen anlegen | an | Spannung, Temperatur und Status je Zelle (3 Variablen je Zelle) |
| System-ID filtern | leer | 8 Hex-Zeichen; nur Pakete dieses WatchMon werden ausgewertet. Die aktuelle Kennung steht in der Variable „System-Kennung“ |
| Verbindung offline nach | 30 s | Zeit ohne Pakete, nach der „Verbindung“ auf Offline wechselt |
| Debug | aus | Schreibt Rohdaten ins Debug-Fenster der Instanz |

## Funktionen für Skripte

| Funktion | Beschreibung |
|---|---|
| `BATWM_Watchdog(int $InstanzID)` | Prüft, ob noch Daten ankommen, und setzt „Verbindung“. Wird vom Modul alle 10 Sekunden selbst aufgerufen. |

## Hinweise und Einschränkungen

- Die Zuordnung Node → Bank ist eine Annahme: Node 1–16 = Bank 1, 17–32 = Bank 2 usw.
  Sie passt zu den Seriennummern der CellMate-K9-Module im Mitschnitt.
- Der Systemstatus folgt der Tabelle aus der Spezifikation. Im Mitschnitt stand er auf 5
  („Leer“), obwohl der SoC bei 97,5 % lag. Die Beschriftung des Profils `BATWM.OpStatus`
  deshalb mit dem WatchMon-Toolkit abgleichen.
- Die Nachrichten sind versioniert (zweites Byte des Typs). Ändert eine neuere Firmware die
  Revision, werden die Pakete ignoriert. Dann „Debug“ einschalten und die Rohdaten prüfen.
- Nicht ausgewertet: Zelldetails (0x4232), Logik-, Remote- und Konfigurationsnachrichten.
- Wird „Zellen je Bank“ nach dem Anlegen geändert, behalten bestehende Variablen ihre
  alten Namen. Die Idents (`N01_V` usw.) stimmen weiterhin.

## Fehlersuche

- **Keine Werte:** Port 18542 am UDP Socket prüfen, Socket muss geöffnet sein. Debug in der
  Instanz einschalten. Erscheinen keine Einträge, kommen keine Pakete an (Netzwerk, WLAN-Modus
  des WatchMon, Firewall).
- **„Verbindung“ bleibt Offline:** Es kommen Pakete an, aber keine der drei Nachrichtentypen.
  Im Debug-Fenster der Instanz und im UDP Socket die Typen prüfen.
- **Mehrere WatchMon im Netz:** Mit „System-ID filtern“ trennen.

## Lizenz

Keine Gewährleistung. Nutzung auf eigene Verantwortung.
