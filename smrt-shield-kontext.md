# smrt-shield – Projekt-Kontext für neuen Chat

## Was ist smrt-shield?

Ein **WordPress-Plugin** für **Elementor Pro Formulare**, das mehrschichtigen, DSGVO-konformen Spam-Schutz ohne externe Prüfdienste bietet. Ein kleines lokales Frontend-Skript erneuert dynamische Aufgaben; die Prüfung bleibt vollständig auf der eigenen WordPress-Installation. Autor: **SMARTini**. Version: **2.1.1**.

---

## Dateistruktur

```
/wp-content/plugins/smrt-shield/
├── smrt-shield.php                  ← Hauptdatei, Autoloader, Hooks
├── RELEASE.md                       ← Ablauf zum Veröffentlichen neuer Versionen
├── assets/js/math-challenge.js      ← Erneuert Aufgaben bei echten Seitenaufrufen
└── includes/
    ├── Activator.php                ← Activation Hook, erstellt DB-Tabelle
    ├── ElementorIntegration.php     ← Kern: Toggle, Felder-Injektion, Validierung
    ├── Logger.php                   ← Schreibt Blockierungen in die DB
    ├── LogsListTable.php            ← WP_List_Table für die Admin-Ansicht
    ├── AdminPage.php                ← Backend-Menüpunkt "Smrt-Shield"
    └── Updater.php                  ← Native Updates aus öffentlichen GitHub Releases
```

**GitHub:** `https://github.com/smartinist/smrt-shield.git` (Branch: `main`)

**Lokaler Pfad:** `/Users/stefanneumann/Local Sites/smrt-shield/app/public/wp-content/plugins/smrt-shield/`

---

## Namespace & Autoloader

Alle Klassen nutzen den Namespace `SmrtShield`. Der Autoloader in `smrt-shield.php` mappt `SmrtShield\Classname` → `includes/Classname.php`.

---

## Konzept & Architektur

### Toggle im Elementor Editor

Jedes Elementor-Pro-Formular bekommt unter **„Zusätzliche Optionen"** einen Schalter **„Smrt-Shield aktivieren"** (Elementor SWITCHER Control, Key: `smrt_shield_enable`, return value: `yes`). Ist er aus, passiert rein gar nichts.

Hook: `elementor/element/form/section_form_options/before_section_end`

---

### Frontend-Injektion (Die Falle)

Hook: `elementor/widget/render_content` (Filter, gibt `$content` zurück)

Wenn der Toggle aktiv ist, werden **vor dem schließenden `</form>`-Tag** per `str_replace` die versteckten Schutzfelder injiziert (in einem `<div>` mit `position:absolute; left:-9999px; opacity:0; z-index:-1`):

1. **Honeypot-Feld** – `name="smrt_organization_url"` (verlockender Bot-Name, unsichtbar, `tabindex="-1"`, `autocomplete="off"`)
2. **Timestamp-Feld** – `name="smrt_timestamp"` – enthält `time()` beim Seitenaufruf
3. **Hash-Feld** – `name="smrt_timestamp_hash"` – enthält `wp_hash($timestamp, 'nonce')` zur Manipulationssicherung
4. **Reset-fester Timestamp-Beweis** – trägt Timestamp und Signatur kodiert im Feldnamen; nötig für Elementor-Popups, die Hidden-Values leeren

Elementor-Popups werden bei jedem Öffnen serverseitig frisch ausgegeben. Zusätzlich aktualisiert das lokale Skript Challenge und Timestamp über den REST-Endpunkt. Falls Elementor oder ein Optimierungsplugin Hidden-Values zurücksetzt, nutzt die serverseitige Prüfung den kryptografisch gleichwertigen, reset-festen Beweis aus dem Feldnamen. Timestamp und Signatur sind seit 2.1 an die Elementor-Widget-ID gebunden und maximal zwei Stunden gültig.

### Rechenaufgabe (seit Version 2.0)

Für jedes Formular mit aktivem Smrt-Shield wird standardmäßig direkt vor dem Absende-Button eine einfache, zufällige Additions- oder Subtraktionsaufgabe ausgegeben. Das Ergebnis ist immer eine nichtnegative ganze Zahl.

