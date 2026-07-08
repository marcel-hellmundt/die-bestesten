# Projekt-Spezifikationen

## Regeln

- **API-Änderungen**: Bei jeder Änderung in `/api` → `CLAUDE.md` + `api/schema.php` aktualisieren, dann committen **und pushen** (Push triggert GitHub Action → Server-Deploy).
- **Webapp-Änderungen**: Mobile + Desktop berücksichtigen. Infos dürfen auf kleinen Screens reduziert/ausgeblendet werden — Kernfunktionalität muss auf beiden nutzbar sein.
- **Branch-Cleanup**: Nach jedem erfolgreichen lokalen Merge eines Branches nach `main` (inkl. Push) den gemergten Source-Branch löschen — lokal (`git branch -d <branch>`) **und** remote, falls vorhanden (`git push origin --delete <branch>`), damit nur Branches mit aktiver Arbeit übrig bleiben.

## Stack

Angular-Webapp + PHP-REST-API, Fantasy-Football. Frontend: Angular (`standalone: false`, Signal-basiert). Backend: PHP. DB: MySQL.

## Repo-Struktur

```
die-bestesten/
├── .github/workflows/  — deploy-api.yml, deploy-webapp.yml, deploy-img.yml (Push auf main → Deploy)
├── api/app/
│   ├── controller/     — Ein Controller pro Ressource (erbt _BaseController)
│   ├── database/       — Ein Trait pro Ressource; composited in base.database.php
│   ├── util/           — image_upload.util.php (Bild-Validierung + Datei-Write per FTP, FTP_HOST/USER/PASSWORD + FTP_DIR_IMAGE; gemeinsamer FTP-Zugang für mehrere Asset-Server, FTP_DIR_AUDIO reserviert für zukünftigen Audio-Server)
│   ├── guard.php       — JWT + RBAC; setzt $GLOBALS['auth_roles'(array)/'auth_manager_id']
│   └── routing.php     — Routen + eingebettete API-Doku
├── api/index.php       — Einstiegspunkt; parst URL → Routing → Controller
├── api/schema.php      — Web-UI für API-Doku (Mermaid-ER + Endpunkte aus routing.php)
├── database/global_schema.sql, league_schema.sql
├── asset_server/        — deployt (nur .htaccess) auf beide Asset-Server-Ordner (Bild + Audio, FTP_DIR_IMAGE/FTP_DIR_AUDIO); Entity-Ordner mit Uploads liegen direkt im jeweiligen Webroot und bleiben beim Deploy erhalten; reines statisches Datei-Serving, kein PHP — Uploads laufen über api/
└── webapp/
```

## Webapp-Struktur

```
webapp/src/app/
├── auth/              — Login + JWT-Guard (auth.guard.ts, auth.service.ts, login/)
├── core/
│   ├── api.service.ts           — HTTP-Wrapper: get/post/patch<T>(path, body?)
│   ├── data-cache.service.ts    — Reaktiver Cache für Lookups
│   └── models/                  — club, country, division, matchday, player, season, transferwindow (je from()-Factory)
├── data/              — /app/data: club, country, division, player, season (Liste + Detail je)
└── shell/             — Layout: Sidebar (Desktop vertikal, Mobile bottom-bar) + Topbar
styles/
├── _variables.scss    — Design-Tokens: Farben, Abstände, Radii, Typografie, Breakpoints
├── _layout.scss       — .data-table, .table-container, .list-bar, .stat-card, .card, .page-title, .row-link, …
└── _buttons.scss, _inputs.scss, _typography.scss, _fonts.scss, _reset.scss
```

### Patterns

- **State**: Signals (`signal`, `computed`, `effect`) + RxJS via `toSignal`/`toObservable`
- **Komponenten**: `standalone: false`, SCSS mit `@use '../../../styles/variables' as *`
- **Routing**: Lazy-loaded Module; Detail-Routen als Kind-Routen im selben Modul
- **Styles**: Globale Klassen aus `_layout.scss` verwenden (`.row-link`, `.data-table`, `.col-id`) statt eigene SCSS schreiben

## API-Autorisierung (RBAC)

**Additives Rollenmodell**: Jeder Manager hat die Basisrolle `manager` (implizit). Zusätzliche Rollen (`maintainer`, `admin`) werden in der `manager_role`-Tabelle gespeichert und sind frei kombinierbar.

