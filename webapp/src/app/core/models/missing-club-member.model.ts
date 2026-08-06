export class MissingClubMember {
  constructor(
    public player_id: string,
    public player_in_club_id: string,
    public displayname: string,
    public club_id: string,
    public club_name: string,
    public club_logo_uploaded: boolean
  ) {}

  get clubLogoUrl(): string | null {
    return this.club_logo_uploaded ? `https://img.die-bestesten.de/club/${this.club_id}.png` : null;
  }

  static from(data: any): MissingClubMember {
    return new MissingClubMember(
      data.player_id,
      data.player_in_club_id,
      data.displayname,
      data.club_id,
      data.club_name,
      !!data.club_logo_uploaded
    );
  }
}
