---
name: commit-push
description: Stage all changed files, create a meaningful commit message, and push to remote. Use when the user says "commite", "commite und pushe", "commit", "commit and push", or similar.
allowed-tools: Bash
argument-hint: [optional commit message]
---

# Commit & Push

Führe folgende Schritte in dieser Reihenfolge aus:

## 1. Status und Diff prüfen
Führe parallel aus:
- `git status` — um alle geänderten Dateien zu sehen
- `git diff` — um die konkreten Änderungen zu sehen
- `git log --oneline -5` — um den Commit-Message-Stil des Repos zu verstehen

## 2. Commit-Message verfassen
- Falls der User eine Nachricht in `$ARGUMENTS` angegeben hat, verwende diese.
- Sonst: Analysiere die Änderungen und schreibe eine präzise Commit-Message.
  - Erste Zeile: kurze Zusammenfassung (max. 72 Zeichen), Englisch
  - Optional: Leerzeile + detailliertere Beschreibung der Einzeländerungen
  - Fokus auf das "Warum", nicht das "Was"

## 3. Dateien stagen und committen
- Stage nur relevante Dateien — keine `.env`, Credentials oder große Binärdateien
- Commit mit HEREDOC-Syntax für korrekte Formatierung
- Füge immer hinzu: `Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>`

## 4. Pushen
- `git push`
- Prüfe ob der Push erfolgreich war

## 5. Bestätigung
- Gib die Commit-Hash und den Branch aus
