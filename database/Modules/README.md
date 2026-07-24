# database/Modules

Per-module migrations and factories. **Empty until Sprint 1.**

```
Store/
  migrations/
  factories/
```

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
