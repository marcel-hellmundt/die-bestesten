-- Achievement Analysis Queries
-- Pro Manager nur das beste Ergebnis (eine Zeile).
-- CONVERT(... USING utf8mb3) auf alle League-DB-Spalten in Cross-DB-Vergleichen.

-- =============================================================================
-- season_champion — Beste Platzierung pro Manager (Threshold: Platz 1)
-- =============================================================================
SELECT achievement_id, manager_name, saison, punkte, platz
FROM (
    SELECT *,
           ROW_NUMBER() OVER (PARTITION BY manager_id ORDER BY platz ASC, punkte DESC) AS rn
    FROM (
        SELECT
            (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'season_champion') AS achievement_id,
            m.id AS manager_id, m.manager_name,
            CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison,
            SUM(tr.points) AS punkte,
            RANK() OVER (PARTITION BY t.season_id ORDER BY SUM(tr.points) DESC) AS platz
        FROM usr_ud16_151_4.team t
        JOIN usr_ud16_151_4.team_rating tr ON tr.team_id = t.id AND tr.invalid = 0
        JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
        JOIN usr_ud16_151_1.matchday md ON md.id = CONVERT(tr.matchday_id USING utf8mb3)
        JOIN usr_ud16_151_1.season s ON s.id = md.season_id
        WHERE CONVERT(t.season_id USING utf8mb3) IN (
            SELECT season_id FROM usr_ud16_151_1.matchday
            GROUP BY season_id HAVING COUNT(*) = SUM(completed) AND COUNT(*) > 0
        )
        GROUP BY m.id, m.manager_name, t.season_id, s.start_date
    ) ranked
) sub WHERE rn = 1
ORDER BY platz ASC, punkte DESC;

-- =============================================================================
-- matchday_wins — Meiste Spieltag-Siege in einer Saison (Bronze ≥8, Silber ≥12, Gold ≥16)
-- =============================================================================
SELECT achievement_id, manager_name, saison, siege
FROM (
    SELECT
        (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'matchday_wins') AS achievement_id,
        m.id AS manager_id, m.manager_name,
        CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison,
        COUNT(*) AS siege,
        ROW_NUMBER() OVER (PARTITION BY m.id ORDER BY COUNT(*) DESC) AS rn
    FROM usr_ud16_151_4.team_rating tr
    JOIN usr_ud16_151_4.team t ON t.id = tr.team_id AND tr.invalid = 0
    JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
    JOIN usr_ud16_151_1.matchday md ON md.id = CONVERT(tr.matchday_id USING utf8mb3) AND md.completed = 1
    JOIN usr_ud16_151_1.season s ON s.id = md.season_id AND s.start_date >= '2017-07-01'
    WHERE tr.points = (
        SELECT MAX(tr2.points) FROM usr_ud16_151_4.team_rating tr2
        WHERE tr2.matchday_id = tr.matchday_id AND tr2.invalid = 0
    )
    GROUP BY m.id, m.manager_name, t.season_id, s.start_date
) sub WHERE rn = 1
ORDER BY siege DESC;

-- =============================================================================
-- century — Höchste Einzelspieltag-Punkte pro Manager (Threshold: 100)
-- =============================================================================
SELECT
    (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'century') AS achievement_id,
    CONVERT(m.manager_name USING utf8mb3) AS manager_name,
    MAX(tr.points) AS max_punkte,
    CASE
        WHEN MAX(tr.points) >= 100 THEN 'gold'
        WHEN MAX(tr.points) >= 90  THEN 'silver'
        WHEN MAX(tr.points) >= 80  THEN 'bronze'
        ELSE NULL
    END AS level
FROM usr_ud16_151_4.team_rating tr
JOIN usr_ud16_151_4.team t ON t.id = tr.team_id AND tr.invalid = 0
JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
JOIN usr_ud16_151_1.matchday md ON md.id = CONVERT(tr.matchday_id USING utf8mb3)
JOIN usr_ud16_151_1.season s ON s.id = md.season_id AND s.start_date >= '2017-07-01'
GROUP BY m.id, m.manager_name
HAVING MAX(tr.points) >= 80
ORDER BY max_punkte DESC;

-- =============================================================================
-- win_streak_3 — Längste Siegesserie in einer Saison (Threshold: 3)
-- =============================================================================
WITH w3_winners AS (
    SELECT t.manager_id, md.season_id, md.number,
           (tr.points = MAX(tr.points) OVER (PARTITION BY tr.matchday_id)) AS is_winner
    FROM usr_ud16_151_4.team_rating tr
    JOIN usr_ud16_151_4.team t ON t.id = tr.team_id AND tr.invalid = 0
    JOIN usr_ud16_151_1.matchday md ON md.id = CONVERT(tr.matchday_id USING utf8mb3) AND md.completed = 1
    JOIN usr_ud16_151_1.season s ON s.id = md.season_id AND s.start_date >= '2017-07-01'
),
w3_islands AS (
    SELECT manager_id, season_id, number,
           number - ROW_NUMBER() OVER (PARTITION BY manager_id, season_id ORDER BY number) AS grp
    FROM w3_winners WHERE is_winner = 1
),
w3_streaks AS (
    SELECT manager_id, season_id, COUNT(*) AS streak_len
    FROM w3_islands GROUP BY manager_id, season_id, grp
),
w3_best AS (
    SELECT manager_id, season_id, MAX(streak_len) AS max_siegesserie,
           ROW_NUMBER() OVER (PARTITION BY manager_id ORDER BY MAX(streak_len) DESC) AS rn
    FROM w3_streaks GROUP BY manager_id, season_id
)
SELECT
    (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'win_streak_3') AS achievement_id,
    m.manager_name,
    CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison,
    b.max_siegesserie
