---
name: achievement-manager
description: Add a new achievement or edit an existing one. Updates global_schema.sql and achievement_conditions.database.php, outputs INSERT/UPDATE SQL for the live server, then commits. Use when the user says "neues achievement", "achievement anlegen", "achievement bearbeiten", or similar.
allowed-tools: Read, Edit, Write, Glob, Grep, Bash
argument-hint: [achievement name or condition_key to edit]
---

# Achievement Manager

Du legst ein neues Achievement an oder bearbeitest ein bestehendes.

## Relevante Dateien

- Schema: `database/global_schema.sql` — INSERT IGNORE-Block ab ca. Zeile 185
- Conditions: `api/app/database/achievement_conditions.database.php` — eine `check_{condition_key}()`-Methode pro Achievement
- Icons: `webapp/public/img/achievements/*.png`

## Schritt 1 — Modus bestimmen

Lies den INSERT-Block aus `global_schema.sql`, um zu sehen ob ein Achievement mit dem angegebenen Namen oder `condition_key` bereits existiert.

- **Existiert** → Bearbeitungsmodus: zeige aktuelle Werte, frage was geändert werden soll
- **Neu** → Anlagemodus: fahre mit Schritt 2 fort

## Schritt 2 — Fehlende Infos erfragen

Falls nicht bereits im Gesprächskontext vorhanden, frage nach:

| Feld | Hinweis |
|---|---|
| `name` | Anzeigename (z.B. "Bomber der Nation") |
| `description` | Ein Satz, was der Manager getan hat |
| `condition_key` | Snake_case, wird auto aus name abgeleitet wenn nicht angegeben |
| `icon` | Dateiname ohne Extension (z.B. `pay`) |
| Bedingungslogik | Welche Tabellen/Spalten, welcher Schwellenwert |
| `reason`-Format | Was soll im Tooltip stehen? (z.B. "TeamName, Spieltag X (2024/25)") |
| `earned_at`-Quelle | Welches `kickoff_date`? (erster/letzter/einmaliger Spieltag) |

Stelle nur Fragen, die wirklich unklar sind — wenn der Kontext reicht, leite die Werte selbst ab.

## Schritt 3 — Icon prüfen

Prüfe ob `webapp/public/img/achievements/{icon}.png` existiert.
Falls nicht: weise den User darauf hin, füge das Achievement aber trotzdem an.

## Schritt 4 — condition_key auf Duplikat prüfen

Grep nach `condition_key` im INSERT-Block. Bei Duplikat: abbrechen und nachfragen.

## Schritt 5 — global_schema.sql aktualisieren

**Neu:** Hänge einen neuen Eintrag an den INSERT-IGNORE-Block an:
```sql
(UUID(), '{condition_key}', '{name}', '{description}', '{icon}'),
```
Achte auf konsistente Spaltenausrichtung mit den bestehenden Zeilen.
Das letzte Wertepaar muss mit `;` enden statt `,`.

**Bearbeiten:** Passe die entsprechende Zeile direkt an.

## Schritt 6 — check-Methode schreiben

Füge am Ende von `achievement_conditions.database.php` (vor der letzten `}`) eine neue Methode ein:

```php
public function check_{condition_key}(array $managerIds): array
{
    if (empty($managerIds)) return [];
    // ...
}
```

**Pflicht-Rückgabeformat:**
```php
return [
    'manager-uuid' => [
        'reason'    => 'Menschenlesbarer Kontext (Saison, Team, Wert)',
        'earned_at' => '2024-11-30 15:30:00',  // kickoff_date des relevanten Spieltags
    ],
    // ...
];
```

**Patterns aus bestehenden Methoden:**
- Cross-DB: `$this->con` = global (matchday, season, player, transferwindow); `$this->con_league` = Liga (team, team_rating, manager_achievement)
- Hilfsmethoden: `$this->seasonLabel(string $startDate): string` → "2024/25"; `$this->formatMio(int $value): string` → "1,5 Mio"
- Platzhalter: `implode(',', array_fill(0, count($ids), '?'))` + `array_values($ids)` bei `execute()`
- Idempotenz: Methode gibt nur die *ersten* Qualifizierer pro Manager zurück — `evaluateAchievements()` verwendet INSERT IGNORE

## Schritt 7 — SQL für den Server ausgeben

Gib dem User das SQL aus, das auf dem Live-Server ausgeführt werden muss:

**Neu:**
```sql
INSERT IGNORE INTO achievement (id, condition_key, name, description, icon)
VALUES (UUID(), '{condition_key}', '{name}', '{description}', '{icon}');
```

**Bearbeiten (nur geänderte Felder):**
```sql
UPDATE achievement SET name = '...', description = '...' WHERE condition_key = '...';
```

## Schritt 8 — Committen

Rufe den `commit-push`-Skill auf oder committe direkt:
- Stage: `database/global_schema.sql` + `api/app/database/achievement_conditions.database.php`
- Commit-Message: `Add {name} achievement` bzw. `Update {name} achievement`
- Danach fragen ob gepusht werden soll