`$methodRoles` pro Controller: HTTP-Methode → erforderliche Rolle. Prüfung: `guest` = kein Token nötig; `manager` = jeder eingeloggte Manager; `maintainer`/`admin` = Manager muss diese Rolle in seiner Rollenliste haben. Fehlende Einträge = `guest`. 401 = kein Token, 403 = Rolle fehlt. Guard setzt `$GLOBALS['auth_manager_id']` + `$GLOBALS['auth_roles']` (Array).

Rollenvergabe: `POST /manager/:id/roles` mit `{role}`, Entzug: `DELETE /manager/:id/roles/:role` — jeweils Admin.

## Datenbankschema

Vollständig in `database/global_schema.sql`. Alle IDs `CHAR(36)` UUID außer country (`CHAR(2)` ISO-Alpha-2).

| Tabelle | Spalten |
|---------|---------|
| country | id PK, name |
| season | id PK, start_date UNIQUE — aktiv = höchstes start_date |
| league | id PK, slug UNIQUE, name, db_name, visibility ENUM('public','private') DEFAULT 'public' — public = Beitrittsanfragen erlaubt; private = nur Einladung |
| club | id PK, country_id FK, name, short_name, logo_uploaded BOOL |
| division | id PK, name, level INT, seats INT, country_id FK |
| matchday | id PK, season_id FK, division_id FK, start_date DATE, kickoff_date DATETIME, number INT, completed BOOL — UNIQUE(season_id, division_id, number) — jede Division pflegt eigene Spieltage |
| player | id PK, kicker_id INT UNIQUE?, country_id FK?, first_name, last_name, displayname UNIQUE, birth_city, date_of_birth, height_cm, weight_kg |
| club_in_season | id PK, club_id FK, season_id FK, division_id FK, position INT? — UNIQUE(club_id, season_id) |
| player_in_season | id PK, player_id FK, season_id FK, price DECIMAL, position ENUM(GOALKEEPER/DEFENDER/MIDFIELDER/FORWARD), photo_uploaded — UNIQUE(player_id, season_id) |
| player_in_club | id PK, player_id FK, club_id FK, from_date DATE, to_date DATE?, on_loan BOOL — UNIQUE(player_id, club_id, from_date) |
| player_rating | id PK, player_id FK, matchday_id FK, club_id FK? (zum Zeitpunkt; NULL für historische Daten), grade DECIMAL?, participation ENUM(starting/substitute)?, goals, assists, clean_sheet, sds BOOL, red_card, yellow_red_card, points — UNIQUE(player_id, matchday_id) |
| transferwindow | id PK, matchday_id FK, start_date DATETIME, end_date DATETIME — 2–4 pro Spieltag |
| stadium | id PK, official_name, name? (Spitzname/Alltagsname), capacity INT?, lat DECIMAL(9,6)?, lng DECIMAL(9,6)?, opened_date DATE?, closed_date DATE? |
| club_stadium | id PK, club_id FK, stadium_id FK, from_date DATE, to_date DATE? — UNIQUE(club_id, from_date) |
| award | id PK, name UNIQUE, icon VARCHAR(100)? (nur Dateiname, z.B. "trophy.png" → public/img/icons/), sort_index INT — Award-Typen; sort_index = Wichtigkeit (1 = wichtigster) |

## API-Endpunkte

Vollständige Doku: `api/schema.php`.

