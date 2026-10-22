# fiCMS Email Sync

`fiCMS-emailsync` ist ein eigenständiges fiCMS-Add-on für wiederholbare, einseitige IMAP-Postfachmigrationen. Der reguläre fiCMS-Cron ist der Worker und hält dessen vorhandenen Prozesslock. Pro Aufruf bearbeitet das Plugin einen fälligen Auftrag in einer Kopierscheibe von bis zu acht Sekunden, begrenzt zusätzlich durch das verbleibende Core-Requestbudget. Es gibt keine zusätzliche globale 20-Minuten-Sperre. Teilläufe sind beim nächsten regulären Cron sofort wieder fällig; `Runtime::request` meldet offene Erstübernahmen an die vorhandene Backlogsteuerung.

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
5. Speichern legt einen inaktiven Entwurf an. Nach Ergänzen der Zugangsdaten den Aktiv-Schalter einschalten; erst nach erfolgreicher Prüfung beider Verbindungen wird der Job beim nächsten Cron-Lauf berücksichtigt.

Der erste erfolgreiche Lauf überführt den Job von `initial` nach `monitoring` und erzeugt einen Ereignisreport. Weitere Läufe übertragen nur noch fehlende Nachrichten. Ein erkannter MX-Wechsel startet den Nachlauf. Abgeschlossen wird erst, wenn mindestens ein vollständiges TTL-Fenster vergangen ist, danach 48 Stunden keine neue Quellnachricht übertragen wurde, der Mindestnachlauf erfüllt ist und ein erfolgreicher Lauf null neue Nachrichten meldet.

Beim Abschluss werden beide für die Migration gespeicherten Passwörter entfernt und ein Abschlussreport erzeugt. Der Report wird beim folgenden Cron-Lauf verschickt, da das Report-System in der Cron-Pipeline vor dem Plugin-Worker läuft.

## Sicherheit und Transferverhalten

Passwörter werden zentral durch `imap\Vault` verschlüsselt und nur im laufenden PHP-Prozess entschlüsselt. Es entstehen weder Passwortdateien noch Prozesse oder Shell-Kommandos. TLS-Zertifikate der beiden Mailserver werden geprüft.

Im geschützten Einstellungsformular können berechtigte Administratoren gespeicherte Passwörter
über das normale Passwortfeld einblenden. MCP-Leseantworten enthalten ausschließlich
Verbindungsmetadaten und `secret_present`, niemals das Passwort oder rückschreibbare Maskierungswerte.

## MCP-Typ `emailsync`

Die regulären Typ-Handler `get`, `create`, `update` und `delete` werden aus `mcp/` entdeckt
und sind ausschließlich für Administratoren verfügbar. `create` legt immer einen inaktiven
Entwurf an; Bezeichnung, Report-Empfänger und Quelladresse genügen. Vor einem `update` wird
der Job mit `get(type:"emailsync",id:"<id>",data:{view:"edit"})` geladen. Das liefert die
beschreibbaren Konfigurationsfelder; ausgelassene oder leere Passwörter bleiben bei identischer
Verbindung gespeichert. Konfiguration und Laufbefehle sind getrennte Aufrufe. Mit
`update(...,data:{test_connection:"destination"})` wird nur das vorhandene Ziel geprüft;
das startet keinen Kopierlauf. `active:1` prüft weiterhin beide Verbindungen.

### Atomar gebundene Passwortübernahme

Ein serverseitiger Executor kann nach `get(...,data:{view:"edit"})` genau ein Passwort
an den zuvor autorisierten Jobstand binden. Der bestehende `update`-Handler verwendet:

```json
{
  "type": "emailsync",
  "id": "<job-id>",
  "data": {
    "source_password": "<nur im geschützten Serverprozess>",
    "expected_configuration_revision": "<get.job.configuration_revision>",
    "expected_identity": {
      "source": {"id":"<id>","host":"<host>","port":993,"security":"ssl","username":"<username>"},
      "destination": {"id":"<id>","host":"<host>","port":993,"security":"ssl","username":"<username>"}
    }
  }
}
```

