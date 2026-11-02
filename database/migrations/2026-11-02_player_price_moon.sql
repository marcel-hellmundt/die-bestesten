-- Migration: Mondpreis — Platzhalter-Marktwerte auf NULL setzen
-- Ziel: GLOBALE DB (eine gemeinsame für dev + prod). Einmal ausführen.
--
-- ERST NACH DEM DEPLOY AUF main AUSFÜHREN. Bis dahin behandelt der Code auf main ein NULL in
-- player_in_season.price an mehreren Stellen als 0 € (z.B. POST /buy) — der Spieler wäre sonst für 0 € kaufbar.
-- Der neue Code (Branch player-without-price) lehnt Kauf/Gebot bei NULL ab und zeigt 🌙 an.
--
-- Hintergrund: Spieler ohne Marktwert des Datenanbieters (CSV: 999.000.000) bzw. per Notfall-Anlage
-- (POST /player/create_manual: 99.000.000) bekamen bisher einen unrealistischen Platzhalter-Preis.
-- NULL = "noch kein Marktwert" (Mondpreis): nicht kaufbar, wird aber bewertet. Die Spalte erlaubt NULL bereits
-- (price DECIMAL(10,2) DEFAULT NULL) — reine Datenbereinigung, keine Schemaänderung.

-- 1) prüfen: welche Saison-Zeilen haben einen Platzhalter (über der Plausibilitätsgrenze 50 Mio.)?
SELECT pis.id, p.displayname, s.start_date AS season_start, d.name AS division, pis.position, pis.price
FROM player_in_season pis
JOIN player p   ON p.id = pis.player_id
JOIN season s   ON s.id = pis.season_id
JOIN division d ON d.id = pis.division_id
WHERE pis.price > 50000000
ORDER BY s.start_date DESC, p.displayname;

-- 2) bereinigen
UPDATE player_in_season SET price = NULL WHERE price > 50000000;
