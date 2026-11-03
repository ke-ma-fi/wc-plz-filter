# Roadmap DT Woohoo

Stand: 2026-10-22 (Review von v2.12.9), fortgeschrieben 2026-11-03 für Release 2.13.0. Aufwand: **S** ≈ < ½ Tag, **M** ≈ 1–2 Tage, **L** ≈ mehrere Tage.
Erledigte Punkte abhaken und mit Commit/PR verlinken. A–C sind mit 2.13.0 erledigt (PRs #17–#21 + Doku-PR);
was danach noch offen ist, steht unter [Offen nach 2.13.0](#offen-nach-2130).

## A – Deploy & Betrieb absichern

- [x] **A1 Branch-Schutz + CI auf `main`** (S) – erledigt (#21)
  `main` bleibt Deploy-Branch, aber: kein direkter Push mehr, nur PRs; Pflicht-Check per GitHub Actions
  (`php -l` auf PHP 8.0–8.3, `node --check` für JS, Plugin-Header-Version == `WC_PLZ_Filter::VERSION`
  und Version erhöht). Ruleset ohne Admin-Bypass. Ein eigener `release`-Branch lohnt erst mit Staging-Seite.
  CI und Anleitung sind da; das Ruleset selbst muss der Repo-Owner noch anlegen (siehe unten).
- [x] **A2 Updater härten** (S) – erledigt (#21) – `class-wc-plz-updater.php` `run_upgrade()`: Sperre gegen parallele
  Läufe (`add_option`-Lock mit Ablauf), `ignore_user_abort(true)`, `set_time_limit()`.
- [x] **A3 Notfall-Runbook `docs/RUNBOOK.md`** (S) – erledigt (#21) – Rollback per Revert-PR; was tun bei Fatal Error
  (Betreiber benennt Plugin-Ordner per FTP um / Recovery-Mode-Mail); Webhook-Secret rotieren; Update-Log.
- [x] **A4 WP-Rocket-Cache** (S) – erledigt (#20) – In `filter_products()` `DONOTCACHEPAGE` setzen, sobald serverseitig
  im Postversand gefiltert wird. Verhindert, dass eine gefilterte Kategorieseite für alle gecacht wird.
  (Alternative „Never cache cookies: `wc_delivery_mode`“ verworfen: würde alle Kunden mit gewähltem Modus
  180 Tage lang vom Cache ausschließen.)
- [x] **A5 `uninstall.php` vervollständigen** (S) – erledigt (#20) – fehlt: `woohoo_mail_log` (enthält Kunden-E-Mails),
  `wc_plz_rocket_busted`, `wc_plz_updater_branch`, `wc_plz_updater_cap_version`, Transients
  `woohoo_po_fails_*` + `wc_plz_reminder_notice`, Capability `manage_woohoo_updates` (Rolle + Einzelnutzer).
- [x] **A6 Repo-Sichtbarkeit entscheiden** – erledigt (#20)
  - Öffentlich lassen (Updater funktioniert ohne Token, Branch-Schutz kostenlos) → `.gitattributes` mit
    `docs/ export-ignore`, damit interne Notizen nicht unter `/wp-content/plugins/…/docs/` live liegen;
    interne Review-Notizen ggf. außerhalb des Repos führen.
  - Oder privat → Updater braucht GitHub-Token (read-only, fine-grained) + Download über die API (M);
    Branch-Schutz für private Repos nur mit GitHub Pro.
  - **Entscheidung:** Repo bleibt öffentlich. `.gitattributes` schließt `docs/`, `.github/`, `bin/`,
    `tests/`, `CLAUDE.md`, `.gitattributes`, `.gitignore` per `export-ignore` vom Deploy-Paket aus;
    README.md und CHANGELOG.md bleiben drin.

## B – Fachliche Fixes

- [x] **B1 Packliste** (M) – erledigt (#18) – `class-woohoo-po-aggregator.php`: Erstattungen abziehen
  (`get_qty_refunded_for_item`), gemischte Einheiten pro Produkt nicht falsch summieren, Formate
  „500 gr“, „1 Liter“, „1.000 g“ erkennen, Dezimalmengen nicht abschneiden (`%d`).
- [x] **B2 Upgrade-Routine** (S) – erledigt (#20) – `maybe_upgrade()` bei geänderter Version: idempotent Tabelle,
  Crons und Capabilities sicherstellen. Hintergrund: Webhook-Updates führen den Aktivierungs-Hook nie
  aus; was künftig dort ergänzt wird, käme sonst nie in Produktion an.
- [x] **B3 Kompatibilitäts-Angaben** (S) – erledigt (#20) – HPOS-Erklärung (`FeaturesUtil::declare_compatibility`),
  Header „Requires at least“ / „WC tested up to“, Plugin-Beschreibung („drei Modi“) aktualisieren.
  Umgesetzt: HPOS (`custom_order_tables`; Shop läuft weiter auf Posts-Speicher, `cart_checkout_blocks`
  bewusst nicht erklärt), `Requires at least: 6.0`, neue Beschreibung. „WC tested up to“ nicht gesetzt.
- [x] **B4 Kleine Frontend-Fixes, beim nächsten Frontend-Eingriff mitnehmen** (S) – erledigt (#19)
  - Escape-Listener nur reagieren lassen, wenn das PLZ-Popup offen ist (`plz-popup.js:591`) –
    aktuell setzt Escape *irgendwo* auf der Seite „skipped“, wenn noch kein Modus gewählt ist.
  - ID- und `:has()`-Selektoren als zwei getrennte CSS-Regeln (JS + Inline-Head-Script),
    damit ältere Browser nicht die ganze Regel verwerfen.
  - `maxlength="5"` entfernen (abgeschnittene PLZ beim Einfügen von „63 667“), Doppel-Submit sperren.
- [x] **B5 Mailer** (S) – erledigt (#20) – Fehlergrund via `wp_mail_failed` mitloggen; Aufbewahrungsdauer für
  das Mail-Log (personenbezogene Daten) festlegen (offene Punkte aus `reviews/pr-12-mailer-review.md`).

## C – Doku & Arbeitsweise

- [x] **C1 Doku an Code angleichen** (S) – erledigt (Doku-PR) – bekannte Abweichungen:
  - README: Cookie-Laufzeit 30 → **180** Tage; Zonen-Cache 1 h → **12 h**; Popup erscheint standardmäßig
    erst nach dem ersten Warenkorb-Artikel (`popup_trigger`); Entwickler-Reset löscht nur das Cookie;
    Merkliste speichert doch Server-Zähler (`wc-plz/v1/merkliste-stats`); Icon ist ein Herz;
    Uninstall-Abschnitt; fehlende Features (Mindestbestellwert, Versandart-Hinweise, Auto-Update-Tab,
    Mail-Log, „Cache leeren“); Installationsanleitung (`.zip` existiert nicht).
  - `reviews/2026-07-20-security-review.md`: Punkt 1 ist behoben (`manage_woohoo_updates`).
  - `product-overview.md`: 500-Fehler gibt keine Exception-Details mehr an den Client.
  - `NAMING.md`: `fix_source_dir` nutzt den aktiven Ordnernamen; Merkliste/Cart-Indicator als
    Ausnahme von der `Woohoo_*`-Regel vermerken.
  - `notes/settings-capability-filter.md`: Zeilenverweise veraltet (mit C4/#17 neu geschrieben).
  - Admin-Label „Notizblock-Icon“ → Herz: PHP-Änderung, nicht im Doku-PR → „Offen nach 2.13.0“.
- [x] **C2 `CHANGELOG.md` + Versionsregel** (S) – erledigt (Doku-PR) – Version nur aufwärts, eine pro Release, Tag pro Release.
- [x] **C3 `CLAUDE.md`** (S) – erledigt (Doku-PR) – Namensregeln, Versions-Bump bei Asset-Änderungen, jede `register_setting`
  braucht `option_page_capability_*`, keine Direkt-Pushes auf `main`, UI-Texte deutsch.
- [x] **C4 Settings-Capability-Filter zentralisieren** (S–M) – erledigt (#17) – Plan in `notes/settings-capability-filter.md`.
- [x] **C5 Aufräumen** (S) – erledigt (Doku-PR) – `docs/demos/` (veraltet), `notes/badge-toast.md` (überholt),
  `notes/to-dos.md` (offene Punkte hierher übernehmen).

## D – Irgendwann

- [ ] **D1 Barrierefreiheit** (M) – sichtbarer Fokus statt `outline:none`, Popup auf kleinen Displays
  scrollbar, Fokus-Falle im Popup, Touch-Ziele ≥ 44 px, Kontraste, Badge-Status für Screenreader.
- [ ] **D2 Tests** (M) – PHPUnit für PLZ-Matching (aus `is_local()` herauslösen) und Packlisten-Einheiten.
- [ ] **D3 Modulmuster** (L) – `class-wc-plz-filter.php` (1243 Zeilen) in Zonen / Sichtbarkeit /
  Warenkorb-Regeln / Popup / Admin aufteilen. Erst nach D2.
- [ ] **D4 Merkliste-`init`-Ping entfernen**, sobald die Einführungszahlen ausgewertet sind.
- [ ] **D5 Produktideen**
  - Postversand: Frischware ausgegraut statt ausgeblendet, mit „Auf Abholung wechseln?“.
  - Nach PLZ-Eingabe Bestellschluss / nächsten Liefertermin anzeigen.
  - Merkliste: „Alles in den Warenkorb“; ggf. „Zuletzt gekauft“-Tab im Merkliste-Widget
    (aus der alten `notes/to-dos.md`, evtl. unnötig – Merkliste könnte reichen).
  - Einheitliche Badge-Toast-Hinweise statt `alert()` (`?plz_blocked=1`) und WC-Notices
    außerhalb des Warenkorbs (entfernte Produkte, Mindestbestellwert) – vom Badge ausgehend,
    selbst schließend (Idee aus der alten `notes/badge-toast.md`).
  - Packliste: Druckansicht, Abhaken, „Morgen“-Knopf.

## Bewusst akzeptiert (kein Handlungsbedarf)

| Thema | Begründung |
|---|---|
| Escape im Popup setzt „skipped“ | Gewollt. Der globale Listener ist seit B4 (#19) auf das offene Popup begrenzt. |
| Merkliste-`init`-Ping bei jedem Seitenaufruf | Temporäre Einführungsstatistik, Rückbau in D4. |
| Frischware: Abholung gewählt, an der Kasse Postversand | WooCommerce lehnt per Versandklasse ab. |
| Popup-PLZ überschreibt Konto-PLZ eingeloggter Kunden | Kunde wählt an der Kasse aktiv; erst bei Beschwerde fixen. |
| Redirect zu externem Referrer (z. B. Google) | Laufkundschaft ohne gewählten Modus, betrifft praktisch niemanden. |
| Ungenauigkeiten der PLZ-Statistik (Abholung-Reload, Spam-Möglichkeit) | Statistik ist nur Hilfsgröße. |
| Zahlungserinnerung: nicht-atomare Sperre, Telefon-Bestellungen | 4–5 Läufe/Woche, keine Telefonkunden. |
| Browser ohne `:has()` | Slug-Regel bleibt dort wirkungslos; seit B4 (#19) greift die ID-Regel trotzdem, serverseitige Regeln ohnehin. |

## Offen nach 2.13.0

- [ ] **GitHub-Ruleset für `main` anlegen** – nur der Repo-Owner kann das (Anleitung: RUNBOOK
  Abschnitt 2; Pflicht-Checks `php-lint (PHP 8.0)`…`(PHP 8.3)`, `js-check`, `version`, ohne Bypass).
  Bis dahin schützt nur Disziplin vor Direkt-Pushes auf `main`.
- [ ] **Tag `v2.13.0`** nach dem Merge `base` → `main` setzen (RUNBOOK, Release-Checkliste).
- [ ] **Admin-Texte an den Code angleichen** (S, PHP):
  - Zusatz-Features: „Notizblock-Icon“ → „Herz-Icon“ (`class-woohoo-module-widgets.php`).
  - Liefermodus → Entwickler-Reset: Text und Button sprechen von „Cookie & Session“, gelöscht wird
    nur das Cookie (`handle_admin_reset()`).
  - Docblock von `WC_PLZ_Cart_Indicator` nennt eine „green outline“ – es ist die Akzentfarbe.
- [ ] **Mail-Log: Lost-Update-Risiko** (M) – Read-Modify-Write auf `woohoo_mail_log` (und
  `wc_plz_reminder_log`); eigene Tabelle mit `INSERT` pro Eintrag
  (`reviews/pr-12-mailer-review.md` Punkt 2).
- [ ] **Doppelte Logs Zahlungs-Erinnerung** (S) – Reminder-Log auf Order-Bezug/Resend reduzieren
  oder bewusst so lassen (PR-12 Punkt 3); optional `reference_url` im Mail-Log (Punkt 5).
- [ ] **Toter Code** (S) – `WC_PLZ_Stats::maybe_create_table()` wird nirgends aufgerufen; seit B2
  erledigt `install()` das. Entfernen.
- [ ] **PLZ-Statistik-API** (S) – `total_events` zählt die ganze Tabelle, nicht den abgefragten
  Zeitraum (dokumentiert in `plz-statistics.md`); ggf. um eine Periodensumme ergänzen.
