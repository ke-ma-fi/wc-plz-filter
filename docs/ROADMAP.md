# Roadmap DT Woohoo

Stand: 2026-10-22 (Review von v2.12.9). Aufwand: **S** ≈ < ½ Tag, **M** ≈ 1–2 Tage, **L** ≈ mehrere Tage.
Erledigte Punkte abhaken und mit Commit/PR verlinken.

## A – Deploy & Betrieb absichern (als Nächstes)

- [ ] **A1 Branch-Schutz + CI auf `main`** (S)
  `main` bleibt Deploy-Branch, aber: kein direkter Push mehr, nur PRs; Pflicht-Check per GitHub Actions
  (`php -l` auf PHP 8.0–8.3, `node --check` für JS, Plugin-Header-Version == `WC_PLZ_Filter::VERSION`
  und Version erhöht). Ruleset ohne Admin-Bypass. Ein eigener `release`-Branch lohnt erst mit Staging-Seite.
- [ ] **A2 Updater härten** (S) – `class-wc-plz-updater.php` `run_upgrade()`: Sperre gegen parallele
  Läufe (`add_option`-Lock mit Ablauf), `ignore_user_abort(true)`, `set_time_limit()`.
- [ ] **A3 Notfall-Runbook** `docs/RUNBOOK.md` (S) – Rollback per Revert-PR; was tun bei Fatal Error
  (Betreiber benennt Plugin-Ordner per FTP um / Recovery-Mode-Mail); Webhook-Secret rotieren; Update-Log.
- [ ] **A4 WP-Rocket-Cache** (S) – In `filter_products()` `DONOTCACHEPAGE` setzen, sobald serverseitig
  im Postversand gefiltert wird. Verhindert, dass eine gefilterte Kategorieseite für alle gecacht wird.
  (Alternative „Never cache cookies: `wc_delivery_mode`“ verworfen: würde alle Kunden mit gewähltem Modus
  180 Tage lang vom Cache ausschließen.)
- [ ] **A5 `uninstall.php` vervollständigen** (S) – fehlt: `woohoo_mail_log` (enthält Kunden-E-Mails),
  `wc_plz_rocket_busted`, `wc_plz_updater_branch`, `wc_plz_updater_cap_version`, Transients
  `woohoo_po_fails_*` + `wc_plz_reminder_notice`, Capability `manage_woohoo_updates` (Rolle + Einzelnutzer).
- [ ] **A6 Repo-Sichtbarkeit entscheiden**
  - Öffentlich lassen (Updater funktioniert ohne Token, Branch-Schutz kostenlos) → `.gitattributes` mit
    `docs/ export-ignore`, damit interne Notizen nicht unter `/wp-content/plugins/…/docs/` live liegen;
    interne Review-Notizen ggf. außerhalb des Repos führen.
  - Oder privat → Updater braucht GitHub-Token (read-only, fine-grained) + Download über die API (M);
    Branch-Schutz für private Repos nur mit GitHub Pro.

## B – Fachliche Fixes

- [ ] **B1 Packliste** (M) – `class-woohoo-po-aggregator.php`: Erstattungen abziehen
  (`get_qty_refunded_for_item`), gemischte Einheiten pro Produkt nicht falsch summieren, Formate
  „500 gr“, „1 Liter“, „1.000 g“ erkennen, Dezimalmengen nicht abschneiden (`%d`).
- [ ] **B2 Upgrade-Routine** (S) – `maybe_upgrade()` bei geänderter Version: idempotent Tabelle,
  Crons und Capabilities sicherstellen. Hintergrund: Webhook-Updates führen den Aktivierungs-Hook nie
  aus; was künftig dort ergänzt wird, käme sonst nie in Produktion an.
- [ ] **B3 Kompatibilitäts-Angaben** (S) – HPOS-Erklärung (`FeaturesUtil::declare_compatibility`),
  Header „Requires at least“ / „WC tested up to“, Plugin-Beschreibung („drei Modi“) aktualisieren.
