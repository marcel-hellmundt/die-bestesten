export class Transferwindow {
  constructor(
    public id: string,
    public matchday_id: string,
    public start_date: string,
    public end_date: string,
    public offer_count: number = 0,
  ) {}

  static from(data: any): Transferwindow {
    return new Transferwindow(
      data.id,
      data.matchday_id,
      data.start_date,
      data.end_date,
      Number(data.offer_count ?? 0),
    );
  }
}
