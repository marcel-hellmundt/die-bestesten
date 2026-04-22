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
│   ├── guard.php       — JWT + RBAC; setzt $GLOBALS['auth_roles'(array)/'auth_manager_id']
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

**Additives Rollenmodell**: Jeder Manager hat die Basisrolle `manager` (implizit). Zusätzliche Rollen (`maintainer`, `admin`) werden in der `manager_role`-Tabelle gespeichert und sind frei kombinierbar.

`$methodRoles` pro Controller: HTTP-Methode → erforderliche Rolle. Prüfung: `guest` = kein Token nötig; `manager` = jeder eingeloggte Manager; `maintainer`/`admin` = Manager muss diese Rolle in seiner Rollenliste haben. Fehlende Einträge = `guest`. 401 = kein Token, 403 = Rolle fehlt. Guard setzt `$GLOBALS['auth_manager_id']` + `$GLOBALS['auth_roles']` (Array).

Rollenvergabe: `POST /manager/:id/roles` mit `{role}`, Entzug: `DELETE /manager/:id/roles/:role` — jeweils Admin.

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
| player | id PK, kicker_id INT UNIQUE?, country_id FK?, first_name, last_name, displayname UNIQUE, birth_city, date_of_birth, height_cm, weight_kg |
| club_in_season | id PK, club_id FK, season_id FK, division_id FK, position INT? — UNIQUE(club_id, season_id) |
| player_in_season | id PK, player_id FK, season_id FK, price DECIMAL, position ENUM(GOALKEEPER/DEFENDER/MIDFIELDER/FORWARD), photo_uploaded — UNIQUE(player_id, season_id) |
| player_in_club | id PK, player_id FK, club_id FK, from_date DATE, to_date DATE?, on_loan BOOL — UNIQUE(player_id, club_id, from_date) |
| player_rating | id PK, player_id FK, matchday_id FK, club_id FK? (zum Zeitpunkt; NULL für historische Daten), grade DECIMAL?, participation ENUM(starting/substitute)?, goals, assists, clean_sheet, sds BOOL, red_card, yellow_red_card, points — UNIQUE(player_id, matchday_id) |
| transferwindow | id PK, matchday_id FK, start_date DATETIME, end_date DATETIME — 2–4 pro Spieltag |
| stadium | id PK, official_name, name? (Spitzname/Alltagsname), capacity INT?, lat DECIMAL(9,6)?, lng DECIMAL(9,6)?, opened_date DATE?, closed_date DATE? |
| club_stadium | id PK, club_id FK, stadium_id FK, from_date DATE, to_date DATE? — UNIQUE(club_id, from_date) |
| award | id PK, name UNIQUE, icon VARCHAR(100)? (nur Dateiname, z.B. "trophy.png" → public/img/icons/), sort_index INT — Award-Typen; sort_index = Wichtigkeit (1 = wichtigster) |

## API-Endpunkte

Vollständige Doku: `api/schema.php`.

