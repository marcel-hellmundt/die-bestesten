import { Component, computed, inject } from '@angular/core';
import { AuthService } from '../auth/auth.service';

@Component({
  selector: 'app-data',
  standalone: false,
  templateUrl: './data.component.html',
  styleUrl: './data.component.scss'
})
export class DataComponent {
  private auth = inject(AuthService);
  isMaintainer = computed(() => this.auth.isMaintainer());
}
