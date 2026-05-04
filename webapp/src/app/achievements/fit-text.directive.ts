import { AfterViewInit, Directive, ElementRef, Input, OnChanges } from '@angular/core';

@Directive({ selector: '[fitText]', standalone: false })
export class FitTextDirective implements AfterViewInit, OnChanges {
  @Input() fitTextMin = 10;
  @Input() fitTextMax = 16;

  constructor(private el: ElementRef<HTMLElement>) {}

  ngAfterViewInit(): void { this.fit(); }
  ngOnChanges(): void     { this.fit(); }

  private fit(): void {
    const el = this.el.nativeElement;
    el.style.fontSize = this.fitTextMax + 'px';
    el.style.whiteSpace = 'nowrap';

    let size = this.fitTextMax;
    while (el.scrollWidth > el.offsetWidth && size > this.fitTextMin) {
      size--;
      el.style.fontSize = size + 'px';
    }
  }
}
