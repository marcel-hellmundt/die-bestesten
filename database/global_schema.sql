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
    short_name VARCHAR(10) DEFAULT NULL,        -- Kurzname/Kürzel, z.B. 'FCB', 'BVB'
    logo_uploaded BOOLEAN DEFAULT FALSE,        -- Gibt an, ob ein Logo für den Club hochgeladen wurde
    FOREIGN KEY (country_id) REFERENCES country(id)
);

-- Tabelle: season
CREATE TABLE IF NOT EXISTS season (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),  -- GUID als eindeutige ID
    start_date DATE NOT NULL UNIQUE             -- Startdatum der Saison; aktive Saison = höchstes start_date
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

-- Tabelle: player
CREATE TABLE IF NOT EXISTS player (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),  -- GUID als eindeutige ID
    country_id VARCHAR(3) DEFAULT NULL,         -- ISO Alpha-3 Code, FK zu country.id
    first_name VARCHAR(32) DEFAULT NULL,        -- Vorname
    last_name VARCHAR(32) DEFAULT NULL,         -- Nachname
    displayname VARCHAR(32) NOT NULL UNIQUE,    -- Anzeigename, muss eindeutig sein
    birth_city VARCHAR(64) DEFAULT NULL,        -- Geburtsstadt
    date_of_birth DATE DEFAULT NULL,            -- Geburtsdatum
    height_cm INT DEFAULT NULL,                 -- Größe in cm
    weight_kg INT DEFAULT NULL,                 -- Gewicht in kg
    FOREIGN KEY (country_id) REFERENCES country(id)
);

-- Tabelle: player_in_season (m-n Beziehung player <-> season)
CREATE TABLE IF NOT EXISTS player_in_season (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),  -- GUID als eindeutige ID
    player_id CHAR(36) NOT NULL,                -- FK zu player.id
    season_id CHAR(36) NOT NULL,                -- FK zu season.id
    price DECIMAL(10,2) DEFAULT NULL,           -- Marktwert
    position ENUM('GOALKEEPER', 'DEFENDER', 'MIDFIELDER', 'FORWARD') DEFAULT NULL,  -- Position
    photo_uploaded BOOLEAN DEFAULT FALSE,       -- Gibt an, ob ein Foto für den Spieler in dieser Saison hochgeladen wurde
    FOREIGN KEY (player_id) REFERENCES player(id),
    FOREIGN KEY (season_id) REFERENCES season(id),
    UNIQUE KEY uk_player_season (player_id, season_id)
);

-- Tabelle: player_in_club (m-n Beziehung player <-> club)
CREATE TABLE IF NOT EXISTS player_in_club (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),  -- GUID als eindeutige ID
    player_id CHAR(36) NOT NULL,                -- FK zu player.id
    club_id CHAR(36) NOT NULL,                  -- FK zu club.id
    from_date DATE DEFAULT NULL,                -- Seit-wann
    to_date DATE DEFAULT NULL,                  -- Bis-wann (NULL für aktuelle Verträge)
    on_loan BOOLEAN DEFAULT FALSE,              -- Gibt an, ob der Spieler ausgeliehen ist
    FOREIGN KEY (player_id) REFERENCES player(id),
    FOREIGN KEY (club_id) REFERENCES club(id),
    UNIQUE KEY uk_player_club_from (player_id, club_id, from_date)
);

-- Tabelle: player_rating (Bewertungen pro Spieler und Spieltag)
CREATE TABLE IF NOT EXISTS player_rating (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),  -- GUID als eindeutige ID
    player_id CHAR(36) NOT NULL,                -- FK zu player.id
    matchday_id CHAR(36) NOT NULL,              -- FK zu matchday.id
    grade DECIMAL(3,1) NOT NULL,                -- Note, z.B. 1.5; Wertebereich 1.0..6.0
    is_starting BOOLEAN DEFAULT FALSE,          -- In der Startaufstellung
    is_substitute BOOLEAN DEFAULT FALSE,        -- Eingesetzt als Einwechselspieler
    goals INT DEFAULT 0,                        -- Tore
    assists INT DEFAULT 0,                      -- Vorlagen
    clean_sheet BOOLEAN DEFAULT FALSE,          -- Weiße Weste
    red_card BOOLEAN DEFAULT FALSE,             -- Rote Karte
    yellow_red_card BOOLEAN DEFAULT FALSE,      -- Gelb-Rote Karte
    points INT DEFAULT NULL,                    -- Punkte (kann aus anderen Werten berechnet werden)
    FOREIGN KEY (player_id) REFERENCES player(id),
    FOREIGN KEY (matchday_id) REFERENCES matchday(id),
    UNIQUE KEY uk_player_matchday (player_id, matchday_id),
    INDEX idx_player_id (player_id),
    INDEX idx_matchday_id (matchday_id)
);

-- Weitere globale Tabellen werden hier hinzugefügt