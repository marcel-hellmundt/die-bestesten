export class Player {
  constructor(
    public id: string,
    public country_id: string | null,
    public first_name: string | null,
    public last_name: string | null,
    public displayname: string,
    public birth_city: string | null,
    public date_of_birth: string | null,
    public height_cm: number | null,
    public weight_kg: number | null,
    public total_points: number,
  ) {}

  get flagUrl(): string {
    return `img/flags/${this.country_id}.svg`;
  }

  static from(data: any): Player {
    return new Player(
      data.id,
      data.country_id ?? null,
      data.first_name ?? null,
      data.last_name ?? null,
      data.displayname,
      data.birth_city ?? null,
      data.date_of_birth ?? null,
      data.height_cm ?? null,
      data.weight_kg ?? null,
      data.total_points ?? 0,
    );
  }
}
