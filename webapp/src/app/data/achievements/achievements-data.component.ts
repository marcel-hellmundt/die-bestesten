import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { BehaviorSubject, switchMap, catchError, of } from 'rxjs';
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

  private reload$ = new BehaviorSubject<void>(undefined);

  achievements = toSignal(
    this.reload$.pipe(
      switchMap(() =>
        this.api.get<AchievementAdmin[]>('achievement?all=true').pipe(
          catchError(() => of([] as AchievementAdmin[]))
        )
      )
    ),
    { initialValue: [] as AchievementAdmin[] }
  );

  analyseAllState = signal<'idle' | 'loading' | 'success' | 'error'>('idle');
  loadingIds      = signal<Set<string>>(new Set());

  failedImageIds = signal<Set<string>>(new Set());

  earnedPercent(a: AchievementAdmin): number {
    if (!a.total_managers) return 0;
    return Math.round((a.earned_count / a.total_managers) * 100);
  }

  managerPhotoUrl(id: string): string {
    return `https://img.die-bestesten.de/img/manager/${id}.jpg`;
  }

  onImageError(id: string): void {
    this.failedImageIds.update(s => new Set([...s, id]));
  }

  analyseAll(): void {
    if (this.analyseAllState() === 'loading') return;
    this.analyseAllState.set('loading');
    this.api.post<{ status: boolean }>('achievement/evaluate').subscribe({
      next: () => {
        this.analyseAllState.set('success');
        this.reload$.next();
      },
      error: () => this.analyseAllState.set('error'),
    });
  }

  analyseOne(id: string): void {
    if (this.loadingIds().has(id)) return;
    this.loadingIds.update(s => new Set([...s, id]));
    this.api.post<{ status: boolean }>(`achievement/evaluate/${id}`).subscribe({
      next: () => {
        this.loadingIds.update(s => { const n = new Set(s); n.delete(id); return n; });
        this.reload$.next();
      },
      error: () => {
        this.loadingIds.update(s => { const n = new Set(s); n.delete(id); return n; });
      },
    });
  }
}
