# Datenschutz-API (DSGVO)

Dieser Fork erweitert die Snipe-IT-API um drei Endpunkte für Betroffenenrechte nach der DSGVO:

| Methode | Pfad | Berechtigung | Zweck |
|---|---|---|---|
| `GET` | `/api/v1/users/{id}/personal-data` | Superadmin | Datenexport eines Nutzers (Art. 15 / 20 DSGVO) |
| `GET` | `/api/v1/account/personal-data` | jeder angemeldete Nutzer | Selbstauskunft über die eigenen Daten |
| `POST` | `/api/v1/users/{id}/anonymize` | Superadmin | Anonymisierung eines Nutzers (Art. 17 DSGVO) |

Zusätzlich liefern `GET /api/v1/users` und `GET /api/v1/users/{id}` das Feld `anonymized_at`.

Diese Endpunkte gibt es nur in diesem Fork, nicht im Original-Snipe-IT. In der offiziellen API-Referenz (snipe-it.readme.io) tauchen sie deshalb nicht auf.

## Allgemeines

### Authentifizierung

Wie die übrige Snipe-IT-API: ein persönliches API-Token im Header `Authorization: Bearer <token>`, dazu `Accept: application/json`.

### User-Agent

Ist unter *Einstellungen → Sicherheit* die Sperre für Skript-Clients aktiv, lehnt Snipe-IT Anfragen mit typischen Standard-User-Agents ab (z. B. `curl/`, `python-requests/`, `GuzzleHttp/`). Setze in Skripten deshalb einen eigenen, aussagekräftigen User-Agent, wie in den Beispielen unten.

### Fehlerantworten

Fehler kommen im üblichen Snipe-IT-Format:

```json
{
    "status": "error",
    "messages": "…",
    "payload": null
}
```

| Situation | HTTP-Status | `messages` |
|---|---|---|
| Keine Berechtigung (kein Superadmin) | `403` | `"403"` |
| Nutzer-ID existiert nicht | `200` | `"User not found"` |
| Fachlicher Fehler bei der Anonymisierung | `200` | Fehlermeldung, siehe dort |

Wichtig: Wie überall in der Snipe-IT-API melden fachliche Fehler den HTTP-Status `200`. Werte in Skripten immer das Feld `status` aus, nicht nur den HTTP-Status.

## Datenexport eines Nutzers

```http
GET /api/v1/users/{id}/personal-data
```

Liefert alles, was Snipe-IT über die Person speichert, als ein JSON-Dokument. Funktioniert auch für gelöschte und bereits anonymisierte Nutzer.

- **Berechtigung:** nur Superadmins. Der Export enthält IP-Adressen und die vollständige Anmeldehistorie.
- **Protokollierung:** Jeder Abruf wird im Verlauf des betroffenen Nutzers als „Personenbezogene Daten exportiert“ vermerkt, mit dem abrufenden Konto.
- **Gleiche Daten im Web:** Auf der Nutzerseite lädt der Button „Personenbezogene Daten exportieren“ dasselbe Dokument als Datei herunter.

```bash
# Export von Nutzer 42 in eine Datei schreiben
curl -sS \
  -H "Authorization: Bearer $SNIPEIT_TOKEN" \
  -H "Accept: application/json" \
  -A "Datenschutz-Export/1.0" \
  "https://snipeit.example.org/api/v1/users/42/personal-data" \
  -o personal-data-user-42.json
```

## Selbstauskunft

```http
GET /api/v1/account/personal-data
```

Liefert dasselbe Dokument wie der Datenexport, aber immer für das Konto, zu dem das API-Token gehört. Jeder Nutzer mit API-Zugang kann damit seine eigenen Daten abrufen. Auch dieser Abruf wird im eigenen Verlauf vermerkt.

```bash
# Eigene Daten abrufen
curl -sS \
  -H "Authorization: Bearer $SNIPEIT_TOKEN" \
  -H "Accept: application/json" \
  -A "Datenschutz-Export/1.0" \
  "https://snipeit.example.org/api/v1/account/personal-data"
```

## Aufbau des Exports

Beide Endpunkte liefern direkt das Dokument, ohne das übliche `status`/`payload`-Format. Datumswerte kommen als Zeichenkette aus der Datenbank, `generated_at` im ISO-8601-Format. JSON-Inhalte aus der Datenbank (z. B. `log_meta`, `permissions`) werden als Objekt ausgegeben, nicht als Zeichenkette.

