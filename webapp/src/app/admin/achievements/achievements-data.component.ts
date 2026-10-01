import { Component, computed, inject, signal } from '@angular/core';
import { toSignal } from '@angular/core/rxjs-interop';
import { BehaviorSubject, switchMap, catchError, of } from 'rxjs';
import { ApiService } from '../../core/api.service';

interface AchievementManager {
  id: string;
  manager_name: string;
  earned_at: string | null;
  reason: string | null;
  level: 'bronze' | 'silver' | 'gold' | null;
}

interface AchievementAdmin {
  id: string;
  condition_key: string;
  name: string;
  description: string;
  icon: string | null;
  threshold_bronze: number | null;
  threshold_silver: number | null;
  threshold_gold:   number | null;
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

  resolvedDescription(a: AchievementAdmin): string {
    if (a.threshold_bronze == null) return a.description;
    const parts = [a.threshold_bronze, a.threshold_silver, a.threshold_gold]
      .filter((v): v is number => v != null)
      .join(' | ');
    return a.description.replace('{threshold}', `[${parts}]`);
  }

  earnedPercent(a: AchievementAdmin): number {
    if (!a.total_managers) return 0;
    return Math.round((a.earned_count / a.total_managers) * 100);
  }

  managerPhotoUrl(id: string): string {
    return `https://img.die-bestesten.de/manager/${id}.jpg`;
  }

  onImageError(id: string): void {
    this.failedImageIds.update(s => new Set([...s, id]));
  }

  private readonly levelOrder: Record<string, number> = { gold: 0, silver: 1, bronze: 2 };

  groupManagers(managers: AchievementManager[]): { level: 'bronze' | 'silver' | 'gold' | null; items: AchievementManager[] }[] {
    const sorted = [...managers].sort((a, b) => {
      const la = a.level != null ? (this.levelOrder[a.level] ?? 3) : 3;
      const lb = b.level != null ? (this.levelOrder[b.level] ?? 3) : 3;
      return la - lb;
    });
    const groups: { level: 'bronze' | 'silver' | 'gold' | null; items: AchievementManager[] }[] = [];
    for (const m of sorted) {
      const last = groups.at(-1);
      if (last && last.level === m.level) {
        last.items.push(m);
      } else {
        groups.push({ level: m.level, items: [m] });
      }
    }
    return groups;
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