FROM w3_best b
JOIN usr_ud16_151_4.manager m ON m.id = CONVERT(b.manager_id USING utf8mb3)
JOIN usr_ud16_151_1.season s ON s.id = b.season_id
WHERE b.rn = 1
ORDER BY b.max_siegesserie DESC;

-- =============================================================================
-- sds_4 — Meiste SDS-Nominierungen an einem Spieltag (Threshold: 4)
-- =============================================================================
SELECT achievement_id, manager_name, spieltag, saison, sds_count
FROM (
    SELECT
        (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'sds_4') AS achievement_id,
        m.id AS manager_id, m.manager_name,
        md.number AS spieltag,
        CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison,
        COUNT(*) AS sds_count,
        ROW_NUMBER() OVER (PARTITION BY m.id ORDER BY COUNT(*) DESC) AS rn
    FROM usr_ud16_151_4.team_lineup tl
    JOIN usr_ud16_151_4.team t ON t.id = tl.team_id
    JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
    JOIN usr_ud16_151_1.player_rating pr
        ON pr.player_id = CONVERT(tl.player_id USING utf8mb3)
        AND pr.matchday_id = CONVERT(tl.matchday_id USING utf8mb3)
        AND pr.sds = 1
    JOIN usr_ud16_151_1.matchday md ON md.id = CONVERT(tl.matchday_id USING utf8mb3)
    JOIN usr_ud16_151_1.season s ON s.id = md.season_id
    WHERE tl.nominated = 1
    GROUP BY m.id, m.manager_name, tl.matchday_id, md.number, s.start_date
) sub WHERE rn = 1
ORDER BY sds_count DESC;

-- =============================================================================
-- season_points — Beste Saisonpunkte pro Manager (Bronze ≥1400, Silber ≥1500, Gold ≥1600)
-- =============================================================================
SELECT achievement_id, manager_name, saison, punkte,
       CASE WHEN punkte >= 1600 THEN 'gold' WHEN punkte >= 1500 THEN 'silver' ELSE 'bronze' END AS level
FROM (
    SELECT
        (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'season_points') AS achievement_id,
        m.id AS manager_id, m.manager_name,
        CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison,
        SUM(tr.points) AS punkte,
        ROW_NUMBER() OVER (PARTITION BY m.id ORDER BY SUM(tr.points) DESC) AS rn
    FROM usr_ud16_151_4.team t
    JOIN usr_ud16_151_4.team_rating tr ON tr.team_id = t.id AND tr.invalid = 0
    JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
    JOIN usr_ud16_151_1.matchday md ON md.id = CONVERT(tr.matchday_id USING utf8mb3)
    JOIN usr_ud16_151_1.season s ON s.id = md.season_id
    GROUP BY m.id, m.manager_name, t.season_id, s.start_date
) sub WHERE rn = 1 AND punkte >= 1400
ORDER BY punkte DESC;

-- =============================================================================
-- season_goals — Meiste Tore in einer Saison (Bronze ≥70, Silber ≥80, Gold ≥90)
-- =============================================================================
SELECT achievement_id, manager_name, saison, tore,
       CASE WHEN tore >= 90 THEN 'gold' WHEN tore >= 80 THEN 'silver' ELSE 'bronze' END AS level
FROM (
    SELECT
        (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'season_goals') AS achievement_id,
        m.id AS manager_id, m.manager_name,
        CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison,
        SUM(tr.goals) AS tore,
        ROW_NUMBER() OVER (PARTITION BY m.id ORDER BY SUM(tr.goals) DESC) AS rn
    FROM usr_ud16_151_4.team t
    JOIN usr_ud16_151_4.team_rating tr ON tr.team_id = t.id AND tr.invalid = 0
    JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
    JOIN usr_ud16_151_1.matchday md ON md.id = CONVERT(tr.matchday_id USING utf8mb3)
    JOIN usr_ud16_151_1.season s ON s.id = md.season_id
    GROUP BY m.id, m.manager_name, t.season_id, s.start_date
) sub WHERE rn = 1 AND tore >= 70
ORDER BY tore DESC;

-- =============================================================================
-- season_assists — Meiste Vorlagen in einer Saison (Bronze ≥60, Silber ≥65, Gold ≥70)
-- =============================================================================
SELECT achievement_id, manager_name, saison, vorlagen,
       CASE WHEN vorlagen >= 70 THEN 'gold' WHEN vorlagen >= 65 THEN 'silver' ELSE 'bronze' END AS level
