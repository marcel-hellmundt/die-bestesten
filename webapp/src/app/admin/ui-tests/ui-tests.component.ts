import { Component, computed, inject } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { ActivatedRoute, Router } from '@angular/router';
import { map } from 'rxjs';

type UiTest = 'spieltag' | 'packs';

/** Die Test-Oberflächen — eine neue kommt hier als weitere Option dazu (+ ihr Baustein im Template). */
const TESTS: { key: UiTest; label: string }[] = [
  { key: 'spieltag', label: 'Spieltags-Abschluss' },
  { key: 'packs', label: 'Sticker-Packs' },
];

/**
 * /verwaltung/ui-tests: alle Test-Oberflächen für Admins an einer Stelle — was hier liegt, sieht sonst niemand, und
 * nichts davon schreibt etwas. Umschalter zwischen den Tests, die Auswahl steht als ?test=… in der Adresse
 * (Neuladen und Links bleiben beim gewählten Test).
 *   Spieltags-Abschluss — Vorschau der Spieltags-Zusammenfassung (admin/matchday-summary)
 *   Sticker-Packs       — alle Pack-Designs zum Aufreißen (stickers/packs, PackGalleryModule)
 */
@Component({
  selector: 'app-ui-tests',
  standalone: false,
  templateUrl: './ui-tests.component.html',
  styleUrl: './ui-tests.component.scss',
})
export class UiTestsComponent {
  private route = inject(ActivatedRoute);
  private router = inject(Router);

  readonly TESTS = TESTS;

  private param = toSignal(this.route.queryParamMap.pipe(map(p => p.get('test'))),
    { initialValue: this.route.snapshot.queryParamMap.get('test') });
  /** gewählter Test; unbekannter oder fehlender Parameter = der erste */
  view = computed<UiTest>(() => TESTS.find(t => t.key === this.param())?.key ?? TESTS[0].key);

  setView(key: UiTest): void {
    this.router.navigate([], { relativeTo: this.route, queryParams: { test: key }, replaceUrl: true });
  }
}
