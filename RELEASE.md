# Smrt-Shield veröffentlichen

## Voraussetzungen

- Die Versionsnummer im Plugin-Header und `SMRT_SHIELD_VERSION` müssen identisch sein.
- Der Versions-Tag muss exakt dazu passen, zum Beispiel `v2.1.1`.
- Der Tag darf erst nach den lokalen Prüfungen auf GitHub übertragen werden.

## Automatischer Release

Ein Push eines Tags im Format `v*` startet `.github/workflows/release.yml`. Der Workflow:

1. vergleicht Tag und Plugin-Version,
2. erstellt ein ZIP mit dem Stammordner `smrt-shield`,
3. erzeugt eine SHA-256-Prüfsumme,
4. veröffentlicht ein GitHub Release mit `smrt-shield.zip` und `SHA256SUMS`.

Der Updater akzeptiert ausschließlich das explizite Release-Asset `smrt-shield.zip`; die automatisch von GitHub erzeugten Quellcodearchive werden ignoriert.

## Rollout

1. Backup und Funktionsprüfung auf der lokalen Installation.
2. Release zunächst auf einer Pilot-Website installieren und beide Antwortfälle testen.
3. Erst danach auf weitere Websites verteilen oder automatische Updates freigeben.
