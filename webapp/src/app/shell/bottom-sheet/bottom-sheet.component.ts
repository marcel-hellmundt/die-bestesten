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
}
