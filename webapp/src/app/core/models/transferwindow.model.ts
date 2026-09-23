export class Transferwindow {
  constructor(
    public id: string,
    public matchday_id: string,
    public start_date: string,
    public end_date: string,
    public offer_count: number | null = 0, // null = geheim (laufende/kommende Phase, Nicht-Admin)
  ) {}

  static from(data: any): Transferwindow {
    return new Transferwindow(
      data.id,
      data.matchday_id,
      data.start_date,
      data.end_date,
      data.offer_count === null ? null : Number(data.offer_count ?? 0),
    );
  }
}