```
GET/POST /club_in_season       — Saison-Zuordnungen; POST 409 bei Duplikat
PATCH    /club_in_season/:id   — Division/Position aktualisieren
GET      /division[/:id]
GET      /club[/:id]           — /:id enthält stadium-Objekt (aktuelles Stadion, to_date IS NULL) oder null
GET      /country[/:id]
GET      /season[/:id|/active]
GET      /matchday[/:id]       — ?season_id gibt has_ratings (bool) zurück ob mindestens ein player_rating für den Spieltag existiert
PATCH    /matchday/:id         — {completed:bool} — Auth
GET      /all_time_standings   — { standings: [{id,manager_name,alias,total_points}], top_matchdays: [{points,matchday_number,team_name,season_id,manager_name}] } — Auth
GET      /league[/:id]         — enthält manager_count aus der jeweiligen Liga-DB
POST     /league/migrate       — {league_id} — Teams + TeamRatings aus Old-DB in Liga-DB migrieren — Admin
GET      /transferwindow[/:id] — ?matchday_id|season_id
POST     /transferwindow       — {matchday_id,start_date,end_date} — Maintainer+
POST     /transferwindow/migrate — Admin
GET      /team_lineup          — ?team_id (erforderlich), ?matchday_id (optional) → {matchday, matchdays[], nominated[], bench[], points, max_points} — jeder Spieler enthält grade, points, goals, assists, clean_sheet, sds, participation; Auto-Init für aktuellen Spieltag wenn noch keine Einträge — Auth
PATCH    /team_lineup          — {team_id, matchday_id, players:[{player_id, nominated, position_index}]} — nur eigenes Team, nur Editierfenster (start_date ≤ now < kickoff_date) — Auth
GET      /player_in_team             — ?team_id (erforderlich) → aktive Spieler mit position, price, points, current_club_id, club_logo_uploaded; ?include_former=1 → {current, former}; ?player_id → {id, season_id, team_name, color, manager_name, alias, manager_id} oder null (welches Team besitzt diesen Spieler) — Auth
POST     /sell                       — {team_id, player_id, transferwindow_id} — nur eigenes Team, nur offenes Fenster; erstellt sell + transaction, setzt player_in_team.to_matchday_id, bereinigt team_lineup (nominated → alles löschen, bench → nur Spieler) — Auth
POST     /buy                        — {team_id, player_id, transferwindow_id} — nur eigenes Team, nur offenes Fenster; 409 wenn Spieler bereits in einem Team oder Positionslimit erreicht (GK≤2, DEF≤6, MID≤6, FWD≤4); erstellt player_in_team + transaction (negativ) — Auth
GET      /player_in_season/bundesliga_count — ?season_id (optional, default aktiv) → {count}
GET      /player[/:id]           — ?club_id=UUID gibt aktuellen Kader zurück (player_in_club.to_date IS NULL) mit season_position
POST     /player/migrate       — gibt migrated/skipped-Counts zurück
GET      /player_rating        — ?matchday_id&club_id → Spielerinfos + price, starting_count (Starts in der Saison); sortiert nach starting_count DESC, position, price DESC
GET      /player_rating/status — ?matchday_id → [{club_id, rating_count, starter_count, grade_count, goals, assists, has_sds}] — aggregierter Status aller Clubs für einen Spieltag
POST     /player_rating/init   — {matchday_id,club_id} → leere Ratings erstellen (gleiche ID in alte DB gespiegelt); 409 wenn completed oder (vor kickoff_date und nicht Admin) — Maintainer+
POST     /player_rating/validate-csv — multipart: matchday_id + csv-Datei (;-getrennt, Spalte 4 = Angezeigter Name, Spalte 8 = Punkte) → {ok, checked?} oder {ok: false, mismatches: [{displayname, csv_points, db_points}]} — Maintainer+
PATCH    /player_rating/:id    — Maintainer+; 403 wenn Spieltag completed; Body: grade, participation, goals, assists, clean_sheet, sds, red_card, yellow_red_card (points wird immer serverseitig berechnet); Änderungen + berechnete points werden in alte DB gespiegelt
POST     /auth                 — JWT-Login
POST     /auth/password-reset-request — {email} — sendet Reset-Link; immer 200 (kein E-Mail-Leak)
POST     /auth/password-reset — {token,new_password} — setzt Passwort zurück; 400 wenn Token ungültig/abgelaufen
GET      /team_rating          — ?season_id → { matchday, ratings[], sds_player, max_matchday_number } letzter gestarteter Spieltag; bei nicht-abgeschlossenem Spieltag: Live-Punkte aus player_rating × team_lineup (fine = 0) — Auth
GET      /team_rating/season   — ?season_id → aggregierte Saisontabelle aller Teams, sortiert nach Punkten — Auth
GET      /team/mine            — Eigenes Team der aktiven Saison {id, team_name, season_id, color}; 404 wenn kein Team — Auth
GET      /team/:id             — Team per ID (manager_name, alias, total_points, matchdays_played) — Auth
GET      /manager/me           — {id,manager_name,alias,role,status} — Auth
PATCH    /manager/me           — {current_password,new_password} für Passwort; {email} allein für E-Mail — Auth
DELETE   /manager/me           — {password} — Auth; löscht nicht, sendet stattdessen Mail an Admin
GET      /transaction          — ?team_id (erforderlich) → {budget, transactions[]} — nur eigenes Team (403 sonst) — Auth
GET      /search               — ?q (min. 3 Zeichen) → {players[], clubs[], teams[], managers[]} — max. 8 je Typ; teams enthalten season_label — Auth
```

## Liga-DB (`database/league_schema.sql`)

**manager**: id PK, manager_name UNIQUE, alias UNIQUE?, password, status ENUM(active/blocked/deleted) DEFAULT active, email UNIQUE?, date_of_birth?

**manager_role**: id PK, manager_id FK, role ENUM(maintainer/admin) — UNIQUE(manager_id, role) — additiv; jeder Manager hat implizit 'manager'

**password_reset_token**: id PK, manager_id FK, token_hash VARCHAR(64) UNIQUE, expires_at DATETIME, used BOOL DEFAULT 0, created_at DATETIME

**team**: id PK, manager_id FK, season_id (cross-DB, kein FK), team_name VARCHAR(100), color VARCHAR(7)?, created_at — UNIQUE(manager_id, season_id)

**transaction**: id PK, team_id FK, amount DECIMAL(10,2), reason VARCHAR(255), matchday_id (cross-DB, kein FK)?, created_at — Budget = SUM(amount) pro team_id

**team_rating**: id PK, team_id FK, matchday_id (cross-DB), points, max_points, goals, assists, clean_sheet, sds, sds_defender, missed_goals, points_goalkeeper/defender/midfielder/forward (denorm.), invalid BOOL — UNIQUE(team_id, matchday_id)

**team_award**: id PK, team_id FK, award_id (cross-DB auf global_schema.award, kein FK) — UNIQUE(award_id, team_id) — season ergibt sich aus team.season_id

**sell**: id PK, player_id (cross-DB), team_id FK (Verkäufer), transferwindow_id (cross-DB), price INT, created_at

**player_in_team**: id PK, team_id FK, player_id (cross-DB), from_matchday_id (cross-DB, Kauf), to_matchday_id (cross-DB, Verkauf; NULL = aktiv), offer_id FK?, sell_id FK? — UNIQUE(player_id, from_matchday_id) — max. 1 aktives Team pro Spieler wird auf Applikationsebene geprüft

**team_lineup**: id PK, team_id FK, player_id (cross-DB), matchday_id (cross-DB), nominated BOOL, position_index INT? — UNIQUE(team_id, player_id, matchday_id) — alle Kader-Spieler des Spieltags; nominated=1 = aufgestellt

**maintainer_contribution**: id PK, manager_id FK, player_rating_id (cross-DB auf global_schema.player_rating, kein FK), contribution_type ENUM(bulk_create/manual_create/grade), created_at — UNIQUE(player_rating_id, contribution_type) — trackt welcher Maintainer Aufstellung/Noten eingetragen hat; grade-Einträge werden per UPSERT ersetzt (letzter Setzer behält Credit)
