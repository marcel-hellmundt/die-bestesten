export class Matchday {
  constructor(
    public id: string,
    public season_id: string,
    public number: number,
    public start_date: string,
    public kickoff_date: string
  ) {}

  static from(data: any): Matchday {
    return new Matchday(
      data.id,
      data.season_id,
      data.number,
      data.start_date,
      data.kickoff_date
    );
  }
}
