import { Injectable } from '@angular/core';

// In-memory only (nicht in Router-State/history) — überlebt bewusst keinen Reload/Deep-Link:
// die Prev/Next-Navigation auf /team/:id ist nur "smart", wenn der Nutzer tatsächlich über eine
// Liste (Manager-Seite, Liga-Tabelle, Spieltag) dorthin gekommen ist. Ohne Kontext bleiben die
// Chevron-Buttons ausgeblendet.
@Injectable({ providedIn: 'root' })
export class TeamNavService {
  private teamIds: string[] | null = null;

  setContext(teamIds: string[]): void {
    this.teamIds = [...teamIds];
  }

  getContext(): string[] | null {
    return this.teamIds;
  }
}