Beide Identitäten enthalten exakt die fünf Werte aus `get.job.source` und
`get.job.destination`; `port` ist eine JSON-Ganzzahl. Alternativ wird ausschließlich
`destination_password` geschrieben. Sobald einer der `expected_*`-Parameter vorkommt,
sind beide Pflicht: genau ein nichtleeres Passwortfeld, keine weiteren Konfigurationsfelder
oder Laufbefehle. Ohne diese Parameter bleiben normale Konfigurationsänderungen kompatibel.
Der Executor muss beide Identitäten und die Revision an seinen autorisierten Zielstand binden;
dieser Vergleich ersetzt keine Berechtigungsprüfung im CRM.

Unter dem bestehenden `jobs.json`-Lock wird der vollständige gelesene Job-Snapshot verglichen.
Erst danach werden Revision und beide Verbindungsidentitäten erneut geprüft und die neue
Verbindung über `imap\ConnectionStore` gespeichert. Änderungen am Job während des Aufrufs
führen vor jeder Secretpersistenz zur Ablehnung. Bestehende Verbindungen werden beim Schreiben
nicht überschrieben; bei fehlgeschlagenem Speichern werden neu angelegte Verbindungen entfernt.
Das Passwort der anderen Seite bleibt erhalten. Aktive Jobs werden ohne Änderung mit
`credential_write_failed` abgewiesen; inaktive Jobs behalten ihre Phase. Es gibt keine Aktivierung, Verbindungsprüfung,
Nachlaufänderung oder Übertragung als Nebenwirkung.

Erfolg enthält exakt `{"result":true,"type":"emailsync","id":"<job-id>","status":"submitted"}`.
Fehler enthalten ausschließlich `{"result":false,"error":"<code>"}` mit `target_changed`,
`invalid_protected_credential_write` oder `credential_write_failed`. Fremde Exceptions und
Passwörter werden nicht zurückgegeben. `submitted` bestätigt die Speicherung; eine neue
secretfreie `get`-Abfrage kann `secret_present` prüfen. Ein unklarer Transportausgang ist
kein Anlass zur automatischen Wiederholung.

Der Transfer ist additiv und löscht weder Nachrichten noch Ordner. Nachrichten werden als unveränderte MIME-Rohdaten samt Empfangsdatum und den Flags `Seen`, `Answered`, `Flagged` und `Draft` übertragen. Der Fortschritt wird pro Ordner nach jeder Nachricht über Quell-UID und UIDVALIDITY gespeichert. Bei einem abgebrochenen Lauf wird daran angeknüpft; vor dem erneuten Anhängen prüft das Add-on mögliche Zielnachrichten anhand ihres SHA-256-Inhalts-Hashs.

## Zuständigkeiten

- fiCMS-Core: wiederverwendbare IMAP-Verbindungen und verschlüsselte Zugangsdaten
- dieses Add-on: Migrationsjobs, Transfersteuerung, Fortschritt, MX-Erkennung, Nachlauf und Reports
- spätere Inbox-Funktion: eigener dauerhafter Consumer der Core-Verbindungen, unabhängig von diesem Migrations-Add-on

Der Job-State und die geschützten Laufprotokolle liegen unter `system/plugins/fiCMS-emailsync/state` und werden nicht versioniert.

## Aufträge vorbereiten

Neue Aufträge werden als inaktive Entwürfe gespeichert. Bezeichnung, ein aktiver fiCMS-Benutzer
als Report-Empfänger und die Quelladresse genügen; Serverdaten und Passwörter können später
ergänzt werden. Speichern führt keine IMAP-Anmeldung und keinen Kopierlauf aus.

Erst der vorhandene Aktiv-Schalter prüft beide Verbindungen und startet bei Erfolg die
Erstsynchronisierung beim nächsten Cron. Fehlende oder falsche Zugangsdaten lassen den Entwurf
inaktiv. Auch API-Aktivierung und „beim nächsten Cron ausführen“ verwenden diese Prüfung.
Änderungen an Verbindungen pausieren den Auftrag. Nach dem ersten Lauf lässt sich die Identität
der Quell- und Zielpostfächer nicht mehr wechseln; dafür ist ein eigener Auftrag nötig.