```
GET/POST /club_in_season       — Saison-Zuordnungen; POST 409 bei Duplikat
PATCH    /club_in_season/:id   — Division/Position aktualisieren
GET      /division[/:id]
GET      /club[/:id]           — /:id enthält stadium-Objekt (aktuelles Stadion, to_date IS NULL) oder null
POST     /club/:id/logo        — multipart/form-data, Feld "image" (PNG) → setzt club.logo_uploaded — Maintainer+
GET      /country[/:id]
GET      /season[/:id|/active]
POST     /season                — {start_date: YYYY-MM-DD} → {id}; UNIQUE auf start_date — Admin
GET      /matchday[/:id]       — ?season_id gibt has_ratings (bool) zurück; filtert nach Division der aktiven Liga — Auth
POST     /matchday             — {season_id, number, start_date, kickoff_date} → {id}; division_id aus Liga-Kontext; 409 bei Duplikat, 422 wenn keine Division konfiguriert — Admin
PATCH    /matchday/:id         — {completed:bool} — bei completed=true: team_rating + Transaktionen erstellen, Achievements auswerten, Notifications senden, Zusammenfassungs-E-Mail an Admins (nur wenn email hinterlegt) — Admin
GET      /all_time_standings   — { standings: [{id,manager_name,alias,total_points}], top_matchdays: [{points,matchday_number,team_name,season_id,manager_name}] } — Auth
GET      /league[/:id]         — enthält manager_count aus der jeweiligen Liga-DB
GET      /league/mine          — Aktuelle Liga des Deployments {id,slug,name,db_name,division_id}
PATCH    /league/:id           — {division_id: UUID|null} Spielerpool-Division setzen; oder {visibility: 'public'|'private'} Sichtbarkeit setzen — Admin
POST     /league/:id/join      — Beitrittsanfrage stellen (status='requested'); benachrichtigt alle Admins; 403 wenn visibility='private' — Auth
POST     /league/:id/accept    — Einladung annehmen (invited→active); benachrichtigt alle Admins per E-Mail; 409 wenn keine ausstehende Einladung — Auth
POST     /league/:id/decline   — Einladung ablehnen (invited→denied); 409 wenn keine ausstehende Einladung — Auth
POST     /league/:id/invite    — {manager_id} Manager einladen (status='invited'); benachrichtigt Manager — Admin
POST     /league/:id/approve   — {manager_id} Anfrage genehmigen (requested→active); benachrichtigt Manager — Admin
POST     /league/:id/deny      — {manager_id} Mitgliedschaft ablehnen (→denied) — Admin
POST     /league/validate_ratings  — {league_id} — prüft team_ratings ab 2020/21 gegen team_lineup + player_rating — Admin
POST     /league/fix_rating        — {league_id, team_id, matchday_id, field, value} — korrigiert ein Feld in team_rating (Liga-DB) — Admin
POST     /league/conclude_season   — {league_id, season_id} — Saisonauszeichnungen vergeben (Meister, Goldene Bürste, Hölzerne Bank); idempotent; wird auch automatisch bei Spieltag 34 ausgeführt — Admin
GET      /transferwindow[/:id] — ?matchday_id|season_id
POST     /transferwindow       — {matchday_id,start_date,end_date} — Maintainer+
GET      /team_lineup          — ?team_id (erforderlich), ?matchday_id (optional) → {matchday, matchdays[], nominated[], bench[], points, max_points} — jeder Spieler enthält grade, points, goals, assists, clean_sheet, sds, participation; Auto-Init für aktuellen Spieltag wenn noch keine Einträge — Auth; alternativ ?player_id + ?season_id → [{matchday_number, nominated}] — Auth
PATCH    /team_lineup          — {team_id, matchday_id, players:[{player_id, nominated, position_index}]} — nur eigenes Team, nur Editierfenster (start_date ≤ now < kickoff_date) — Auth
GET      /player_in_team             — ?team_id (erforderlich) → aktive Spieler mit position, price, points, current_club_id, club_logo_uploaded; ?include_former=1 → {current, former}; ?player_id → aktuelles Team oder null; ?player_id + ?season_id → Teamhistorie [{team_id, team_name, color, manager_name, alias, from_matchday_number, to_matchday_number}] — Auth
POST     /sell                       — {team_id, player_id, transferwindow_id} — nur eigenes Team, nur offenes Fenster; erstellt sell + transaction, setzt player_in_team.to_matchday_id, bereinigt team_lineup (nominated → alles löschen, bench → nur Spieler) — Auth
POST     /buy                        — {team_id, player_id, transferwindow_id} — nur eigenes Team, nur offenes Fenster; 409 wenn Spieler bereits in einem Team oder Positionslimit erreicht (GK≤2, DEF≤6, MID≤6, FWD≤4); erstellt player_in_team + transaction (negativ) — Auth
GET      /offer                      — ?team_id → {offers[], pending_sum} — offers enthält: displayname, position, photo_uploaded, club_id, club_logo_uploaded, season_id, losers[{team_id,team_color,team_season_id,is_winner}] (für success/lost-Gebote) — nur eigenes Team — Auth; ?transferwindow_id → {window, offers[{player_id,displayname,position,bids[]}]} aller Gebote einer geschlossenen Transferphase; triggert Lazy Settlement (höchstes Gebot gewinnt, Kaskade bei Positionslimit) — 422 wenn Fenster noch offen — Auth
POST     /offer                      — {team_id, player_id, transferwindow_id, offer_value} — Gebot auf vereinslosen Spieler; 409 wenn Spieler in Team oder Positionslimit erreicht (inkl. offene Gebote; GK≤2, DEF≤6, MID≤6, FWD≤4); 422 wenn Fenster zu / Gebot < Marktwert / Budget überschritten; INSERT offer (status=pending) — Auth
PATCH    /offer/:id                  — Body:{team_id, offer_value} — Gebotswert eines pending-Gebots ändern; 422 wenn < Marktwert oder Budget überschritten — Auth
DELETE   /offer/:id                  — Body:{team_id} — offenes Gebot stornieren (status=cancelled) — Auth
GET      /player_in_season/bundesliga_count — ?season_id (optional, default aktiv) → {count} — Spieler der konfigurierten Liga-Division
GET      /player_in_season/available_players — ?season_id (optional, default aktiv) → {players[{id,displayname,position,price,season_points,photo_uploaded,club_id,club_name,club_short_name,club_logo_uploaded,season_id}]} — Spieler der konfigurierten Liga-Division ohne Fantasy-Team
POST     /player_in_season — {player_id, season_id, position, price} → {id}; 409 bei Duplikat — Maintainer+
GET      /player[/:id]           — ?club_id=UUID gibt aktuellen Kader zurück (player_in_club.to_date IS NULL) mit season_position
POST     /player/create        — {kicker_id, first_name, last_name, displayname, season_id, position, price, club_id?, from_date?} → {id} — erstellt player + player_in_season + optional player_in_club — Maintainer+
POST     /player/:id/photo     — multipart/form-data, Feld "image" (PNG) + Body season_id → setzt player_in_season.photo_uploaded — Maintainer+
POST     /player_in_club       — {player_id, club_id, from_date, on_loan?} → {id} — fügt Spieler einem Verein zu (neuer player_in_club-Eintrag) — Maintainer+
GET      /player_rating        — ?matchday_id&club_id → Spielerinfos + price, starting_count (Starts in der Saison); sortiert nach starting_count DESC, position, price DESC
GET      /player_rating/best_xi — ?matchday_id (required), ?free_agents_only=0|1 — beste valide 11 (343/352/433/442/451) für einen Spieltag; gibt {formation, players[{player_id,displayname,position,points,grade,club_id,club_name,club_short_name}], total_points} zurück; free_agents_only=1 nur Spieler ohne Fantasy-Team — Auth
GET      /player_rating/status — ?matchday_id → [{club_id, rating_count, starter_count, grade_count, goals, assists, has_sds}] — aggregierter Status aller Clubs für einen Spieltag
POST     /player_rating/init   — {matchday_id,club_id} → leere Ratings erstellen; 409 wenn completed oder (vor kickoff_date und nicht Admin) — Maintainer+
POST     /player_rating/validate-csv — multipart: matchday_id + csv-Datei (;-getrennt, Spalte 4 = Angezeigter Name, Spalte 8 = Punkte) → {ok, checked?} oder {ok: false, mismatches: [{kicker_id, displayname, csv_points, db_points, error}]}; error: 'points mismatch' | 'player not found in db' (+ first_name/last_name/club_name/position/price) | 'no ratings in season' — Maintainer+
PATCH    /player_rating/:id    — Maintainer+; 403 wenn Spieltag completed; Body: grade, participation, goals, assists, clean_sheet, sds, red_card, yellow_red_card (points wird immer serverseitig berechnet)
POST     /auth                 — JWT-Login; Response enthält token + leagues[] + league_id (null wenn keine Liga)
POST     /auth/switch-league  — {league_id} → {token, league_id}; neues JWT mit geänderter league_id; 403 wenn kein Zugang — Auth
POST     /auth/password-reset-request — {email} — sendet Reset-Link; immer 200 (kein E-Mail-Leak)
POST     /auth/password-reset — {token,new_password} — setzt Passwort zurück; 400 wenn Token ungültig/abgelaufen
GET      /team_rating          — ?season_id → { matchday, ratings[], sds_player, max_matchday_number } letzter gestarteter Spieltag; bei nicht-abgeschlossenem Spieltag: Live-Punkte aus player_rating × team_lineup (fine = 0) — Auth
GET      /team_rating/season   — ?season_id → aggregierte Saisontabelle aller Teams, sortiert nach Punkten — Auth
GET      /team                 — ?season_id → [{id,team_name,color,color_secondary,season_id,manager_id,manager_name,alias}] sortiert nach Name — Auth
GET      /team/mine            — Eigenes Team der aktiven Saison {id, team_name, season_id, color}; 404 wenn kein Team — Auth
GET      /team/:id             — Team per ID (manager_name, alias, total_points, matchdays_played) — Auth
POST     /team                 — {team_name, color_name?, color_secondary_name?} → {id}; color_name referenziert global.color.name (z.B. "red"); benachrichtigt alle Admins per E-Mail; 409 wenn bereits Team vorhanden — Auth
GET      /color               — [{name, hex}] globale Farbpalette (name = PK, z.B. "red") — kein Auth erforderlich
PATCH    /color/:name         — {hex: '#rrggbb'} Hex ändern, kaskadiert auf team.color aller Teams dieser Liga — Admin
GET      /team/previous        — Letztes Team aus Vorsaison {id,team_name,color,season_id}; 404 wenn keines — Auth
GET      /team/check-name      — ?name= (min. 3 Zeichen) → {available: bool}; 400 wenn zu kurz — Auth
POST     /team/:id/logo        — multipart/form-data, Feld "image" (PNG) — nur eigenes Team — Auth
POST     /team/:id/logo/takeover — übernimmt Logo aus Vorsaison-Team desselben Managers — nur eigenes Team; 404 wenn kein Vorsaison-Team — Auth
GET      /manager              — [{id,manager_name,alias,status,last_activity,roles[],leagues[{id,name}]}] alle Manager global — Admin
GET      /manager/me           — {id,manager_name,alias,role,status} — Auth
GET      /manager/birthdays   — [{id,manager_name}] — Manager mit heutigem Geburtstag (MONTH+DAY match) — Auth
GET      /manager/leagues      — [{id,name,slug}] — alle Ligen des eingeloggten Managers — Auth
POST     /manager/me/photo     — multipart/form-data, Feld "image" (JPEG) — eigenes Profilfoto — Auth
PATCH    /manager/me           — {current_password,new_password} für Passwort; {email} oder {first_name} allein ohne Passwort — Auth
DELETE   /manager/me           — {password} — Auth; löscht nicht, sendet stattdessen Mail an Admin
GET      /transaction          — ?team_id (erforderlich) → {budget, transactions[]} — nur eigenes Team (403 sonst) — Auth
GET      /search               — ?q (min. 3 Zeichen) → {players[], clubs[], teams[], managers[]} — max. 8 je Typ; teams enthalten season_label — Auth
GET      /h2h               ?season_id= (optional, default=aktiv) → {groups:[{id,name,sort_index,teams[],standings[],matches[]}], knockout_matches:[]} — Auth
GET      /h2h/:id            → Match-Detail {match,matchday,home_team,away_team,home_rating,away_rating,home_lineup[],home_bench[],away_lineup[],away_bench[]} mit Spieler-Einzelpunkten — Auth
POST     /h2h               {season_id,phase,leg,home_team_id,away_team_id,matchday_id,group_id?,sort_index?} → {id} — Admin
PATCH    /h2h/:id           {home_team_id?,away_team_id?,matchday_id?,group_id?,sort_index?} — Admin
DELETE   /h2h/:id           — Admin
POST     /h2h/generate      {league_id, season_id} → {status,groups:4,matches:24} — Generiert H2H-Gruppenphase nach festem 12-Teams-Template (Snake-Seeding nach Vorjahresrang, 4 Gruppen à 3, 24 Gruppenmatches auf Spieltage 1–18); sendet allgemeine Gruppen-Notification + individuelle Spiele-Notification an alle Manager — Admin
POST     /h2h/draw_quarterfinals {league_id, season_id} → {matches:8} — Legt 8 Viertelfinale (Hin+Rück) nach festem Bracket aus Gruppenständen an (Bed.: Spieltag 18 abgeschlossen, noch keine QFs vorhanden); Bracket: A1:B2@MD20, B1:A2@MD21, C1:D2@MD22, D1:C2@MD23 (Hin), B2:A1@MD24, A2:B1@MD25, D2:C1@MD26, C2:D1@MD27 (Rück); sendet Notification an alle Manager — Admin
POST     /h2h/draw_semifinals    {league_id, season_id} → {matches:4} — Legt 4 Halbfinale (Hin+Rück) aus VF-Siegern an (Aggregat-Tore, Tiebreaker: Gesamtpunkte beider Legs; Bed.: Spieltag 27 abgeschlossen, noch keine SFs vorhanden); Überkreuzung der VF-Stränge: VF1:VF3@MD29, VF2:VF4@MD30 (Hin), VF3:VF1@MD31, VF4:VF2@MD32 (Rück); sendet Notification an alle Manager — Admin
POST     /h2h/draw_final         {league_id, season_id} → {matches:1} — Legt Finale aus HF-Siegern an (Aggregat-Tore, Tiebreaker: Gesamtpunkte beider Legs; Bed.: Spieltag 32 abgeschlossen, noch kein Final vorhanden); HF1:HF2@MD34 — Admin
GET      /h2h_group         ?season_id= → [{id,name,sort_index,teams:[team_id,...]}] — Auth
POST     /h2h_group         {season_id,name,sort_index?} → {id} — Admin
PATCH    /h2h_group/:id     {name?,sort_index?,teams?:[team_id,...]} (teams ersetzt alle Zuordnungen) — Admin
DELETE   /h2h_group/:id     kaskadiert auf h2h_group_team; h2h_match.group_id → NULL — Admin
GET      /watchlist            — ?team_id (erforderlich, nur eigenes Team) → [{id,player_id,displayname,photo_uploaded,position,price,season_id,club_id,club_name,club_short_name,club_logo_uploaded,current_team{team_id,team_name,color,team_season_id,manager_name,alias}|null,created_at}] — Auth
POST     /watchlist            — {team_id, player_id} → {id} — Spieler zur Beobachtungsliste hinzufügen; idempotent (INSERT IGNORE) — nur eigenes Team — Auth
DELETE   /watchlist/:id        — {team_id} — Spieler von der Beobachtungsliste entfernen — nur eigenes Team — Auth
GET      /achievement          — [{id,name,description,icon,threshold_bronze,threshold_silver,threshold_gold,earned_at,reason,seen_at,level,earned_count,total_managers}] — earned_at+reason+seen_at+level=null wenn nicht verdient; threshold_*=null bei Achievements ohne Stufen; description enthält '{threshold}' als Platzhalter bei gestuften Achievements; level='bronze'|'silver'|'gold' (Achievements ohne Stufen immer 'gold'); sortiert nach earned_count DESC — Auth; ?all=true → [{id,condition_key,name,description,icon,threshold_bronze,threshold_silver,threshold_gold,earned_count,total_managers,managers[{id,manager_name,earned_at,level}]}] — Admin
POST     /achievement/evaluate — Achievement-Auswertung für alle Manager anstoßen (Backfill); idempotent — Admin
POST     /achievement/evaluate/:id — Einzelnes Achievement neu auswerten: vergibt an neue Gewinner und entzieht Managern, die Anforderungen nicht mehr erfüllen — Admin
PATCH    /achievement/seen     — Alle noch nicht gesehenen Achievements (seen_at IS NULL) des eingeloggten Managers als gesehen markieren — Auth
GET      /notification         — [{id,sender_id,sender_name,receiver_id,title,message,created_at,read_at}] neueste zuerst — Auth
GET      /notification/unread_count — {count: N} — leichtgewichtiger Endpunkt für 1s-Polling — Auth
PATCH    /notification/:id     — Einzelne Notification als gelesen markieren (read_at = NOW()); 403 wenn nicht eigene — Auth
PATCH    /notification/read_all — Alle ungelesenen Notifications als gelesen markieren — Auth
POST     /notification         — {receiver_id, title, message?, sender_id?} erstellen; sender_id=null → Systemnachricht — Admin
GET      /notification/preferences — {matchday_completed: bool, achievement_earned: bool, h2h_draw: bool}; fehlende DB-Einträge = true (default ON) — Auth
PATCH    /notification/preferences — {event_type: matchday_completed|achievement_earned|h2h_draw, enabled: bool} — Auth
```

