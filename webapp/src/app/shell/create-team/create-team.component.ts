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
import {
  Subject,
  Subscription,
  debounceTime,
  distinctUntilChanged,
  switchMap,
  of,
  forkJoin,
  catchError,
} from 'rxjs';
import { Router } from '@angular/router';
import { ApiService } from '../../core/api.service';
import { BottomSheetService } from '../../core/bottom-sheet.service';
import { DataCacheService } from '../../core/data-cache.service';

const COLOR_COMBOS: Record<string, string[]> = {
  red: ['white', 'black', 'blue', 'yellow'],
  blue: ['white', 'black', 'yellow', 'red'],
  green: ['white', 'black', 'yellow'],
  yellow: ['black', 'blue'],
  violet: ['white'],
  orange: ['white', 'black'],
  black: ['white'],
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
  private router = inject(Router);

  @ViewChild('logoInput') logoInput!: ElementRef<HTMLInputElement>;

  colors = signal<{ name: string; hex: string }[]>([]);
  teamName = signal('');
  nameStatus = signal<'idle' | 'checking' | 'valid' | 'invalid'>('idle');
  color = signal('red');
  secondaryColor = signal('white');
  logoFile = signal<File | null>(null);
  logoPreview = signal<string | null>(null);
  previousTeam = signal<{ id: string; season_id: string; color: string | null } | null>(null);
  submitState = signal<'idle' | 'loading' | 'error'>('idle');
  errorMsg = signal<string | null>(null);

  private nameCheck$ = new Subject<string>();
  private nameSub!: Subscription;

  nameToHex = computed(() => Object.fromEntries(this.colors().map((c) => [c.name, c.hex])));

  primaryPalette = computed(() => {
    const order = Object.keys(COLOR_COMBOS);
    return this.colors()
      .filter((c) => order.includes(c.name))
      .sort((a, b) => order.indexOf(a.name) - order.indexOf(b.name));
  });

  secondaryOptions = computed(() => {
    const allowed = COLOR_COMBOS[this.color()] ?? this.colors().map((c) => c.name);
    const colorMap = Object.fromEntries(this.colors().map((c) => [c.name, c]));
    return allowed.filter((name) => colorMap[name]).map((name) => colorMap[name]);
  });

  effectiveSecondary = computed((): string => {
    const opts = this.secondaryOptions();
    const cur = this.secondaryColor();
    return opts.some((c) => c.name === cur) ? cur : (opts[0]?.name ?? cur);
  });

  previousLogoUrl = computed(() => {
    const prev = this.previousTeam();
    return prev ? `https://img.die-bestesten.de/img/team/${prev.season_id}/${prev.id}.png` : null;
  });

  displayedLogo = computed(() => this.logoPreview() ?? this.previousLogoUrl());

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
          ).pipe(
            catchError(() => { this.nameStatus.set('idle'); return of(null); })
          );
        }),
      )
      .subscribe(result => {
        if (result === null) return;
        this.nameStatus.set(result.available ? 'valid' : 'invalid');
      });

    forkJoin({
      colors: this.api.get<{ name: string; hex: string }[]>('color'),
      prev: this.api.get<any>('team/previous').pipe(catchError(() => of(null))),
    }).subscribe({
      next: ({ colors, prev }) => {
        this.colors.set(colors);
        const hexToName = Object.fromEntries(colors.map((c) => [c.hex, c.name]));

        if (prev?.team_name) {
          this.teamName.set(prev.team_name);
          this.nameCheck$.next(prev.team_name);
        }
        if (prev?.color) {
          const name = hexToName[prev.color];
          if (name && name in COLOR_COMBOS) this.color.set(name);
        }
        if (prev?.color_secondary) {
          const name = hexToName[prev.color_secondary];
          if (name) this.secondaryColor.set(name);
        }
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
      this.api.uploadTeamLogo(team.id, newFile).subscribe({
        next: () => this.finalize(),
        error: () => this.finalize(),
      });
      return;
    }

    const prev = this.previousTeam();
    if (prev) {
      this.api.takeoverTeamLogo(team.id).subscribe({
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
    this.router.navigate(['/liga/teams']);
  }
}
