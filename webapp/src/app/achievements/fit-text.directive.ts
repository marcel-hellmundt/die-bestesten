import { AfterViewInit, Directive, ElementRef, Input, OnChanges } from '@angular/core';

@Directive({ selector: '[fitText]', standalone: false })
export class FitTextDirective implements AfterViewInit, OnChanges {
  @Input() fitTextMin = 10;
  @Input() fitTextMax = 16;

  constructor(private el: ElementRef<HTMLElement>) {}

  ngAfterViewInit(): void { requestAnimationFrame(() => this.fit()); }
  ngOnChanges(): void     { requestAnimationFrame(() => this.fit()); }

  private fit(): void {
    const el     = this.el.nativeElement;
    const parent = el.parentElement;
    if (!parent) return;

    el.style.whiteSpace = 'nowrap';

    // Available width = parent width minus all siblings' widths
    let siblingsWidth = 0;
    for (let i = 0; i < parent.children.length; i++) {
      const child = parent.children[i] as HTMLElement;
      if (child !== el) siblingsWidth += child.offsetWidth;
    }
    const available = parent.offsetWidth - siblingsWidth;

    let size = this.fitTextMax;
    el.style.fontSize = size + 'px';

    while (el.scrollWidth > available && size > this.fitTextMin) {
      size--;
      el.style.fontSize = size + 'px';
    }
  }
}
