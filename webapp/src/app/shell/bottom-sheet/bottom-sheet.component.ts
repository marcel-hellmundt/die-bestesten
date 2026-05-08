import { Component, inject } from '@angular/core';
import { BottomSheetService } from '../../core/bottom-sheet.service';

@Component({
  selector: 'app-bottom-sheet',
  standalone: false,
  templateUrl: './bottom-sheet.component.html',
  styleUrl: './bottom-sheet.component.scss',
})
export class BottomSheetComponent {
  service = inject(BottomSheetService);

  private _dragStartY = 0;
  private _dragging = false;

  onHandleTouchStart(e: TouchEvent): void {
    this._dragStartY = e.touches[0].clientY;
    this._dragging = true;
  }

  onHandleTouchMove(e: TouchEvent): void {
    if (!this._dragging) return;
    if (e.touches[0].clientY - this._dragStartY > 60) {
      this._dragging = false;
      this.service.close();
    }
  }

  onHandleTouchEnd(): void {
    this._dragging = false;
  }
}
