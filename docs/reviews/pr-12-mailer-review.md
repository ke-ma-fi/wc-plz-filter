# Review-Notizen: PR #12 – Woohoo Mailer Modul

> Festgehalten für spätere Umsetzung. Stand: Review vom 2026-07-19,
> Branch `refactor/isolate-mailservice` (PR [#12](https://github.com/ke-ma-fi/wc-plz-filter/pull/12)).

**Status (2.13.0):** Punkte 1, 4 und 6 sind mit PR #20 (Squash-Commit `cdc36d2` auf `base`)
behoben. Punkte 2, 3 und 5 sind offen (siehe
[ROADMAP](../ROADMAP.md), „Offen nach 2.13.0“).

| # | Thema | Status |
|---|---|---|
| 1 | Fehlergrund im Log | ✅ behoben (#20) |
| 2 | Lost-Update-Risiko beim Log-Schreiben | offen |
| 3 | Doppelte Logging-Pfade | offen |
| 4 | Ungültige Empfängeradresse | ✅ behoben (#20) |
| 5 | `reference` ohne Link | offen (optional) |
| 6 | PII-Retention | ✅ behoben (#20) |

## Wichtig

### 1. Mail-Fehler werden ohne Diagnoseinformation geloggt
- **Wo:** `includes/class-woohoo-mailer.php` – `send()`
- **Problem:** `wp_mail()` liefert bei Fehlschlag nur `false`; die eigentliche Ursache
  (ungültiger Empfänger, SMTP-Timeout, durch Security-Plugin blockiert, ...) geht
  verloren. Im Log steht nur `status: error`.
- **Lösung:** `wp_mail_failed`-Hook abfangen und die `WP_Error`-Message mit ins
  Log schreiben (zusätzliches Feld `error`).
- **Status: behoben (#20).** `send()` hängt sich nur für die Dauer des eigenen
  `wp_mail()`-Aufrufs an `wp_mail_failed` und schreibt die Meldung ins Feld
  `error`; der Mail-Log-Tab zeigt sie unter dem Status an.

### 2. Lost-Update-Risiko beim Log-Schreiben (jetzt zentral für alle Module)
- **Wo:** `includes/class-woohoo-mailer.php` – `append_log()`
- **Problem:** Read-Modify-Write auf einer einzelnen `wp_options`-Zeile
  (`get_option` → mutieren → `update_option`), nicht atomar. Durch die
  Zentralisierung wächst die Kollisionsfläche von einem Modul auf potenziell
  alle künftigen Mail-Quellen im Plugin.
- **Lösung:** Eigene DB-Tabelle mit `INSERT` pro Eintrag (atomar) statt
  serialisiertem Array in einer Options-Zeile; Aufräumen auf `MAX_LOG`
  asynchron statt bei jedem Send.
- **Status: offen.** Gilt genauso für `wc_plz_reminder_log`. Solange nur die
  Zahlungs-Erinnerung Mails verschickt, gering; relevant, sobald weitere
  Mail-Quellen dazukommen.

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
- **Status: offen.** Beide Logs bestehen weiter; in
  [`docs/payment-reminder.md`](../payment-reminder.md) ist dokumentiert, dass
  sie unabhängig sind. Ein ungültiger Empfänger erscheint im Mail-Log als
  `invalid_recipient`, im Reminder-Log nur als `error`.

## Optional

### 4. Keine Prüfung auf leere/ungültige Empfängeradresse
- **Wo:** `includes/class-woohoo-mailer.php` – `send()`
- Guard-Clause vor `wp_mail()`, eigener Log-Status z. B. `invalid_recipient`.
- **Status: behoben (#20).** Leere oder per `is_email()` ungültige Adressen
  (auch einzelne in einer Komma-Liste) werden ohne `wp_mail()`-Aufruf mit Status
  `invalid_recipient` („Ungültiger Empfänger“) geloggt.

### 5. `reference` im Mailer-Log ist reiner Text ohne Link
- **Wo:** `includes/class-woohoo-mailer.php` – `render_tab()`
- Rückschritt ggü. dem bestehenden Reminder-Log (das `order_id` verlinkt).
  Optional: `reference_url` in `$args` ergänzen.
- **Status: offen.**

### 6. PII-Retention ohne Ablauf
- **Wo:** `includes/class-woohoo-mailer.php`
- Volle Kunden-E-Mails + Betreffzeilen dauerhaft (bis 100 Einträge, kein TTL)
  in `wp_options`. DSGVO-Abwägung wert, da jetzt zentraler Sammelpunkt für
  alle Kunden-Mail-Metadaten.
- **Status: behoben (#20).** Einträge werden nach 90 Tagen gelöscht
  (`Woohoo_Mailer::MAX_AGE_DAYS`, zusätzlich zum Limit von 100 Einträgen), beim
  Lesen oder Schreiben des Logs. `uninstall.php` löscht `woohoo_mail_log`.
