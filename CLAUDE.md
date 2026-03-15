# Projekt-Spezifikationen

## Überblick

Dieses Mono-Repository enthält eine Webapp und eine REST-API für [Projektzweck hier einfügen].

## Struktur

- **webapp/**: Angular-Anwendung
- **api/**: Node.js REST-API
- **database/**: SQL-Dateien für Datenbankschema

## Technologien

- Frontend: Angular
- Backend: Node.js
- Datenbank: [Datenbanksystem, z.B. PostgreSQL/MySQL]

## Datenbankschema

Das Datenbankschema befindet sich im `database/` Ordner und enthält zwei separate Schemas:

### Globale Datenbank

- `global_schema.sql`: Enthält Tabellen für globale Daten (league, season, matchday, player, player_in_season, player_rating)
  - **country**: id (VARCHAR(3) PRIMARY KEY), name (VARCHAR(100))
  - **club**: id (CHAR(36) UUID PRIMARY KEY), country_id (VARCHAR(3) FK zu country), name (VARCHAR(100)), short_name (VARCHAR(10)), logo_uploaded (BOOLEAN)
  - **season**: id (CHAR(36) UUID PRIMARY KEY), start_date (DATE UNIQUE)
  - **matchday**: id (CHAR(36) UUID PRIMARY KEY), season_id (CHAR(36) FK zu season), start_date (DATE), kickoff_date (DATE), number (INT)
  - **player**: id (CHAR(36) UUID PRIMARY KEY), country_id (VARCHAR(3) FK zu country), first_name (VARCHAR(32)), last_name (VARCHAR(32)), displayname (VARCHAR(32) UNIQUE), birth_city (VARCHAR(64)), date_of_birth (DATE), height_cm (INT), weight_kg (INT)
  - **player_in_season**: id (CHAR(36) UUID PRIMARY KEY), player_id (CHAR(36) FK zu player), season_id (CHAR(36) FK zu season), price (DECIMAL(10,2)), position (ENUM), photo_uploaded (BOOLEAN), UNIQUE(player_id, season_id)
  - **player_in_club**: id (CHAR(36) UUID PRIMARY KEY), player_id (CHAR(36) FK zu player), club_id (CHAR(36) FK zu club), from_date (DATE), to_date (DATE), on_loan (BOOLEAN), UNIQUE(player_id, club_id, from_date)
  - **player_rating**: id (CHAR(36) UUID PRIMARY KEY), player_id (CHAR(36) FK zu player), matchday_id (CHAR(36) FK zu matchday), grade (DECIMAL(3,1)), is_starting (BOOLEAN), is_substitute (BOOLEAN), goals (INT), assists (INT), clean_sheet (BOOLEAN), red_card (BOOLEAN), yellow_red_card (BOOLEAN), points (INT), UNIQUE(player_id, matchday_id)

### Liga-spezifische Datenbank

- `league_schema.sql`: Enthält Tabellen für liga-spezifische Daten (manager, team, team_rating, team_lineup, player_in_team)
  - (Noch nicht implementiert)

## API-Endpunkte

- [Liste der Endpunkte]

## Entwicklung

- [Anweisungen zum Setup und Ausführen]

## Deployment

- [Deployment-Strategie]
