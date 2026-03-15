-- Datenbankschema für das Projekt

-- Erstelle die Datenbank
CREATE DATABASE IF NOT EXISTS project_db;

-- Verwende die Datenbank
USE project_db;

-- Beispiel-Tabelle: Benutzer
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Beispiel-Tabelle: Produkte (falls relevant)
CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    price DECIMAL(10, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Weitere Tabellen können hier hinzugefügt werden