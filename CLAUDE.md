# Projekt-Spezifikationen

## Regeln

- **API-Änderungen**: Bei jeder Änderung in `/api` müssen `CLAUDE.md` (Datenbankschema + Endpunkte) und `api/schema.php` (routing.php-Docs) mitaktualisiert werden. Danach immer committen **und pushen** — nur durch Push wird die GitHub Action ausgelöst, die die Änderungen auf den Server deployed.
- **Webapp-Änderungen**: Immer Mobile- und Desktop-Kompatibilität berücksichtigen. Informationen dürfen auf kleinen Screens ausgeblendet oder reduziert werden, wenn der Platz nicht reicht — aber die Kernfunktionalität muss auf beiden nutzbar sein.

## Überblick

Mono-Repository mit einer Angular-Webapp und einer PHP-REST-API für eine Fantasy-Football-Plattform.

## Struktur

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
- **player_in_club**: id `CHAR(36)` PK, player_id FK→player, club_id FK→club, from_date `DATE`, to_date `DATE`, on_loan `BOOLEAN` — UNIQUE(player_id, club_id, from_date)
- **player_rating**: id `CHAR(36)` PK, player_id FK→player, matchday_id FK→matchday, grade `DECIMAL(3,1)`, is_starting, is_substitute, goals, assists, clean_sheet, red_card, yellow_red_card, points — UNIQUE(player_id, matchday_id)

### Migrations-Reihenfolge (FK-Abhängigkeiten)

1. `country`, `season`, `league`
2. `club`, `division`, `player`, `matchday`
3. `club_in_season`, `player_in_season`, `player_in_club`
4. `player_rating`

## API-Endpunkte

Vollständige Dokumentation unter `api/schema.php`. Endpoints:

- `GET/POST /club_in_season` — Saison-Zuordnungen; POST erstellt neu (409 bei Duplikat)
- `PATCH /club_in_season/:id` — Division/Position aktualisieren
- `GET /division`, `GET /division/:id`
- `GET /club`, `GET /club/:id`
- `GET /country`, `GET /country/:id`
- `GET /season`, `GET /season/:id`, `GET /season/active`
- `GET /matchday`, `GET /matchday/:id`
- `GET /player`, `GET /player/:id`
- `POST /player/migrate` — Migriert player, player_in_season, player_in_club, player_rating aus alter DB; gibt migrated/skipped-Counts zurück
- `POST /auth` — JWT-Login

## Liga-spezifische Datenbank

`database/league_schema.sql` — noch nicht implementiert (manager, team, team_rating, team_lineup, player_in_team)