## Global-DB — Manager-Tabellen (`database/global_schema.sql`)

*Seit Multi-Liga-Support sind Manager-Daten global — ein Account kann mehreren Ligen beitreten.*

**manager**: id PK, manager_name UNIQUE (Anzeigename/Username), first_name VARCHAR(100)? (echter Vorname — für Achievement-Vergleiche), alias UNIQUE?, password, status ENUM(active/blocked/deleted) DEFAULT active, email UNIQUE?, date_of_birth?, last_activity DATETIME?

**manager_role**: id PK, manager_id FK, role ENUM(maintainer/admin) — UNIQUE(manager_id, role) — additiv; jeder Manager hat implizit 'manager'

**password_reset_token**: id PK, manager_id FK, token_hash VARCHAR(64) UNIQUE, expires_at DATETIME, used BOOL DEFAULT 0, created_at DATETIME

**manager_league**: id PK, manager_id FK → manager, league_id FK → league, joined_at DATETIME, status ENUM('active','invited','requested','denied') DEFAULT 'active' — UNIQUE(manager_id, league_id) — Bidirektionaler Beitritts-Workflow: Admin lädt ein (invited) oder Manager stellt Anfrage (requested); Genehmigung/Annahme → active; Ablehnung → denied (final)

**notification**: id PK, sender_id CHAR(36)? (NULL = Systemnachricht; kein FK), receiver_id FK → manager, title VARCHAR(255), message TEXT?, created_at DATETIME, read_at DATETIME? (NULL = ungelesen)

