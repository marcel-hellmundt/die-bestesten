export class Club {
  constructor(
    public id: string,
    public country_id: string,
    public name: string,
    public short_name: string | null,
    public logo_uploaded: boolean
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
