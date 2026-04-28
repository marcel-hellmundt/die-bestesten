import { Component, computed, inject } from '@angular/core';
import { catchError, of } from 'rxjs';
import { toSignal } from '@angular/core/rxjs-interop';
import { ApiService } from '../core/api.service';

interface Achievement {
  id:              string;
  name:            string;
  description:     string;
  icon:            string | null;
  earned_at:       string | null;
  earned_count:    number;
  total_managers:  number;
}

@Component({
  selector: 'app-achievements',
  standalone: false,
  templateUrl: './achievements.component.html',
  styleUrl: './achievements.component.scss',
})
export class AchievementsComponent {
  private api = inject(ApiService);

  achievements = toSignal(
    this.api.get<Achievement[]>('achievement').pipe(catchError(() => of([] as Achievement[]))),
    { initialValue: [] as Achievement[] }
  );

  earnedCount = computed(() => this.achievements().filter(a => a.earned_at).length);
}