## Entwicklungsprüfungen

```sh
php dev/tests/drafts.php
php dev/tests/mcp.php
php dev/tests/protected-concurrency.php
php dev/tests/protected-failures.php
```

Der Konkurrenztest benötigt `pcntl` und einen benachbarten fiCMS-Checkout (alternativ
`FICMS_CORE=/pfad/zu/fiCMS`). Er verwendet die vorhandenen Core-Owner für Lock und
verschlüsselte Verbindungen mit zwei echten PHP-Prozessen. Sämtlicher Zustand besteht
aus synthetischen Fixtures in einem temporären Verzeichnis, das am Testende entfernt wird.

Der Fehlertest verwendet dieselben unveränderten Core-Owner. Er prüft einen nicht
beschreibbaren Connectionstore, einen globalen PHP-Fehlerhandler mit Trace-Argumenten
und eine gezielte Exception im Plugin-State-Adapter nach erfolgreicher Connection-Persistenz.
Der geschützte Schreibaufruf ersetzt PHP-Warnungen lokal durch eine feste Exception ohne
Verkettung oder Trace-Argumente und stellt den vorherigen Handler im `finally` wieder her.
Auch nach einer Exception beim Jobschreiben läuft der Cleanup; er entfernt ausschließlich
neu angelegte, von keinem Job referenzierte Verbindungen. Das garantiert keine
crashsichere Transaktion bei Prozessabbruch oder eine Bereinigung trotz anhaltender I/O-Fehler.

## Priorisierung und Abschluss

Erstübernahmen und unvollständige Teilläufe werden vor Monitoring-Aufträgen ausgewählt und untereinander nach dem ältesten Lauf rotiert. Monitoring läuft höchstens stündlich; nach sechs Stunden ohne Prüfung bekommt ein fälliger Monitoring-Auftrag einen fairen Vorrang. Die tatsächliche Startfrequenz bleibt vom regulären Hoster-/fiCMS-Cron abhängig.

Automatisch als Zielwechsel erkannt werden ausschließlich vollständige MX-Ziele, die dem gespeicherten Zielserver entsprechen. Unterschiedliche IMAP-/MX-Hostnamen werden über die vorhandene manuelle Bestätigung derselben beobachteten MX-Konfiguration bestätigt. Rückwechsel oder andere MX-Ziele verwerfen die alte Wechselbestätigung; DNS-Ausfälle erlauben keinen Abschluss. Eine bestehende Wechselzeit wird nur bei derselben zuvor beobachteten Konfiguration übernommen.

Abschluss verlangt vollständige Erstübernahme, passende aktuelle MX und einen erfolgreichen vollständigen leeren Schlussabgleich **nach** Ablauf von TTL, Mindestüberwachung und Ruhezeit. Ein solcher bereits gespeicherter Nachweis kann vor dem nächsten Quellenlogin ausgewertet werden. Abgeschlossene Aufträge werden deaktiviert und nicht erneut verbunden; gespeicherte Zugänge bleiben für den Benutzer erhalten. Löschen eines Auftrags verwendet weiterhin die vorhandene referenzgeprüfte Verbindungsbereinigung.

Wird nach vollständiger Erstübernahme und erkanntem Ziel-MX die Quellanmeldung ausdrücklich abgewiesen, pausiert der Auftrag als `source_unavailable` mit `review_reason`, ohne erneute Fehlermail und ohne falschen Erfolgsabschluss. Zielprobleme, Transportfehler, unvollständige Kopien und fehlender Zielwechsel bleiben normale Fehler mit Wiederholung.

Regressionen: `php dev/tests/scheduling.php` prüft Auswahl, Backlog, MX und Abschlussübergänge ohne Netzwerk; `php dev/tests/transfer-budget.php` führt den echten Transferloop mit synthetischen IMAP-Clients und deterministischer Uhr aus. Bestehende Credential-CAS-, Konkurrenz- und Fehlerprüfungen bleiben unverändert.
