export interface Stadium {
  id: string;
  official_name: string;
  name: string | null;
  capacity: number | null;
  lat: number | null;
  lng: number | null;
  opened_date: string | null;
}

export class Club {
  constructor(
    public id: string,
    public country_id: string,
    public name: string,
    public short_name: string | null,
    public logo_uploaded: boolean,
    public stadium: Stadium | null = null
  ) {}

  get logoUrl(): string {
    return this.logo_uploaded
      ? `https://img.die-bestesten.de/img/club/${this.id}.png`
      : 'img/placeholders/club.png';
  }

  get logoClass(): string {
    return this.logo_uploaded ? 'logo' : 'logo logo--placeholder';
  }

  get flagUrl(): string {
    return `img/flags/${this.country_id}.svg`;
  }

  get stadiumDisplayName(): string | null {
    return this.stadium?.name ?? this.stadium?.official_name ?? null;
  }

  static from(data: any): Club {
    return new Club(
      data.id,
      data.country_id,
      data.name,
      data.short_name ?? null,
      !!data.logo_uploaded,
      data.stadium ?? null
    );
  }
}
