import { AfterViewInit, Directive, ElementRef, HostListener, Renderer2, inject } from '@angular/core';

/**
 * Setzt die Klasse "is-stuck", sobald ein position:sticky-Element beim Scrollen tatsächlich an
 * seiner top-Kante klebt (Top-Kante des Elements <= sein sticky-top). Auf Mobile (dort nicht
 * sticky) bleibt die Klasse aus. Reines Klassen-Toggle per Renderer2, ohne eigenes Signal/CD.
 */
@Directive({
  selector: '[appStuck]',
  standalone: false,
})
export class StuckDirective implements AfterViewInit {
  private el       = inject<ElementRef<HTMLElement>>(ElementRef);
  private renderer = inject(Renderer2);

  @HostListener('window:scroll')
  @HostListener('window:resize')
  update(): void {
    const el  = this.el.nativeElement;
    const cs  = getComputedStyle(el);
    const top = parseFloat(cs.top);
    const stuck = cs.position === 'sticky' && !Number.isNaN(top) && el.getBoundingClientRect().top <= top + 0.5;
    if (stuck) this.renderer.addClass(el, 'is-stuck');
    else       this.renderer.removeClass(el, 'is-stuck');
  }

  ngAfterViewInit(): void {
    this.update();
  }
}
