# Privacy API (GDPR)

This fork adds three API endpoints for data subject rights under the GDPR:

| Method | Path | Permission | Purpose |
|---|---|---|---|
| `GET` | `/api/v1/users/{id}/personal-data` | Superuser | Personal data export of a user (Art. 15 / 20 GDPR) |
| `GET` | `/api/v1/account/personal-data` | Any authenticated user | Export of the caller's own data |
| `POST` | `/api/v1/users/{id}/anonymize` | Superuser | Anonymize a user (Art. 17 GDPR) |

In addition, `GET /api/v1/users` and `GET /api/v1/users/{id}` now return the field `anonymized_at`.

These endpoints only exist in this fork, not in upstream Snipe-IT, so they are not part of the official API reference (snipe-it.readme.io).

## General

### Authentication

Same as the rest of the Snipe-IT API: a personal access token in the `Authorization: Bearer <token>` header, plus `Accept: application/json`.

### User agent

If blocking of scripted API clients is enabled under *Settings → Security*, Snipe-IT rejects requests carrying common default user agents (e.g. `curl/`, `python-requests/`, `GuzzleHttp/`). Set a custom, descriptive user agent in scripts, as the examples below do.

### Error responses

Errors use the usual Snipe-IT envelope:

```json
{
    "status": "error",
    "messages": "…",
    "payload": null
}
```

| Situation | HTTP status | `messages` |
|---|---|---|
| Not permitted (not a superuser) | `403` | `"403"` |
| User ID does not exist | `200` | `"User not found"` |
| Anonymization not possible | `200` | Error message, see below |

Note: as everywhere in the Snipe-IT API, business errors are returned with HTTP status `200`. Always check the `status` field in scripts, not just the HTTP status.

## Personal data export

```http
GET /api/v1/users/{id}/personal-data
```

Returns everything Snipe-IT stores about the person as a single JSON document. Works for deleted and already anonymized users too.

- **Permission:** superusers only, since the export contains IP addresses and the complete login history.
- **Audit trail:** every export is recorded in the user's history as "Personal data exported", together with the account that requested it.
- **Same data in the web UI:** the "Export personal data" button on the user page downloads the same document as a file.

```bash
# Write the export of user 42 to a file
curl -sS \
  -H "Authorization: Bearer $SNIPEIT_TOKEN" \
  -H "Accept: application/json" \
  -A "privacy-export/1.0" \
  "https://snipeit.example.org/api/v1/users/42/personal-data" \
  -o personal-data-user-42.json
```

## Own data export

```http
GET /api/v1/account/personal-data
```

Returns the same document as the personal data export, always for the account the API token belongs to. Every user with API access can use it to retrieve their own data. This export is recorded in the user's own history as well.

```bash
# Retrieve your own data
curl -sS \
  -H "Authorization: Bearer $SNIPEIT_TOKEN" \
  -H "Accept: application/json" \
  -A "privacy-export/1.0" \
  "https://snipeit.example.org/api/v1/account/personal-data"
```

## Export format

Both endpoints return the document directly, without the usual `status`/`payload` envelope. Dates are returned as stored in the database; `generated_at` is ISO 8601. JSON stored in the database (e.g. `log_meta`, `permissions`) is returned as an object, not as a string.

| Key | Content |
|---|---|
| `generated_at` | Time of the export |
| `user_id` | ID of the user |
| `profile` | All columns of the user account except secrets, plus `has_password` and `has_two_factor_secret` (see below) |
| `groups` | Groups (`id`, `name`) |
| `companies` | Companies (`id`, `name`, `created_at`) |
| `assigned_items.assets` | Checked-out assets (`id`, `asset_tag`, `name`, `serial`, `last_checkout`, `expected_checkin`) |
| `assigned_items.licenses` | License seats (`seat_id`, `license_id`, `name`, `assigned_at`) |
| `assigned_items.accessories` | Accessories (`id`, `accessory_id`, `name`, `note`, `assigned_at`) |
| `assigned_items.consumables` | Consumables (`id`, `consumable_id`, `name`, `note`, `assigned_at`) |
| `acceptances` | Checkout acceptances, including the file names of the signature and the EULA PDF |
| `checkout_requests` | Requests for items |
| `asset_requests` | Legacy asset requests |
| `maintenances` | Maintenances where the person was the device's user or the responsible party |
| `manages` | IDs of managed users, managed locations and departments |
| `uploaded_files` | Files uploaded to the user (file name, note, date) |
| `activity.about_user` | Activity log entries about the person (as item or target), complete with note, `log_meta`, IP address and user agent |
| `activity.performed_by_user` | Actions the person performed (see below) |
| `login_attempts` | Login attempts with the person's username or email address, including IP address and user agent |
| `api_tokens` | API tokens (`name`, `scopes`, `revoked`, `created_at`, `expires_at`), never the token itself |
| `password_reset_requests` | Times of pending password reset requests |
| `saved_searches` | Saved searches |
| `report_templates` | Own report templates |
| `calendar_events` | Calendar entries derived from start and end date |
| `not_included` | Data the export does not cover |

