import { Component, computed, input } from '@angular/core';
import { PACK_ART, PackInfo, packCountLabel, packDesign, packFace } from './pack.model';

/**
 * "Die Klebrigsten" — ein geschlossenes Pack klein und nur zum Ansehen: dieselbe Vorderseite wie im Pack-Dialog
 * (Folienfarbe je Design, Motiv, Beschriftung, wandernder Glanz, leichtes Schweben), aber ohne Aufreißen.
 * Breite über --pack-width am Element (Standard 72px), alles darauf skaliert mit. Aussehen: _pack-cover.scss.
 */
@Component({
  selector: 'app-pack-cover',
  standalone: false,
  template: `
    <span class="pack pack--{{ design() }}" aria-hidden="true">
      <span class="pack__body">
        @if (art(); as a) {
          <img class="pack__art" [class.pack__art--center]="a.place === 'center'"
               [class.pack__art--balanced]="a.place === 'center' && !pack().leagueName"
               [src]="a.src" [style.mix-blend-mode]="a.blend ?? 'normal'" alt="" />
        }
        <span class="pack__brand">Die Klebrigsten</span>
        <span class="pack__kind">{{ face().kind }}</span>
        <span class="pack__headline" [class.pack__headline--long]="face().headline.length > 10">{{ face().headline }}</span>
        <span class="pack__count">{{ count() }}</span>
        @if (pack().leagueName) { <span class="pack__league">{{ pack().leagueName }}</span> }
      </span>
      <span class="pack__top"></span>
    </span>
  `,
  styleUrl: './pack-cover.component.scss',
})
export class PackCoverComponent {
  pack = input.required<PackInfo>();

  design = computed(() => packDesign(this.pack()));
  face = computed(() => packFace(this.pack()));
  count = computed(() => packCountLabel(this.pack()));
  art = computed(() => PACK_ART[this.design()] ?? null);
}
