export class PlayerImportRow {
  constructor(
    public kicker_id: number,
    public csv_first_name: string,
    public csv_last_name: string,
    public csv_displayname: string,
    public csv_club_name: string,
    public csv_position: string | null,
    public csv_price: number | null,
    public matched_player_id: string | null,
    public matched_displayname: string | null,
    public matched_club_id: string | null,
    public club_logo_uploaded: boolean,
    public already_in_season: boolean,
    public importable: boolean,
    public existing_player_in_season_id: string | null,
    public existing_position: string | null,
    public existing_price: number | null,
    public position_price_mismatch: boolean,
    public current_player_in_club_id: string | null,
    public current_club_id: string | null,
    public current_club_name: string | null,
    public current_club_logo_uploaded: boolean,
    public club_mismatch: boolean,
    public club_confirmed: boolean,
    public club_unresolved: boolean,
    public division_mismatch: boolean,
    public duplicate_candidate_player_id: string | null,
    public duplicate_candidate_kicker_id: number | null
  ) {}

  get isMatched(): boolean {
    return this.matched_player_id !== null;
  }

  get hasDuplicateCandidate(): boolean {
    return this.duplicate_candidate_player_id !== null;
  }

  get clubLogoUrl(): string | null {
    return this.matched_club_id && this.club_logo_uploaded
      ? `https://img.die-bestesten.de/club/${this.matched_club_id}.png`
      : null;
  }

  get currentClubLogoUrl(): string | null {
    return this.current_club_id && this.current_club_logo_uploaded
      ? `https://img.die-bestesten.de/club/${this.current_club_id}.png`
      : null;
  }

  static from(data: any): PlayerImportRow {
    return new PlayerImportRow(
      data.kicker_id,
      data.csv_first_name,
      data.csv_last_name,
      data.csv_displayname,
      data.csv_club_name,
      data.csv_position ?? null,
      data.csv_price ?? null,
      data.matched_player_id ?? null,
      data.matched_displayname ?? null,
      data.matched_club_id ?? null,
      !!data.club_logo_uploaded,
      !!data.already_in_season,
      !!data.importable,
      data.existing_player_in_season_id ?? null,
      data.existing_position ?? null,
      data.existing_price ?? null,
      !!data.position_price_mismatch,
      data.current_player_in_club_id ?? null,
      data.current_club_id ?? null,
      data.current_club_name ?? null,
      !!data.current_club_logo_uploaded,
      !!data.club_mismatch,
      !!data.club_confirmed,
      !!data.club_unresolved,
      !!data.division_mismatch,
      data.duplicate_candidate_player_id ?? null,
      data.duplicate_candidate_kicker_id ?? null
    );
  }
}
