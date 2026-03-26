export class PlayerRating {
  constructor(
    public id: string,
    public player_id: string,
    public matchday_id: string,
    public grade: number | null,
    public participation: 'starting' | 'substitute' | null,
    public goals: number,
    public assists: number,
    public clean_sheet: boolean,
    public sds: boolean,
    public red_card: boolean,
    public yellow_red_card: boolean,
    public points: number | null,
    // Embedded player fields (from GET /player_rating)
    public first_name: string | null,
    public last_name: string | null,
    public displayname: string,
    public country_id: string | null,
    public position: string | null,
    public photo_uploaded: boolean,
  ) {}

  static from(data: any): PlayerRating {
    return new PlayerRating(
      data.id,
      data.player_id,
      data.matchday_id,
      data.grade !== null ? Number(data.grade) : null,
      data.participation ?? null,
      Number(data.goals ?? 0),
      Number(data.assists ?? 0),
      !!data.clean_sheet,
      !!data.sds,
      !!data.red_card,
      !!data.yellow_red_card,
      data.points !== null && data.points !== undefined ? Number(data.points) : null,
      data.first_name ?? null,
      data.last_name ?? null,
      data.displayname ?? '',
      data.country_id ?? null,
      data.position ?? null,
      !!data.photo_uploaded,
    );
  }
}
