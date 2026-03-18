---
name: standup-daily
description: Summarizes the previous working day's commits for a morning standup. Use when the user says "standup", "daily", "was hab ich gestern gemacht", or similar.
allowed-tools: Bash
---

# Standup Daily Summary

Erstelle eine Standup-Zusammenfassung des letzten aktiven Arbeitstages.

## 1. Letzten aktiven Arbeitstag ermitteln

Führe aus:
```
git log --oneline --format="%ad %s" --date=short | head -50
```

Ermittle das heutige Datum aus dem System (`date +%Y-%m-%d`).

- Suche den letzten Tag, an dem Commits vorhanden sind, der **vor heute** liegt.
- Falls gestern Commits vorhanden sind → gestern verwenden.
- Falls nicht → den letzten Tag mit Commits nehmen (letzter aktiver Arbeitstag).

## 2. Commits dieses Tages laden

```
git log --oneline --format="%H %ad %s" --date=short | grep "<DATUM>"
```

Dann für jeden Commit die vollständige Message laden:
```
git log --format="%B" <HASH>
```

## 3. Zusammenfassung schreiben

Schreibe eine klare, prägnante Zusammenfassung auf Deutsch im Standup-Format:

---

**Standup — [Wochentag], [Datum]**

**Was wurde gemacht?**
- [Bullet pro thematische Gruppe, zusammengefasst aus Commit-Messages]
- ...

**Commits ([Anzahl])**
- `[short-hash]` [Commit-Subject]
- ...

---

Regeln:
- Gruppiere thematisch zusammengehörige Commits zu einem Bullet
- Formuliere aus Entwicklerperspektive, prägnant (was wurde gebaut/gefixt/geändert)
- Kein Bullet für Co-Authored-By oder technische Metadaten
- Falls nur 1 Commit → trotzdem als Liste
