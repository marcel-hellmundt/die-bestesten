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
  - **club**: id (CHAR(36) UUID PRIMARY KEY), country_id (VARCHAR(3) FK zu country), name (VARCHAR(100))
  - **season**: id (CHAR(36) UUID PRIMARY KEY), start_date (DATE)
  - **matchday**: id (CHAR(36) UUID PRIMARY KEY), season_id (CHAR(36) FK zu season), start_date (DATE), kickoff_date (DATE), number (INT)

### Liga-spezifische Datenbank

- `league_schema.sql`: Enthält Tabellen für liga-spezifische Daten (manager, team, team_rating, team_lineup, player_in_team)
  - (Noch nicht implementiert)

## API-Endpunkte

- [Liste der Endpunkte]

## Entwicklung

- [Anweisungen zum Setup und Ausführen]

## Deployment

- [Deployment-Strategie]
