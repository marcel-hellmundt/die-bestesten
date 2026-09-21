import { Injectable, signal } from '@angular/core';

/**
 * Wird von Liga-Unterseiten mit eigenem, unter dem Sub-Menü gepinntem Kopfbereich (z.B. die
 * Saison-Auswahl auf /liga/tabelle) auf true gesetzt — dann ist DIESER Bereich die untere Kante
 * des gepinnten Headers und trägt Border/Padding, nicht das Sub-Menü selbst.
 */
@Injectable({ providedIn: 'root' })
export class LigaSubnavService {
  childSticky = signal(false);
}