- [ ] **B4 Kleine Frontend-Fixes, beim nächsten Frontend-Eingriff mitnehmen** (S)
  - Escape-Listener nur reagieren lassen, wenn das PLZ-Popup offen ist (`plz-popup.js:591`) –
    aktuell setzt Escape *irgendwo* auf der Seite „skipped“, wenn noch kein Modus gewählt ist.
  - ID- und `:has()`-Selektoren als zwei getrennte CSS-Regeln (JS + Inline-Head-Script),
    damit ältere Browser nicht die ganze Regel verwerfen.
  - `maxlength="5"` entfernen (abgeschnittene PLZ beim Einfügen von „63 667“), Doppel-Submit sperren.
- [ ] **B5 Mailer** (S) – Fehlergrund via `wp_mail_failed` mitloggen; Aufbewahrungsdauer für
  das Mail-Log (personenbezogene Daten) festlegen (offene Punkte aus `reviews/pr-12-mailer-review.md`).

## C – Doku & Arbeitsweise (bald)

- [ ] **C1 Doku an Code angleichen** (S) – bekannte Abweichungen:
  - README: Cookie-Laufzeit 30 → **180** Tage; Zonen-Cache 1 h → **12 h**; Popup erscheint standardmäßig
    erst nach dem ersten Warenkorb-Artikel (`popup_trigger`); Entwickler-Reset löscht nur das Cookie;
    Merkliste speichert doch Server-Zähler (`wc-plz/v1/merkliste-stats`); Icon ist ein Herz;
    Uninstall-Abschnitt; fehlende Features (Mindestbestellwert, Versandart-Hinweise, Auto-Update-Tab,
    Mail-Log, „Cache leeren“); Installationsanleitung (`.zip` existiert nicht).
  - `reviews/2026-07-20-security-review.md`: Punkt 1 ist behoben (`manage_woohoo_updates`).
  - `product-overview.md`: 500-Fehler gibt keine Exception-Details mehr an den Client.
  - `NAMING.md`: `fix_source_dir` nutzt den aktiven Ordnernamen; Merkliste/Cart-Indicator als
    Ausnahme von der `Woohoo_*`-Regel vermerken.
  - `notes/settings-capability-filter.md`: Zeilenverweise veraltet.
  - Admin-Label „Notizblock-Icon“ → Herz.
- [ ] **C2 `CHANGELOG.md` + Versionsregel** (S) – Version nur aufwärts, eine pro Release, Tag pro Release.
- [ ] **C3 `CLAUDE.md`** (S) – Namensregeln, Versions-Bump bei Asset-Änderungen, jede `register_setting`
  braucht `option_page_capability_*`, keine Direkt-Pushes auf `main`, UI-Texte deutsch.
- [ ] **C4 Settings-Capability-Filter zentralisieren** (S–M) – Plan in `notes/settings-capability-filter.md`.
- [ ] **C5 Aufräumen** (S) – `docs/demos/` (veraltet), `notes/badge-toast.md` (überholt),
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
  - Merkliste: „Alles in den Warenkorb“ (ggf. „Zuletzt gekauft“-Tab aus `to-dos.md`).
  - Packliste: Druckansicht, Abhaken, „Morgen“-Knopf.

## Bewusst akzeptiert (kein Handlungsbedarf)

| Thema | Begründung |
|---|---|
| Escape im Popup setzt „skipped“ | Gewollt. Nur der globale Listener wird in B4 eingegrenzt. |
| Merkliste-`init`-Ping bei jedem Seitenaufruf | Temporäre Einführungsstatistik, Rückbau in D4. |
| Frischware: Abholung gewählt, an der Kasse Postversand | WooCommerce lehnt per Versandklasse ab. |
| Popup-PLZ überschreibt Konto-PLZ eingeloggter Kunden | Kunde wählt an der Kasse aktiv; erst bei Beschwerde fixen. |
| Redirect zu externem Referrer (z. B. Google) | Laufkundschaft ohne gewählten Modus, betrifft praktisch niemanden. |
| Ungenauigkeiten der PLZ-Statistik (Abholung-Reload, Spam-Möglichkeit) | Statistik ist nur Hilfsgröße. |
| Zahlungserinnerung: nicht-atomare Sperre, Telefon-Bestellungen | 4–5 Läufe/Woche, keine Telefonkunden. |
| Browser ohne `:has()` | Kein eigener Fix; wird in B4 mitgenommen, serverseitige Regeln greifen ohnehin. |
