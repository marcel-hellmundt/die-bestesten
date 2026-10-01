import { AfterViewInit, Directive, ElementRef, HostListener, OnDestroy, inject } from '@angular/core';

/**
 * Gleitender Hintergrund für das Segmented Control (.segmented, _layout.scss): statt dass die alte Option
 * aus- und die neue angeht, bewegt sich die Akzentfläche zur neu gewählten Option. Hängt am Klassen-Selektor,
 * greift also bei jedem .segmented automatisch — das Modul muss die Direktive nur importieren (standalone).
 *
 * Misst die Option mit .segmented__option--active und schreibt Position/Breite als --seg-x/--seg-w an den Host;
 * den Schieber zeichnet .segmented--sliding::before. Ohne diese Direktive (oder ohne aktive Option) bleibt es bei
 * der statischen Füllung der aktiven Option. Reines DOM, kein Signal/CD.
 * Geglitten wird nur nach einem Klick (.segmented--animate, kurz gesetzt) — beim Laden, bei Größenänderungen oder
 * nachladender Schrift sitzt der Schieber sofort richtig, statt sichtbar herumzuwandern.
 *
 * Bedient auch die Variante .team-toggle (H2H-Match, mobil: Heim/Auswärts): dort ist die aktive Farbe die
 * Teamfarbe (--team-color am Button) — sie wird als --seg-color an den Host gereicht, der Schieber blendet um.
 */
@Directive({
  selector: '.segmented, .team-toggle',
  standalone: true,
})
export class SegmentedDirective implements AfterViewInit, OnDestroy {
  private host = inject<ElementRef<HTMLElement>>(ElementRef).nativeElement;
  private mutations?: MutationObserver;
  private resize?: ResizeObserver;

  private animateTimer?: ReturnType<typeof setTimeout>;

  /** Klick auf eine Option: für die Dauer des Übergangs animieren (läuft vor dem Klassenwechsel der Option) */
  @HostListener('click')
  onClick(): void {
    if (!this.host.classList.contains('segmented--animate')) this.host.classList.add('segmented--animate');
    clearTimeout(this.animateTimer);
    this.animateTimer = setTimeout(() => this.host.classList.remove('segmented--animate'), 400);
  }

  ngAfterViewInit(): void {
    this.update();

    // Aktive Option wechselt (Klasse), Optionen kommen/gehen (@for), Beschriftung ändert sich
    this.mutations = new MutationObserver(() => this.update());
    this.mutations.observe(this.host, { subtree: true, childList: true, characterData: true, attributes: true, attributeFilter: ['class'] });

    // Breite ändert sich (Fenstergröße, Schrift geladen)
    this.resize = new ResizeObserver(() => this.update());
    this.resize.observe(this.host);
  }

  private x = -1;
  private w = -1;
  private color = '';

  /**
   * Schreibt nur bei echter Änderung: classList.add/remove setzt das class-Attribut auch dann neu, wenn sich
   * nichts ändert — das würde den MutationObserver erneut auslösen (Endlosschleife, Seite friert ein).
   */
  private update(): void {
    const active = this.host.querySelector<HTMLElement>(':scope > .segmented__option--active, :scope > .team-toggle__btn--active');
    const sliding = this.host.classList.contains('segmented--sliding');
    if (!active) {
      if (sliding) this.host.classList.remove('segmented--sliding');
      return;
    }
    const x = active.offsetLeft, w = active.offsetWidth;
    if (x !== this.x || w !== this.w) {
      this.x = x;
      this.w = w;
      this.host.style.setProperty('--seg-x', `${x}px`);
      this.host.style.setProperty('--seg-w', `${w}px`);
    }
    // .team-toggle: Farbe des aktiven Teams für den Schieber
    const color = this.host.classList.contains('team-toggle')
      ? getComputedStyle(active).getPropertyValue('--team-color').trim()
      : '';
    if (color !== this.color) {
      this.color = color;
      if (color) this.host.style.setProperty('--seg-color', color);
      else this.host.style.removeProperty('--seg-color');
    }
    if (!sliding) this.host.classList.add('segmented--sliding');
  }

  ngOnDestroy(): void {
    clearTimeout(this.animateTimer);
    this.mutations?.disconnect();
    this.resize?.disconnect();
  }
}
