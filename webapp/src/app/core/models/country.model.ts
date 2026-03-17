export class Country {
  constructor(
    public id: string,
    public name: string
  ) {}

  get flagUrl(): string {
    return `img/flags/${this.id}.svg`;
  }

  static from(data: any): Country {
    return new Country(data.id, data.name);
  }
}