FROM (
    SELECT
        (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'season_assists') AS achievement_id,
        m.id AS manager_id, m.manager_name,
        CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison,
        SUM(tr.assists) AS vorlagen,
        ROW_NUMBER() OVER (PARTITION BY m.id ORDER BY SUM(tr.assists) DESC) AS rn
    FROM usr_ud16_151_4.team t
    JOIN usr_ud16_151_4.team_rating tr ON tr.team_id = t.id AND tr.invalid = 0
    JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
    JOIN usr_ud16_151_1.matchday md ON md.id = CONVERT(tr.matchday_id USING utf8mb3)
    JOIN usr_ud16_151_1.season s ON s.id = md.season_id
    GROUP BY m.id, m.manager_name, t.season_id, s.start_date
) sub WHERE rn = 1 AND vorlagen >= 60
ORDER BY vorlagen DESC;

-- =============================================================================
-- datenkrake — Spieltage als alleiniger Contributor (Threshold: 1)
-- =============================================================================
WITH dk_contributions AS (
    SELECT pr.matchday_id, mc.manager_id
    FROM usr_ud16_151_4.maintainer_contribution mc
    JOIN usr_ud16_151_1.player_rating pr ON pr.id = CONVERT(mc.player_rating_id USING utf8mb3)
    JOIN usr_ud16_151_1.matchday md ON md.id = pr.matchday_id AND md.completed = 1
    GROUP BY pr.matchday_id, mc.manager_id
),
dk_solo AS (
    SELECT matchday_id, MAX(manager_id) AS manager_id
    FROM dk_contributions
    GROUP BY matchday_id HAVING COUNT(*) = 1
)
SELECT
    (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'datenkrake') AS achievement_id,
    m.manager_name,
    COUNT(*) AS solo_spieltage
FROM dk_solo sm
JOIN usr_ud16_151_4.manager m ON m.id = CONVERT(sm.manager_id USING utf8mb3)
GROUP BY m.id, m.manager_name
ORDER BY solo_spieltage DESC;

-- =============================================================================
-- kleine_grosse — Bester 0,5-Mio-Spieler im Besitzfenster (Bronze ≥10, Silber ≥20, Gold ≥30 Pkt)
-- =============================================================================
SELECT achievement_id, manager_name, spieler, saison, punkte,
       CASE WHEN punkte >= 30 THEN 'gold' WHEN punkte >= 20 THEN 'silver' ELSE 'bronze' END AS level
FROM (
    SELECT
        (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'kleine_grosse') AS achievement_id,
        m.id AS manager_id, m.manager_name,
        p.displayname AS spieler,
        CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison,
        SUM(pr.points) AS punkte,
        ROW_NUMBER() OVER (PARTITION BY m.id ORDER BY SUM(pr.points) DESC) AS rn
    FROM usr_ud16_151_1.player_in_season pis
    JOIN usr_ud16_151_1.player p ON p.id = pis.player_id
    JOIN usr_ud16_151_1.season s ON s.id = pis.season_id
    JOIN usr_ud16_151_4.player_in_team pit ON CONVERT(pit.player_id USING utf8mb3) = pis.player_id
    JOIN usr_ud16_151_4.team t ON t.id = pit.team_id AND CONVERT(t.season_id USING utf8mb3) = pis.season_id
    JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
    JOIN usr_ud16_151_1.matchday md_from ON md_from.id = CONVERT(pit.from_matchday_id USING utf8mb3)
    LEFT JOIN usr_ud16_151_1.matchday md_to ON md_to.id = CONVERT(pit.to_matchday_id USING utf8mb3)
    JOIN usr_ud16_151_1.matchday md ON md.season_id = pis.season_id
        AND md.number >= md_from.number
        AND (pit.to_matchday_id IS NULL OR md.number < md_to.number)
    JOIN usr_ud16_151_1.player_rating pr
        ON pr.player_id = pis.player_id AND pr.matchday_id = md.id AND pr.points IS NOT NULL
    JOIN usr_ud16_151_4.team_lineup tl
        ON tl.team_id = t.id
        AND CONVERT(tl.player_id USING utf8mb3) = pis.player_id
        AND CONVERT(tl.matchday_id USING utf8mb3) = md.id
        AND tl.nominated = 1
    WHERE pis.price = 500000
    GROUP BY m.id, m.manager_name, pis.player_id, p.displayname, pis.season_id, s.start_date
) sub WHERE rn = 1 AND punkte >= 10
ORDER BY punkte DESC;

-- =============================================================================
-- zuschlag — Meiste Bieter bei gewonnener Auktion (Threshold: 6 Bieter)
-- =============================================================================
SELECT achievement_id, manager_name, spieler, n_bieter
FROM (
    SELECT
        (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'zuschlag') AS achievement_id,
        m.id AS manager_id, m.manager_name,
        p.displayname AS spieler,
        bids.n_bieter,
        ROW_NUMBER() OVER (PARTITION BY m.id ORDER BY bids.n_bieter DESC) AS rn
    FROM usr_ud16_151_4.offer o_win
    JOIN usr_ud16_151_4.team t ON t.id = o_win.team_id
    JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
    JOIN usr_ud16_151_1.player p ON p.id = CONVERT(o_win.player_id USING utf8mb3)
    JOIN (
        SELECT transferwindow_id, player_id, COUNT(*) AS n_bieter
        FROM usr_ud16_151_4.offer
        WHERE status IN ('success', 'lost')
        GROUP BY transferwindow_id, player_id
    ) bids ON bids.transferwindow_id = o_win.transferwindow_id AND bids.player_id = o_win.player_id
    WHERE o_win.status = 'success'
) sub WHERE rn = 1
ORDER BY n_bieter DESC;