**notification_preference**: manager_id FK + event_type VARCHAR(50) PK — enabled BOOL DEFAULT 1 — fehlender Eintrag = default ON; event_types: matchday_completed, achievement_earned, scouted_player_update

**manager_achievement**: id PK, manager_id FK, achievement_id FK → achievement (echtes FK, gleiche DB!), earned_at DATETIME, reason VARCHAR(255)?, seen_at DATETIME?, level ENUM('bronze','silver','gold') DEFAULT 'gold' — UNIQUE(manager_id, achievement_id) — idempotent per INSERT IGNORE; seen_at=NULL = noch nicht gesehen

**maintainer_contribution**: id PK, manager_id FK, player_rating_id (cross-DB auf global_schema.player_rating, kein FK), contribution_type ENUM(bulk_create/manual_create/grade), created_at — UNIQUE(player_rating_id, contribution_type) — trackt welcher Maintainer Aufstellung/Noten eingetragen hat; grade-Einträge werden per UPSERT ersetzt (letzter Setzer behält Credit)

## Liga-DB (`database/league_schema.sql`)

*Jede Liga hat eine eigene DB. `con_league` verbindet sich dynamisch nach JWT-Decode auf die Liga des auth_league_id. Eine VIEW `manager` in der Liga-DB zeigt auf global_schema.manager — bestehende JOINs funktionieren unverändert.*

