import { Component, computed, effect, inject, input, OnInit, output, signal, untracked } from '@angular/core';
import { StickerStatusService } from '../../core/sticker-status.service';
import { NotificationService } from '../../core/notification.service';
import { StickerCardData, requestTiltPermission } from '../sticker-card/sticker-card.component';
import { PackCard, PackInfo, packInfo } from './pack.model';
import { PackOpener } from './pack-opener';
import { ALBUM_SOURCE, StickerAlbumService } from './sticker-album.service';

/** Ab so vielen Tagen mit ungeöffnet weggeklickten Packs bietet die Einblendung "Nicht mehr anzeigen" an */
const OPT_OUT_AFTER_IGNORED_DAYS = 3;

/**
 * "Die Klebrigsten" — neu erhaltene Packs (z.B. Tages-Pack beim App-Öffnen, Meilenstein nach dem Spieltag)
 * erscheinen auf jeder Seite sofort groß in der Mitte: aufreißen oder "Später öffnen" (dann bleibt das Pack
 * ungeöffnet und kann unter /klebrigsten/sammelalbum geöffnet werden). Beim Schließen werden die Packs
 * serverseitig als angekündigt markiert (announced) — jedes Pack erscheint nur einmal von selbst, auch
 * über mehrere Geräte hinweg. Liegt in der Shell; die Dialog-Logik (inkl. Album-Daten) entsteht erst bei Bedarf.
 */
@Component({
  selector: 'app-pack-announcement',
  standalone: false,
  template: `
    @if (active(); as info) {
      <app-pack-announcement-dialog [first]="info" [offerOptOut]="offerOptOut()" (optOut)="optOut()" (done)="dismiss()" />
    }
  `,
})
export class PackAnnouncementComponent {
  private status = inject(StickerStatusService);
  private notif = inject(NotificationService);
  /** in dieser Sitzung schon geschlossen — bis GET /sticker/me die Markierung zurückliefert */
  private dismissed = signal(new Set<string>());

  readonly active = signal<PackInfo | null>(null);
  /**
   * "Nicht mehr anzeigen" nur für Manager, die Packs an mind. OPT_OUT_AFTER_IGNORED_DAYS verschiedenen Tagen
   * ungeöffnet weggeklickt haben (seit dem letzten geöffneten Pack) — wer Packs öffnet, sieht den Button nie.
   * Beim Einblenden festgehalten, damit er nicht mitten im Dialog auftaucht oder verschwindet.
   */
  readonly offerOptOut = signal(false);
  // Abschaltbar unter Einstellungen → Benachrichtigungen → Einblendungen (overlay_pack); dann bleiben
  // neue Packs einfach in der Pack-Leiste im Sammelalbum (+ Badge), ohne groß zu erscheinen
  // Pausiert, solange z.B. der Bezahl-Dialog eines Euro-Kaufs offen ist — die Packs erscheinen danach
  private pending = computed(() => !this.notif.overlayAllowed('overlay_pack') || this.status.announcePaused() ? []
    : this.status.packs().filter(p => !p.announced && !this.dismissed().has(p.id)));

  constructor() {
    // Neues, noch nicht angekündigtes Pack → Dialog zeigen (einer zur Zeit)
    effect(() => {
      const next = this.pending()[0];
      untracked(() => {
        if (!next || this.active()) return;
        this.offerOptOut.set((this.status.state()?.ignored_days ?? 0) >= OPT_OUT_AFTER_IGNORED_DAYS);
        this.active.set(packInfo(next));
      });
    });
  }

  /** "Nicht mehr anzeigen": Einblendung + Topbar-Zähler aus (wieder einschaltbar in den Einstellungen) */
  optOut(): void {
    this.notif.setPreference('overlay_pack', false);
    this.notif.setPreference('sticker_pack', false);
  }

  /** Dialog zu: alle bis jetzt bekannten Packs gelten als angekündigt (auch übersprungene) — in der DB. */
  dismiss(): void {
    const ids = new Set(this.status.packs().filter(p => !p.announced).map(p => p.id));
    const first = this.active()?.id;
    if (first) ids.add(first);
    this.dismissed.set(new Set([...this.dismissed(), ...ids]));
    this.status.markAnnounced([...ids]);
    this.active.set(null);
  }
}

/** Dialog-Teil der Ankündigung — lädt das Album erst, wenn wirklich ein Pack angekündigt wird. */
@Component({
  selector: 'app-pack-announcement-dialog',
  standalone: false,
  providers: [{ provide: ALBUM_SOURCE, useValue: 'sticker/album' }, StickerAlbumService, PackOpener],
  template: `
    @if (opener.info(); as info) {
      <app-pack-open-dialog heading="Neues Pack!" [pack]="info" [cards]="opener.cards()" [error]="opener.error()"
                            [remaining]="opener.remaining()" [busy]="opener.busy()" [covered]="openCard() !== null"
                            [offerOptOut]="offerOptOut()" (optOut)="optOut.emit()"
                            (tear)="onTear()" (next)="opener.showNext()" (open)="openPulled($event)" (closed)="finish()" />
    }
    @if (openCard(); as card) {
      <app-sticker-card-dialog [data]="card" (closed)="openCard.set(null)" />
    }
  `,
})
export class PackAnnouncementDialogComponent implements OnInit {
  opener = inject(PackOpener);
  first = input.required<PackInfo>();
  offerOptOut = input(false);
  optOut = output<void>();
  done = output<void>();
  openCard = signal<StickerCardData | null>(null);

  ngOnInit(): void {
    this.opener.show(this.first());
  }

  onTear(): void {
    requestTiltPermission(); // synchron in der Tipp-Geste (iOS), falls danach eine Karte groß geöffnet wird
    this.opener.tear();
  }

  openPulled(c: PackCard): void {
    this.openCard.set(c.card);
  }

  finish(): void {
    this.opener.close();
    this.done.emit();
  }
}
