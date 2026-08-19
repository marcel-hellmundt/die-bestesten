---
name: release-notes
description: Schreibt eine priorisierte Update/Release-Info für Nutzer aus den zuletzt auf main gelandeten Änderungen. Use when the user says "release notes", "changelog", "update info für nutzer", "was hat sich geändert", "nutzer-update schreiben", or similar.
allowed-tools: Bash
argument-hint: [optionaler Zeitraum, z.B. "seit gestern", "letzte 7 Tage", ein Commit-Hash, oder leer]
---

# Release Notes für Nutzer

Fasse zusammen, was seit dem letzten Update **für die Nutzer der Webapp wahrnehmbar** war —
keine technische Change-Liste, sondern eine kurze, verständliche Ankündigung.

## 1. Zeitraum bestimmen

- Falls `$ARGUMENTS` einen Zeitraum/Commit/Tag angibt, diesen verwenden.
- Sonst: `git log --oneline --format="%ad %h %s" --date=short main | head -40` ansehen und die
  letzten ~7 Tage aktiver Arbeit auf `main` als Standardzeitraum nehmen.
- Bei Unklarheit (z.B. sehr viele oder sehr wenige Commits im Standardzeitraum) kurz beim Nutzer
  nachfragen, statt zu raten.

## 2. Commits laden

```
git log <range> --format="%H" main
```

Für jeden Commit die volle Message laden (`git log --format="%B" <hash>`), da dort oft die
eigentliche Begründung/das "Warum" steht, das für die Einordnung wichtig ist. Bei größeren
Änderungen zusätzlich `git show --stat <hash>` prüfen, um zu verstehen, welche Bereiche der App
betroffen sind (Pfad wie `webapp/src/app/markt/...` zeigt sofort die betroffene Seite).

## 3. Relevanz filtern

Nur Änderungen aufnehmen, die ein Nutzer in der laufenden App tatsächlich bemerken würde oder die
sein Erlebnis konkret beeinflussen. Leitfrage pro Commit: **"Würde ein Manager der Liga das sehen,
merken oder zu schätzen wissen?"**

**Aufnehmen:**
- Neue/geänderte Seiten, Buttons, Filter, Anzeigen, Layouts (Frontend)
- Backend-/API-Änderungen, die sichtbares Verhalten ändern — falsch angezeigte Daten korrigieren,
  eine neue Aktion ermöglichen, Performance/Ladezeit spürbar verbessern
- Bugfixes, bei denen Nutzer den Fehler potenziell selbst bemerkt haben (falsche Werte, falsches
  Team/Preis/Punkte, kaputter Klick, hängende Ladeanzeige, o.ä.)

**Ignorieren:**
- Reine Refactorings, Umbenennungen, Datei-/Ordner-Umzüge ohne Verhaltensänderung
- Routen-Umbau ohne sichtbaren Effekt (Nutzer navigiert über Menü, nicht über URL)
- Doku-Updates (CLAUDE.md, schema.php-Beschreibungen), CI/Workflow, Formatierung, Tests
- Interne Admin-/Maintainer-Werkzeuge, die normale Manager nie zu Gesicht bekommen (es sei denn,
  der Nutzer fragt explizit auch danach)

## 4. Priorisieren & kategorisieren

Ordne jede aufgenommene Änderung genau einer Kategorie zu, **in dieser festen Reihenfolge**
(nur Kategorien mit mindestens einem Punkt ausgeben):

1. **🚨 Hotfixes** — etwas war akut kaputt/falsch und musste dringend korrigiert werden (kaputter
   Kernablauf, falsche Punkte/Preise/Geld, Login/Auth-Problem, Absturz, Datenverlust-Risiko).
2. **✨ Neue Features** — etwas komplett Neues, das es vorher nicht gab (neue Seite, neue Aktion,
   neuer Menüpunkt, neue Möglichkeit).
3. **🔧 Verbesserungen** — etwas Bestehendes wurde besser/schöner/schneller/übersichtlicher
   (zusätzliche Filter/Sortierung an bestehender Liste, Mobile-Optimierung, Redesign, neue Spalte
   an bestehender Ansicht, Layout-Politur).
4. **🐛 Bugfixes** — normale Korrekturen ohne Dringlichkeit (Rand-/Sonderfall falsch behandelt,
   kleinere visuelle Fehler, Detail stimmte nicht).

Abgrenzung Hotfix vs. Bugfix: Ein Hotfix betrifft etwas, das *gerade akut* falsch/blockiert war
und sofortige Aufmerksamkeit verdiente; ein Bugfix ist eine normale, nicht-dringende Korrektur.

## 5. Formulieren

- Sprache: Deutsch, wie die App selbst.
- Pro Änderung **ein** knapper Stichpunkt aus Nutzersicht — was ist jetzt anders/möglich, nicht
  wie es implementiert wurde. Keine Dateipfade, Commit-Hashes, Branch- oder Variablennamen.
- Mehrere Commits, die zum selben sichtbaren Ergebnis gehören (z.B. Feature + Nachbesserung im
  selben Bereich), zu einem einzigen Stichpunkt zusammenfassen statt separat aufzulisten.
- **Emojis**: Jeder Stichpunkt bekommt zusätzlich zum Kategorie-Emoji ein passendes, inhaltliches
  Emoji, das den Bereich/die Aktion greifbar macht (z.B. 📱 Mobile, 🔍 Filter/Suche, 💰 Preis/Markt,
  👥 Team/Kader, 🔔 Benachrichtigungen, 🏆 Achievements/Ligen, ⚽ Spieler). Wenn sich für einen
  einzelnen Punkt kein treffendes Emoji anbietet, reicht das Kategorie-Emoji der Übergruppe.

## 6. Ausgabe

Gib das Ergebnis direkt als Chat-Antwort aus (Markdown-Überschriften pro Kategorie, Bulletpoints
darunter) — kein Artifact, keine Datei, sofern der Nutzer nicht ausdrücklich danach fragt. Wenn der
Nutzer erkennbar etwas Teilbares für die Liga-Mitglieder möchte (z.B. "schön aufbereitet",
"zum Teilen"), biete an, daraus zusätzlich ein Artifact zu bauen — lade dafür vorher die
`artifact-design`-Skill, bevor du irgendetwas schreibst.

Beispielstruktur:

```
## 🚨 Hotfixes
- 💰 [Kurzbeschreibung]

## ✨ Neue Features
- 🔍 [Kurzbeschreibung]

## 🔧 Verbesserungen
- 📱 [Kurzbeschreibung]

## 🐛 Bugfixes
- 👥 [Kurzbeschreibung]
```

Am Ende kurz den abgedeckten Zeitraum nennen (z.B. "Zeitraum: 12.–19.08."), damit klar ist, was
schon in einem früheren Update stand.