**team**: id PK, manager_id CHAR(36) (cross-DB auf global_schema.manager, kein FK), season_id (cross-DB, kein FK), team_name VARCHAR(100), color VARCHAR(7)?, created_at — UNIQUE(manager_id, season_id)

**transaction**: id PK, team_id FK, amount DECIMAL(10,2), reason VARCHAR(255), matchday_id (cross-DB, kein FK)?, created_at — Budget = SUM(amount) pro team_id

**team_rating**: id PK, team_id FK, matchday_id (cross-DB), points, max_points, goals, assists, red_cards (echte Platzverweise), yellow_red_cards (Gelb-Rote Karten), clean_sheet, sds, sds_defender, missed_goals, points_goalkeeper/defender/midfielder/forward (denorm.), invalid BOOL — UNIQUE(team_id, matchday_id)

**team_award**: id PK, team_id FK, award_id (cross-DB auf global_schema.award, kein FK) — UNIQUE(award_id, team_id) — season ergibt sich aus team.season_id

**sell**: id PK, player_id (cross-DB), team_id FK (Verkäufer), transferwindow_id (cross-DB), price INT, created_at

**player_in_team**: id PK, team_id FK, player_id (cross-DB), from_matchday_id (cross-DB, Kauf), to_matchday_id (cross-DB, Verkauf; NULL = aktiv), offer_id FK?, sell_id FK? — UNIQUE(player_id, team_id, from_matchday_id) — max. 1 aktives Team pro Spieler wird auf Applikationsebene geprüft

**team_lineup**: id PK, team_id FK, player_id (cross-DB), matchday_id (cross-DB), nominated BOOL, position_index INT? — UNIQUE(team_id, player_id, matchday_id) — alle Kader-Spieler des Spieltags; nominated=1 = aufgestellt

**team_watchlist**: id PK, team_id FK, player_id CHAR(36) (cross-DB auf global_schema.player, kein FK), created_at — UNIQUE(team_id, player_id) — private Beobachtungsliste; Benachrichtigung bei Kauf/Verkauf/SdS des Spielers (event_type: scouted_player_update)