-- =============================================================================
-- youth_squad — Max. Feldspieler (kein GK) ≤23 Jahre (kickoff + 2 Tage) an einem Spieltag pro Manager
-- =============================================================================
SELECT achievement_id, manager_name, spieltag, saison, junge_feldspieler, feldspieler_gesamt
FROM (
    SELECT
        (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'youth_squad') AS achievement_id,
        m.id AS manager_id, m.manager_name,
        md.number AS spieltag,
        CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison,
        SUM(CASE WHEN p.date_of_birth IS NOT NULL
                 AND TIMESTAMPDIFF(YEAR, p.date_of_birth, DATE_ADD(md.kickoff_date, INTERVAL 2 DAY)) <= 23
                 THEN 1 ELSE 0 END) AS junge_feldspieler,
        COUNT(*) AS feldspieler_gesamt,
        ROW_NUMBER() OVER (PARTITION BY m.id ORDER BY
            SUM(CASE WHEN p.date_of_birth IS NOT NULL
                     AND TIMESTAMPDIFF(YEAR, p.date_of_birth, DATE_ADD(md.kickoff_date, INTERVAL 2 DAY)) <= 23
                     THEN 1 ELSE 0 END) DESC
        ) AS rn
    FROM usr_ud16_151_4.team_lineup tl
    JOIN usr_ud16_151_4.team t ON t.id = tl.team_id
    JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
    JOIN usr_ud16_151_1.player p ON p.id = CONVERT(tl.player_id USING utf8mb3)
    JOIN usr_ud16_151_1.player_in_season pis
        ON pis.player_id = p.id
        AND pis.season_id = CONVERT(t.season_id USING utf8mb3)
        AND pis.position != 'GOALKEEPER'
    JOIN usr_ud16_151_1.matchday md ON md.id = CONVERT(tl.matchday_id USING utf8mb3) AND md.completed = 1
    JOIN usr_ud16_151_1.season s ON s.id = md.season_id AND s.start_date >= '2017-07-01'
    WHERE tl.nominated = 1
    GROUP BY m.id, m.manager_name, tl.matchday_id, md.number, md.kickoff_date, s.start_date
) sub WHERE rn = 1
ORDER BY junge_feldspieler DESC;

-- =============================================================================
-- tall_squad — Max. Anzahl nominierter Spieler mit ≥190 cm an einem Spieltag pro Manager (Threshold: 7)
-- =============================================================================
SELECT achievement_id, manager_name, spieltag, saison, grosse_spieler, nominated_count
FROM (
    SELECT
        (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'tall_squad') AS achievement_id,
        m.id AS manager_id, m.manager_name,
        md.number AS spieltag,
        CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison,
        SUM(CASE WHEN p.height_cm >= 190 THEN 1 ELSE 0 END) AS grosse_spieler,
        COUNT(*) AS nominated_count,
        ROW_NUMBER() OVER (PARTITION BY m.id ORDER BY
            SUM(CASE WHEN p.height_cm >= 190 THEN 1 ELSE 0 END) DESC
        ) AS rn
    FROM usr_ud16_151_4.team_lineup tl
    JOIN usr_ud16_151_4.team t ON t.id = tl.team_id
    JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
    JOIN usr_ud16_151_1.player p ON p.id = CONVERT(tl.player_id USING utf8mb3)
    JOIN usr_ud16_151_1.matchday md ON md.id = CONVERT(tl.matchday_id USING utf8mb3) AND md.completed = 1
    JOIN usr_ud16_151_1.season s ON s.id = md.season_id AND s.start_date >= '2017-07-01'
    WHERE tl.nominated = 1
    GROUP BY m.id, m.manager_name, tl.matchday_id, md.number, md.kickoff_date, s.start_date
) sub WHERE rn = 1
ORDER BY grosse_spieler DESC;

-- =============================================================================
-- [ANALYSE] Höchster Altersschnitt der nominierten Startelf pro Manager
-- (nur Spieler mit bekanntem Geburtsdatum; Alter an kickoff_date + 2 Tage)
-- =============================================================================
SELECT manager_name, spieltag, saison, ROUND(avg_alter, 1) AS avg_alter, mit_geburtsdatum
FROM (
    SELECT
        m.id AS manager_id, m.manager_name,
        md.number AS spieltag,
        CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison,
        AVG(TIMESTAMPDIFF(YEAR, p.date_of_birth, DATE_ADD(md.kickoff_date, INTERVAL 2 DAY))) AS avg_alter,
        COUNT(*) AS mit_geburtsdatum,
        ROW_NUMBER() OVER (PARTITION BY m.id ORDER BY
            AVG(TIMESTAMPDIFF(YEAR, p.date_of_birth, DATE_ADD(md.kickoff_date, INTERVAL 2 DAY))) DESC
        ) AS rn
    FROM usr_ud16_151_4.team_lineup tl
    JOIN usr_ud16_151_4.team t ON t.id = tl.team_id
    JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
    JOIN usr_ud16_151_1.player p ON p.id = CONVERT(tl.player_id USING utf8mb3)
        AND p.date_of_birth IS NOT NULL
    JOIN usr_ud16_151_1.matchday md ON md.id = CONVERT(tl.matchday_id USING utf8mb3) AND md.completed = 1
    JOIN usr_ud16_151_1.season s ON s.id = md.season_id AND s.start_date >= '2017-07-01'
    WHERE tl.nominated = 1
    GROUP BY m.id, m.manager_name, tl.matchday_id, md.number, md.kickoff_date, s.start_date
) sub WHERE rn = 1
ORDER BY avg_alter DESC;

