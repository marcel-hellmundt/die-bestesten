-- Globale Datenbank Schema
-- Enthält Tabellen für league, season, matchday, player, player_in_season, player_rating

-- Tabelle: country
CREATE TABLE IF NOT EXISTS country (
    id CHAR(2) PRIMARY KEY,     -- ISO Alpha-2 Code, z.B. 'DE'
    name VARCHAR(100) NOT NULL  -- Name des Landes
);

-- Tabelle: club
CREATE TABLE IF NOT EXISTS club (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),  -- GUID als eindeutige ID
    country_id CHAR(2) NOT NULL,                -- Foreign Key zu country.id
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
    kickoff_date DATETIME NOT NULL,             -- Bis wann Spieler ihre Aufstellung anpassen können
    number INT NOT NULL,                        -- Nummer des Spieltages (z.B. 12)
    completed BOOLEAN NOT NULL DEFAULT FALSE,   -- Spieltag abgeschlossen? (Ratings gesperrt wenn TRUE)
    FOREIGN KEY (season_id) REFERENCES season(id)
);

-- Tabelle: transferwindow (Transferfenster je Spieltag)
CREATE TABLE IF NOT EXISTS transferwindow (
    id          CHAR(36)  NOT NULL PRIMARY KEY DEFAULT (UUID()),  -- GUID als eindeutige ID
    matchday_id CHAR(36)  NOT NULL,                               -- FK zu matchday.id
    start_date  DATETIME  NOT NULL,                               -- Beginn des Transferfensters
    end_date    DATETIME  NOT NULL,                               -- Ende des Transferfensters
    FOREIGN KEY (matchday_id) REFERENCES matchday(id)
);

-- Tabelle: player
CREATE TABLE IF NOT EXISTS player (
    id CHAR(36) PRIMARY KEY DEFAULT (UUID()),  -- GUID als eindeutige ID
    country_id CHAR(2) DEFAULT NULL,            -- ISO Alpha-2 Code, FK zu country.id
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
    from_date DATE NOT NULL,                    -- Seit-wann
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
    club_id CHAR(36) DEFAULT NULL,              -- FK zu club.id — Club des Spielers zum Zeitpunkt des Spieltags; NULL für historische Daten ohne Club-Tracking
    grade DECIMAL(3,1) DEFAULT NULL,            -- Note, z.B. 1.5; Wertebereich 1.0..6.0
    participation ENUM('starting', 'substitute') DEFAULT NULL,  -- 'starting' = Startelf, 'substitute' = Eingewechselt, NULL = nicht gespielt
    goals INT DEFAULT 0,                        -- Tore
    assists INT DEFAULT 0,                      -- Vorlagen
    clean_sheet BOOLEAN DEFAULT FALSE,          -- Weiße Weste
    sds BOOLEAN DEFAULT FALSE,                  -- Spieler des Spiels
    red_card BOOLEAN DEFAULT FALSE,             -- Rote Karte
    yellow_red_card BOOLEAN DEFAULT FALSE,      -- Gelb-Rote Karte
    points INT DEFAULT NULL,                    -- Punkte (kann aus anderen Werten berechnet werden)
    FOREIGN KEY (player_id) REFERENCES player(id),
    FOREIGN KEY (matchday_id) REFERENCES matchday(id),
    FOREIGN KEY (club_id) REFERENCES club(id),
    UNIQUE KEY uk_player_matchday (player_id, matchday_id),
    INDEX idx_player_id (player_id),
    INDEX idx_matchday_id (matchday_id),
    INDEX idx_club_id (club_id)
);

-- Tabelle: division (reale Fußball-Spielklassen, z.B. 1. Bundesliga)
CREATE TABLE IF NOT EXISTS division (
    id         CHAR(36)     NOT NULL PRIMARY KEY DEFAULT (UUID()),
    name       VARCHAR(100) NOT NULL,           -- z.B. "1. Bundesliga"
    level      INT          NOT NULL DEFAULT 1, -- Hierarchie-Ebene (1 = höchste Liga)
    seats      INT          NOT NULL DEFAULT 18, -- Anzahl Clubs in dieser Liga
    country_id CHAR(2)      NOT NULL,           -- FK zu country.id
    FOREIGN KEY (country_id) REFERENCES country(id)
);

