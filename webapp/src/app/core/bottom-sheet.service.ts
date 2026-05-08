import { Injectable, TemplateRef, computed, signal } from '@angular/core';

export interface BottomSheetConfig {
  title?: string;
}

interface BottomSheetState {
  template: TemplateRef<any>;
  config: BottomSheetConfig;
}

@Injectable({ providedIn: 'root' })
export class BottomSheetService {
  private _state = signal<BottomSheetState | null>(null);
  readonly current = this._state.asReadonly();
  readonly isOpen  = computed(() => this._state() !== null);

  open(template: TemplateRef<any>, config: BottomSheetConfig = {}): void {
    this._state.set({ template, config });
    document.body.style.overflow = 'hidden';
  }

  close(): void {
    this._state.set(null);
    document.body.style.overflow = '';
  }
}