| Schlüssel | Inhalt |
|---|---|
| `generated_at` | Zeitpunkt des Exports |
| `user_id` | ID des Nutzers |
| `profile` | Alle Spalten des Nutzerkontos außer Geheimnissen, dazu `has_password` und `has_two_factor_secret` (siehe unten) |
| `groups` | Gruppen (`id`, `name`) |
| `companies` | Firmen (`id`, `name`, `created_at`) |
| `assigned_items.assets` | Ausgegebene Assets (`id`, `asset_tag`, `name`, `serial`, `last_checkout`, `expected_checkin`) |
| `assigned_items.licenses` | Lizenzplätze (`seat_id`, `license_id`, `name`, `assigned_at`) |
| `assigned_items.accessories` | Zubehör (`id`, `accessory_id`, `name`, `note`, `assigned_at`) |
| `assigned_items.consumables` | Verbrauchsmaterial (`id`, `consumable_id`, `name`, `note`, `assigned_at`) |
| `acceptances` | Annahmebestätigungen samt Dateinamen von Unterschrift und EULA-PDF |
| `checkout_requests` | Anfragen nach Gegenständen |
| `asset_requests` | Ältere Asset-Anfragen |
| `maintenances` | Wartungen, bei denen die Person Nutzer des Geräts oder verantwortlich war |
| `manages` | IDs der geführten Nutzer, geführte Standorte und Abteilungen |
| `uploaded_files` | Zum Nutzer hochgeladene Dateien (Dateiname, Notiz, Datum) |
| `activity.about_user` | Aktivitätsprotokoll über die Person (sie ist Gegenstand oder Empfänger), vollständig mit Notiz, `log_meta`, IP-Adresse und User-Agent |
| `activity.performed_by_user` | Aktionen, die die Person selbst ausgeführt hat (siehe unten) |
| `login_attempts` | Anmeldeversuche mit Benutzername oder E-Mail-Adresse der Person, mit IP-Adresse und User-Agent |
| `api_tokens` | API-Tokens (`name`, `scopes`, `revoked`, `created_at`, `expires_at`), ohne das Token selbst |
| `password_reset_requests` | Zeitpunkte offener Passwort-Reset-Anfragen |
| `saved_searches` | Gespeicherte Suchen |
| `report_templates` | Eigene Berichtsvorlagen |
| `calendar_events` | Kalendereinträge aus Eintritts- und Austrittsdatum |
| `not_included` | Hinweise auf Daten, die der Export nicht abdeckt |

### Was bewusst fehlt

- **Geheimnisse:** Passwort-Hash, 2FA-Schlüssel, Remember-Token und Aktivierungs- und Reset-Codes werden nie exportiert. Im Profil steht stattdessen nur, ob sie vorhanden sind (`has_password`, `has_two_factor_secret`).
- **Daten Dritter:** Einträge in `activity.performed_by_user` enthalten weder Notiz noch `log_meta`. Dort stehen die Daten der Personen oder Gegenstände, die der Nutzer bearbeitet hat, und die gehören nicht in seine Auskunft (Art. 15 Abs. 4 DSGVO).
- **Dateien selbst:** Unterschriften, EULA-PDFs und Uploads sind nur mit Dateinamen aufgeführt.
- **Außerhalb der Datenbank:** Laravel-Logdateien, Backups und versendete E-Mails sind nicht enthalten.

### Beispiel (gekürzt)

```json
{
    "generated_at": "2026-11-14T22:50:00+01:00",
    "user_id": 42,
    "profile": {
        "id": 42,
        "first_name": "Erika",
        "last_name": "Mustermann",
        "username": "e.mustermann",
        "email": "e.mustermann@example.org",
        "anonymized_at": null,
        "has_password": false,
        "has_two_factor_secret": false
    },
    "groups": [{ "id": 3, "name": "mugen" }],
    "assigned_items": {
        "assets": [{ "id": 17, "asset_tag": "NB-0001", "name": "Notebook", "serial": "ABC123", "last_checkout": "2026-10-01 09:12:00", "expected_checkin": null }],
        "licenses": [],
        "accessories": [],
        "consumables": []
    },
    "activity": {
        "about_user": [{ "id": 901, "action_type": "checkout", "item_type": "App\\Models\\Asset", "item_id": 17, "remote_ip": "203.0.113.0", "user_agent": "Mozilla/5.0 …" }],
        "performed_by_user": []
    },
    "login_attempts": [{ "id": 55, "username": "e.mustermann", "successful": 1, "remote_ip": "203.0.113.0", "user_agent": "Mozilla/5.0 …", "created_at": "2026-11-14 08:01:00" }],
    "not_included": ["Application log files and backups of this Snipe-IT installation", "…"]
}
```

## Anonymisierung

```http
POST /api/v1/users/{id}/anonymize
```

Entfernt die personenbezogenen Daten eines Nutzers **unwiderruflich**. Der Asset-Verlauf bleibt erhalten, verweist aber auf ein Konto ohne Personenbezug.

