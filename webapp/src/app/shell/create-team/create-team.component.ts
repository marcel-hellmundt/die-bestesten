import { Component, ElementRef, OnInit, ViewChild, inject, signal } from '@angular/core';
import { ApiService } from '../../core/api.service';
import { BottomSheetService } from '../../core/bottom-sheet.service';
import { DataCacheService } from '../../core/data-cache.service';

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

  teamName    = signal('');
  color       = signal('#bf1d00');
  logoFile    = signal<File | null>(null);
  logoPreview = signal<string | null>(null);
  submitState = signal<'idle' | 'loading' | 'error'>('idle');
  errorMsg    = signal<string | null>(null);

  readonly palette = [
    '#1abc9c', '#2ecc71', '#3498db', '#9b59b6', '#34495e',
    '#f1c40f', '#e67e22', '#e74c3c', '#95a5a6', '#bf1d00',
  ];

  ngOnInit(): void {
    this.api.get<any>('team/previous').subscribe({
      next: prev => {
        if (prev?.team_name) this.teamName.set(prev.team_name);
        if (prev?.color)     this.color.set(prev.color);
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

    this.submitState.set('loading');
    this.errorMsg.set(null);

    this.api.post<{ status: boolean; id: string }>('team', { team_name: name, color: this.color() })
      .subscribe({
        next: () => {
          const logo = this.logoFile();
          if (logo) {
            this.api.get<any>('team/mine').subscribe({
              next: team => this.api.uploadTeamLogo(team.season_id, team.id, logo).subscribe({
                next:  () => this.finalize(),
                error: () => this.finalize(),
              }),
              error: () => this.finalize(),
            });
          } else {
            this.finalize();
          }
        },
        error: (err: any) => {
          this.submitState.set('error');
          this.errorMsg.set(err?.error?.message ?? 'Fehler beim Erstellen des Teams');
        },
      });
  }

  private finalize(): void {
    this.cache.refreshMyTeam();
    this.bs.close();
  }
}
