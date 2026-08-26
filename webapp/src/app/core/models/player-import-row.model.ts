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
    public club_missing: boolean,
    public division_mismatch: boolean,
    public price_too_high: boolean,
    public duplicate_candidate_player_id: string | null,
    public duplicate_candidate_kicker_id: number | null,
    public country_id: string | null,
    public birth_city: string | null,
    public date_of_birth: string | null,
    public height_cm: number | null,
    public weight_kg: number | null,
    public has_current_photo: boolean
  ) {}

  get isMatched(): boolean {
    return this.matched_player_id !== null;
  }

  get hasMasterData(): boolean {
    return !!this.country_id && !!this.birth_city && !!this.date_of_birth && !!this.height_cm && !!this.weight_kg;
  }

  /** Was für "komplett fertig" noch fehlt — leer, wenn Stammdaten + Foto vollständig sind. */
  get missingItems(): string[] {
    const missing: string[] = [];
    if (!this.date_of_birth) missing.push('Geburtsdatum');
    if (!this.height_cm)     missing.push('Größe');
    if (!this.weight_kg)     missing.push('Gewicht');
    if (!this.country_id)    missing.push('Nationalität');
    if (!this.birth_city)    missing.push('Geburtsort');
    if (!this.has_current_photo) missing.push('Foto');
    return missing;
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
      !!data.club_missing,
      !!data.division_mismatch,
      !!data.price_too_high,
      data.duplicate_candidate_player_id ?? null,
      data.duplicate_candidate_kicker_id ?? null,
      data.country_id ?? null,
      data.birth_city ?? null,
      data.date_of_birth ?? null,
      data.height_cm ?? null,
      data.weight_kg ?? null,
      !!data.has_current_photo
    );
  }
}
