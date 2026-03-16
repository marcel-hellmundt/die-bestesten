export class Country {
  constructor(
    public id: string,
    public name: string
  ) {}

  static from(data: any): Country {
    return new Country(data.id, data.name);
  }
}
