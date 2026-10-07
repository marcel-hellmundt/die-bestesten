# Konzept: Besticoin und Spaßwährung ab der nächsten Saison

Stand: 2026-10-07 · Branch `future/next-season-lukaten-and-coin-mode` · Status: Entwurf, noch kein Code

Dieses Dokument beschreibt, wie die heutigen Lukaten ab der nächsten Saison in zwei getrennte Währungen
aufgeteilt werden, was dafür technisch nötig ist, wie alte Saisons weiter funktionieren und welche Risiken
es gibt. Alles, was als „Vorschlag" oder „offen" markiert ist, ist noch nicht entschieden.

## 1. Ausgangslage und Ziel

Lukaten waren als limitierte Tipp-Währung für das Bestico gedacht: jeder Manager startet mit 100, getippt
wird auf H2H-Matches, am Saisonende zeigt der Kontostand, wer am besten tippt. Seit dem Klebrigsten-Shop
kann man mit denselben Lukaten Sticker-Packs kaufen. Damit ist die Wertung verwässert:

- Wer Packs kauft, fällt in der Schatzkammer zurück, obwohl er nicht schlechter tippt.
- Manche sind pleite und wollen weiter tippen, andere wollen mehr Packs und scheuen Euro-Käufe.
- Neue Lukaten ins System zu geben würde die Exklusivität zerstören.

Ziel ab der nächsten Saison:

| | Besticoin | Spaßwährung (Arbeitsname „Knete") |
|---|---|---|
| Menge | fest: 100 je Manager und Liga | unbegrenzt, wächst mit der Aktivität |
| Wofür | nur Einsätze auf H2H-Tipps | nur Spaß: Sticker, später CasinPro |
| Woher | Startguthaben, Tippgewinne | Aktivität, Euro-Tausch |
| Gilt | je Liga und Saison, startet jede Saison neu | je Manager, bleibt über Saisons erhalten |

Harte Regeln, die für alles Weitere gelten:

1. Kein Umtausch zwischen Besticoin und Spaßwährung, in keine Richtung.
2. Nichts, was man mit Spaßwährung oder Euro kaufen kann, wirkt auf Punkte, Kader, Aufstellung oder
   Team-Budget.
3. Die laufende Saison bleibt, wie sie ist.

## 2. Besticoin

**Regeln**

- Jeder Manager startet je Liga und Saison mit 100. Das System ist geschlossen: was Manager verlieren,
  liegt bei der Bank; was sie gewinnen, zahlt die Bank. Die Summe aus allen Managern und der Bank bleibt
  konstant (bei 12 Managern 1200).
- Einzige Verwendung: Einsatz auf H2H-Tipps. Kein Shop, kein Euro, keine Vergabe für Aktivität.
- Wer pleite ist, bekommt keinen Nachschub (entschieden: keine Bank-Ausschüttung, kein Dispo). Tippen
  ohne Einsatz bleibt möglich und zählt weiter für die Sieg-Wertung im Wettbüro.

**Heutiger Rechenweg** (bleibt die Grundlage)

Der Kontostand wird nirgends gespeichert, sondern je Saison live berechnet
(`api/app/database/h2h_prediction.database.php`):

- `getManagerLukatenBudget()`: 100 − Summe der Einsätze + Summe (Einsatz × Quote) der gewonnenen Tipps
  − Shop-Käufe (`getShopLukatenSpent()`, Tabelle `sticker_shop_purchase` in der Liga-DB).
- `getBankLukatenBalance()`: Summe der Einsätze − Auszahlungen.
- `getLukatenStandings()`: Schatzkammer mit den Zeilen Manager, Bank und Shop.

Im neuen Modus entfallen nur der Shop-Abzug und die Shop-Zeile. Der Rest ist bereits genau das
gewünschte geschlossene System.

**Zwei Dinge, die heute schon gelten und im neuen Modus auffallen werden**

- Kontostände sind keine ganzen Zahlen, weil Gewinne Einsatz × Quote sind (z.B. 12 × 2,35 = 28,2).
  Offen: so lassen oder Auszahlungen runden. Runden würde die Summe minimal verändern.
- Die „1200" sind eine gedachte Größe: 100 bekommt jeder Manager rechnerisch, sobald er tippt; in der
  Schatzkammer stehen nur Manager mit mindestens einem Einsatz. Wer nie tippt, hält seine 100 unsichtbar.

## 3. Spaßwährung

**Name** (offen): Favorit „Knete" — Slang für Geld und passend zu den Klebrigsten. Alternativen: Kleber,
Tuben, Taler, Kröten, Moneten.

**Grundsätze**

- Ein Konto je Manager, unabhängig von Liga und Saison (das Album ist ebenfalls global je Manager).
- Nur ganze Zahlen.
- Jede Gutschrift und jede Ausgabe ist eine eigene Zeile im Kontobuch (Abschnitt 4), der Kontostand ist
  die Summe.
- Im Kontobuch steht je Gutschrift, ob sie verdient oder gekauft ist. Damit bleibt offen, ob gekaufte
  Währung später im CasinPro eingesetzt werden darf (Abschnitt 7).

**Quellen** (Werte sind Platzhalter und müssen per Simulation kalibriert werden)

| Quelle | Vorschlag | Obergrenze | Missbrauchsrisiko und Gegenmittel |
|---|---|---|---|
| Noten/Einsätze/Statistik eintragen | je Beitrag ein kleiner Betrag | je Spieltag gedeckelt | Eintragen und wieder löschen → nur einmal je Bewertung und Art vergeben, Grundlage `maintainer_contribution` (dort ist jeder Beitrag je Manager schon eindeutig) |
| Aufstellung vor Anpfiff vollständig | fester Betrag je Spieltag | 1× je Spieltag und Liga | gering |
| Achievement erhalten | nach Stufe (Bronze/Silber/Gold) | einmal je Achievement und Stufe | Neuauswertung kann Achievements entziehen → Gutschrift bleibt |
| Doppelte Sticker zurückgeben | je Karte nach Seltenheit | letzte Karte bleibt | macht Tauschen unattraktiver → Rückgabewert niedrig halten |
| Tipp abgegeben | kleiner Betrag je Spieltag | 1× je Spieltag | koppelt lose an Bestico, aber ohne Besticoin zu berühren |
| Streak (7 Tage in Folge) | Bonus zusätzlich zum Streak-Pack | wie Streak-Pack | gering, Regel existiert schon |
| Begrüßungsbonus zur Umstellung | einmalig | 1× je Manager | — |
| Euro-Tausch | Bündel, siehe unten | max. offene Zahlungen wie heute | Storno muss Gutschrift zurücknehmen können |

Bewusst keine Quelle: Platzierung, Punkte oder Siege im Hauptspiel. Sonst würde die Spaßwährung zur
zweiten Tabelle.

**Abflüsse**

| Abfluss | Bemerkung |
|---|---|
| Sticker-Packs | die vier Pack-Arten bleiben (`stickerPackKinds()`): Normal 3 Sticker, Big 7, Verein 5, Special 3 mit Holo |
| Wunschsticker | gezielt eine fehlende Karte, teuer; vor allem zum Saisonende („Album-Finisher") |
| Holo-Veredelung | vorhandene Karte zu Silber/Gold aufwerten |
| Kosmetik | Kartenrahmen, Album-Cover, Aufreiß-Animation, Abzeichen am Profil |
| Streak retten | verpassten Tag nachholen |
| Verschenken | Pack oder Währung an einen anderen Manager |
| CasinPro | spätere Ausbaustufe, siehe Abschnitt 7 |

**Preise** (offen, Prinzip statt Zahl)

Heute kosten die Packs 15 / 30 / 40 / 45 Lukaten (Normal / Big / Verein / Special,
`stickerShopOffers()`), bei 100 Lukaten Startguthaben also höchstens sechs normale Packs je Saison. Die
Euro-Angebote (`stickerShopEurOffers()`): Starter 10 normale Packs 1,99 € (einmal je Saison), Handvoll
5 normale 2,99 €, Stapel 4 normale + 3 Big 4,99 €, Kiste 3 normale + 3 Big + 1 Special + 1 Verein
6,99 €, Vereins- und Special-Pack einzeln je 1,99 €.

Für die Kalibrierung gilt:

- Das Preisverhältnis der Pack-Arten kann bleiben (15 : 30 : 40 : 45).
- Ein Euro-Paket direkt zu kaufen darf nicht schlechter sein als der Umweg Euro → Spaßwährung → Pack,
  sonst sind die Euro-Packs tot. Alternative: Euro-Packs ganz durch Währungs-Bündel ersetzen. Offen.
- Ein aktiver Manager ohne Euro soll deutlich mehr Packs bekommen als heute über Lukaten, aber das Album
  nicht geschenkt bekommen. Zielwert vor dem Start mit der Simulation festlegen.

**Euro**

- Tausch-Bündel über den bestehenden Ablauf (`StickerShopEurTrait`, Tabelle `sticker_eur_purchase`):
  Kauf anlegen, per PayPal.me mit Kauf-Code zahlen, Admin bestätigt oder storniert.
- Anders als bei Packs lässt sich gutgeschriebene Währung sofort ausgeben. Für das Storno braucht es eine
  Regel: Gutschrift erst nach Zahlungsbestätigung (einfach, aber Wartezeit) oder sofort mit Sperre wie bei
  den Karten aus unbezahlten Packs. Vorschlag: erst nach Bestätigung.

**Anreize, Euro auszugeben** (nach erwarteter Wirkung in einer Freundesliga)

1. **Gemeinschaftstopf:** Alle Einnahmen finanzieren die Saisonabschlussfeier, mit sichtbarem
   Fortschrittsbalken; bei Etappenzielen bekommen alle ein Holo-Pack. Ausgeben wird sozial belohnt.
2. **Dauerkarte:** einmal je Saison zahlen, dafür täglich etwas Währung, ein zweites Tages-Pack oder eine
   exklusive Album-Seite.
3. **Verschenken:** Pack oder Währung für einen anderen Manager, etwa zum Geburtstag.
4. **Album-Finisher:** Wunschsticker am Saisonende, wenn nur noch wenige Karten fehlen.
5. **Limitierte Sticker:** Manager-Sticker der eigenen Liga oder Legenden, nur in Event-Packs.
6. **Kosmetik und Unterstützer-Abzeichen.**
7. **Erstkauf-Bonus, zeitlich begrenzte Bündel** (Derby-Woche, Deadline-Day, Weihnachten), Angebot des Tages.

## 4. Technisches Zielbild

Noch kein Code; beschreibt, worauf die Ausbaustufen hinauslaufen.

**Saison-Schalter**

- Neue Spalte in der globalen DB: `season.coin_mode` mit `lukaten` (Default) oder `split`.
- Bestehende Saisons behalten `lukaten`. Die neue Saison wird mit `split` angelegt.
- Der Schalter steuert drei Dinge: den Rechenweg (mit oder ohne Shop-Abzug), die Beschriftung (Lukaten
  oder Besticoin) und die Zahlart im Shop (Lukaten oder Spaßwährung).
- Vorteil: Der Code kann früh und wirkungslos nach `main`, weil die laufende Saison auf `lukaten` steht.
  Ein lange lebender Branch würde bei dem Änderungstempo im Repo schnell veralten.

**Kontobuch der Spaßwährung**

- Neue Tabelle in der globalen DB, eine Zeile je Buchung: Manager, Betrag (positiv oder negativ), Quelle,
  Herkunft (verdient oder gekauft), eindeutiger Schlüssel je Manager, Verweis (z.B. Pack oder Euro-Kauf),
  Zeitpunkt.
- Der eindeutige Schlüssel macht Vergaben idempotent — dasselbe Muster wie `sticker_pack.source_key`
  (z.B. `lineup:{team_id}:{matchday_id}`). Ein zweiter Versuch legt nichts an.
- Kontostand = Summe der Beträge, wie das Team-Budget aus `transaction`.
- Ausgaben unter Named Lock je Manager, wie im heutigen Shop-Kauf (`buyStickerShopOffer()`), damit
  parallele Käufe nicht doppelt ausgeben.
- Zeilen werden nie geändert oder gelöscht; ein Storno ist eine Gegenbuchung.

**Was entfällt**

- Die Hauptliga-Logik des Shops (`getStickerShopLeague()`): Lukaten gibt es je Liga, deshalb wird heute aus
  der obersten Liga des Managers bezahlt. Die Spaßwährung ist global, im neuen Modus braucht es das nicht.

**Betroffene Stellen**

| Bereich | Dateien (Auswahl) |
|---|---|
| Budget, Bank, Schatzkammer | `api/app/database/h2h_prediction.database.php` |
| Shop Lukaten / Euro | `api/app/database/sticker_shop.database.php`, `sticker_shop_eur.database.php` |
| Shop-Oberfläche und Angebote | `webapp/src/app/stickers/shop/` (`shop.model.ts`, `sticker-shop.component.*`) |
| Wettbüro, Tipp-Karte | `webapp/src/app/liga/h2h/betting-office.component.*`, `h2h-match.component.*` |
| Simulation | `webapp/src/app/stickers/sticker-sim.ts`, `sticker-simulation.component.*` |
| Benachrichtigungen, Admin-Mails | Texte mit „Lukaten" in `sticker_shop.database.php` |

Größenordnung in der Webapp: rund 120 Vorkommen von „Lukaten" in 17 Dateien. Die meisten sind
Beschriftungen und müssen je nach Saison-Schalter „Lukaten" oder „Besticoin" zeigen.

## 5. Alte Saisons

**Was schon passt**

Alle Rechenwege nehmen eine Saison entgegen und lesen nur deren Zeilen (`h2h_match.season_id`,
`sticker_shop_purchase.season_id`). Mit `coin_mode = lukaten` rechnet eine alte Saison deshalb exakt wie
heute, einschließlich Shop-Abzug und Shop-Zeile.

**Was fehlt**

Die Schatzkammer lässt sich heute gar nicht für eine alte Saison aufrufen: `getLukatenStandings()` und
`getManagerLukatenBudgetForActiveSeason()` nehmen immer die aktive Saison. Sobald die neue Saison aktiv ist,
wäre die alte Schatzkammer nicht mehr erreichbar. Für die Anforderung „alte Saisons weiter ansehen" braucht
es also zusätzlich:

- eine Saison-Auswahl für die Schatzkammer (Parameter `season_id` an den Endpunkten, Auswahl im Wettbüro),
- Beschriftung und Zeilen je nach Schalter der gewählten Saison.

Die eigenen Tipps (`GET /h2h_prediction/mine`) liefern schon alle Saisons.

**Regel für die Umsetzung**

Bestehende Tabellen und Zeilen werden nie umgeschrieben oder umbenannt. Es kommt nur Neues dazu. Die
Lukaten-Käufe der alten Saison bleiben in `sticker_shop_purchase` liegen.

## 6. Migration und Umstellung

**Reihenfolge**

1. Migration global: `season.coin_mode` (Default `lukaten`) und das Kontobuch anlegen. Ohne Wirkung.
2. Code deployen, der den Schalter liest. Die laufende Saison verhält sich unverändert.
3. Neue Saison anlegen, dabei `coin_mode = split` setzen.
4. Begrüßungsbonus der Spaßwährung buchen (falls gewünscht).
5. Album der neuen Saison wie gewohnt am 1.9. einfrieren.

Keine Migration auf den Liga-DBs nötig: Besticoin nutzt weiter `h2h_prediction`, neue Shop-Käufe landen im
globalen Kontobuch statt in `sticker_shop_purchase`.

**Rückweg**

Schalter der neuen Saison zurück auf `lukaten`. Sauber möglich, solange noch keine Spaßwährung ausgegeben
wurde; danach müssten die Ausgaben einzeln bewertet werden.

**Offene Punkte**

- Rest-Lukaten der alten Saison: Vorschlag verfallen lassen, sie sind ohnehin saisongebunden.
- Ungeöffnete Packs der alten Saison: gehören zum alten Album, kein Unterschied zu heute.
- Zeit zwischen Saisonstart (1.7.) und Einfrieren des Albums (1.9.): In dieser Zeit lässt sich Währung
  verdienen, aber kein Pack kaufen. Entweder so akzeptieren oder Quellen erst ab dem 1.9. öffnen.
- Mehrere Ligen: Besticoin gibt es je Liga, ein Manager in zwei Ligen hat zwei Konten. Aktivitäts-Quellen
  je Liga (Aufstellung) zahlen dann doppelt in die eine Spaßwährung. Gewollt oder deckeln?
- Startguthaben der Spaßwährung und Höhe des Begrüßungsbonus.

## 7. Risiken

| Risiko | Einschätzung | Umgang |
|---|---|---|
| **Glücksspiel-Nähe** — Euro → Spaßwährung → Automaten-Spiel im CasinPro | Die heikelste Kombination, rechtlich und beim Jugendschutz, auch ohne Auszahlung. Keine Rechtsberatung; vor dem Bau des CasinPro prüfen lassen. | CasinPro ist eine getrennte, spätere Stufe. Das Kontobuch kennt die Herkunft, sodass sich gekaufte Währung vom Casino ausschließen lässt. |
| **Privates PayPal** für digitale Güter | Besteht schon bei den Euro-Packs: PayPal-Bedingungen, Steuer, Widerruf. Wächst mit Umsatz und Angebot. | Vor dem Ausbau der Euro-Anreize klären; der Gemeinschaftstopf ändert die Einordnung nicht automatisch. |
| **Inflation** | Eine unbegrenzte Währung ohne genug Abflüsse entwertet Packs und Euro-Käufe. | Obergrenzen je Quelle, teure Abflüsse (Wunschsticker, Holo), vor dem Start in der Simulation durchspielen. |
| **Missbrauch der Quellen** | Vor allem beim Eintragen von Noten. | Vergabe nur einmal je eindeutigem Schlüssel, Deckel je Spieltag. |
| **Rundung und Storno** | Gutschrift vor Zahlung könnte ausgegeben und dann storniert werden. | Gutschrift erst nach Zahlungsbestätigung. |
| **Veraltender Branch** | Das Repo ändert sich täglich. | Früh und schlafend nach `main` hinter dem Saison-Schalter. |
| **Akzeptanz** | Pleite ohne Ausweg bei Besticoin; der Lukaten-Shop fällt weg. | Vor Saisonstart klar ankündigen; Begrüßungsbonus in Spaßwährung als Ausgleich. |
| **Verwechslung** | Zwei Währungen in einer Oberfläche. | Eigene Symbole und Farben, Besticoin nur im Wettbüro sichtbar, Spaßwährung nur bei den Klebrigsten und in der Topbar. |

## 8. Ausbaustufen

| Stufe | Inhalt | Voraussetzung | Schlafend nach `main`? |
|---|---|---|---|
| 1 | Saison-Schalter, Rechenweg ohne Shop im neuen Modus, Beschriftung je Saison | — | ja |
| 2 | Schatzkammer für alte Saisons (Saison-Auswahl) | 1 | ja, ist sofort nützlich |
| 3 | Kontobuch, Kontostand-Endpunkt, Anzeige in der Topbar | 1 | ja |
| 4 | Shop zahlt im neuen Modus mit Spaßwährung | 3, Preise entschieden | ja |
| 5 | Quellen (Noten, Aufstellung, Achievements, Doppelte, Tipps, Streak) | 3, Werte entschieden | ja |
| 6 | Euro-Tausch-Bündel | 3, PayPal-Frage geklärt | ja |
| 7 | Simulation um Quellen und Abflüsse erweitern | Werte als Parameter | ja, unabhängig |
| 8 | Anreize und Kosmetik (Topf, Dauerkarte, Verschenken, Wunschsticker) | 4–6 | einzeln |
| 9 | CasinPro | Rechtsfrage geklärt | nein, erst nach Entscheidung |

Stufe 7 kann vorgezogen werden: Ohne sie lassen sich die Werte für die Stufen 4 und 5 nicht begründet
festlegen.

## 9. Offene Entscheidungen

1. Name der Spaßwährung.
2. Preise der Packs und Verdienstwerte je Quelle (nach der Simulation).
3. Bleiben die Euro-Packs neben den Währungs-Bündeln bestehen?
4. Startguthaben und Begrüßungsbonus.
5. Besticoin-Auszahlungen runden oder Nachkommastellen behalten?
6. Zahlen Aktivitäts-Quellen je Liga oder je Manager?
7. Quellen schon ab Saisonstart oder erst ab dem Einfrieren des Albums?
8. Welche Euro-Anreize zuerst?
9. Darf gekaufte Währung ins CasinPro?

## Bereits entschieden

- Die Spaßwährung bleibt über den Saisonwechsel erhalten; Besticoin startet je Saison neu.
- Pleite bei Besticoin heißt kein Nachschub.
- CasinPro ist eine spätere, getrennte Stufe; das Kontobuch unterscheidet verdient und gekauft.
- Dieser Branch liefert zuerst nur dieses Konzept.
