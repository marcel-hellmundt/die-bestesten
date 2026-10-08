import { Component, computed, DestroyRef, effect, inject, input, OnInit, output, signal, untracked } from '@angular/core';
import { StickerStatusService } from '../../core/sticker-status.service';
import { NotificationService } from '../../core/notification.service';
import { MatchdaySummaryService } from '../../core/matchday-summary.service';
import { StickerCardData } from '../sticker-card/sticker-card.component';
import { PackCard, PackInfo, packInfo } from './pack.model';
import { PackOpener } from './pack-opener';
import { ALBUM_SOURCE, StickerAlbumService } from './sticker-album.service';

/** Ab so vielen Tagen mit ungeöffnet weggeklickten Packs bietet die Einblendung "Nicht mehr anzeigen" an */
const OPT_OUT_AFTER_IGNORED_DAYS = 3;

/**
 * "Die Klebrigsten" — neu erhaltene Packs (z.B. Tages-Pack beim App-Öffnen, Meilenstein nach dem Spieltag)
 * erscheinen auf jeder Seite sofort groß in der Mitte — mehrere auf einmal klein nebeneinander zur Auswahl,
 * welches zuerst aufgerissen wird: aufreißen oder "Später öffnen" (dann bleibt das Pack
 * ungeöffnet und kann unter /klebrigsten/sammelalbum geöffnet werden). Beim Einblenden werden alle neuen
 * Packs serverseitig als angekündigt markiert (announced) — jedes Pack erscheint nur einmal von selbst, auch
 * über mehrere Geräte hinweg. Liegt in der Shell; die Dialog-Logik (inkl. Album-Daten) entsteht erst bei Bedarf.
 */
@Component({
  selector: 'app-pack-announcement',
  standalone: false,
  template: `
    @if (active(); as packs) {
      <app-pack-announcement-dialog [packs]="packs" [offerOptOut]="offerOptOut()" (optOut)="optOut()" (done)="dismiss()" />
    }
  `,
})
export class PackAnnouncementComponent {
  private status = inject(StickerStatusService);
  private notif = inject(NotificationService);
  private summary = inject(MatchdaySummaryService);
  /** in dieser Sitzung schon geschlossen — bis GET /sticker/me die Markierung zurückliefert */
  private dismissed = signal(new Set<string>());

  /** gerade eingeblendete neue Packs (bei mehreren: Auswahl) */
  readonly active = signal<PackInfo[] | null>(null);
  /**
   * "Nicht mehr anzeigen" nur für Manager, die Packs an mind. OPT_OUT_AFTER_IGNORED_DAYS verschiedenen Tagen
   * ungeöffnet weggeklickt haben (seit dem letzten geöffneten Pack) — wer Packs öffnet, sieht den Button nie.
   * Beim Einblenden festgehalten, damit er nicht mitten im Dialog auftaucht oder verschwindet.
   */
  readonly offerOptOut = signal(false);
  // Abschaltbar unter Einstellungen → Benachrichtigungen → Einblendungen (overlay_pack); dann bleiben
  // neue Packs einfach in der Pack-Leiste im Sammelalbum (+ Badge), ohne groß zu erscheinen
  // Pausiert, solange z.B. der Bezahl-Dialog eines Euro-Kaufs offen ist — die Packs erscheinen danach.
  // Ebenso im versteckten Tab (Hintergrund/minimiert): eingeblendet und als angekündigt markiert wird erst,
  // wenn der Tab wieder angesehen wird — sonst wäre das Pack "gezeigt", ohne dass es jemand gesehen hat
  // Nach einem Spieltagsabschluss kommt erst die Zusammenfassung (sie nennt die neuen Packs), dann die Packs
  private pending = computed(() => !this.notif.overlayAllowed('overlay_pack') || this.status.announcePaused()
    || !this.status.tabVisible() || this.summary.blocking() ? []
    : this.status.packs().filter(p => !p.announced && !this.dismissed().has(p.id)));

  constructor() {
    // Shell weg (Abmelden) bei offenem Dialog: die Markierung "offen" nicht stehen lassen
    inject(DestroyRef).onDestroy(() => this.status.announcing.set(false));

    // Neues, noch nicht angekündigtes Pack → Dialog zeigen (einer zur Zeit). active wird mitgelesen, damit
    // ein Pack, das während eines offenen Dialogs dazukommt, nach dem Schließen noch eingeblendet wird.
    effect(() => {
      const pending = this.pending();
      const busy = this.active() !== null;
      untracked(() => {
        if (!pending.length || busy) return;
        this.offerOptOut.set((this.status.state()?.ignored_days ?? 0) >= OPT_OUT_AFTER_IGNORED_DAYS);
        // Alle jetzt neuen Packs gelten ab dem Einblenden als angekündigt — auch die, die gleich per
        // "Nächstes Pack" im selben Dialog geöffnet werden (beim Schließen wären sie schon aus packs() raus)
        const ids = pending.map(p => p.id);
        this.dismissed.set(new Set([...this.dismissed(), ...ids]));
        this.status.markAnnounced(ids);
        this.active.set(pending.map(packInfo));
        this.status.announcing.set(true);
      });
    });
  }

  /** "Nicht mehr anzeigen": Einblendung + Topbar-Zähler aus (wieder einschaltbar in den Einstellungen) */
  optOut(): void {
    this.notif.setPreference('overlay_pack', false);
    this.notif.setPreference('sticker_pack', false);
  }

  /** Dialog zu — markiert ist schon beim Einblenden; währenddessen neu dazugekommene Packs erscheinen danach. */
  dismiss(): void {
    this.active.set(null);
    this.status.announcing.set(false);
  }
}

/** Dialog-Teil der Ankündigung — lädt das Album erst, wenn wirklich ein Pack angekündigt wird. */
@Component({
  selector: 'app-pack-announcement-dialog',
  standalone: false,
  providers: [{ provide: ALBUM_SOURCE, useValue: 'sticker/album' }, StickerAlbumService, PackOpener],
  template: `
    @if (opener.visible()) {
      <app-pack-open-dialog [heading]="packs().length > 1 ? 'Neue Packs!' : 'Neues Pack!'" [pack]="opener.info()"
                            [choices]="opener.choices()" [choosing]="opener.choosing()"
                            [cards]="opener.cards()" [error]="opener.error()"
                            [remaining]="opener.remaining()" [busy]="opener.busy()" [covered]="openCard() !== null"
                            [offerOptOut]="offerOptOut()" (optOut)="optOut.emit()"
                            (pick)="opener.pick($event)" (back)="opener.backToChoice()"
                            (tear)="onTear()" (next)="opener.showNext()" (open)="openPulled($event)" (closed)="finish()" />
    }
    @if (openCard(); as card) {
      <app-sticker-card-dialog [data]="card" (closed)="openCard.set(null)" />
    }
  `,
})
export class PackAnnouncementDialogComponent implements OnInit {
  opener = inject(PackOpener);
  packs = input.required<PackInfo[]>();
  offerOptOut = input(false);
  optOut = output<void>();
  done = output<void>();
  openCard = signal<StickerCardData | null>(null);

  ngOnInit(): void {
    this.opener.showChoice(this.packs());
  }

  onTear(): void {
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