- Die Aufgabe wird bei jeder serverseitigen Formularausgabe neu erzeugt.
- Ein kleines lokales Frontend-Skript lädt zusätzlich bei jedem tatsächlichen Seitenaufruf eine frische Aufgabe vom WordPress-REST-Endpunkt `smrt-shield/v1/challenge`. Damit bleiben Aufgaben auch bei Elementor- oder Seiten-Caches wechselnd.
- Operanden, Operator und Zufallswert liegen in einem Base64-kodierten Payload.
- Der Payload wird mit `wp_hash(..., 'nonce')` und den individuellen WordPress-Salts signiert.
- Die Lösung selbst steht nicht in einem versteckten Feld; sie wird bei der Validierung serverseitig berechnet.
- Unter dem Zahlenfeld steht der sichtbare, per `aria-describedby` verknüpfte Hinweis „Ergebnis als Zahl eingeben“.
- Ein zusätzlicher reset-fester Beweis kodiert Challenge und Signatur im Namen eines versteckten Felds. Er enthält ebenfalls keine Lösung und kann wegen der WordPress-Signatur nicht manipuliert werden.
- Jede Challenge ist an die konkrete Elementor-Widget-ID gebunden, enthält ihren signierten Ausgabezeitpunkt und ist höchstens zwei Stunden gültig.
- Ein gültiger Challenge-Beweis wird nach dem ersten Prüfversuch zwei Stunden lang per WordPress-Transient als verbraucht markiert. Gespeichert wird nur ein SHA-256-Schlüssel, keine Formular- oder IP-Daten.
- Ein fehlender, manipulierter oder falsch beantworteter Challenge-Request wird blockiert.
- Es gibt keine globale Optionsseite.
- Im einzelnen Elementor-Formular kann die Rechenaufgabe mit `smrt_shield_disable_math_challenge` ausdrücklich deaktiviert werden. Fehlt diese neue Einstellung bei einem Bestandsformular, bleibt die Rechenaufgabe aktiv.

Schlägt die REST-Aktualisierung fehl, ist JavaScript verzögert/deaktiviert oder leert Elementor die Hidden-Values, bleibt die serverseitig gerenderte und reset-fest signierte Aufgabe nutzbar.

---

### Validierung – 5-Layer-Check (Backend)

Hook: `elementor_pro/forms/validation` (Action)

> **Wichtig:** Settings werden einzeln abgefragt: `$record->get_form_settings('smrt_shield_enable')` – NICHT ohne Parameter (verursacht Fatal Error in neueren Elementor Pro Versionen).

**Reihenfolge der Checks:**

| # | Check | Blockiergrund (Log) |
|---|-------|---------------------|
| 1 | **Größenlimit** – zu viele oder zu große verarbeitete Werte | `Formularinhalt zu groß` |
| 2 | **Honeypot** – Feld `smrt_organization_url` nicht leer | `Honeypot ausgelöst` |
| 3 | **Timestamp-Integrität** – Hash stimmt nicht überein oder Felder fehlen | `Manipulierter/Fehlender Timestamp` |
| 4a | **Mindestzeit** – Differenz zwischen Timestamp und `time()` < 3 Sekunden | `Zu schnell ausgefüllt (< 3s)` |
| 4b | **Höchstalter** – Timestamp ist älter als zwei Stunden | `Formular abgelaufen` |
| 5 | **Rechenaufgabe** – signierte, formulargebundene, unverbrauchte Aufgabe und korrekte Ganzzahl | `Rechenaufgabe falsch beantwortet` |
| 6a | **Zeichensatz-Check** (Whitelist-Regex) | `Nicht-lateinischer Zeichensatz erkannt` |
| 6b | **Keyword-Blacklist** (`stripos`, case-insensitive) | `Blacklist-Keyword: [Wort] gefunden` |
| 6c | **URL-Check** (externe URLs zählen) | `Externe URL im Formular gefunden` |

Das Größenlimit erlaubt höchstens 100 verarbeitete Werte, 20.000 Byte pro Wert und 100.000 Byte insgesamt. Hochgeladene Dateiinhalte werden dabei nicht mitgezählt.

