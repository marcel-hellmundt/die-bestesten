export class Team {
  constructor(
    public id: string,
    public season_id: string,
    public team_name: string,
    public color: string | null,
    public total_points: number,
    public matchdays_played: number,
    public season_placement: number | null,
    public season_team_count: number | null
  ) {}

  get logoUrl(): string {
    return `https://img.die-bestesten.de/img/team/${this.season_id}/${this.id}.png`;
  }

  static from(data: any): Team {
    return new Team(
      data.id,
      data.season_id,
      data.team_name,
      data.color ?? null,
      Number(data.total_points),
      Number(data.matchdays_played),
      data.season_placement != null ? Number(data.season_placement) : null,
      data.season_team_count != null ? Number(data.season_team_count) : null
    );
  }
}