-- =============================================================================
-- season_transfers — Erfolgreiche Transfers (offer.status = 'success') pro Manager pro Saison (Threshold: 80)
-- =============================================================================
SELECT achievement_id, manager_name, saison, transfers
FROM (
    SELECT
        (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'season_transfers') AS achievement_id,
        m.id AS manager_id, m.manager_name,
        CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison,
        COUNT(*) AS transfers,
        ROW_NUMBER() OVER (PARTITION BY m.id ORDER BY COUNT(*) DESC) AS rn
    FROM usr_ud16_151_4.offer o
    JOIN usr_ud16_151_4.team t ON t.id = o.team_id
    JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
    JOIN usr_ud16_151_1.season s ON s.id = CONVERT(t.season_id USING utf8mb3)
    WHERE o.status = 'success'
    GROUP BY m.id, m.manager_name, t.season_id, s.start_date
) sub WHERE rn = 1
ORDER BY transfers DESC;

-- =============================================================================
-- season_red_cards — Platzverweise (red_card + yellow_red_card) pro Manager pro Saison (Bronze ≥4, Silber ≥6, Gold ≥8)
-- =============================================================================
SELECT achievement_id, manager_name, saison, platzverweise
FROM (
    SELECT
        (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'season_red_cards') AS achievement_id,
        m.id AS manager_id, m.manager_name,
        CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison,
        SUM(pr.red_card + pr.yellow_red_card) AS platzverweise,
        ROW_NUMBER() OVER (PARTITION BY m.id ORDER BY SUM(pr.red_card + pr.yellow_red_card) DESC) AS rn
    FROM usr_ud16_151_4.team_lineup tl
    JOIN usr_ud16_151_4.team t ON t.id = tl.team_id
    JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
    JOIN usr_ud16_151_1.player_rating pr
        ON pr.player_id = CONVERT(tl.player_id USING utf8mb3)
        AND pr.matchday_id = CONVERT(tl.matchday_id USING utf8mb3)
        AND (pr.red_card = 1 OR pr.yellow_red_card = 1)
    JOIN usr_ud16_151_1.matchday md ON md.id = CONVERT(tl.matchday_id USING utf8mb3)
    JOIN usr_ud16_151_1.season s ON s.id = md.season_id AND s.start_date >= '2017-07-01'
    WHERE tl.nominated = 1
    GROUP BY m.id, m.manager_name, t.season_id, s.start_date
) sub WHERE rn = 1
ORDER BY platzverweise DESC;

-- =============================================================================
-- matchday_assists — Meiste Vorlagen an einem Spieltag pro Manager (Bronze ≥6, Silber ≥7, Gold ≥8)
-- =============================================================================
SELECT achievement_id, manager_name, spieltag, saison, vorlagen
FROM (
    SELECT
        (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'matchday_assists') AS achievement_id,
        m.id AS manager_id, m.manager_name,
        md.number AS spieltag,
        CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison,
        tr.assists AS vorlagen,
        ROW_NUMBER() OVER (PARTITION BY m.id ORDER BY tr.assists DESC) AS rn
    FROM usr_ud16_151_4.team_rating tr
    JOIN usr_ud16_151_4.team t ON t.id = tr.team_id AND tr.invalid = 0
    JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
    JOIN usr_ud16_151_1.matchday md ON md.id = CONVERT(tr.matchday_id USING utf8mb3) AND md.completed = 1
    JOIN usr_ud16_151_1.season s ON s.id = md.season_id AND s.start_date >= '2017-07-01'
) sub WHERE rn = 1
ORDER BY vorlagen DESC;

-- =============================================================================
-- matchday_goals — Meiste Tore an einem Spieltag pro Manager (Bronze ≥8, Silber ≥9, Gold ≥10)
-- =============================================================================
SELECT achievement_id, manager_name, spieltag, saison, tore
FROM (
    SELECT
        (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'matchday_goals') AS achievement_id,
        m.id AS manager_id, m.manager_name,
        md.number AS spieltag,
        CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison,
        tr.goals AS tore,
        ROW_NUMBER() OVER (PARTITION BY m.id ORDER BY tr.goals DESC) AS rn
    FROM usr_ud16_151_4.team_rating tr
    JOIN usr_ud16_151_4.team t ON t.id = tr.team_id AND tr.invalid = 0
    JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
    JOIN usr_ud16_151_1.matchday md ON md.id = CONVERT(tr.matchday_id USING utf8mb3) AND md.completed = 1
    JOIN usr_ud16_151_1.season s ON s.id = md.season_id AND s.start_date >= '2017-07-01'
) sub WHERE rn = 1
ORDER BY tore DESC;

