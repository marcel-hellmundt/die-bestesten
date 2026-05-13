import { Component, ElementRef, OnInit, ViewChild, computed, inject, signal } from '@angular/core';
import { ApiService } from '../../core/api.service';
import { BottomSheetService } from '../../core/bottom-sheet.service';
import { DataCacheService } from '../../core/data-cache.service';

const PRIMARY_PALETTE = [
  '#e74c3c',
  '#3867d6',
  '#2ecc71',
  '#f1c40f',
  '#9b59b6',
  '#e67e22',
  '#1e272e',
];

const SECONDARY_PALETTE = ['#ffffff', '#1e272e', '#e74c3c', '#3867d6', '#f1c40f'];

const COLOR_COMBOS: Record<string, string[]> = {
  '#e74c3c': ['#ffffff', '#1e272e', '#f1c40f'],
  '#3867d6': ['#ffffff', '#1e272e', '#f1c40f'],
  '#2ecc71': ['#ffffff'],
  '#f1c40f': ['#1e272e'],
  '#9b59b6': ['#ffffff'],
  '#e67e22': ['#ffffff', '#1e272e'],
  '#1e272e': ['#ffffff'],
};

@Component({
  selector: 'app-create-team',
  standalone: false,
  templateUrl: './create-team.component.html',
  styleUrl: './create-team.component.scss',
})
export class CreateTeamComponent implements OnInit {
  private api = inject(ApiService);
  private cache = inject(DataCacheService);
  private bs = inject(BottomSheetService);

  @ViewChild('logoInput') logoInput!: ElementRef<HTMLInputElement>;

  teamName = signal('');
  color = signal('#e74c3c');
  secondaryColor = signal('#ffffff');
  logoFile = signal<File | null>(null);
  logoPreview = signal<string | null>(null);
  previousTeam = signal<{ id: string; season_id: string; color: string | null } | null>(null);
  submitState = signal<'idle' | 'loading' | 'error'>('idle');
  errorMsg = signal<string | null>(null);

  previousLogoUrl = computed(() => {
    const prev = this.previousTeam();
    return prev ? `https://img.die-bestesten.de/img/team/${prev.season_id}/${prev.id}.png` : null;
  });

  displayedLogo = computed(() => this.logoPreview() ?? this.previousLogoUrl());

  readonly primaryPalette = PRIMARY_PALETTE;

  secondaryOptions = computed(() =>
    SECONDARY_PALETTE.filter((c) => (COLOR_COMBOS[this.color()] ?? SECONDARY_PALETTE).includes(c)),
  );

  effectiveSecondary = computed(() => {
    const opts = this.secondaryOptions();
    const cur = this.secondaryColor();
    return opts.includes(cur) ? cur : opts[0];
  });

  ngOnInit(): void {
    this.api.get<any>('team/previous').subscribe({
      next: (prev) => {
        if (prev?.team_name) this.teamName.set(prev.team_name);
        if (prev?.color && PRIMARY_PALETTE.includes(prev.color)) this.color.set(prev.color);
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
    reader.onload = (ev) => this.logoPreview.set(ev.target?.result as string);
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

    this.api
      .post<{ status: boolean; id: string }>('team', { team_name: name, color: this.color() })
      .subscribe({
        next: () => {
          this.api.get<any>('team/mine').subscribe({
            next: (team) => this.uploadLogo(team),
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
        .then((r) => r.blob())
        .then((blob) => {
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
