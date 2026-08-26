# Projekt-Spezifikationen

## Regeln

- **API-Änderungen**: Bei jeder Änderung in `/api` → `CLAUDE.md` + `api/schema.php` aktualisieren, dann committen **und pushen** (Push triggert GitHub Action → Server-Deploy; auf `main` in die production-, auf jedem anderen Branch in die development-Umgebung/-Domain).
- **Webapp-Änderungen**: Mobile + Desktop berücksichtigen. Infos dürfen auf kleinen Screens reduziert/ausgeblendet werden — Kernfunktionalität muss auf beiden nutzbar sein.
- **Branch-Cleanup**: Nach jedem erfolgreichen lokalen Merge eines Branches nach `main` (inkl. Push) den gemergten Source-Branch löschen — lokal (`git branch -d <branch>`) **und** remote, falls vorhanden (`git push origin --delete <branch>`), damit nur Branches mit aktiver Arbeit übrig bleiben.

## Stack

Angular-Webapp + PHP-REST-API, Fantasy-Football. Frontend: Angular (`standalone: false`, Signal-basiert). Backend: PHP. DB: MySQL.

## Repo-Struktur

```
die-bestesten/
├── .github/workflows/  — deploy-api.yml, deploy-webapp.yml, deploy-asset-server.yml (Push auf jeden Branch → Deploy; main → production-Environment/-Domain, alle anderen Branches → development-Environment/-Domain, siehe FTP_DIR_*/Build-Konfiguration je GitHub Environment)
├── api/app/
│   ├── controller/     — Ein Controller pro Ressource (erbt _BaseController)
│   ├── database/       — Ein Trait pro Ressource; composited in base.database.php
│   ├── util/           — image_upload.util.php (Bild-Validierung + Datei-Write per FTP, FTP_HOST/USER/PASSWORD + FTP_DIR_IMAGE; gemeinsamer FTP-Zugang für mehrere Asset-Server, FTP_DIR_AUDIO reserviert für zukünftigen Audio-Server; akzeptierte Upload-Formate JPEG/PNG/WebP, serverseitig ins jeweilige Zielformat (PNG oder JPEG) konvertiert)
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

`$methodRoles` pro Controller: HTTP-Methode → erforderliche Rolle. Prüfung: `guest` = kein Token nötig, aber falls ein gültiger Token mitgeschickt wird, dekodiert der Guard ihn trotzdem optional (setzt `auth_manager_id`/`auth_league_id`, ohne bei ungültigem/fehlendem Token einen Fehler zu werfen) — relevant für Endpunkte wie `/league/mine`, die sich für eingeloggte Manager anders verhalten; `manager` = jeder eingeloggte Manager; `maintainer`/`admin` = Manager muss diese Rolle in seiner Rollenliste haben. Fehlende Einträge = `guest`. 401 = kein Token, 403 = Rolle fehlt. Guard setzt `$GLOBALS['auth_manager_id']` + `$GLOBALS['auth_roles']` (Array).

Rollenvergabe: `POST /manager/:id/roles` mit `{role}`, Entzug: `DELETE /manager/:id/roles/:role` — jeweils Admin.

## Datenbankschema

Vollständig in `database/global_schema.sql`. Alle IDs `CHAR(36)` UUID außer country (`CHAR(2)` ISO-Alpha-2).

| Tabelle | Spalten |
|---------|---------|
| country | id PK, name |
| season | id PK, start_date UNIQUE — aktiv = höchstes start_date |
| league | id PK, slug UNIQUE, name, db_name, visibility ENUM('public','private') DEFAULT 'public' — public = Beitrittsanfragen erlaubt; private = nur Einladung; fine_ruleset ENUM('classic','none') DEFAULT 'classic' — classic = Spieltags-/Saisonstrafen (Kegelstrafen: 3€/2€/1,50€/1€ + 5€ Startgeld); none = keine Strafen; powerranking_enabled BOOL DEFAULT TRUE — steuert Sichtbarkeit/Nutzbarkeit von /powerranking (Menüpunkt + Endpunkte) |
| club | id PK, country_id FK, name UNIQUE, short_name, logo_uploaded BOOL |
| division | id PK, name, level INT, seats INT, country_id FK, starting_budget INT DEFAULT 50000000 (Startbudget eines neuen Fantasy-Teams), points_bonus INT DEFAULT 20000 (Marktwert-/Auszahlungs-Bonus pro Saisonpunkt) |
| matchday | id PK, season_id FK, division_id FK, start_date DATE, kickoff_date DATETIME, number INT, completed BOOL — UNIQUE(season_id, division_id, number) — jede Division pflegt eigene Spieltage |
| player | id PK, kicker_id INT UNIQUE?, country_id FK?, first_name, last_name, displayname UNIQUE, birth_city, date_of_birth, height_cm, weight_kg |
| club_in_season | id PK, club_id FK, season_id FK, division_id FK, position INT? — UNIQUE(club_id, season_id) |
| player_in_season | id PK, player_id FK, season_id FK, price DECIMAL, position ENUM(GOALKEEPER/DEFENDER/MIDFIELDER/FORWARD), photo_uploaded, last_updated DATETIME? DEFAULT NULL (Erstell-/Änderungszeitpunkt; NULL = seit jeher unverändert/immer sichtbar; steuert Markt-Sichtbarkeit während eines offenen Transferfensters, siehe /player_in_season/available_players) — UNIQUE(player_id, season_id) |
| player_in_club | id PK, player_id FK, club_id FK, from_date DATE, to_date DATE?, on_loan BOOL — UNIQUE(player_id, club_id, from_date) |
| player_rating | id PK, player_id FK, matchday_id FK, club_id FK? (zum Zeitpunkt; NULL für historische Daten), grade DECIMAL?, participation ENUM(starting/substitute)?, goals, assists, clean_sheet, sds BOOL, red_card, yellow_red_card, points — UNIQUE(player_id, matchday_id) |
| transferwindow | id PK, matchday_id FK, start_date DATETIME, end_date DATETIME — 2–4 pro Spieltag |
| stadium | id PK, official_name, name? (Spitzname/Alltagsname), capacity INT?, lat DECIMAL(9,6)?, lng DECIMAL(9,6)? |
| club_stadium | id PK, club_id FK, stadium_id FK, from_date DATE, to_date DATE? — UNIQUE(club_id, from_date) |
| award | id PK, name UNIQUE, icon VARCHAR(100)? (nur Dateiname, z.B. "trophy.png" → public/img/icons/), sort_index INT — Award-Typen; sort_index = Wichtigkeit (1 = wichtigster) |

## API-Endpunkte

Vollständige Doku: `api/schema.php`.

```
GET/POST /club_in_season       — Saison-Zuordnungen; POST 409 bei Duplikat
PATCH    /club_in_season/:id   — Division/Position aktualisieren
GET      /division[/:id]
PATCH    /division/:id           — {starting_budget: INT>0, points_bonus: INT>0} — Startbudget neuer Fantasy-Teams + Marktwert-/Auszahlungs-Bonus pro Saisonpunkt setzen — Admin
GET      /club[/:id]           — enthält stadium-Objekt (aktuelles Stadion, to_date IS NULL) oder null — auch in der Liste
POST     /club                 — {country_id, name, short_name?} → {id}; 409 bei Namensduplikat — Admin
POST     /club/:id/logo        — multipart/form-data, Feld "image" (PNG) → setzt club.logo_uploaded — Maintainer+
GET      /stadium              — Alle Stadien inkl. lat/lng, capacity, other_visitors ([{id,manager_name}] anderer Manager, die das Stadion besucht haben, eingeloggter Manager ausgeschlossen) und aktuell verknüpftem Club ({id,name,logo_uploaded} oder null) — Auth
POST     /stadium              — {club_id, official_name, name?, capacity?, lat?, lng?, from_date?} → {id}; legt Stadion an und verknüpft es sofort als aktuelles Stadion des Clubs (club_stadium, to_date NULL); from_date default heute — Admin
GET      /manager_stadium      — Stadion-IDs, die der eingeloggte Manager als besucht markiert hat — Auth
POST     /manager_stadium      — {stadium_id} — als besucht markieren (idempotent) — Auth
DELETE   /manager_stadium/:stadium_id — Markierung entfernen (idempotent) — Auth
GET      /country[/:id]
GET      /season[/:id|/active]
POST     /season                — {start_date: YYYY-MM-DD} → {id}; UNIQUE auf start_date — Admin
GET      /matchday[/:id]       — ?season_id gibt has_ratings (bool) zurück; filtert nach Division der aktiven Liga — Auth; ?division_id überschreibt die Division (Default aus Liga-Kontext)
POST     /matchday             — {season_id, number, start_date, kickoff_date, division_id?} → {id}; division_id optional (Default aus Liga-Kontext); 409 bei Duplikat, 422 wenn keine Division konfiguriert oder division_id ungültig — Admin
PATCH    /matchday/:id         — {completed:bool} — bei completed=true: team_rating + Transaktionen erstellen, Achievements auswerten, Notifications senden, Zusammenfassungs-E-Mail an Admins (nur wenn email hinterlegt) — Admin; ODER beliebige Kombination aus {number, start_date, kickoff_date} — Stammdaten bearbeiten; 409 wenn completed oder Nummer bereits vergeben, 422 wenn kickoff_date vor start_date — Admin
DELETE   /matchday/:id         — 409 wenn completed oder bereits in der Liga verwendet (team_lineup/team_rating/transaction/player_in_team/h2h_match) oder von Bewertungen/Transferfenstern referenziert — Admin
GET      /all_time_standings   — { standings: [{id,manager_name,alias,total_points}], top_matchdays: [{points,matchday_number,team_name,season_id,manager_name}] } — Auth
GET      /league[/:id]         — enthält manager_count (global) und team_count (Teams der aktiven Saison aus der jeweiligen Liga-DB; 0 ohne aktive Saison); /:id: teams[] zusätzlich mit squad_count + squad_value (aktiver Kader + Marktwertsumme) je Team
GET      /league/mine          — Aktuelle Liga {id,slug,name,db_name,division_id,fine_ruleset} — bei JWT die Liga aus auth_league_id, sonst Fallback auf die per DB_NAME_LEAGUE konfigurierte Deployment-Liga
PATCH    /league/:id           — {division_id: UUID|null} Spielerpool-Division setzen; oder {visibility: 'public'|'private'} Sichtbarkeit setzen; oder {fine_ruleset: 'classic'|'none'} Strafen-Regelsatz setzen (classic = Kegelstrafen, none = keine Strafen; steuert fine-Felder in /liga/matchday, /liga/table, /team_rating); oder {powerranking_enabled: bool} Powerranking-Tippspiel an/aus schalten (default true) — Admin
POST     /league/:id/join      — Beitrittsanfrage stellen (status='requested'); benachrichtigt alle Admins; 403 wenn visibility='private' — Auth
POST     /league/:id/accept    — Einladung annehmen (invited→active); benachrichtigt alle Admins per E-Mail; 409 wenn keine ausstehende Einladung — Auth
POST     /league/:id/decline   — Einladung ablehnen (invited→denied); 409 wenn keine ausstehende Einladung — Auth
POST     /league/:id/invite    — {manager_id} Manager einladen (status='invited'); benachrichtigt Manager — Admin
POST     /league/:id/approve   — {manager_id} Anfrage genehmigen (requested→active); benachrichtigt Manager — Admin
POST     /league/:id/deny      — {manager_id} Mitgliedschaft ablehnen (→denied) — Admin
GET      /league/:id/draft_pool    — ?season_id erforderlich — Spieler der Liga-Division ohne aktives Team in dieser Saison (Pool für Draft-Zuweisung), inkl. kicker_id je Spieler (Abgleich mit externen Draft-Exporten); ligenübergreifend nutzbar (nicht an auth_league_id gebunden) — Admin
POST     /league/:id/draft_assign  — {season_id, assignments:[{team_id, player_ids:[...]}]} — weist mehreren Teams auf einmal Spieler zu; erstellt player_in_team + transaction je Spieler (Preis = exakter player_in_season.price), from_matchday_id = Spieltag 1 der Division/Saison; prüft Positionslimits (GK≤2/DEF≤6/MID≤6/FWD≤4) + Doppelvergabe und überspringt Verstöße mit Grund statt abzubrechen (skipped[{team_id,player_id,reason}]); 422 wenn Spieltag 1 fehlt — Admin
POST     /league/validate_ratings  — {league_id} — prüft team_ratings ab 2020/21 gegen team_lineup + player_rating — Admin
POST     /league/fix_rating        — {league_id, team_id, matchday_id, field, value} — korrigiert ein Feld in team_rating (Liga-DB) — Admin
POST     /league/conclude_season   — {league_id, season_id} — Saisonauszeichnungen vergeben (Meister, Goldene Bürste, Hölzerne Bank); idempotent; wird auch automatisch bei Spieltag 34 ausgeführt — Admin
GET      /transferwindow[/:id] — ?matchday_id|season_id (+ optional ?division_id überschreibt Division der aktiven Liga); jedes Fenster enthält offer_count (Anzahl Gebote)
POST     /transferwindow       — {matchday_id,start_date,end_date} — Maintainer+
PATCH    /transferwindow/:id   — beliebige Kombination aus {start_date,end_date}; 422 bei Regelverstoß (außerhalb Spieltag-Zeitraum), 409 bei Überschneidung — Admin
DELETE   /transferwindow/:id   — 409 wenn bereits Gebote (offer) oder Verkäufe (sell) existieren — Admin
GET      /team_lineup          — ?team_id (erforderlich), ?matchday_id (optional) → {matchday, matchdays[], nominated[], bench[], points, max_points} — jeder Spieler enthält grade, points (dieser Spieltag), season_points (Saison-Gesamtpunkte), goals, assists, clean_sheet, sds, participation; nominated[] sortiert nach Position, dann position_index (manuelle Aufstellungsreihenfolge); bench[] sortiert nach Position, dann season_points DESC, dann price DESC; Auto-Init für aktuellen Spieltag wenn noch keine Einträge; bei nicht abgeschlossenem Spieltag werden team_lineup-Einträge von Spielern, die nicht mehr aktiv im Team sind (z. B. zwischenzeitlich verkauft), automatisch gelöscht und nicht zurückgegeben, UND eine nicht mehr erreichbare Formation (Sanity-Check, siehe PATCH) automatisch auf Bank zurückgesetzt (alle nominated=0) — abgeschlossene Spieltage bleiben unangetastet (historischer Spielstand) — Auth; alternativ ?player_id + ?season_id → [{matchday_number, nominated}] — Auth
PATCH    /team_lineup          — {team_id, matchday_id, players:[{player_id, nominated, position_index}]} — nur eigenes Team, nur Editierfenster (start_date ≤ now < kickoff_date); 422 wenn resultierende Formation durch keine der 7 gültigen Formationen (343/352/433/442/451/532/541, GK immer 1) mehr erreichbar ist (Sanity-Check gegen z. B. zu viele Spieler auf einer Position; erlaubt weiterhin unvollständige Zwischenstände beim schrittweisen Aufstellen) — Auth
GET      /player_in_team             — ?team_id (erforderlich) → aktive Spieler mit position, price, points, current_club_id, club_logo_uploaded; ?include_former=1 → {current, former}; ?player_id → aktuelles Team oder null; ?player_id + ?season_id → Teamhistorie [{team_id, team_name, color, manager_name, alias, from_matchday_number, to_matchday_number, price_paid}] — price_paid = Kaufpreis, ermittelt aus transaction (team_id+from_matchday_id+Reason-Text), null falls keine passende Transaktion — Auth
POST     /sell                       — {team_id, player_id, transferwindow_id} — nur eigenes Team, nur offenes Fenster; erstellt sell + transaction, setzt player_in_team.to_matchday_id, entfernt nur den eigenen team_lineup-Eintrag des verkauften Spielers für alle noch nicht abgeschlossenen Spieltage (nicht nur den des Transferfensters) — übrige nominierte Spieler bleiben unverändert stehen, es entsteht ggf. eine Lücke in der Formation statt eines Bank-Resets — Auth
POST     /buy                        — {team_id, player_id, transferwindow_id} — nur eigenes Team, nur offenes Fenster; 409 wenn Spieler bereits in einem Team, noch nicht verfügbar (player_in_season seit Fensterbeginn erstellt/geändert, siehe soon_available) oder Positionslimit erreicht (GK≤2, DEF≤6, MID≤6, FWD≤4); erstellt player_in_team + transaction (negativ) — Auth
GET      /offer                      — ?team_id → {offers[], pending_sum} — offers enthält: displayname, position, photo_uploaded, club_id, club_logo_uploaded, season_id, losers[{team_id,team_color,team_season_id,is_winner}] (für success/lost-Gebote); stornierte Gebote (status=cancelled) werden nicht zurückgegeben — nur eigenes Team — Auth; ?transferwindow_id → {window, offers[{player_id,displayname,position,bids[]}]} aller Gebote einer geschlossenen Transferphase; triggert Lazy Settlement (höchstes Gebot gewinnt, Kaskade bei Positionslimit) — 422 wenn Fenster noch offen — Auth; bids[].offer_value/price_snapshot sind bei status≠success (verlorene/stornierte Gebote) null — Gebotshöhen unterlegener Teams bleiben geheim
POST     /offer                      — {team_id, player_id, transferwindow_id, offer_value} — Gebot auf vereinslosen Spieler; 409 wenn Spieler in Team, noch nicht verfügbar (player_in_season seit Fensterbeginn erstellt/geändert, siehe soon_available) oder Positionslimit erreicht (inkl. offene Gebote; GK≤2, DEF≤6, MID≤6, FWD≤4); 422 wenn Fenster zu / Gebot < Marktwert / Budget überschritten; INSERT offer (status=pending) — Auth
PATCH    /offer/:id                  — Body:{team_id, offer_value} — Gebotswert eines pending-Gebots ändern; 422 wenn < Marktwert oder Budget überschritten — Auth
DELETE   /offer/:id                  — Body:{team_id} — offenes Gebot stornieren (status=cancelled) — Auth
GET      /player_in_season/bundesliga_count — ?season_id (optional, default aktiv) → {count} — Spieler der konfigurierten Liga-Division
GET      /player_in_season/available_players — ?season_id (optional, default aktiv) → {players[{id,displayname,position,price,season_points,photo_uploaded,club_id,club_name,club_short_name,club_logo_uploaded,season_id,current_team_id,current_team_name,current_team_season_id,new_on_market,sold_by_team_id,sold_by_team_name,sold_by_team_season_id,soon_available}]} — Spieler der konfigurierten Liga-Division ohne Fantasy-Team; ?include_all=1 → auch Spieler mit Fantasy-Team, current_team_* dann gesetzt (sonst null); new_on_market=true wenn der Spieler aktuell vereinslos (Fantasy) ist und entweder (a) während des gerade offenen Transferfensters von einem anderen Team verkauft wurde (sell-Tabelle) oder (b) im direkt vorherigen Transferfenster als soon_available galt (player_in_season seitdem nicht mehr geändert) und jetzt zum ersten Mal freigeschaltet ist — gilt jeweils nur für genau ein Fenster; sold_by_team_* (nur bei Fall (a) gesetzt, sonst null) = Team, das ihn zuletzt in diesem Fenster verkauft hat; soon_available=true wenn player_in_season.last_updated ≥ Beginn des gerade offenen Transferfensters (neu angelegt oder Position/Preis geändert, seit das Fenster läuft) — Spieler bleibt in der Antwort enthalten (Transparenz), ist aber nicht kauf-/bietbar (POST /buy, /offer liefern 409) bis zum nächsten Fenster
POST     /player_in_season — {player_id, season_id, position, price} (0 < price <= 50.000.000) → {id}; 409 bei Duplikat — Maintainer+
PATCH    /player_in_season/:id — {position?, price?} (mind. eines erforderlich; 0 < price <= 50.000.000) — korrigiert Position/Marktwert; 404 wenn nicht gefunden — Maintainer+
POST     /player_in_season/preview_csv — multipart: Feld "csv" (;-getrennt: ID;Vorname;Nachname;Kurzname;Angezeigter Name;Verein;Position;Marktwert;Punkte;Notendurchschnitt) + Feld "division_id" (optional — ohne wird die Spielklasse per Mehrheitsentscheid aus den CSV-Vereinen automatisch erkannt; division_id=null in der Response falls kein Club einer Division zuordbar ist, dann bleiben rows/missing_players leer und der Aufruf muss mit expliziter division_id wiederholt werden) → {status,season_id,season_start_date,division_id,division_auto_detected,division_candidates[{division_id,count}],rows[{kicker_id,csv_*,matched_player_id,matched_displayname,matched_club_id,club_logo_uploaded,already_in_season,importable,existing_player_in_season_id,existing_position,existing_price,position_price_mismatch,current_player_in_club_id,current_club_id,current_club_name,current_club_logo_uploaded,club_mismatch,club_confirmed,club_unresolved,club_missing,division_mismatch,price_too_high,duplicate_candidate_player_id,duplicate_candidate_kicker_id}],missing_players[{player_id,player_in_club_id,displayname,club_id,club_name,club_logo_uploaded}],division_warning,division_mismatch_count,resolved_club_count}; matcht player über kicker_id (Spalte ID → int nach "pl-k"), club über Namen (exakt, sonst Fuzzy-Fallback bei eindeutigem Treffer); importable nur true wenn weder club_mismatch (aktueller player_in_club-Club bekannt und abweichend vom CSV-Club) noch club_unresolved (CSV-Vereinsname konnte keinem Club zugeordnet werden) noch club_missing (CSV-Club aufgelöst und Spieler gematcht, aber kein aktueller player_in_club bekannt — würde sonst einen player_in_season-Eintrag ohne Club erzeugen, der auf dem Transfermarkt unsichtbar bleibt, da /player_in_season/available_players einen aktuellen player_in_club voraussetzt) noch division_mismatch (gematchter Club spielt laut club_in_season nachweislich in einer anderen Division als der verwendeten) noch price_too_high (CSV-Marktwert > 50.000.000 €) vorliegt — fehlendes club_in_season blockiert weiterhin nicht; club_confirmed ist rein informativ; division_warning = true wenn mehr als die Hälfte der aufgelösten CSV-Clubs (division_mismatch_count von resolved_club_count) nicht zur verwendeten Division gehören; für Zeilen ohne kicker_id-Treffer wird zusätzlich per exaktem Displaynamen nach einem evtl. bereits vorhandenen Spieler unter anderer kicker_id gesucht (duplicate_candidate_player_id/duplicate_candidate_kicker_id) — Hinweis auf falsche/geänderte kicker_id in der CSV; missing_players = Spieler, die aktuell laut player_in_club einem Club der verwendeten division_id zugeordnet sind, aber nicht in der CSV vorkommen; schreibt nichts — Maintainer+
POST     /player_in_season/import_csv — {rows:[{player_id,position,price}]} → {status,season_id,created[{player_id,id}],created_count,skipped[{player_id,reason}]}; überspringt Zeilen mit bereits vorhandenem player_in_season (reason=already_in_season), ungültigen Daten (reason=invalid_row) oder Marktwert > 50.000.000 € (reason=price_too_high) statt 409/500 zu werfen — verhindert, dass eine einzelne Zeile mit zu hohem Marktwert per DB-Fehler die gesamte Schleife abbricht und alle nachfolgenden Zeilen mit überspringt — Maintainer+
GET      /player[/:id]           — ?club_id=UUID gibt aktuellen Kader zurück (player_in_club.to_date IS NULL) mit season_position
POST     /player/create        — {kicker_id, first_name, last_name, displayname, season_id, position, price, club_id?, from_date?} (0 < price <= 50.000.000) → {id} — erstellt player + player_in_season + optional player_in_club — Maintainer+
POST     /player/:id/photo     — multipart/form-data, Feld "image" (PNG) + Body season_id → setzt player_in_season.photo_uploaded — Maintainer+; 403 wenn für diese player_in_season bereits ein Foto hochgeladen ist und der Aufrufer kein Admin ist (Überschreiben eines vorhandenen Fotos nur Admin)
POST     /player_in_club       — {player_id, club_id, from_date, on_loan?} → {id} — fügt Spieler einem Verein zu (neuer player_in_club-Eintrag) — Maintainer+
PATCH    /player_in_club/:id   — beliebige Kombination aus {club_id, from_date, to_date, on_loan}, mind. eines erforderlich; to_date=null explizit erlaubt (öffnet beendete Zugehörigkeit wieder); 404 wenn nicht gefunden, 422 wenn to_date < from_date — Maintainer+
DELETE   /player_in_club/:id   — löscht den Eintrag; 404 wenn nicht gefunden — Maintainer+
GET      /player_rating        — ?matchday_id&club_id → Spielerinfos + price, starting_count (Starts in der Saison); sortiert nach starting_count DESC, position, price DESC
GET      /player_rating/best_xi — ?matchday_id (required), ?free_agents_only=0|1 — beste valide 11 (343/352/433/442/451/532/541) für einen Spieltag; gibt {formation, players[{player_id,displayname,position,points,grade,club_id,club_name,club_short_name}], total_points} zurück; free_agents_only=1 nur Spieler ohne Fantasy-Team — Auth
GET      /player_rating/status — ?matchday_id → [{club_id, rating_count, starter_count, grade_count, goals, assists, has_sds}] — aggregierter Status aller Clubs für einen Spieltag
POST     /player_rating/init   — {matchday_id,club_id} → leere Ratings erstellen für aktuelle Clubspieler mit gültigem player_in_season (Position + Marktwert gesetzt) in der Saison des Spieltags; 409 wenn completed oder (vor kickoff_date und nicht Admin) — Maintainer+
POST     /player_rating/validate-csv — multipart: matchday_id + csv-Datei (;-getrennt, Spalte 4 = Angezeigter Name, Spalte 8 = Punkte) → {ok, checked?} oder {ok: false, mismatches: [{kicker_id, displayname, csv_points, db_points, error}]}; error: 'points mismatch' | 'player not found in db' (+ first_name/last_name/club_name/position/price) | 'no ratings in season' — Maintainer+
PATCH    /player_rating/:id    — Maintainer+; 403 wenn Spieltag completed; Body: grade, participation, goals, assists, clean_sheet, sds, red_card, yellow_red_card (points wird immer serverseitig berechnet)
POST     /auth                 — JWT-Login; Response enthält token + leagues[] + league_id (null wenn keine Liga)
POST     /auth/switch-league  — {league_id} → {token, league_id}; neues JWT mit geänderter league_id; 403 wenn kein Zugang — Auth
POST     /auth/password-reset-request — {email} — sendet Reset-Link; immer 200 (kein E-Mail-Leak)
POST     /auth/password-reset — {token,new_password} — setzt Passwort zurück (Token aus Reset- oder Einladungslink); gibt {token,leagues,league_id} zurück (automatischer Login mit neuem JWT); 400 wenn Token ungültig/abgelaufen
GET      /saisonvorschau       — { season_id, previous_season_id, available, kickoff_date, teams:[{id,team_name,color,color_secondary,manager_id,manager_name,alias,squad_valid,position_counts:{GOALKEEPER,DEFENDER,MIDFIELDER,FORWARD},previous_season_points,previous_season_points_value11,previous_season_points_best11,points_breakdown:{all,value11,best11}:[{name,points,position,club_id,club_logo_uploaded},...],newcomer_count,newcomer_players:[{name,club_id,club_logo_uploaded},...]}], promoted_clubs:[{id,name,short_name,logo_uploaded}], promoted_club_teams:[{team_id,team_name,color,color_secondary,count,players:[{name,club_id,club_logo_uploaded},...]}], special_clubs:[{id,name,logo_uploaded}], special_club_teams:[{team_id,team_name,color,color_secondary,count,players:[{name,club_id,club_logo_uploaded},...]}] } — Kader-Übersicht der aktiven Saison; nur verfügbar bis Anpfiff Spieltag 1 der Liga-Division (kickoff_date) — danach available=false, teams/etc. leer, keine Kaderberechnung; previous_season_points = Summe Vorsaison-Punkte aller aktuellen Kaderspieler; previous_season_points_value11 = Vorsaison-Punkte der 11 (nach Marktwert) teuersten Spieler in einer gültigen Formation; previous_season_points_best11 = Vorsaison-Punkte der 11 Spieler mit der höchsten erreichbaren Punktzahl in einer gültigen Formation; beide null ohne erreichbare Formation (bei squad_valid=true immer erreichbar); points_breakdown = je Modus die zugrundeliegenden Spieler mit Punkten/Position für den Frontend-Tooltip beim Hover über die Punkte-Zahl (all nach Punkten absteigend, value11 nach Marktwert absteigend, best11 nach Position GOALKEEPER→DEFENDER→MIDFIELDER→FORWARD); newcomer_count/newcomer_players = Anzahl/Spieler (alphabetisch, mit aktuellem Verein fürs Logo) der Kaderspieler ohne player_rating in der Vorsaison; promoted_clubs = Vereine der Liga-Division, die in der Vorsaison genau eine Division tiefer (level+1) spielten; promoted_club_teams zählt dabei keine Lückenfüller-Spieler (Marktwert exakt 500.000€ in der 1. Liga bzw. 100.000€ in der 2. Liga); promoted_club_teams/special_club_teams = Teams mit Kaderspielern (players analog mit club_id/club_logo_uploaded) dieser bzw. der fest gewählten Vereine "RB Leipzig"/"TSG Hoffenheim", absteigend nach count sortiert (nur count>0) — Auth
GET      /saisonvorschau/status — { available, kickoff_date } — Sichtbarkeit des Saisonvorschau-Menüpunkts im Frontend, ohne Kaderberechnung (analog GET /h2h/status) — Auth
GET      /team_rating          — ?season_id → { matchday, ratings[], sds_player, max_matchday_number } letzter gestarteter Spieltag; bei nicht-abgeschlossenem Spieltag: Live-Punkte aus player_rating × team_lineup (fine = 0) — Auth
GET      /team_rating/season   — ?season_id → aggregierte Saisontabelle aller Teams, sortiert nach Punkten; fine-Felder sowie luck.lucky/luck.unlucky (Glückspilze/Pechvögel) sind 0 bzw. leer, wenn league.fine_ruleset='none' (Goldene Bürste/Hölzerne Bank/Spieltagssiege bleiben davon unberührt) — Auth
GET      /team                 — ?season_id → [{id,team_name,color,color_secondary,season_id,manager_id,manager_name,alias,squad_valid,total_value,position_counts:{GOALKEEPER,DEFENDER,MIDFIELDER,FORWARD}}] sortiert nach Name — squad_valid = Mindestanforderungen erfüllt (GK≥1/DEF≥5/MID≥5/FWD≥3), position_counts = aktuelle Kaderbesetzung je Position — Auth
GET      /team/mine            — Eigenes Team der aktiven Saison {id, team_name, season_id, color}; 404 wenn kein Team — Auth
GET      /team/:id             — Team per ID (manager_name, alias, total_points, matchdays_played) — Auth
POST     /team                 — {team_name, color_name?, color_secondary_name?} → {id}; color_name referenziert global.color.name (z.B. "red"); benachrichtigt alle Admins per E-Mail; 409 wenn bereits Team vorhanden — Auth
GET      /color               — [{name, hex}] globale Farbpalette (name = PK, z.B. "red") — kein Auth erforderlich
PATCH    /color/:name         — {hex: '#rrggbb'} Hex ändern, kaskadiert auf team.color aller Teams dieser Liga — Admin
GET      /team/previous        — Letztes Team aus Vorsaison {id,team_name,color,season_id}; 404 wenn keines — Auth
GET      /team/check-name      — ?name= (min. 3 Zeichen) → {available: bool}; 400 wenn zu kurz — Auth
POST     /team/:id/logo        — multipart/form-data, Feld "image" (PNG) — nur eigenes Team — Auth
POST     /team/:id/logo/takeover — übernimmt Logo aus Vorsaison-Team desselben Managers — nur eigenes Team; 404 wenn kein Vorsaison-Team — Auth
GET      /manager              — [{id,manager_name,alias,status,email,last_activity,stadiums_visited,roles[],leagues[{id,name}]}] alle Manager global — stadiums_visited = Anzahl per manager_stadium als besucht markierter Stadien — Admin
POST     /manager              — {manager_name,first_name?,email,league_id} → {id,invite_link}; legt Manager mit status=invited an (zufälliges Platzhalter-Passwort) und manager_league sofort status=active (Liga bereits zugewiesen); sendet Einladungs-Mail (Link zu /login/accept-invite, 7 Tage gültig) — nach Passwort-Setzen automatischer Login mit league_id im JWT; 400 fehlende/ungültige Felder, 404 Liga nicht gefunden, 409 manager_name/email bereits vergeben — Admin
POST     /manager/:id/resend-invite — → {invite_link}; neuer Token (alter wird ungültig) — 409 wenn status != invited — Admin
GET      /manager/me           — {id,manager_name,alias,role,status} — Auth
GET      /manager/birthdays   — [{id,manager_name}] — Manager mit heutigem Geburtstag (MONTH+DAY match) — Auth
GET      /manager/leagues      — [{id,name,slug}] — alle Ligen des eingeloggten Managers — Auth
POST     /manager/me/photo     — multipart/form-data, Feld "image" (JPEG) — eigenes Profilfoto — Auth
PATCH    /manager/me           — {current_password,new_password} für Passwort; {email} oder {first_name} allein ohne Passwort — Auth
DELETE   /manager/me           — {password} — Auth; löscht nicht, sendet stattdessen Mail an Admin
GET      /transaction          — ?team_id (erforderlich) → {budget, transactions[]} — nur eigenes Team (403 sonst) — Auth
GET      /search               — ?q (min. 3 Zeichen) → {players[], clubs[], teams[], managers[]} — max. 8 je Typ; teams enthalten season_label — Auth
GET      /h2h               ?season_id= (optional, default=aktiv) → {groups:[{id,name,sort_index,teams[],standings[],matches[]}], knockout_matches:[]} — Auth
GET      /h2h/status        → {exists} — ob die Liga jemals ein H2H-Turnier generiert hat (beliebige Saison); für die Sichtbarkeit des H2H-Menüpunkts im Frontend — Auth
GET      /h2h/:id            → Match-Detail {match,matchday,home_team,away_team,home_rating,away_rating,home_lineup[],home_bench[],away_lineup[],away_bench[]} mit Spieler-Einzelpunkten — Auth
POST     /h2h               {season_id,phase,leg,home_team_id,away_team_id,matchday_id,group_id?,sort_index?} → {id} — Admin
PATCH    /h2h/:id           {home_team_id?,away_team_id?,matchday_id?,group_id?,sort_index?} — Admin
DELETE   /h2h/:id           — Admin
POST     /h2h/generate      {league_id, season_id} → {status,groups,matches} — Generiert H2H-Gruppenphase nach festem Template; unterstützt nur genau 9 oder 12 Teams pro Saison (sonst 400): 12 Teams → 4 Gruppen à 3, 24 Matches; 9 Teams → 3 Gruppen à 3, 18 Matches (1 Match/Spieltag); je Snake-Seeding nach Vorjahresrang, Spieltage 1–18 der Division der Liga (Fallback: höchste deutsche Division ohne konfigurierte Liga-Division); 400 wenn dort Spieltage 1–18 fehlen; sendet allgemeine Gruppen-Notification + individuelle Spiele-Notification an alle Manager — Admin
POST     /h2h/draw_quarterfinals {league_id, season_id} → {matches:8} — Nur 12-Team-Format (4 Gruppen; sonst 400); legt 8 Viertelfinale (Hin+Rück) nach festem Bracket aus Gruppenständen an (Bed.: Spieltag 18 der Liga-Division abgeschlossen, Spieltage 20–27 dort vorhanden, noch keine QFs vorhanden); Bracket: A1:B2@MD20, B1:A2@MD21, C1:D2@MD22, D1:C2@MD23 (Hin), B2:A1@MD24, A2:B1@MD25, D2:C1@MD26, C2:D1@MD27 (Rück); sendet Notification an alle Manager — Admin
POST     /h2h/draw_semifinals    {league_id, season_id} → {matches:4} — Legt 4 Halbfinale (Hin+Rück) an; 12-Team-Format (4 Gruppen): aus VF-Siegern (Aggregat-Tore, Tiebreaker: Gesamtpunkte beider Legs; Bed.: Spieltag 27 der Liga-Division abgeschlossen), Überkreuzung der VF-Stränge: VF1:VF3@MD29, VF2:VF4@MD30 (Hin), VF3:VF1@MD31, VF4:VF2@MD32 (Rück); 9-Team-Format (3 Gruppen): direkt aus Gruppenständen — 3 Gruppensieger + bester Gruppenzweiter (Punkte→Tordifferenz→Tore), Zweiter spielt gegen Sieger der zyklisch nächsten Gruppe zur Rematch-Vermeidung (Bed.: Spieltag 18 der Liga-Division abgeschlossen); Spieltage 29–32 müssen dort vorhanden sein, noch keine SFs vorhanden; sendet Notification an alle Manager — Admin
POST     /h2h/draw_final         {league_id, season_id} → {matches:1} — Legt Finale aus HF-Siegern an (Aggregat-Tore, Tiebreaker: Gesamtpunkte beider Legs; Bed.: Spieltag 32 der Liga-Division abgeschlossen, Spieltag 34 dort vorhanden, noch kein Final vorhanden); HF1:HF2@MD34 — Admin
GET      /h2h_group         ?season_id= → [{id,name,sort_index,teams:[team_id,...]}] — Auth
POST     /h2h_group         {season_id,name,sort_index?} → {id} — Admin
PATCH    /h2h_group/:id     {name?,sort_index?,teams?:[team_id,...]} (teams ersetzt alle Zuordnungen) — Admin
DELETE   /h2h_group/:id     kaskadiert auf h2h_group_team; h2h_match.group_id → NULL — Admin
GET      /watchlist            — ?team_id (erforderlich, nur eigenes Team) → [{id,player_id,displayname,photo_uploaded,position,price,season_points,season_id,club_id,club_name,club_short_name,club_logo_uploaded,current_team{team_id,team_name,color,team_season_id,manager_name,alias}|null,created_at}] — Auth
POST     /watchlist            — {team_id, player_id} → {id} — Spieler zur Beobachtungsliste hinzufügen; idempotent (INSERT IGNORE) — nur eigenes Team — Auth
DELETE   /watchlist/:id        — {team_id} — Spieler von der Beobachtungsliste entfernen — nur eigenes Team — Auth
GET      /powerranking          — ?season_id (optional, default aktiv), ?preview=1 (Admin sieht Reveal-Ansicht schon vor Anpfiff Spieltag 1) → vor Anpfiff Spieltag 1: {locked:false,season_id,kickoff_date,my_picks:[{team_id,position}],submitted_count,total_managers} — submitted_count/total_managers = wie viele Manager der Saison bereits (irgend)einen Tipp abgegeben haben von wie vielen insgesamt; danach (oder mit preview=1 als Admin): {locked:bool,preview:bool,season_id,kickoff_date,standings:[{team_id,team_name,color,manager_name,season_id,total_points,actual_position}],entries:[{manager_id,manager_name,alias,total_deviation,picks:[{team_id,predicted_position,actual_position,deviation}]}]} sortiert nach total_deviation ASC — standings = aktuelle Live-Saisontabelle wie /team_rating/season; actual_position = Standard-Wettkampf-Rang (1224), punktgleiche Teams (z.B. alle 0 Punkte vor Saisonstart) teilen sich denselben Platz statt willkürlich durchnummeriert zu werden; preview:true = Reveal nur wegen Admin-Vorschau, locked bleibt false; 403 wenn league.powerranking_enabled=false — Auth
POST     /powerranking          — {season_id, picks:[{team_id,position}]} — ersetzt alle Picks des Managers für die Saison; 403 nach Anpfiff Spieltag 1 oder wenn league.powerranking_enabled=false, 422 wenn picks keine vollständige 1..N-Permutation aller Teams der Saison ist — Auth
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
GET      /session               — ?range=day|month|year (optional, default day) → {range, managers[{manager_id,manager_name,alias,buckets:{key:Sekunden},mobile_seconds:{key:Sekunden},desktop_seconds:{key:Sekunden}}]} sortiert nach Gesamtnutzung DESC — Nutzungsdauer je Manager gebucketed nach Zeitraum (Heatmap-Rohdaten); Bucket-Schlüssel: day=Stunde "YYYY-MM-DDTHH:00:00", month=Tag "YYYY-MM-DD", year=Montag der Woche "YYYY-MM-DD"; mobile_seconds/desktop_seconds = dieselben Buckets, aber nur je eine Gerätekategorie (device_type mobile/tablet bzw. desktop/unbekannt), unabhängig voneinander dedupliziert — Mobile-Anteil für die Heatmap-Färbung ist mobile_seconds/(mobile_seconds+desktop_seconds), nicht gegen buckets (kann bei gleichzeitiger Mehrgeräte-Nutzung > buckets-Wert liegen) — Admin
```

## Global-DB — Manager-Tabellen (`database/global_schema.sql`)

*Seit Multi-Liga-Support sind Manager-Daten global — ein Account kann mehreren Ligen beitreten.*

**manager**: id PK, manager_name UNIQUE (Anzeigename/Username), first_name VARCHAR(100)? (echter Vorname — für Achievement-Vergleiche), alias UNIQUE?, password, status ENUM(active/blocked/deleted/invited) DEFAULT active (invited = von Admin per `POST /manager` angelegt, wartet auf Erstpasswort via Einladungslink; wechselt bei erfolgreichem `POST /auth/password-reset` automatisch zu active), email UNIQUE?, date_of_birth?, last_activity DATETIME?

**manager_role**: id PK, manager_id FK, role ENUM(maintainer/admin) — UNIQUE(manager_id, role) — additiv; jeder Manager hat implizit 'manager'

**password_reset_token**: id PK, manager_id FK, token_hash VARCHAR(64) UNIQUE, expires_at DATETIME, used BOOL DEFAULT 0, created_at DATETIME

**manager_league**: id PK, manager_id FK → manager, league_id FK → league, joined_at DATETIME, status ENUM('active','invited','requested','denied') DEFAULT 'active' — UNIQUE(manager_id, league_id) — Bidirektionaler Beitritts-Workflow: Admin lädt ein (invited) oder Manager stellt Anfrage (requested); Genehmigung/Annahme → active; Ablehnung → denied (final)

**notification**: id PK, sender_id CHAR(36)? (NULL = Systemnachricht; kein FK), receiver_id FK → manager, title VARCHAR(255), message TEXT?, created_at DATETIME, read_at DATETIME? (NULL = ungelesen)

**notification_preference**: manager_id FK + event_type VARCHAR(50) PK — enabled BOOL DEFAULT 1 — fehlender Eintrag = default ON; event_types: matchday_completed, achievement_earned, scouted_player_update

**manager_achievement**: id PK, manager_id FK, achievement_id FK → achievement (echtes FK, gleiche DB!), earned_at DATETIME, reason VARCHAR(255)?, seen_at DATETIME?, level ENUM('bronze','silver','gold') DEFAULT 'gold' — UNIQUE(manager_id, achievement_id) — idempotent per INSERT IGNORE; seen_at=NULL = noch nicht gesehen

**manager_stadium**: id PK, manager_id FK, stadium_id FK → stadium (echtes FK, gleiche DB!), created_at — UNIQUE(manager_id, stadium_id) — vom Manager als besucht markierte Stadien; idempotent per INSERT IGNORE

**manager_session**: id PK, manager_id FK, started_at DATETIME, ended_at DATETIME, device_type VARCHAR(10)? (mobile/tablet/desktop), os VARCHAR(20)? (iOS/Android/Windows/macOS/Linux), browser VARCHAR(20)? (Chrome/Safari/Firefox/Edge/Opera) — näherungsweise Sitzungsdauer per Heartbeat; Guard::authorize() verlängert bei jedem authentifizierten Request die jüngste Session mit ended_at ≥ jetzt−2min UND gleichem device_type/os/browser (aus User-Agent geparst), sonst wird eine neue Zeile angelegt — ein Geräte-/Browser-Wechsel beendet die vorherige Session immer, unabhängig vom Zeitabstand; Grundlage für den Admin-Nutzungs-Heatmap-Report (GET /session); beim Anlegen einer neuen Session räumt touchSession() automatisch alte 0s-Zeilen (started_at = ended_at) desselben Managers auf, die außerhalb des 2-Minuten-Fensters liegen und daher nie mehr verlängert werden können — hält die Tabelle ohne separaten Cron-Job schlank

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

**powerranking_pick**: id PK, season_id (cross-DB)?, manager_id (cross-DB)?, team_id FK, position INT, created_at — UNIQUE(season_id,manager_id,team_id) + UNIQUE(season_id,manager_id,position) — Kicker-Stecktabelle-Tipp; Tippphase bis Anpfiff Spieltag 1 (Editierfenster), danach gesperrt und für alle sichtbar
