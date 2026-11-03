# Runbook – Deploy, Rollback, Notfall

Für den Entwickler **und** den Shop-Betreiber (Notfall). Plugin: „DT Woohoo“, interner Name
`wc-plz-filter`. Läuft produktiv nur im Shop der Metzgerei Fischer.

> **Betreiber, Seite ist down?** → direkt zu [Notfall: Seite zeigt Fehler](#5-notfall-seite-zeigt-fehler-fatal-error).

Dieses Dokument liegt **nicht** auf dem Shop-Server (`docs/` wird nicht mit ausgeliefert), und der
Link in der README funktioniert im installierten Plugin nicht. Online-Fassung:
<https://github.com/ke-ma-fi/wc-plz-filter/blob/main/docs/RUNBOOK.md>. Entwickler: dem Betreiber
diesen Link bzw. einen Ausdruck von Abschnitt 5 **vorab** geben – im Notfall ist keine Zeit zum Suchen.

---

## 1. Wie ein Deploy funktioniert

1. Änderung als Pull Request (PR) gegen `main` (Integrations-Branch `base` → PR `base` → `main`).
2. CI prüft den PR (siehe [CI-Checks](#ci-checks)). Erst wenn alles grün ist, mergen.
3. Der Merge ist ein Push auf `main` → GitHub schickt den Push-Webhook an
   `https://<shop>/wp-json/wc-plz/v1/webhook`.
4. Das Plugin prüft die Signatur (Webhook-Secret), den Branch (`ref` muss `refs/heads/<Branch>`
   aus den Einstellungen sein) und lädt dann
   `https://github.com/<repo>/archive/refs/heads/<Branch>.zip` herunter und installiert es über den
   WordPress-Plugin-Upgrader in den **aktuellen** Plugin-Ordner.

**Jeder Push auf `main` geht sofort live.** Ein kaputter Push legt den Shop lahm – und damit auch den
Webhook, über den der Fix kommen müsste. Deshalb: nie direkt auf `main` pushen (siehe Ruleset unten).

**Dev-Instanz:** Dort steht im Feld „Branch“ `dev`; Pushes auf `dev` aktualisieren nur die
Dev-Instanz. Für `dev` läuft keine CI.

**Wo sehe ich, was passiert ist?** WooCommerce → DT Woohoo → Tab **„Updates“**:
„Aktuelle Version“, „Letztes Update“ und die Tabelle **„Update-Log“** (die letzten **10** Einträge,
neueste oben; Status *OK*, *Fehler* oder *Gesperrt*). Sichtbar nur mit der Berechtigung
`manage_woohoo_updates` (Administratoren bekommen sie automatisch). Auf GitHub zusätzlich:
Settings → Webhooks → Webhook → **Recent Deliveries** (Antwortcode und -text).

Webhook-Antworten:

| Code | Antwort | Bedeutung |
|---|---|---|
| 200 | `{"updated":true,…}` | Update installiert |
| 200 | `{"skipped":true}` | Push auf anderen Branch/Tag oder `ping` – nichts zu tun |
| 200 | `{"skipped":"duplicate"}` | Diese Zustellung (`X-GitHub-Delivery`) wurde schon erfolgreich verarbeitet |
| 403 | `invalid_signature` | Secret auf GitHub ≠ Secret im Shop |
| 404 | `rest_no_route` | Route existiert nicht: Plugin **inaktiv** oder Ordner umbenannt. Direkt nach einem Deploy fast immer ein Notfall → [Abschnitt 5](#5-notfall-seite-zeigt-fehler-fatal-error) |
| 409 | `locked` | Es läuft gerade schon ein Update; ein automatischer Nachlauf ist geplant (siehe [Update-Sperre](#7-update-sperre-409)) |
| 500 | `{"updated":false,…}` | Download/Installation fehlgeschlagen, Details im Update-Log |
| 500 | `internal_server_error` | PHP-Fatal-Error – Plugin (oder etwas anderes im Shop) ist kaputt → [Abschnitt 5](#5-notfall-seite-zeigt-fehler-fatal-error) |
| 501 | `not_configured` | Repo nicht eingetragen |

GitHub bricht nach ca. 10 Sekunden ab und zeigt dann „timed out“. Das Update läuft im Shop trotzdem
zu Ende – maßgeblich ist das Update-Log.

„Redeliver“ auf GitHub schickt dieselbe Delivery-ID erneut: Nach einem **erfolgreichen** Lauf wird
sie als `duplicate` übersprungen, nach Fehler oder 409 wird das Update erneut versucht.

---

## 2. GitHub einrichten (einmalig, manuell)

Rulesets sind für **öffentliche** Repos kostenlos (für private Repos bräuchte es GitHub Pro/Team).

Settings → **Rules → Rulesets** → New ruleset → **New branch ruleset**:

- Name: `main schützen`, Enforcement status: **Active**
- **Bypass list: leer lassen** (auch keine Admins)
- Target branches → Add target → Include by pattern: `main`
- Regeln anhaken:
  - **Restrict deletions**
  - **Block force pushes**
  - **Require a pull request before merging** (Required approvals: `0` – bei einem einzelnen
    Entwickler kann man den eigenen PR nicht freigeben)
  - **Require status checks to pass** → diese Checks hinzufügen (sie erscheinen in der Auswahl erst,
    nachdem die CI einmal gelaufen ist):
    - `php-lint (PHP 8.0)`
    - `php-lint (PHP 8.1)`
    - `php-lint (PHP 8.2)`
    - `php-lint (PHP 8.3)`
    - `js-check`
    - `version`
- Optional ein zweites, gleiches Ruleset für `base`.

### CI-Checks

Workflow `.github/workflows/ci.yml`, läuft bei PRs gegen `main`/`base` und Pushes auf `main`/`base`:

- `php-lint (PHP x.y)` – `php -l` auf alle PHP-Dateien, PHP 8.0–8.3.
- `js-check` – `node --check` auf `assets/js/*.js`.
- `version` – Header `Version:` in `wc-plz-filter.php` == `WC_PLZ_Filter::VERSION`; bei PRs gegen
  `main` zusätzlich: Version muss **größer** sein als auf `main`.
  Lokal: `bin/check-version.sh` bzw. `git fetch origin main && bin/check-version.sh origin/main`.

Job-Namen nicht umbenennen, sonst greifen die Pflicht-Checks im Ruleset nicht mehr.

---

## 3. Release-Checkliste

1. Version erhöhen, **an beiden Stellen gleich**:
   - `wc-plz-filter.php` → Kopfzeile `Version:`
   - `includes/class-wc-plz-filter.php` → `const VERSION`
2. Eintrag in `CHANGELOG.md` – eine Version pro Release, nur aufwärts.
3. `bin/check-version.sh origin/main` lokal grün.
4. PR gegen `main`, CI grün, mergen → Deploy läuft automatisch.
5. Im Update-Log (bzw. GitHub „Recent Deliveries“) prüfen: neue Version angekommen?
6. Tag setzen: `git tag -a v<version> -m "v<version>" <merge-commit> && git push origin v<version>`
   (Tag-Pushes lösen kein Update aus, die Antwort ist `skipped`).

**Jeder PR nach `main` braucht einen Versions-Bump** (Check `version`) – auch ein reiner Doku-PR.
Und jeder Merge nach `main` ist ein Deploy, auch wenn sich nur Doku geändert hat. Doku-Änderungen
deshalb in `base` sammeln und mit dem nächsten Release nach `main` bringen.

**Nach einem Hotfix direkt nach `main`** (PR von einem Branch ab `main`, z. B. 2.13.0 → 2.13.1):
`main` zurück nach `base` mergen, **bevor** der nächste Release-PR `base` → `main` geöffnet wird,
und die Version in `base` über die des Hotfixes heben. Sonst schlägt der Check `version` fehl (bzw.
der Release-PR hat Konflikte in den Versionszeilen).

---

## 4. Rollback

- **Normalfall:** Auf GitHub den PR öffnen → **Revert** → Revert-PR mergen. Das ist ein neuer Push auf
  `main` und deployt den alten Stand. Achtung: Die CI verlangt eine höhere Version – im Revert-PR
  die Version wieder **hochzählen** (nicht auf die alte zurücksetzen).
- **Ausnahme: Rollback von Release 2.13.0.** 2.13.0 hat `.github/workflows/ci.yml`,
  `bin/check-version.sh` und `.gitattributes` erst eingeführt. Ein kompletter GitHub-„Revert“ würde sie
  wieder löschen – dann läuft im Revert-PR keine CI, die Pflicht-Checks melden sich nie, und der PR
  ist im Ruleset (ohne Bypass) **nicht mergebar**. Deshalb nur den Plugin-Code zurücksetzen,
  `.github/`, `bin/` und `.gitattributes` bleiben:

  ```sh
  git fetch origin
  git switch -c revert-2.13.0 origin/main
  # Plugin-Code auf den Stand vor 2.13.0 (letzter main-Commit davor: 31e9aa7):
  git restore --source=31e9aa7 --staged --worktree -- assets includes uninstall.php wc-plz-filter.php
  # Danach die Version in wc-plz-filter.php (Header) und includes/class-wc-plz-filter.php
  # (const VERSION) auf eine Version > 2.13.0 setzen, z. B. 2.13.1.
  bin/check-version.sh origin/main
  git commit -am "Rollback: Plugin-Code vor 2.13.0, als v2.13.1"
  git push -u origin revert-2.13.0
  ```

  Dann PR `revert-2.13.0` → `main`, CI abwarten, mergen.
- **Notbremse Ruleset:** Hängt ein dringender PR an den Pflicht-Checks fest, kann der **Repo-Owner**
  das Ruleset vorübergehend abschalten: Settings → **Rules → Rulesets** → `main schützen` →
  Enforcement status **Disabled** → speichern. Nach dem Merge sofort wieder auf **Active** stellen.
- **Webhook kam nicht an / ist fehlgeschlagen:** Im Tab „Updates“ auf **„Jetzt aktualisieren“** klicken
  (lädt den aktuellen Stand des eingestellten Branches) oder auf GitHub die Zustellung „Redeliver“.

---

## 5. Notfall: Seite zeigt Fehler (Fatal Error)

Symptom: „Es gab einen kritischen Fehler auf dieser Website“ / weiße Seite direkt nach einem Update.
Solange das Plugin defekt oder inaktiv ist, funktioniert **auch der Webhook nicht** – ein Fix auf
GitHub kommt dann nicht von selbst an.

**Schritt 1 – Shop wieder online bringen (Betreiber):**

- **Variante A – Recovery-Mode-Mail:** WordPress schickt bei Fatal Errors eine E-Mail
  („… hat ein technisches Problem“) an die Admin-Adresse. Den Link darin öffnen, einloggen,
  unter Plugins **„DT Woohoo“ deaktivieren**.
- **Variante B – FTP / Datei-Manager des Hosters:** Ordner `wp-content/plugins/wc-plz-filter`
  umbenennen, z. B. in `wc-plz-filter-AUS`. WordPress deaktiviert das Plugin dann automatisch.
  (Falls der Ordner anders heißt: es ist der Ordner mit der Datei `wc-plz-filter.php`.)
  Den **ursprünglichen Ordnernamen notieren** – der Entwickler braucht ihn für das Fix-ZIP.

**Niemals** in WordPress auf Plugins → DT Woohoo → **„Löschen“** klicken: Dabei läuft
`uninstall.php` und löscht alle Einstellungen, Statistiken und Logs des Plugins.

Danach den Entwickler informieren (Uhrzeit, Fehlermeldung aus der Mail, Screenshot, **Name des
Plugin-Ordners**).

**Schritt 2 – Fix bereitstellen (Entwickler):** Fix bzw. Revert per PR nach `main` mergen (siehe
Rollback). Da der Webhook gerade tot ist, zusätzlich ein Installations-ZIP bauen und dem Betreiber
schicken. Der Ordner im ZIP muss **genau so heißen wie der Plugin-Ordner auf dem Server** – das ist
nicht unbedingt `wc-plz-filter`, denn der Updater behält immer den Namen des aktiven Ordners bei
(`fix_source_dir()`). Den echten Namen beim Betreiber erfragen (am besten schon vorab, solange
alles läuft):

- per FTP / Datei-Manager: der Ordner unter `wp-content/plugins/`, der `wc-plz-filter.php` enthält
  (nach Variante B: der Name **vor** dem Umbenennen), oder
- in der Plugin-Liste mit der Maus über „Deaktivieren“ bzw. „Aktivieren“ bei „DT Woohoo“ fahren:
  der Link enthält `plugin=<ordner>%2Fwc-plz-filter.php`.

```sh
git fetch origin && git archive --prefix=<ordner>/ -o <ordner>.zip origin/main
# Normalfall: git archive --prefix=wc-plz-filter/ -o wc-plz-filter.zip origin/main
```

Stimmt der Ordnername nicht, installiert WordPress das ZIP als **zweites Plugin**, statt
„Aktuelle Version mit der hochgeladenen ersetzen“ anzubieten. (Aus demselben Grund nicht das
„Download ZIP“ von GitHub verwenden: Dessen Ordner heißt `wc-plz-filter-main`.)

**Schritt 3 – Fix einspielen und wieder aktivieren (Betreiber):**

- Plugins → **Neues Plugin hinzufügen** → **Plugin hochladen** → ZIP vom Entwickler auswählen →
  „Jetzt installieren“.
  - Nach Variante A meldet WordPress, dass das Plugin schon installiert ist → **„Aktuelle Version
    mit der hochgeladenen ersetzen“**.
  - Nach Variante B wird ein neuer Ordner mit dem ursprünglichen Namen angelegt (der umbenannte
    `…-AUS` bleibt liegen).
- Plugins → „DT Woohoo“ **aktivieren**. Erst ab jetzt funktioniert der Webhook wieder.
  Die Einstellungen bleiben erhalten (sie liegen in der Datenbank, nicht im Ordner).
  - Nach Variante B stehen zwei „DT Woohoo“ in der Liste: das mit der **neuen, höheren
    Versionsnummer** aktivieren (Ordner mit dem ursprünglichen Namen, nicht `…-AUS`).
  - Steht das neue Plugin schon als **aktiv** da, ist nichts mehr zu tun: WordPress hatte es nach dem
    Umbenennen noch als aktiv geführt, der hochgeladene Fix ist damit sofort live.
- Im Tab „Updates“ prüfen, dass die erwartete Version angezeigt wird.
- Nach Variante B den alten Ordner `wc-plz-filter-AUS` **nur per FTP/Datei-Manager** löschen. In
  der Plugin-Liste erscheint er als zweites, inaktives „DT Woohoo“ – dort **nicht** auf „Löschen“
  klicken (siehe oben, löscht alle Daten).

Den umbenannten Ordner einfach zurückzubenennen ist keine Lösung: Das bringt den fehlerhaften Code
zurück, und solange WordPress das Plugin noch als aktiv führt, ist die Seite sofort wieder down.

---

## 6. Webhook-Secret rotieren

Wer: ein Benutzer mit der Berechtigung `manage_woohoo_updates` (Administratoren automatisch; weitere
Benutzer kann ein Administrator im Tab „Updates“ unter „Update-Rechte vergeben“ berechtigen).
Der Entwickler hat keinen Admin-Zugang – der Betreiber führt Schritt 1 aus.

1. WooCommerce → DT Woohoo → „Updates“ → **„Secret regenerieren“** → bestätigen. Das neue Secret steht
   im Feld „Webhook Secret“.
2. Secret auf sicherem Weg an den Entwickler (kein normales E-Mail/Chat im Klartext, wenn möglich).
3. GitHub: Settings → **Webhooks** → Webhook bearbeiten → Feld „Secret“ → neues Secret → „Update webhook“.
4. Testen: „Recent Deliveries“ → letzte Zustellung → „Redeliver“. Erwartet 200 (nicht 403).

Zwischen Schritt 1 und 3 schlagen alle Webhook-Zustellungen mit 403 fehl (es wird nichts installiert).

---

## 7. Update-Sperre (409)

Ein Update hält eine Sperre (Option `wc_plz_updater_lock`, Wert = Startzeit). Solange sie besteht,
werden weitere Updates abgewiesen:

- Webhook antwortet **409** `{"error":"locked"}`,
- „Jetzt aktualisieren“ zeigt „Update übersprungen: Es läuft bereits ein Update …“,
- im Update-Log steht ein Eintrag mit Status **Gesperrt**.

Das verhindert, dass zwei Installationen (z. B. Webhook + Klick, oder zwei schnelle Pushes)
gleichzeitig in denselben Plugin-Ordner schreiben. Die Sperre wird nach jedem Lauf freigegeben,
auch bei Fehlern.

**Automatischer Nachlauf:** GitHub stellt einen mit 409 abgewiesenen Webhook nicht von selbst erneut
zu. Deshalb plant das Plugin bei einem 409 per WP-Cron einen einmaligen Nachlauf (Hook
`wc_plz_updater_retry`) in ca. **60 Sekunden**. Ist die Sperre dann noch belegt, wird er erneut um
60 Sekunden verschoben – ohne Einträge im Update-Log, solange er wartet. Sobald die Sperre frei ist,
installiert er den aktuellen Stand des eingestellten Branches. Ein zweiter, schneller Push geht also
nicht verloren. (WP-Cron läuft nur bei Seitenaufrufen; ohne Besucher kann es etwas länger dauern.)

**Hängt die Sperre** (Lauf ist abgestürzt): einfach **10 Minuten warten** – danach gilt sie als
verwaist und das nächste Update übernimmt sie automatisch. Dann „Jetzt aktualisieren“ oder auf
GitHub „Redeliver“.

Die IDs der letzten 20 verarbeiteten Zustellungen (erfolgreich installiert oder wegen anderem
Branch übersprungen) stehen in `wc_plz_updater_deliveries` (Schutz vor doppelter Ausführung).
