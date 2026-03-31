import { Component, inject, input } from '@angular/core';
import { DomSanitizer, SafeHtml } from '@angular/platform-browser';
import { ICONS } from './icons';

@Component({
  selector: 'app-icon',
  standalone: false,
  template: `<span class="icon" [innerHTML]="svg()" aria-hidden="true"></span>`,
  styles: [`:host { display: contents; } .icon { display: inline-flex; align-items: center; }`],
})
export class IconComponent {
  name = input.required<string>();

  private sanitizer = inject(DomSanitizer);

  svg(): SafeHtml {
    const raw = ICONS[this.name()];
    return raw ? this.sanitizer.bypassSecurityTrustHtml(raw) : '';
  }
}
