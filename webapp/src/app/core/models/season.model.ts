export class Season {
  constructor(
    public id: string,
    public start_date: string
  ) {}

  get displayName(): string {
    const year = parseInt(this.start_date.substring(0, 4), 10);
    const y1 = String(year % 100).padStart(2, '0');
    const y2 = String((year + 1) % 100).padStart(2, '0');
    return `${y1}/${y2}`;
  }

  get longDisplayName(): string {
    const year = parseInt(this.start_date.substring(0, 4), 10);
    const y2 = String((year + 1) % 100).padStart(2, '0');
    return `${year}/${y2}`;
  }

  static from(data: any): Season {
    return new Season(data.id, data.start_date);
  }
}