-- Tabelle: club_in_season (Zuordnung Club -> Saison -> Spielklasse + Tabellenplatz)
CREATE TABLE IF NOT EXISTS club_in_season (
    id          CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    club_id     CHAR(36) NOT NULL,
    season_id   CHAR(36) NOT NULL,
    division_id CHAR(36) NOT NULL,
    position    INT      DEFAULT NULL,          -- Tabellenplatz am Saisonende
    FOREIGN KEY (club_id)     REFERENCES club(id),
    FOREIGN KEY (season_id)   REFERENCES season(id),
    FOREIGN KEY (division_id) REFERENCES division(id),
    UNIQUE KEY uk_club_season (club_id, season_id)
);

-- Tabelle: stadium
CREATE TABLE IF NOT EXISTS stadium (
    id            CHAR(36)      NOT NULL PRIMARY KEY DEFAULT (UUID()),
    official_name VARCHAR(100)  NOT NULL,           -- Offizieller Name, z.B. "Fußball Arena München"
    name          VARCHAR(100)  DEFAULT NULL,        -- Umgangssprachlicher Name, z.B. "Allianz Arena"; NULL wenn kein Spitzname
    capacity      INT           DEFAULT NULL,        -- Zuschauerkapazität, z.B. 75000
    lat           DECIMAL(9,6)  DEFAULT NULL,        -- Breitengrad
    lng           DECIMAL(9,6)  DEFAULT NULL,        -- Längengrad
    opened_date   DATE          DEFAULT NULL,        -- Eröffnungsdatum
    closed_date   DATE          DEFAULT NULL         -- Schließungsdatum (NULL = noch in Betrieb)
);

-- Tabelle: club_stadium (m-n Beziehung club <-> stadium mit Zeitraum)
CREATE TABLE IF NOT EXISTS club_stadium (
    id          CHAR(36) NOT NULL PRIMARY KEY DEFAULT (UUID()),
    club_id     CHAR(36) NOT NULL,                  -- FK zu club.id
    stadium_id  CHAR(36) NOT NULL,                  -- FK zu stadium.id
    from_date   DATE     NOT NULL,                  -- Seit wann der Club dieses Stadion nutzt
    to_date     DATE     DEFAULT NULL,              -- Bis wann (NULL = aktuell)
    FOREIGN KEY (club_id)    REFERENCES club(id),
    FOREIGN KEY (stadium_id) REFERENCES stadium(id),
    UNIQUE KEY uk_club_stadium_from (club_id, from_date)
);

-- Tabelle: league (Spiel-Instanzen; jede Liga hat eine eigene Datenbank)
CREATE TABLE IF NOT EXISTS league (
    id      CHAR(36)     NOT NULL PRIMARY KEY DEFAULT (UUID()),
    slug    VARCHAR(32)  NOT NULL UNIQUE,       -- Login-Identifier, z.B. "bestesten-2025"
    name    VARCHAR(100) NOT NULL,              -- Anzeigename
    db_name VARCHAR(64)  NOT NULL               -- Datenbankname der Liga-Datenbank
);

-- Tabelle: award (Award-Typen; liganeutral; sort_index = Wichtigkeit, 1 = wichtigster)
CREATE TABLE IF NOT EXISTS award (
    id         CHAR(36)     NOT NULL PRIMARY KEY DEFAULT (UUID()),
    name       VARCHAR(100) NOT NULL UNIQUE,
    icon       VARCHAR(100) NULL DEFAULT NULL,  -- Pfad zum Icon, z.B. "img/icons/trophy.png"
    sort_index INT          NOT NULL DEFAULT 0
);
