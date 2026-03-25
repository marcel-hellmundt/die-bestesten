# Projekt-Spezifikationen

## Regeln

- **API-Änderungen**: Bei jeder Änderung in `/api` müssen `CLAUDE.md` (Datenbankschema + Endpunkte) und `api/schema.php` (routing.php-Docs) mitaktualisiert werden. Danach immer committen **und pushen** — nur durch Push wird die GitHub Action ausgelöst, die die Änderungen auf den Server deployed.
- **Webapp-Änderungen**: Immer Mobile- und Desktop-Kompatibilität berücksichtigen. Informationen dürfen auf kleinen Screens ausgeblendet oder reduziert werden, wenn der Platz nicht reicht — aber die Kernfunktionalität muss auf beiden nutzbar sein.

## Überblick

Mono-Repository mit einer Angular-Webapp und einer PHP-REST-API für eine Fantasy-Football-Plattform.

## Repo-Struktur (High-Level)

```
die-bestesten/
├── .github/workflows/        — deploy-api.yml, deploy-webapp.yml (GitHub Actions → Server-Deploy bei Push auf main)
├── api/                      — PHP REST-API
│   ├── app/
│   │   ├── controller/       — Ein Controller pro Ressource (erbt _BaseController)
│   │   ├── database/         — Ein Trait pro Ressource; alle in base.database.php per use composited
│   │   ├── guard.php         — JWT-Verifikation (setzt $GLOBALS['auth_role'])
│   │   └── routing.php       — Route-Objekte mit eingebetteter API-Doku
│   ├── index.php             — Einstiegspunkt; parst URL → Routing → Controller
│   ├── schema.php            — Web-UI für API-Doku (Mermaid-ER + Endpunkte aus routing.php)
│   └── vendor/               — Composer-Dependencies (firebase/php-jwt)
├── database/
│   ├── global_schema.sql     — Globales DB-Schema (alle Tabellen)
│   └── league_schema.sql     — Liga-spezifisches Schema (noch nicht implementiert)
└── webapp/                   — Angular-Anwendung (siehe unten)
```

## Webapp-Struktur (Detailed)

```
webapp/src/
├── app/
│   ├── app-module.ts              — Root-Modul
│   ├── app-routing-module.ts      — Root-Routing: /login → AuthModule, /app → ShellModule
│   ├── auth/                      — Login + JWT-Guard
│   │   ├── auth.guard.ts          — Leitet auf /login um wenn kein gültiger Token
│   │   ├── auth.module.ts
│   │   ├── auth.service.ts        — Token speichern/lesen, isAdmin(), getToken()
│   │   └── login/                 — Login-Formular (POST /auth)
│   ├── core/                      — Shared Services und Models
│   │   ├── api.service.ts         — HTTP-Wrapper: get<T>(path), post<T>(path, body), patch<T>(path, body)
│   │   ├── data-cache.service.ts  — Reaktiver Cache für Lookups (z.B. seasonName(id))
│   │   ├── icon/                  — Icon-Komponente
│   │   └── models/                — Typisierte Datenmodelle mit statischer from()-Factory
│   │       ├── club.model.ts
│   │       ├── country.model.ts
│   │       ├── division.model.ts
│   │       ├── matchday.model.ts
│   │       ├── player.model.ts
│   │       ├── season.model.ts
│   │       └── transferwindow.model.ts
│   ├── data/                      — Data-Management-Modul unter /app/data
│   │   ├── data.component         — Sub-Nav (Spieler, Clubs, Saisons, Ligen, Länder)
│   │   ├── data.module.ts         — Lazy-Routing + Declarations aller Data-Komponenten
│   │   ├── club/                  — Club-Liste + Detail (/data/club, /data/club/:id)
│   │   ├── country/               — Länder-Liste + Detail (/data/country, /data/country/:id)
│   │   ├── division/              — Ligen-Liste (/data/division)
│   │   ├── player/                — Spieler-Liste + Detail (/data/player, /data/player/:id)
│   │   └── season/                — Master-Detail: Saisons → Spieltage → Transferfenster (/data/season)
│   └── shell/                     — App-Shell unter /app
│       ├── shell.module.ts
│       ├── shell.component        — Layout: Sidebar (nav) + Topbar + <router-outlet>
│       ├── nav/                   — Sidebar-Navigation; Desktop vertikal, Mobile bottom-bar
│       └── topbar/                — Topbar
├── environments/
│   ├── environment.ts             — apiUrl → lokale API
│   └── environment.prod.ts        — apiUrl → https://api.claude.die-bestesten.de
└── styles/                        — Globale SCSS (importiert via styles.scss)
    ├── index.scss                 — Importiert alle Partials
    ├── _variables.scss            — Design-Tokens: Farben, Abstände, Radii, Typografie, Breakpoints
    ├── _layout.scss               — Globale Klassen: .data-table, .table-container, .list-bar,
    │                                .stat-card, .card, .page-title, .state-msg, .row-link, …
    ├── _buttons.scss              — .btn, .btn-primary, .btn-danger, …
    ├── _inputs.scss               — .input
    ├── _typography.scss
    ├── _fonts.scss
    └── _reset.scss
```

### Patterns in der Webapp

