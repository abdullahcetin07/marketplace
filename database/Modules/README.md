# database/Modules

Per-module migrations, factories and seeders.

```
Catalog/
  migrations/
  Factories/
  Seeders/
```

A module's `Seeders/` directory is for reference data the module needs to be
usable but that an operator then owns — `CatalogTaxonomySeeder` is the worked
example: a starting taxonomy, idempotent, and deliberately NOT registered in
`DatabaseSeeder`, because it is run once when opening the catalog rather than on
every seed.

Registered by the module's own service provider:

```php
public function boot(): void
{
    $this->loadMigrationsFrom(database_path('Modules/Store/migrations'));
}
```

Kept out of `database/migrations/` so a module's schema travels with the module
— extracting or removing one does not mean picking its migrations out of a
shared directory by date.

Factories are discovered by `Model::newFactory()` or by an explicit
`protected static function newFactory()` on the model.

See [docs/modules.md](../../docs/modules.md).
