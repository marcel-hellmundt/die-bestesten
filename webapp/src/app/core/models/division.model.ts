export class Division {
  constructor(
    public id: string,
    public name: string,
    public level: number,
    public seats: number,
    public country_id: string
  ) {}

  get flagUrl(): string {
    return `img/flags/${this.country_id}.svg`;
  }

  static from(data: any): Division {
    return new Division(
      data.id,
      data.name,
      data.level,
      data.seats,
      data.country_id
    );
  }
}
