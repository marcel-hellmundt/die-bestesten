-- Globale Datenbank Schema
-- Enthält Tabellen für league, season, matchday, player, player_in_season, player_rating

-- Tabelle: country
CREATE TABLE IF NOT EXISTS country (
    id VARCHAR(3) PRIMARY KEY,  -- Eindeutiges Kürzel, z.B. 'DEU'
    name VARCHAR(100) NOT NULL  -- Name des Landes
);

-- Tabelle: club
CREATE TABLE IF NOT EXISTS club (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),  -- GUID als eindeutige ID
    country_id VARCHAR(3) NOT NULL,             -- Foreign Key zu country.id
    name VARCHAR(100) NOT NULL,                 -- Name des Clubs
    FOREIGN KEY (country_id) REFERENCES country(id)
);

-- Tabelle: season
CREATE TABLE IF NOT EXISTS season (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),  -- GUID als eindeutige ID
    start_date DATE NOT NULL                    -- Startdatum der Saison
);

-- Tabelle: matchday
CREATE TABLE IF NOT EXISTS matchday (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),  -- GUID als eindeutige ID
    season_id CHAR(36) NOT NULL,                -- Foreign Key zu season.id
    start_date DATE NOT NULL,                   -- Startdatum, ab wann Spieler offiziell in diesem Spieltag sind
    kickoff_date DATE NOT NULL,                 -- Bis wann Spieler ihre Aufstellung anpassen können
    number INT NOT NULL,                        -- Nummer des Spieltages (z.B. 12)
    FOREIGN KEY (season_id) REFERENCES season(id)
);

-- Weitere globale Tabellen werden hier hinzugefügt