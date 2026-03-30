# Projekt-Spezifikationen

## Regeln

- **API-Änderungen**: Bei jeder Änderung in `/api` → `CLAUDE.md` + `api/schema.php` aktualisieren, dann committen **und pushen** (Push triggert GitHub Action → Server-Deploy).
- **Webapp-Änderungen**: Mobile + Desktop berücksichtigen. Infos dürfen auf kleinen Screens reduziert/ausgeblendet werden — Kernfunktionalität muss auf beiden nutzbar sein.

## Stack

Angular-Webapp + PHP-REST-API, Fantasy-Football. Frontend: Angular (`standalone: false`, Signal-basiert). Backend: PHP. DB: MySQL.

## Repo-Struktur

```
die-bestesten/
├── .github/workflows/  — deploy-api.yml, deploy-webapp.yml (Push auf main → Deploy)
├── api/app/
│   ├── controller/     — Ein Controller pro Ressource (erbt _BaseController)
│   ├── database/       — Ein Trait pro Ressource; composited in base.database.php
│   ├── guard.php       — JWT + RBAC; setzt $GLOBALS['auth_role'/'auth_manager_id']
│   └── routing.php     — Routen + eingebettete API-Doku
├── api/index.php       — Einstiegspunkt; parst URL → Routing → Controller
├── api/schema.php      — Web-UI für API-Doku (Mermaid-ER + Endpunkte aus routing.php)
├── database/global_schema.sql, league_schema.sql
└── webapp/
```

## Webapp-Struktur

```
webapp/src/app/
├── auth/              — Login + JWT-Guard (auth.guard.ts, auth.service.ts, login/)
├── core/
│   ├── api.service.ts           — HTTP-Wrapper: get/post/patch<T>(path, body?)
│   ├── data-cache.service.ts    — Reaktiver Cache für Lookups
│   └── models/                  — club, country, division, matchday, player, season, transferwindow (je from()-Factory)
├── data/              — /app/data: club, country, division, player, season (Liste + Detail je)
└── shell/             — Layout: Sidebar (Desktop vertikal, Mobile bottom-bar) + Topbar
styles/
├── _variables.scss    — Design-Tokens: Farben, Abstände, Radii, Typografie, Breakpoints
├── _layout.scss       — .data-table, .table-container, .list-bar, .stat-card, .card, .page-title, .row-link, …
└── _buttons.scss, _inputs.scss, _typography.scss, _fonts.scss, _reset.scss
```

### Patterns

- **State**: Signals (`signal`, `computed`, `effect`) + RxJS via `toSignal`/`toObservable`
- **Komponenten**: `standalone: false`, SCSS mit `@use '../../../styles/variables' as *`
- **Routing**: Lazy-loaded Module; Detail-Routen als Kind-Routen im selben Modul
- **Styles**: Globale Klassen aus `_layout.scss` verwenden (`.row-link`, `.data-table`, `.col-id`) statt eigene SCSS schreiben

## API-Autorisierung (RBAC)

`$methodRoles` pro Controller: HTTP-Methode → Mindestrolle. Hierarchie: `guest(0) < manager(1) < maintainer(2) < admin(3)`. Fehlende Einträge = `guest`. 401 = kein Token, 403 = Rolle zu niedrig. Guard setzt `$GLOBALS['auth_manager_id']` + `$GLOBALS['auth_role']`.

## Datenbankschema

Vollständig in `database/global_schema.sql`. Alle IDs `CHAR(36)` UUID außer country (`CHAR(2)` ISO-Alpha-2).

| Tabelle | Spalten |
|---------|---------|
| country | id PK, name |
| season | id PK, start_date UNIQUE — aktiv = höchstes start_date |
| league | id PK, slug UNIQUE, name, db_name |
| club | id PK, country_id FK, name, short_name, logo_uploaded BOOL |
| division | id PK, name, level INT, seats INT, country_id FK |
| matchday | id PK, season_id FK, start_date DATE, kickoff_date DATETIME, number INT, completed BOOL |
| player | id PK, country_id FK?, first_name, last_name, displayname UNIQUE, birth_city, date_of_birth, height_cm, weight_kg |
| club_in_season | id PK, club_id FK, season_id FK, division_id FK, position INT? — UNIQUE(club_id, season_id) |
| player_in_season | id PK, player_id FK, season_id FK, price DECIMAL, position ENUM(GOALKEEPER/DEFENDER/MIDFIELDER/FORWARD), photo_uploaded — UNIQUE(player_id, season_id) |
| player_in_club | id PK, player_id FK, club_id FK, from_date DATE, to_date DATE?, on_loan BOOL — UNIQUE(player_id, club_id, from_date) |
| player_rating | id PK, player_id FK, matchday_id FK, club_id FK (zum Zeitpunkt), grade DECIMAL?, participation ENUM(starting/substitute)?, goals, assists, clean_sheet, sds BOOL, red_card, yellow_red_card, points — UNIQUE(player_id, matchday_id) |
| transferwindow | id PK, matchday_id FK, start_date DATETIME, end_date DATETIME — 2–4 pro Spieltag |

## API-Endpunkte

Vollständige Doku: `api/schema.php`.

```
GET/POST /club_in_season       — Saison-Zuordnungen; POST 409 bei Duplikat
PATCH    /club_in_season/:id   — Division/Position aktualisieren
GET      /division[/:id]
GET      /club[/:id]
GET      /country[/:id]
GET      /season[/:id|/active]
GET      /matchday[/:id]
PATCH    /matchday/:id         — {completed:bool} — Auth
GET      /transferwindow[/:id] — ?matchday_id|season_id
POST     /transferwindow/migrate — Admin
GET      /player_in_season/bundesliga_count — ?season_id (optional, default aktiv) → {count}
GET      /player[/:id]
POST     /player/migrate       — gibt migrated/skipped-Counts zurück
GET      /player_rating        — ?matchday_id&club_id (mit Spielerinfos)
POST     /player_rating/init   — {matchday_id,club_id} → leere Ratings erstellen — Maintainer+
PATCH    /player_rating/:id    — Maintainer+; 403 wenn Spieltag completed
POST     /auth                 — JWT-Login
POST     /auth/password-reset-request — {email} — sendet Reset-Link; immer 200 (kein E-Mail-Leak)
POST     /auth/password-reset — {token,new_password} — setzt Passwort zurück; 400 wenn Token ungültig/abgelaufen
GET      /manager/me           — {id,manager_name,alias,role,status} — Auth
PATCH    /manager/me           — {current_password,new_password} für Passwort; {email} allein für E-Mail — Auth
DELETE   /manager/me           — {password} — Auth; löscht nicht, sendet stattdessen Mail an Admin
```

## Liga-DB (`database/league_schema.sql`)

**manager**: id PK, manager_name UNIQUE, alias UNIQUE?, password, role ENUM(admin/maintainer/manager) DEFAULT manager, status ENUM(active/blocked/deleted) DEFAULT active, email UNIQUE?, date_of_birth?

**password_reset_token**: id PK, manager_id FK, token_hash VARCHAR(64) UNIQUE, expires_at DATETIME, used BOOL DEFAULT 0, created_at DATETIME

Ausstehend: team, team_rating, team_lineup, player_in_team
