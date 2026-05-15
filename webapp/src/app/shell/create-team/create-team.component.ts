import {
  Component,
  ElementRef,
  OnInit,
  OnDestroy,
  ViewChild,
  computed,
  inject,
  signal,
} from '@angular/core';
import { Subject, Subscription, debounceTime, distinctUntilChanged, switchMap, of } from 'rxjs';
import { ApiService } from '../../core/api.service';
import { BottomSheetService } from '../../core/bottom-sheet.service';
import { DataCacheService } from '../../core/data-cache.service';

const PRIMARY_PALETTE = [
  '#ff3f34',
  '#3867d6',
  '#20bf6b',
  '#fed330',
  '#9b59b6',
  '#f79f1f',
  '#1e272e',
];

const SECONDARY_PALETTE = ['#ffffff', '#1e272e', '#ff3f34', '#3867d6', '#fed330'];

const COLOR_COMBOS: Record<string, string[]> = {
  '#ff3f34': ['#ffffff', '#1e272e', '#3867d6', '#fed330'],
  '#3867d6': ['#ffffff', '#1e272e', '#ff3f34', '#fed330'],
  '#20bf6b': ['#ffffff'],
  '#fed330': ['#1e272e'],
  '#9b59b6': ['#ffffff'],
  '#f79f1f': ['#ffffff', '#1e272e'],
  '#1e272e': ['#ffffff'],
};

@Component({
  selector: 'app-create-team',
  standalone: false,
  templateUrl: './create-team.component.html',
  styleUrl: './create-team.component.scss',
})
export class CreateTeamComponent implements OnInit, OnDestroy {
  private api = inject(ApiService);
  private cache = inject(DataCacheService);
  private bs = inject(BottomSheetService);

  @ViewChild('logoInput') logoInput!: ElementRef<HTMLInputElement>;

  teamName = signal('');
  nameStatus = signal<'idle' | 'checking' | 'valid' | 'invalid'>('idle');
  color = signal('#ff3f34');
  secondaryColor = signal('#ffffff');
  logoFile = signal<File | null>(null);
  logoPreview = signal<string | null>(null);
  previousTeam = signal<{ id: string; season_id: string; color: string | null } | null>(null);
  submitState = signal<'idle' | 'loading' | 'error'>('idle');
  errorMsg = signal<string | null>(null);

  private nameCheck$ = new Subject<string>();
  private nameSub!: Subscription;

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
    this.nameSub = this.nameCheck$
      .pipe(
        debounceTime(400),
        distinctUntilChanged(),
        switchMap((name) => {
          if (name.length < 3) {
            this.nameStatus.set('idle');
            return of(null);
          }
          this.nameStatus.set('checking');
          return this.api.get<{ available: boolean }>(
            `team/check-name?name=${encodeURIComponent(name)}`,
          );
        }),
      )
      .subscribe({
        next: (result) => {
          if (result === null) return;
          this.nameStatus.set(result.available ? 'valid' : 'invalid');
        },
        error: () => this.nameStatus.set('idle'),
      });

    this.api.get<any>('team/previous').subscribe({
      next: (prev) => {
        if (prev?.team_name) {
          this.teamName.set(prev.team_name);
          this.nameCheck$.next(prev.team_name);
        }
        if (prev?.color && PRIMARY_PALETTE.includes(prev.color)) this.color.set(prev.color);
        if (prev?.color_secondary && SECONDARY_PALETTE.includes(prev.color_secondary))
          this.secondaryColor.set(prev.color_secondary);
        if (prev?.id && prev?.season_id) this.previousTeam.set(prev);
      },
      error: () => {},
    });
  }

  ngOnDestroy(): void {
    this.nameSub?.unsubscribe();
  }

  onNameInput(value: string): void {
    this.teamName.set(value);
    this.nameCheck$.next(value.trim());
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
    if (!name || this.nameStatus() !== 'valid') return;

    if (!this.logoFile() && !this.previousTeam()) {
      this.errorMsg.set('Bitte lade ein Logo hoch');
      return;
    }

    this.submitState.set('loading');
    this.errorMsg.set(null);

    this.api
      .post<{ status: boolean; id: string }>('team', {
        team_name: name,
        color: this.color(),
        color_secondary: this.effectiveSecondary(),
      })
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

    const prev = this.previousTeam();
    if (prev) {
      this.api.takeoverTeamLogo(team.season_id, team.id, prev.season_id, prev.id).subscribe({
        next: () => this.finalize(),
        error: () => this.finalize(),
      });
      return;
    }

    this.finalize();
  }

  private finalize(): void {
    this.cache.refreshMyTeam();
    this.bs.close();
  }
}