### Deliberately excluded

- **Secrets:** password hash, 2FA secret, remember token, activation and reset codes are never exported. The profile only states whether they exist (`has_password`, `has_two_factor_secret`).
- **Third-party data:** entries in `activity.performed_by_user` carry neither note nor `log_meta`. Those describe the people or items the user worked on and do not belong in this person's export (Art. 15(4) GDPR).
- **Files themselves:** signatures, EULA PDFs and uploads are listed by file name only.
- **Outside the database:** Laravel log files, backups and sent emails are not included.

### Example (abridged)

```json
{
    "generated_at": "2026-11-14T22:50:00+01:00",
    "user_id": 42,
    "profile": {
        "id": 42,
        "first_name": "Jane",
        "last_name": "Doe",
        "username": "jane.doe",
        "email": "jane.doe@example.org",
        "anonymized_at": null,
        "has_password": false,
        "has_two_factor_secret": false
    },
    "groups": [{ "id": 3, "name": "Staff" }],
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
    "login_attempts": [{ "id": 55, "username": "jane.doe", "successful": 1, "remote_ip": "203.0.113.0", "user_agent": "Mozilla/5.0 …", "created_at": "2026-11-14 08:01:00" }],
    "not_included": ["Application log files and backups of this Snipe-IT installation", "…"]
}
```

## Anonymization

```http
POST /api/v1/users/{id}/anonymize
```

**Irreversibly** removes a user's personal data. The asset history is kept, but points to an account that no longer identifies anyone.

- **Permission:** superusers only. You cannot anonymize your own account.
- **Checked-out items do not block it.** Assets, accessories, licenses and consumables still assigned to the person stay assigned to the anonymized account (see below).
- **No request body needed.**
- **Same function in the web UI:** the "Anonymize user" button on the user page, with confirmation.

```bash
# Anonymize user 42
curl -sS -X POST \
  -H "Authorization: Bearer $SNIPEIT_TOKEN" \
  -H "Accept: application/json" \
  -A "privacy-export/1.0" \
  "https://snipeit.example.org/api/v1/users/42/anonymize"
```

### Responses

Success:

```json
{ "status": "success", "messages": "The user has been anonymized.", "payload": null }
```

Business errors (HTTP status `200`, `status` = `"error"`):

| Cause | Message |
|---|---|
| Already anonymized | "This user has already been anonymized." |
| Own account | "You cannot anonymize your own account." |
| Demo mode | "This feature has been disabled for the demo installation." |

Messages are returned in the language set for the account the API token belongs to.

### What happens

**User account**

- First name "Anonymized user", last name `#<ID>`, username `anonymized-<ID>`. Both values are stored in the language of the installation.
- All other personal fields are cleared, e.g. email address, phone, address, employee number, notes, avatar, manager, location and department.
- 2FA, password, OIDC and SCIM identifiers are removed.
- The account is deactivated and deleted, and `anonymized_at` is set.
- Group memberships are removed. Company assignments are kept so that company scoping of the history still works.

**Activity log** (every entry about, for or by the person)

- IP address and user agent are removed.
- Name, username, email address and employee number are replaced with "[anonymized]" (in the language of the installation) in notes and change details. Only whole words of at least three characters are replaced.
- Change details of the person's own profile, i.e. previous values such as old phone numbers, are deleted entirely.

**Also deleted**

- Login attempts with the person's username or email address
- Password reset entries
- API tokens
- Private saved searches and report templates; shared templates are kept
- Calendar entries
- Avatar and uploaded files, including the files themselves

**Kept**

- Signed checkout acceptances and their EULA PDFs, as proof of the handover of company property.
- Items still checked out to the person (assets, accessories, licenses, consumables). They stay assigned to the anonymized account, remain visible on the item and can be checked in later as usual.
- Users and locations that have the person as their manager keep pointing at the anonymized account.

The database changes run in a single transaction. Files are only deleted once the transaction has been committed. Afterwards the user's history shows an "Anonymized" entry naming the account that performed it.

**Not covered:** mentions of the person in notes that are neither written by nor about them, e.g. a note on someone else's asset that mentions their name.

## Automatic cleanup

In addition to the API, *Settings → Privacy* offers retention periods. They are applied daily by the command `snipeit:privacy-cleanup`, which can also be run by hand:

- Remove IP addresses and user agents from the activity log after X days
- Delete login attempts after X days
- Automatically anonymize deleted users after X days, following the same rules as above
- Optionally store IP addresses truncated only (IPv4: last octet zeroed, IPv6: first 48 bits only)

With no retention periods set, the command deletes nothing.
