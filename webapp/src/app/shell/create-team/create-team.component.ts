import { Component, ElementRef, OnInit, ViewChild, computed, inject, signal } from '@angular/core';
import { ApiService } from '../../core/api.service';
import { BottomSheetService } from '../../core/bottom-sheet.service';
import { DataCacheService } from '../../core/data-cache.service';

const BASE_PALETTE = [
  '#e84118', '#0652dd', '#05c46b', '#ffd32a', '#1e272e', '#d2dae2',
];

@Component({
  selector: 'app-create-team',
  standalone: false,
  templateUrl: './create-team.component.html',
  styleUrl: './create-team.component.scss',
})
export class CreateTeamComponent implements OnInit {
  private api   = inject(ApiService);
  private cache = inject(DataCacheService);
  private bs    = inject(BottomSheetService);

  @ViewChild('logoInput') logoInput!: ElementRef<HTMLInputElement>;

  teamName        = signal('');
  color           = signal('#bf1d00');
  logoFile        = signal<File | null>(null);
  logoPreview     = signal<string | null>(null);
  previousTeam    = signal<{ id: string; season_id: string; color: string | null } | null>(null);
  submitState     = signal<'idle' | 'loading' | 'error'>('idle');
  errorMsg        = signal<string | null>(null);

  previousLogoUrl = computed(() => {
    const prev = this.previousTeam();
    return prev ? `https://img.die-bestesten.de/img/team/${prev.season_id}/${prev.id}.png` : null;
  });

  displayedLogo = computed(() => this.logoPreview() ?? this.previousLogoUrl());

  palette = computed(() => {
    const prevColor = this.previousTeam()?.color;
    if (!prevColor) return BASE_PALETTE;
    return [prevColor, ...BASE_PALETTE.filter(c => c !== prevColor)];
  });

  ngOnInit(): void {
    this.api.get<any>('team/previous').subscribe({
      next: prev => {
        if (prev?.team_name) this.teamName.set(prev.team_name);
        if (prev?.color)     this.color.set(prev.color);
        if (prev?.id && prev?.season_id) this.previousTeam.set(prev);
      },
      error: () => {},
    });
  }

  onLogoClick(): void {
    this.logoInput.nativeElement.click();
  }

  onLogoFileSelected(e: Event): void {
    const file = (e.target as HTMLInputElement).files?.[0];
    if (!file) return;
    this.logoFile.set(file);
    const reader = new FileReader();
    reader.onload = ev => this.logoPreview.set(ev.target?.result as string);
    reader.readAsDataURL(file);
    (e.target as HTMLInputElement).value = '';
  }

  submit(): void {
    const name = this.teamName().trim();
    if (!name) return;

    if (!this.logoFile() && !this.previousTeam()) {
      this.errorMsg.set('Bitte lade ein Logo hoch');
      return;
    }

    this.submitState.set('loading');
    this.errorMsg.set(null);

    this.api.post<{ status: boolean; id: string }>('team', { team_name: name, color: this.color() })
      .subscribe({
        next: () => {
          this.api.get<any>('team/mine').subscribe({
            next: team => this.uploadLogo(team),
            error: () => this.finalize(),
          });
        },
        error: (err: any) => {
          this.submitState.set('error');
          this.errorMsg.set(err?.error?.message ?? 'Fehler beim Erstellen des Teams');
        },
      });
  }

  private uploadLogo(team: { id: string; season_id: string }): void {
    const newFile = this.logoFile();
    if (newFile) {
      this.api.uploadTeamLogo(team.season_id, team.id, newFile).subscribe({
        next: () => this.finalize(),
        error: () => this.finalize(),
      });
      return;
    }

    const oldUrl = this.previousLogoUrl();
    if (oldUrl) {
      fetch(oldUrl)
        .then(r => r.blob())
        .then(blob => {
          const file = new File([blob], 'logo.png', { type: blob.type || 'image/png' });
          this.api.uploadTeamLogo(team.season_id, team.id, file).subscribe({
            next: () => this.finalize(),
            error: () => this.finalize(),
          });
        })
        .catch(() => this.finalize());
      return;
    }

    this.finalize();
  }

  private finalize(): void {
    this.cache.refreshMyTeam();
    this.bs.close();
  }
}
