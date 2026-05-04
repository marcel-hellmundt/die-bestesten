import { Component, computed, inject } from '@angular/core';
import { catchError, of } from 'rxjs';
import { toSignal } from '@angular/core/rxjs-interop';
import { ApiService } from '../core/api.service';

export interface Achievement {
  id:               string;
  name:             string;
  description:      string;
  icon:             string | null;
  type:             string | null;
  threshold_bronze: number | null;
  threshold_silver: number | null;
  threshold_gold:   number | null;
  earned_at:        string | null;
  reason:           string | null;
  seen_at:          string | null;
  level:            'bronze' | 'silver' | 'gold' | null;
  earned_count:     number;
  total_managers:   number;
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

  rarity(a: Achievement): 'häufig' | 'ungewöhnlich' | 'selten' {
    if (!a.total_managers) return 'selten';
    const ratio = a.earned_count / a.total_managers;
    if (ratio >= 0.5) return 'häufig';
    if (ratio >= 0.2) return 'ungewöhnlich';
    return 'selten';
  }

  rarityKey(a: Achievement): string {
    return this.rarity(a).replace('ä', 'a').replace('ö', 'o').replace('ü', 'u');
  }

  resolvedDescription(a: Achievement): string {
    const threshold = a.level === 'bronze' ? a.threshold_bronze
                    : a.level === 'silver' ? a.threshold_silver
                    : a.threshold_gold;
    return threshold != null
      ? a.description.replace('{threshold}', threshold.toString())
      : a.description;
  }
}
