export interface StadiumClub {
  id: string;
  name: string;
  logo_uploaded: boolean;
}

export class StadiumMapEntry {
  constructor(
    public id: string,
    public official_name: string,
    public name: string | null,
    public capacity: number | null,
    public lat: number | null,
    public lng: number | null,
    public opened_date: string | null,
    public club: StadiumClub | null
  ) {}

  get displayName(): string {
    return this.name ?? this.official_name;
  }

  static from(data: any): StadiumMapEntry {
    return new StadiumMapEntry(
      data.id,
      data.official_name,
      data.name ?? null,
      data.capacity ?? null,
      data.lat ?? null,
      data.lng ?? null,
      data.opened_date ?? null,
      data.club ?? null
    );
  }
}
