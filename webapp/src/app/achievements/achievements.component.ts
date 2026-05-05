import { Component, computed, inject } from '@angular/core';
import { catchError, of } from 'rxjs';
import { toSignal } from '@angular/core/rxjs-interop';
import { ApiService } from '../core/api.service';

export interface Achievement {
  id: string;
  name: string;
  description: string;
  icon: string | null;
  threshold_bronze: number | null;
  threshold_silver: number | null;
  threshold_gold: number | null;
  earned_at: string | null;
  reason: string | null;
  seen_at: string | null;
  level: 'bronze' | 'silver' | 'gold' | null;
  earned_count: number;
  total_managers: number;
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
    { initialValue: [] as Achievement[] },
  );

  constructor() {
    this.api.patch('achievement/seen', {}).subscribe();
  }

  earnedCount = computed(() => this.achievements().filter((a) => a.earned_at).length);

  earnedPercent = computed(() => {
    const total = this.achievements().length;
    return total ? Math.round((this.earnedCount() / total) * 100) : 0;
  });

  levelCounts = computed(() => {
    const earned = this.achievements().filter((a) => a.earned_at);
    return {
      bronze: earned.filter((a) => a.level === 'bronze').length,
      silver: earned.filter((a) => a.level === 'silver').length,
      gold: earned.filter((a) => a.level === 'gold' || a.level === null).length,
    };
  });

  rarity(a: Achievement): 'häufig' | 'ungewöhnlich' | 'selten' | 'super selten' {
    if (!a.total_managers || a.earned_count === 0) return 'super selten';
    const ratio = a.earned_count / a.total_managers;
    if (ratio >= 0.5) return 'häufig';
    if (ratio >= 0.2) return 'ungewöhnlich';
    return 'selten';
  }

  rarityKey(a: Achievement): string {
    return this.rarity(a).replace('ä', 'a').replace('ö', 'o').replace('ü', 'u').replace(' ', '-');
  }

  onCardMouseMove(event: MouseEvent): void {
    const card = event.currentTarget as HTMLElement;
    const rect = card.getBoundingClientRect();
    const x = event.clientX - rect.left;
    const y = event.clientY - rect.top;
    const rotateY =  ((x - rect.width  / 2) / (rect.width  / 2)) * 8;
    const rotateX = -((y - rect.height / 2) / (rect.height / 2)) * 8;
    card.style.transition = 'box-shadow 0.3s ease';
    card.style.transform = `perspective(600px) rotateX(${rotateX}deg) rotateY(${rotateY}deg) scale3d(1.04,1.04,1.04)`;
    card.style.setProperty('--shine-x', `${(x / rect.width)  * 100}%`);
    card.style.setProperty('--shine-y', `${(y / rect.height) * 100}%`);
    card.style.zIndex = '1';
  }

  onCardMouseLeave(event: MouseEvent): void {
    const card = event.currentTarget as HTMLElement;
    card.style.transition = 'transform 0.5s cubic-bezier(0.23,1,0.32,1), box-shadow 0.3s ease';
    card.style.transform = '';
    card.style.zIndex = '';
  }

  resolvedDescription(a: Achievement): string {
    const threshold =
      a.level === 'bronze'
        ? a.threshold_bronze
        : a.level === 'silver'
          ? a.threshold_silver
          : a.threshold_gold;
    return threshold != null
      ? a.description.replace('{threshold}', threshold.toString())
      : a.description;
  }
}