- **Berechtigung:** nur Superadmins. Das eigene Konto lässt sich nicht anonymisieren.
- **Voraussetzung:** Der Person ist nichts mehr ausgegeben, und sie ist nicht mehr als Vorgesetzte von Nutzern oder Standorten eingetragen. Das sind dieselben Bedingungen wie beim Löschen.
- **Kein Request-Body nötig.**
- **Gleiche Funktion im Web:** Button „Benutzer anonymisieren“ auf der Nutzerseite, mit Bestätigung.

```bash
# Nutzer 42 anonymisieren
curl -sS -X POST \
  -H "Authorization: Bearer $SNIPEIT_TOKEN" \
  -H "Accept: application/json" \
  -A "Datenschutz-Export/1.0" \
  "https://snipeit.example.org/api/v1/users/42/anonymize"
```

### Antworten

Erfolg:

```json
{ "status": "success", "messages": "Der Benutzer wurde anonymisiert.", "payload": null }
```

Fachliche Fehler (HTTP-Status jeweils `200`, `status` = `"error"`):

| Ursache | Meldung (Deutsch) |
|---|---|
| Noch Gegenstände ausgegeben oder Vorgesetzten-Rolle | „Diesem Benutzer sind noch Gegenstände ausgegeben oder er ist noch Vorgesetzter von Benutzern bzw. Standorten. …“ |
| Bereits anonymisiert | „Dieser Benutzer ist bereits anonymisiert.“ |
| Eigenes Konto | „Du kannst dein eigenes Konto nicht anonymisieren.“ |
| Demo-Modus | „Diese Funktion wurde für die Demo-Installation deaktiviert.“ |

Die Meldungen kommen in der Spracheinstellung des Kontos, zu dem das API-Token gehört.

### Was passiert

**Nutzerkonto**

- Vorname „Anonymisierter Benutzer“, Nachname `#<ID>`, Benutzername `anonymized-<ID>`.
- Alle übrigen persönlichen Felder werden geleert, z. B. E-Mail-Adresse, Telefon, Adresse, Personalnummer, Notizen, Profilbild, Vorgesetzter, Standort und Abteilung.
- 2FA, Passwort, OIDC- und SCIM-Kennungen werden entfernt.
- Das Konto wird deaktiviert und gelöscht, `anonymized_at` wird gesetzt.
- Die Gruppenmitgliedschaften werden entfernt. Die Firmenzuordnung bleibt, damit die Firmen-Abgrenzung im Verlauf weiter stimmt.

**Aktivitätsprotokoll** (alle Einträge über, für oder von der Person)

- IP-Adresse und User-Agent werden entfernt.
- Name, Benutzername, E-Mail-Adresse und Personalnummer werden in Notizen und Änderungsdetails durch „[anonymisiert]“ ersetzt. Ersetzt werden nur ganze Wörter und erst ab drei Zeichen.
- Änderungsdetails des eigenen Profils, also frühere Werte wie alte Telefonnummern, werden komplett gelöscht.

**Gelöscht werden außerdem**

- Anmeldeversuche mit Benutzername oder E-Mail-Adresse der Person
- Passwort-Reset-Einträge
- API-Tokens
- private gespeicherte Suchen und Berichtsvorlagen; geteilte Vorlagen bleiben
- Kalendereinträge
- Profilbild und hochgeladene Dateien, einschließlich der Dateien selbst

**Bleibt erhalten**

- Unterschriebene Annahmebestätigungen und ihre EULA-PDFs, als Nachweis der Übergabe von Firmeneigentum.

Die Datenbankänderungen laufen in einer Transaktion. Dateien werden erst gelöscht, wenn die Transaktion erfolgreich abgeschlossen ist. Im Verlauf des Nutzers erscheint danach ein Eintrag „Anonymisiert“ mit dem ausführenden Konto.

**Nicht erfasst** werden Erwähnungen der Person in Notizen, die weder von ihr stammen noch sie betreffen, z. B. eine Notiz an einem fremden Asset, in der ihr Name vorkommt.

## Automatische Bereinigung

Ergänzend zur API gibt es unter *Einstellungen → Datenschutz* Aufbewahrungsfristen. Sie werden täglich durch den Befehl `snipeit:privacy-cleanup` angewendet, der auch von Hand laufen kann:

- IP-Adressen und User-Agents im Aktivitätsprotokoll nach X Tagen entfernen
- Anmeldeversuche nach X Tagen löschen
- Gelöschte Nutzer nach X Tagen automatisch anonymisieren, nach denselben Regeln wie oben
- Optional IP-Adressen nur gekürzt speichern (IPv4: letztes Oktett genullt, IPv6: nur die ersten 48 Bit)

Ohne gesetzte Fristen löscht der Befehl nichts.
