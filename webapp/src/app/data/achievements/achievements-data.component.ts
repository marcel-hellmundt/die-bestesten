import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { catchError, of } from 'rxjs';
import { ApiService } from '../../core/api.service';

interface AchievementManager {
  id: string;
  manager_name: string;
  earned_at: string | null;
}

interface AchievementAdmin {
  id: string;
  condition_key: string;
  name: string;
  description: string;
  icon: string | null;
  sort_index: number;
  earned_count: number;
  total_managers: number;
  managers: AchievementManager[];
}

@Component({
  selector: 'app-achievements-data',
  standalone: false,
  templateUrl: './achievements-data.component.html',
  styleUrl: './achievements-data.component.scss'
})
export class AchievementsDataComponent {
  private api = inject(ApiService);

  achievements = toSignal(
    this.api.get<AchievementAdmin[]>('achievement?all=true').pipe(
      catchError(() => of([] as AchievementAdmin[]))
    ),
    { initialValue: [] as AchievementAdmin[] }
  );

  analyseState = signal<'idle' | 'loading' | 'success' | 'error'>('idle');

  earnedPercent(a: AchievementAdmin): number {
    if (!a.total_managers) return 0;
    return Math.round((a.earned_count / a.total_managers) * 100);
  }

  managerPhotoUrl(id: string): string {
    return `https://img.die-bestesten.de/img/manager/${id}.jpg`;
  }

  failedImageIds = signal<Set<string>>(new Set());

  onImageError(id: string): void {
    this.failedImageIds.update(s => new Set([...s, id]));
  }

  analyse(): void {
    if (this.analyseState() === 'loading') return;
    this.analyseState.set('loading');
    this.api.post<{ status: boolean }>('achievement/evaluate').subscribe({
      next: () => this.analyseState.set('success'),
      error: () => this.analyseState.set('error'),
    });
  }
}
