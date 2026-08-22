export class PowerrankingPick {
  constructor(
    public team_id: string,
    public position: number,
    public actual_position: number | null = null,
    public deviation: number | null = null,
  ) {}

  static from(data: any): PowerrankingPick {
    return new PowerrankingPick(
      data.team_id,
      Number(data.position ?? data.predicted_position),
      data.actual_position != null ? Number(data.actual_position) : null,
      data.deviation != null ? Number(data.deviation) : null,
    );
  }
}