- **State-Management**: Signal-basiert (`signal`, `computed`, `effect`) + RxJS (`BehaviorSubject`, `switchMap`, `forkJoin`) via `toSignal`/`toObservable`
- **Komponenten-Muster**: `standalone: false`, SCSS per Komponente mit `@use '../../../styles/variables' as *`
- **Routing**: Lazy-loaded Module; Detail-Routen als Kind-Routen im selben Modul
- **Globale Styles**: Wiederverwendbare Klassen in `_layout.scss` nutzen (`.row-link`, `.data-table`, `.col-id`, etc.) statt eigene SCSS schreiben

## Struktur (Kurzform)

- **webapp/**: Angular-Anwendung
- **api/**: PHP REST-API
- **database/**: SQL-Dateien für Datenbankschema

## Technologien

- Frontend: Angular (standalone: false, Signal-basiert)
- Backend: PHP
- Datenbank: MySQL

## Datenbankschema

Das Schema befindet sich in `database/global_schema.sql`.

### Tabellen und Spalten

- **country**: id `CHAR(2)` PK (ISO Alpha-2), name `VARCHAR(100)`
- **season**: id `CHAR(36)` PK, start_date `DATE` UNIQUE — aktive Saison = höchstes start_date
- **league**: id `CHAR(36)` PK, slug `VARCHAR(32)` UNIQUE, name `VARCHAR(100)`, db_name `VARCHAR(64)`
- **club**: id `CHAR(36)` PK, country_id `CHAR(2)` FK→country, name `VARCHAR(100)`, short_name `VARCHAR(10)`, logo_uploaded `BOOLEAN`
- **division**: id `CHAR(36)` PK, name `VARCHAR(100)`, level `INT`, seats `INT`, country_id `CHAR(2)` FK→country
- **matchday**: id `CHAR(36)` PK, season_id `CHAR(36)` FK→season, start_date `DATE`, kickoff_date `DATETIME`, number `INT`
- **player**: id `CHAR(36)` PK, country_id `CHAR(2)` FK→country (nullable), first_name, last_name, displayname `VARCHAR(32)` UNIQUE, birth_city, date_of_birth, height_cm, weight_kg
- **club_in_season**: id `CHAR(36)` PK, club_id FK→club, season_id FK→season, division_id FK→division, position `INT` nullable — UNIQUE(club_id, season_id)
- **player_in_season**: id `CHAR(36)` PK, player_id FK→player, season_id FK→season, price `DECIMAL(10,2)`, position `ENUM(GOALKEEPER,DEFENDER,MIDFIELDER,FORWARD)`, photo_uploaded `BOOLEAN` — UNIQUE(player_id, season_id)
- **player_in_club**: id `CHAR(36)` PK, player_id FK→player, club_id FK→club, from_date `DATE NOT NULL`, to_date `DATE`, on_loan `BOOLEAN` — UNIQUE(player_id, club_id, from_date)
- **player_rating**: id `CHAR(36)` PK, player_id FK→player, matchday_id FK→matchday, grade `DECIMAL(3,1) NULL`, participation `ENUM('starting','substitute') NULL`, goals, assists, clean_sheet, sds `BOOLEAN`, red_card, yellow_red_card, points — UNIQUE(player_id, matchday_id)
- **transferwindow**: id `CHAR(36)` PK, matchday_id `CHAR(36)` FK→matchday, start_date `DATETIME`, end_date `DATETIME` — üblicherweise 2, selten 4 Fenster pro Spieltag

### Migrations-Reihenfolge (FK-Abhängigkeiten)

1. `country`, `season`, `league`
2. `club`, `division`, `player`, `matchday`
3. `club_in_season`, `player_in_season`, `player_in_club`
4. `player_rating`, `transferwindow`

## API-Endpunkte

Vollständige Dokumentation unter `api/schema.php`. Endpoints:

- `GET/POST /club_in_season` — Saison-Zuordnungen; POST erstellt neu (409 bei Duplikat)
- `PATCH /club_in_season/:id` — Division/Position aktualisieren
- `GET /division`, `GET /division/:id`
- `GET /club`, `GET /club/:id`
- `GET /country`, `GET /country/:id`
- `GET /season`, `GET /season/:id`, `GET /season/active`
- `GET /matchday`, `GET /matchday/:id`
- `GET /transferwindow`, `GET /transferwindow/:id` — optional gefiltert nach `matchday_id` oder `season_id`
- `POST /transferwindow/migrate` — Migriert Transferfenster aus alter DB (nur Admin)
- `GET /player`, `GET /player/:id`
- `POST /player/migrate` — Migriert player, player_in_season, player_in_club, player_rating aus alter DB; gibt migrated/skipped-Counts zurück
- `POST /auth` — JWT-Login
- `GET /manager/me` — Eigenes Profil (id, manager_name, alias, role, status); erfordert Auth
- `PATCH /manager/me` — Passwort ändern; Body: `{ current_password, new_password }`; erfordert Auth
- `DELETE /manager/me` — Eigenen Account löschen; Body: `{ password }`; erfordert Auth

## Liga-spezifische Datenbank

`database/league_schema.sql` — manager-Tabelle implementiert; team, team_rating, team_lineup, player_in_team noch ausstehend

### manager-Tabelle (league DB)

- **manager**: id `CHAR(36)` PK, manager_name `VARCHAR(64)` UNIQUE, alias `VARCHAR(64)` UNIQUE nullable, password `VARCHAR(255)`, role `ENUM(admin,maintainer,user)`, status `ENUM(active,blocked)`, date_of_birth `DATE` nullable
