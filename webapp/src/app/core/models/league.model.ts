export class League {
  constructor(
    public id: string,
    public slug: string,
    public name: string,
    public db_name: string,
    public manager_count: number,
  ) {}

  static from(data: any): League {
    return new League(
      data.id,
      data.slug,
      data.name,
      data.db_name,
      data.manager_count ?? 0,
    );
  }
}
