export class Club {
  constructor(
    public id: string,
    public country_id: string,
    public name: string,
    public short_name: string | null,
    public logo_uploaded: boolean
  ) {}

  static from(data: any): Club {
    return new Club(
      data.id,
      data.country_id,
      data.name,
      data.short_name ?? null,
      !!data.logo_uploaded
    );
  }
}
