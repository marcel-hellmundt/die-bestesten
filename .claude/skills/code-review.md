# Code Review

Führe ein umfassendes Code-Review des gesamten Projekts durch. Analysiere beide Teilprojekte (`webapp/` und `api/`) und schreibe einen strukturierten Bericht mit folgenden Abschnitten:

## 1. Projektstruktur & Architektur
- Verzeichnisstruktur und Schichten-Trennung
- Konsistenz der Namenskonventionen (Dateinamen, Klassen, Variablen)
- Modularisierung: Was gehört zusammen, was ist falsch aufgeteilt?

## 2. Aktualität der Coding-Patterns
- Angular: Signals vs. Zone.js, standalone vs. NgModule, inject() vs. constructor DI, `any`-Typen
- PHP: PDO-Nutzung, Trait-Architektur, moderne PHP-Features (8.x)
- Inkonsistente Mischung alter und neuer Patterns im gleichen Projekt

## 3. Pakete & Dependencies
- Veraltete oder fehlende Pakete (`package.json`, `composer.json`)
- Unnötige Dependencies / fehlende Dev-Tools (Linting, Testing)
- Sicherheitsrelevante Paketversionen

## 4. Security Check
- JWT-Handling (Client-seitig und Server-seitig)
- CORS-Konfiguration
- SQL-Injection-Risiken (parametrized queries?)
- Authentifizierung und Autorisierung (Guard-Logik, public routes)
- Secrets-Management (ENV-Dateien, .gitignore)
- Input-Validierung (API-seitig)
- Sensitive Daten in Logs oder Responses

## 5. Code Smells
- Doppelter / redundanter Code (DRY-Verletzungen)
- Zu große Funktionen / Komponenten
- Magic Numbers / Strings ohne Konstanten
- `any`-Typen in TypeScript
- Tote Code-Pfade (unused variables, dead branches)
- Fehlende Error-Behandlung

## 6. Inkonsistenzen
- **Code**: Unterschiedliche Patterns für ähnliche Aufgaben (z.B. State-Management, API-Calls)
- **UI**: Unterschiedliches Verhalten ähnlicher Komponenten (Loading-States, Error-States, Tabellen)
- **Schema**: Abweichungen zwischen DB-Schema, API-Response-Shape und TypeScript-Modellen
- **Naming**: Englisch vs. Deutsch, snake_case vs. camelCase an falschen Stellen

## Format
- Nutze Markdown mit klaren Überschriften
- Pro Fund: kurze Beschreibung + Fundstelle (Datei:Zeile) + Empfehlung
- Bewerte Schwere: 🔴 kritisch / 🟡 mittel / 🟢 minor
- Fasse am Ende die 3 wichtigsten Handlungsempfehlungen zusammen