-- =============================================================================
-- kegelkasse — Längste Serie auf dem letzten Platz (Threshold: 3)
-- =============================================================================
WITH kk_last AS (
    SELECT t.manager_id, md.season_id, md.number,
           (tr.points <= MIN(tr.points) OVER (PARTITION BY tr.matchday_id)) AS is_last
    FROM usr_ud16_151_4.team_rating tr
    JOIN usr_ud16_151_4.team t ON t.id = tr.team_id
    JOIN usr_ud16_151_1.matchday md ON md.id = CONVERT(tr.matchday_id USING utf8mb3) AND md.completed = 1
    JOIN usr_ud16_151_1.season s ON s.id = md.season_id AND s.start_date >= '2017-01-01'
),
kk_islands AS (
    SELECT manager_id, season_id, number,
           number - ROW_NUMBER() OVER (PARTITION BY manager_id, season_id ORDER BY number) AS grp
    FROM kk_last WHERE is_last = 1
),
kk_streaks AS (
    SELECT manager_id, season_id, COUNT(*) AS streak_len
    FROM kk_islands GROUP BY manager_id, season_id, grp
),
kk_best AS (
    SELECT manager_id, season_id, MAX(streak_len) AS max_letzte_serie,
           ROW_NUMBER() OVER (PARTITION BY manager_id ORDER BY MAX(streak_len) DESC) AS rn
    FROM kk_streaks GROUP BY manager_id, season_id
)
SELECT
    (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'kegelkasse') AS achievement_id,
    m.manager_name,
    CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison,
    b.max_letzte_serie
FROM kk_best b
JOIN usr_ud16_151_4.manager m ON m.id = CONVERT(b.manager_id USING utf8mb3)
JOIN usr_ud16_151_1.season s ON s.id = b.season_id
WHERE b.rn = 1
ORDER BY b.max_letzte_serie DESC;


-- =============================================================================
-- geburtstagskind — Nominierter Spieler hat Geburtstag (kickoff+2 Tage) und ≥10 Punkte
-- =============================================================================
SELECT
    (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'geburtstagskind') AS achievement_id,
    CONVERT(m.id USING utf8mb3) AS manager_id,
    CONVERT(m.manager_name USING utf8mb3) AS manager,
    p.id AS player_id,
    p.displayname,
    p.date_of_birth,
    md.id AS matchday_id,
    DATE_FORMAT(DATE_ADD(md.kickoff_date, INTERVAL 2 DAY), '%d.%m.%Y') AS geburtstag_stichtag,
    pr.points AS spieler_punkte,
    md.number AS spieltag,
    CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison
FROM usr_ud16_151_4.team_lineup tl
JOIN usr_ud16_151_4.team t ON t.id = tl.team_id
JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
JOIN usr_ud16_151_1.matchday md ON md.id = CONVERT(tl.matchday_id USING utf8mb3) AND md.completed = 1
JOIN usr_ud16_151_1.season s ON s.id = md.season_id
JOIN usr_ud16_151_1.player p ON p.id = CONVERT(tl.player_id USING utf8mb3)
JOIN usr_ud16_151_1.player_rating pr
    ON pr.player_id = p.id AND pr.matchday_id = md.id
WHERE tl.nominated = 1
  AND p.date_of_birth IS NOT NULL
  AND MONTH(p.date_of_birth) = MONTH(DATE_ADD(md.kickoff_date, INTERVAL 2 DAY))
  AND DAY(p.date_of_birth) = DAY(DATE_ADD(md.kickoff_date, INTERVAL 2 DAY))
  AND pr.points >= 10
ORDER BY manager, spieltag;

-- =============================================================================
-- phantome — ≥2 nominierte Starter ohne Note an einem Spieltag
-- =============================================================================
SELECT
    (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'phantome') AS achievement_id,
    CONVERT(m.manager_name USING utf8mb3) AS manager,
    md.number AS spieltag,
    CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison,
    COUNT(*) AS starter_ohne_note
FROM usr_ud16_151_4.team_lineup tl
JOIN usr_ud16_151_4.team t ON t.id = tl.team_id
JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
JOIN usr_ud16_151_1.matchday md ON md.id = CONVERT(tl.matchday_id USING utf8mb3) AND md.completed = 1
JOIN usr_ud16_151_1.season s ON s.id = md.season_id
JOIN usr_ud16_151_1.player_rating pr
    ON pr.player_id = CONVERT(tl.player_id USING utf8mb3) AND pr.matchday_id = md.id
WHERE tl.nominated = 1
  AND pr.participation = 'starting'
  AND pr.grade IS NULL
GROUP BY m.id, m.manager_name, tl.matchday_id, md.number, s.start_date
HAVING COUNT(*) >= 2
ORDER BY starter_ohne_note DESC, manager, spieltag;

