# Review-Notizen: PR #12 – Woohoo Mailer Modul

> Festgehalten für spätere Umsetzung. Stand: Review vom 2026-07-19,
> Branch `refactor/isolate-mailservice` (PR [#12](https://github.com/ke-ma-fi/wc-plz-filter/pull/12)).

## Wichtig

### 1. Mail-Fehler werden ohne Diagnoseinformation geloggt
- **Wo:** `includes/class-woohoo-mailer.php` – `send()`
- **Problem:** `wp_mail()` liefert bei Fehlschlag nur `false`; die eigentliche Ursache
  (ungültiger Empfänger, SMTP-Timeout, durch Security-Plugin blockiert, ...) geht
  verloren. Im Log steht nur `status: error`.
- **Lösung:** `wp_mail_failed`-Hook abfangen und die `WP_Error`-Message mit ins
  Log schreiben (zusätzliches Feld `error`).

### 2. Lost-Update-Risiko beim Log-Schreiben (jetzt zentral für alle Module)
- **Wo:** `includes/class-woohoo-mailer.php` – `append_log()`
- **Problem:** Read-Modify-Write auf einer einzelnen `wp_options`-Zeile
  (`get_option` → mutieren → `update_option`), nicht atomar. Durch die
  Zentralisierung wächst die Kollisionsfläche von einem Modul auf potenziell
  alle künftigen Mail-Quellen im Plugin.
- **Lösung:** Eigene DB-Tabelle mit `INSERT` pro Eintrag (atomar) statt
  serialisiertem Array in einer Options-Zeile; Aufräumen auf `MAX_LOG`
  asynchron statt bei jedem Send.

### 3. Doppelte, divergierende Logging-Pfade für dasselbe Mail-Event
- **Wo:** `includes/class-wc-plz-reminder.php` (`send_for_order`,
  `resend_for_order`) zusammen mit `Woohoo_Mailer::append_log()`
- **Problem:** Jeder Versand erzeugt zwei unabhängige Log-Einträge
  (`woohoo_mail_log` und `wc_plz_reminder_log`) mit unterschiedlichem Schema –
  doppelte Schreiblast, keine Verknüpfung, können bei Punkt 2 auseinanderlaufen.
- **Lösung:** Reminder-Log auf das reduzieren, was der zentrale Log nicht
  abbilden kann (Order-Bezug für Resend-Button), statt to/subject/status
  redundant zu halten – oder bewusst dokumentieren, dass beide Logs
  unabhängig sind.

## Optional

### 4. Keine Prüfung auf leere/ungültige Empfängeradresse
- **Wo:** `includes/class-woohoo-mailer.php` – `send()`
- Guard-Clause vor `wp_mail()`, eigener Log-Status z. B. `invalid_recipient`.

### 5. `reference` im Mailer-Log ist reiner Text ohne Link
- **Wo:** `includes/class-woohoo-mailer.php` – `render_tab()`
- Rückschritt ggü. dem bestehenden Reminder-Log (das `order_id` verlinkt).
  Optional: `reference_url` in `$args` ergänzen.

### 6. PII-Retention ohne Ablauf
- **Wo:** `includes/class-woohoo-mailer.php`
- Volle Kunden-E-Mails + Betreffzeilen dauerhaft (bis 100 Einträge, kein TTL)
  in `wp_options`. DSGVO-Abwägung wert, da jetzt zentraler Sammelpunkt für
  alle Kunden-Mail-Metadaten.