**Zeichensatz-Regex (Whitelist-Ansatz):**
```php
$forbidden_regex = '/[^\p{Latin}\p{Common}\p{Inherited}]/u';
```
Blockiert Bengali, Kyrillisch, Arabisch, Han, Devanagari, Hebräisch, Thai usw. in einem Schritt. Lässt Umlaute, Akzente, Satzzeichen durch.

> **Bugfix-Geschichte:** Vorher Blacklist mit nur 3 Schriften (`\p{Cyrillic}|\p{Arabic}|\p{Han}`) – Bengali (`হাই, আমি...`) kam durch. Gefixt durch Umstieg auf Whitelist-Ansatz.

**Blacklist (erweiterbar via Filter):**
```php
$blacklist = apply_filters('smrt_shield_blacklist', [
    'seo optimization', 'crypto', 'bitcoin', 'viagra', 'enlargement',
    'hack your', 'million dollars', 'make money online', 'guest post'
]);
```

**URL-Check:**
- Ausgenommen: `home_url()` und `wp_get_referer()` (aktuelle Seiten-URL)
- Schwellenwert: `> 0` externe URLs → blockieren

**Bei Spam:** Generische Fehlermeldung: *„Ein Fehler ist aufgetreten. Bitte laden Sie die Seite neu..."* – keine spezifischen Infos für Bots.

---

## Datenbank

Tabelle: `wp_smrt_shield_logs`

| Spalte | Inhalt |
|---|---|
| `id` | Auto-Increment |
| `time` | Zeitstempel der Blockierung |
| `form_id` | Formularname oder ID |
| `block_reason` | Detaillierter Grund |
| `user_agent` | Browser/Bot User-Agent |
| `honeypot_value` | Wert des Honeypots oder blockierten Texts |

**Keine IP-Adressen, keine echten Nutzerdaten** → DSGVO-konform. Tabelle wird beim Plugin-Aktivieren via `register_activation_hook` + `dbDelta()` erstellt.

---

## Admin-Backend

- Eigener Menüpunkt **„Smrt-Shield"** im WordPress-Hauptmenü (Icon: `dashicons-shield`)
- `WP_List_Table` mit Pagination (20/Seite), Sortierung nach Zeit, Bulk-Aktion „Löschen"

---

## Updates über GitHub (seit Version 2.0)

- `Update URI`: `https://github.com/smartinist/smrt-shield`
- `Updater.php` verwendet den nativen WordPress-Filter `update_plugins_github.com`.
- Geprüft wird ausschließlich das jeweils neueste öffentliche GitHub Release.
- Ein Update wird nur angeboten, wenn das Release ein explizites Asset namens `smrt-shield.zip` enthält.
- Automatisch erzeugte GitHub-Quellcodearchive werden nicht verwendet, weil ihr Stammordner nicht dem Plugin-Slug entspricht.
- Erfolgreiche Antworten werden sechs Stunden, Fehler eine Stunde als Site Transient gecacht.
- Das Release-Asset wird durch den Workflow `.github/workflows/release.yml` gebaut.

---

## Wichtige Quirks & Design-Entscheidungen

- `get_form_settings()` muss mit Schlüssel aufgerufen werden → sonst Fatal Error in Elementor Pro
- Felder-Injektion läuft über `elementor/widget/render_content` Filter (kein `elementor-pro/forms/render_end`, der existiert nicht)
- Nur für die Rechenaufgabe gibt es ein kleines, abhängigkeitenfreies Frontend-Skript; es kommuniziert ausschließlich mit der eigenen WordPress-Installation.
- Das Frontend-Skript erneuert auch bei deaktivierter Rechenaufgabe den Timestamp. `data-nowprocket` und `data-no-optimize` verhindern, dass gängige Optimierungsplugins den sicherheitsrelevanten Refresh verzögern.
- Funktioniert auch in Elementor-Popups; Aufgabe und Timestamp werden beim serverseitigen Popup-Aufruf neu erzeugt und nach Elementor-Resets abgesichert
- Die Rechenaufgabe wird vor dem Elementor-Submit-Feld platziert; bei geändertem Elementor-Markup fällt die Injektion auf das Formularende zurück.