-- =============================================================================
-- transfer_reue — Spieler verkauft, der am nächsten Spieltag SDS wird
-- =============================================================================
SELECT
    (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'transfer_reue') AS achievement_id,
    CONVERT(m.manager_name USING utf8mb3) AS manager,
    p.displayname AS verkaufter_spieler,
    md_next.number AS spieltag,
    CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison
FROM usr_ud16_151_4.sell sl
JOIN usr_ud16_151_4.team t ON t.id = sl.team_id
JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
JOIN usr_ud16_151_1.transferwindow tw ON tw.id = CONVERT(sl.transferwindow_id USING utf8mb3)
JOIN usr_ud16_151_1.matchday md_tw ON md_tw.id = tw.matchday_id
JOIN usr_ud16_151_1.matchday md_next ON md_next.id = (
    SELECT id FROM usr_ud16_151_1.matchday
    WHERE completed = 1 AND kickoff_date > md_tw.kickoff_date
    ORDER BY kickoff_date ASC LIMIT 1
)
JOIN usr_ud16_151_1.player_rating pr
    ON pr.player_id = CONVERT(sl.player_id USING utf8mb3)
    AND pr.matchday_id = md_next.id
    AND pr.sds = 1
JOIN usr_ud16_151_1.player p ON p.id = CONVERT(sl.player_id USING utf8mb3)
JOIN usr_ud16_151_1.season s ON s.id = md_next.season_id
ORDER BY manager, spieltag;

-- =============================================================================
-- bankdruecker — Spieltag mit den meisten Bank-SDS pro Manager
-- =============================================================================
SELECT achievement_id, manager, spieltag, saison, anzahl_bank_sds
FROM (
    SELECT
        (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'bankdruecker') AS achievement_id,
        m.id AS manager_id, CONVERT(m.manager_name USING utf8mb3) AS manager,
        md.number AS spieltag,
        CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison,
        COUNT(*) AS anzahl_bank_sds,
        ROW_NUMBER() OVER (PARTITION BY m.id ORDER BY COUNT(*) DESC) AS rn
    FROM usr_ud16_151_4.team_lineup tl
    JOIN usr_ud16_151_4.team t ON t.id = tl.team_id
    JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
    JOIN usr_ud16_151_1.player_rating pr
        ON pr.player_id = CONVERT(tl.player_id USING utf8mb3)
        AND pr.matchday_id = CONVERT(tl.matchday_id USING utf8mb3)
        AND pr.sds = 1
    JOIN usr_ud16_151_1.matchday md ON md.id = CONVERT(tl.matchday_id USING utf8mb3) AND md.completed = 1
    JOIN usr_ud16_151_1.season s ON s.id = md.season_id AND s.start_date >= '2017-07-01'
    WHERE tl.nominated = 0
    GROUP BY m.id, m.manager_name, tl.matchday_id, md.number, md.kickoff_date, s.start_date
    HAVING COUNT(*) >= 2
) sub WHERE rn = 1
ORDER BY anzahl_bank_sds DESC, manager;

-- =============================================================================
-- torwart_torschuetze — Nominierter Torwart erzielt ein Tor
-- =============================================================================
SELECT
    (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'torwart_torschuetze') AS achievement_id,
    CONVERT(m.manager_name USING utf8mb3) AS manager,
    p.displayname AS torwart,
    pr.goals AS tore,
    md.number AS spieltag,
    CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison
FROM usr_ud16_151_4.team_lineup tl
JOIN usr_ud16_151_4.team t ON t.id = tl.team_id
JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
JOIN usr_ud16_151_1.player_in_season pis
    ON pis.player_id = CONVERT(tl.player_id USING utf8mb3)
    AND pis.season_id = CONVERT(t.season_id USING utf8mb3)
    AND pis.position = 'GOALKEEPER'
JOIN usr_ud16_151_1.player_rating pr
    ON pr.player_id = CONVERT(tl.player_id USING utf8mb3)
    AND pr.matchday_id = CONVERT(tl.matchday_id USING utf8mb3)
    AND pr.goals >= 1
JOIN usr_ud16_151_1.matchday md ON md.id = CONVERT(tl.matchday_id USING utf8mb3) AND md.completed = 1
JOIN usr_ud16_151_1.season s ON s.id = md.season_id AND s.start_date >= '2017-07-01'
JOIN usr_ud16_151_1.player p ON p.id = CONVERT(tl.player_id USING utf8mb3)
WHERE tl.nominated = 1
ORDER BY manager, spieltag;

-- =============================================================================
-- alles_perfekt — Ist-Punkte = Max-Punkte an einem Spieltag (≥60 Punkte)
-- =============================================================================
SELECT achievement_id, manager_name, spieltag, saison, punkte
FROM (
    SELECT
        (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'alles_perfekt') AS achievement_id,
        m.id AS manager_id, CONVERT(m.manager_name USING utf8mb3) AS manager_name,
        md.number AS spieltag,
        CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison,
        tr.points AS punkte,
        ROW_NUMBER() OVER (PARTITION BY m.id ORDER BY md.kickoff_date ASC) AS rn
    FROM usr_ud16_151_4.team_rating tr
    JOIN usr_ud16_151_4.team t ON t.id = tr.team_id AND tr.invalid = 0
    JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
    JOIN usr_ud16_151_1.matchday md ON md.id = CONVERT(tr.matchday_id USING utf8mb3) AND md.completed = 1
    JOIN usr_ud16_151_1.season s ON s.id = md.season_id AND s.start_date >= '2017-07-01'
    WHERE tr.points = tr.max_points AND tr.max_points > 0 AND tr.points >= 60
) sub WHERE rn = 1
ORDER BY manager_name, spieltag;

