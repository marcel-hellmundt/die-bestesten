export class Transferwindow {
  constructor(
    public id: string,
    public matchday_id: string,
    public start_date: string,
    public end_date: string,
  ) {}

  static from(data: any): Transferwindow {
    return new Transferwindow(
      data.id,
      data.matchday_id,
      data.start_date,
      data.end_date,
    );
  }
}
