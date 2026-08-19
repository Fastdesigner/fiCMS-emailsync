# fiCMS Email Sync

`fiCMS-emailsync` ist ein eigenständiges fiCMS-Add-on für wiederholbare, einseitige IMAP-Postfachmigrationen. Der fiCMS-Cron ist der Worker: Pro Cron-Lauf wird höchstens ein fälliger Sync-Job vollständig ausgeführt, auch wenn dieser mehrere Stunden dauert.

## Voraussetzungen

- fiCMS-Core mit `imap\Client`, `imap\ConnectionStore`, `imap\Vault` und `imap\State`
- PHP-Streams, OpenSSL und Sodium
- ein aktiver fiCMS-Benutzer als Empfänger der Ereignisreports

Das Add-on braucht weder `ext-imap` noch `imapsync`, Composer, Shell-Zugriff oder die Möglichkeit, Pakete auf dem Server nachzuinstallieren. Damit läuft der Transfer auch in nicht verwaltbaren Hosting-Paketen, solange PHP verschlüsselte Netzwerkverbindungen öffnen darf.

## Installation

Das Repository wird nach `system/plugins/fiCMS-emailsync` installiert. Bei einer lizenzverwalteten Installation stellt die fiCMS-API die Repository-Konfiguration und, falls erforderlich, den Zugriffstoken als Update-Konfiguration bereit. Danach wird der API-Abgleich erzwungen und der Updater für `fiCMS-emailsync` ausgeführt.

Für eine lokale Entwicklungsinstallation kann dieses Repository direkt nach `system/plugins/fiCMS-emailsync` geklont oder dort verlinkt werden. Das Add-on benötigt keinen eigenen Build-Schritt.

## Benutzung

1. In den fiCMS-Einstellungen `Postfach-Synchronisierung` öffnen.
2. Einen neuen Job anlegen und Domain, Report-Empfänger, Quellpostfach und Zielpostfach eintragen.
3. Optional die erwarteten MX-Zielhosts hinterlegen. Damit wird nicht jede beliebige MX-Änderung als Umzug akzeptiert.
4. Intervall, Ruhezeit, Mindestnachlauf und Ersatz-TTL prüfen. Standard sind 60 Minuten, 48 Stunden, sieben Tage und 24 Stunden.
5. Speichern. Der Job ist aktiv und wird beim nächsten Cron-Lauf berücksichtigt.

Der erste erfolgreiche Lauf überführt den Job von `initial` nach `monitoring` und erzeugt einen Ereignisreport. Weitere Läufe übertragen nur noch fehlende Nachrichten. Ein erkannter MX-Wechsel startet den Nachlauf. Abgeschlossen wird erst, wenn mindestens ein vollständiges TTL-Fenster vergangen ist, danach 48 Stunden keine neue Quellnachricht übertragen wurde, der Mindestnachlauf erfüllt ist und ein erfolgreicher Lauf null neue Nachrichten meldet.

Beim Abschluss werden beide für die Migration gespeicherten Passwörter entfernt und ein Abschlussreport erzeugt. Der Report wird beim folgenden Cron-Lauf verschickt, da das Report-System in der Cron-Pipeline vor dem Plugin-Worker läuft.

## Sicherheit und Transferverhalten

Passwörter werden zentral durch `imap\Vault` verschlüsselt und nur im laufenden PHP-Prozess entschlüsselt. Es entstehen weder Passwortdateien noch Prozesse oder Shell-Kommandos. TLS-Zertifikate der beiden Mailserver werden geprüft.

Der Transfer ist additiv und löscht weder Nachrichten noch Ordner. Nachrichten werden als unveränderte MIME-Rohdaten samt Empfangsdatum und den Flags `Seen`, `Answered`, `Flagged` und `Draft` übertragen. Der Fortschritt wird pro Ordner nach jeder Nachricht über Quell-UID und UIDVALIDITY gespeichert. Bei einem abgebrochenen Lauf wird daran angeknüpft; vor dem erneuten Anhängen prüft das Add-on mögliche Zielnachrichten anhand ihres SHA-256-Inhalts-Hashs.

## Zuständigkeiten

- fiCMS-Core: wiederverwendbare IMAP-Verbindungen und verschlüsselte Zugangsdaten
- dieses Add-on: Migrationsjobs, Transfersteuerung, Fortschritt, MX-Erkennung, Nachlauf und Reports
- spätere Inbox-Funktion: eigener dauerhafter Consumer der Core-Verbindungen, unabhängig von diesem Migrations-Add-on

Der Job-State und die geschützten Laufprotokolle liegen unter `system/plugins/fiCMS-emailsync/state` und werden nicht versioniert.