-- =============================================================================
-- pechvogel — Spieltag mit den meisten nominierten 6,0-Spielern pro Manager
-- =============================================================================
SELECT achievement_id, manager, spieltag, saison, anzahl_sechsen
FROM (
    SELECT
        (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'pechvogel') AS achievement_id,
        m.id AS manager_id, CONVERT(m.manager_name USING utf8mb3) AS manager,
        md.number AS spieltag,
        CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison,
        COUNT(*) AS anzahl_sechsen,
        ROW_NUMBER() OVER (PARTITION BY m.id ORDER BY COUNT(*) DESC) AS rn
    FROM usr_ud16_151_4.team_lineup tl
    JOIN usr_ud16_151_4.team t ON t.id = tl.team_id
    JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
    JOIN usr_ud16_151_1.player_rating pr
        ON pr.player_id = CONVERT(tl.player_id USING utf8mb3)
        AND pr.matchday_id = CONVERT(tl.matchday_id USING utf8mb3)
        AND pr.grade = 6.0
    JOIN usr_ud16_151_1.matchday md ON md.id = CONVERT(tl.matchday_id USING utf8mb3) AND md.completed = 1
    JOIN usr_ud16_151_1.season s ON s.id = md.season_id AND s.start_date >= '2017-07-01'
    WHERE tl.nominated = 1
    GROUP BY m.id, m.manager_name, tl.matchday_id, md.number, md.kickoff_date, s.start_date
    HAVING COUNT(*) >= 2
) sub WHERE rn = 1
ORDER BY anzahl_sechsen DESC, manager;

-- =============================================================================
-- bankraeuber — Spieler mit größter Punktedifferenz (Bank > Aufgestellt) pro Manager
-- =============================================================================
SELECT achievement_id, manager_name, spieler, saison, nom_count, bank_count, nom_punkte, bank_punkte, differenz
FROM (
    SELECT
        (SELECT id FROM usr_ud16_151_1.achievement WHERE condition_key = 'bankraeuber') AS achievement_id,
        m.id AS manager_id, CONVERT(m.manager_name USING utf8mb3) AS manager_name,
        p.displayname AS spieler,
        CONCAT(YEAR(s.start_date), '/', RIGHT(YEAR(s.start_date)+1, 2)) AS saison,
        SUM(CASE WHEN tl.nominated = 1 THEN 1 ELSE 0 END) AS nom_count,
        SUM(CASE WHEN tl.nominated = 0 THEN 1 ELSE 0 END) AS bank_count,
        SUM(CASE WHEN tl.nominated = 1 THEN COALESCE(pr.points, 0) ELSE 0 END) AS nom_punkte,
        SUM(CASE WHEN tl.nominated = 0 THEN COALESCE(pr.points, 0) ELSE 0 END) AS bank_punkte,
        SUM(CASE WHEN tl.nominated = 0 THEN COALESCE(pr.points, 0) ELSE 0 END) -
        SUM(CASE WHEN tl.nominated = 1 THEN COALESCE(pr.points, 0) ELSE 0 END) AS differenz,
        ROW_NUMBER() OVER (PARTITION BY m.id ORDER BY
            SUM(CASE WHEN tl.nominated = 0 THEN COALESCE(pr.points, 0) ELSE 0 END) -
            SUM(CASE WHEN tl.nominated = 1 THEN COALESCE(pr.points, 0) ELSE 0 END) DESC
        ) AS rn
    FROM usr_ud16_151_4.team_lineup tl
    JOIN usr_ud16_151_4.team t ON t.id = tl.team_id
    JOIN usr_ud16_151_4.manager m ON m.id = t.manager_id
    JOIN usr_ud16_151_1.matchday md ON md.id = CONVERT(tl.matchday_id USING utf8mb3) AND md.completed = 1
    JOIN usr_ud16_151_1.season s ON s.id = md.season_id AND s.start_date >= '2017-07-01'
        AND CONVERT(t.season_id USING utf8mb3) = s.id
    JOIN usr_ud16_151_1.player p ON p.id = CONVERT(tl.player_id USING utf8mb3)
    LEFT JOIN usr_ud16_151_1.player_rating pr
        ON pr.player_id = CONVERT(tl.player_id USING utf8mb3)
        AND pr.matchday_id = CONVERT(tl.matchday_id USING utf8mb3)
    GROUP BY m.id, m.manager_name, tl.player_id, t.season_id, s.start_date
    HAVING SUM(CASE WHEN tl.nominated = 0 THEN 1 ELSE 0 END) > 0
       AND SUM(CASE WHEN tl.nominated = 0 THEN COALESCE(pr.points, 0) ELSE 0 END) >
           SUM(CASE WHEN tl.nominated = 1 THEN COALESCE(pr.points, 0) ELSE 0 END)
) sub WHERE rn = 1
ORDER BY differenz DESC;
