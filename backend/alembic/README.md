# Database migrations (Alembic)

Alembic creates and updates the database tables from the SQLAlchemy models, one
reviewed step at a time, so every machine and the server get the same schema.
Nothing else creates tables (there is no `create_all` in the app).

Run every command from the `backend/` folder, with the virtual environment active
and `DATABASE_URL` set in `.env`.

```bash
alembic upgrade head          # create / update the tables to the newest version
alembic current               # which version this database is on
alembic history               # all versions, newest first
alembic downgrade -1          # undo the last version (careful on real data)
alembic upgrade head --sql    # print the SQL instead of running it
```

## Change the schema (add or change a model)

1. Edit the model in `app/models/`.
2. `alembic revision --autogenerate -m "short description"`
3. **Open the new file in `alembic/versions/` and read it.** Autogenerate misses
   some things (renamed columns look like drop + add; check constraints, enums).
4. `alembic upgrade head` on your local database, then run `pytest`.
5. Commit the model change and the migration file in the same PR.

Never edit a migration that is already merged: add a new one.
Migration files have one owner (Rule 4 in `docs/release-plan.md`), so two people never create
competing versions from the same starting point.

## The first migration

`alembic/versions/` is empty on purpose: the first version is generated **after the
`users` table fix (review item C5) is merged**, so it describes the final tables:

```bash
grep -n "^## \|^# " backend/alembic/README.md
git diff backend/alembic/README.md
```

Until that file exists, `alembic upgrade head` succeeds but creates **no tables**.
`test_migrations.py` is skipped until then and afterwards checks that the
migrations create exactly the tables and columns the models define.

## Things to know

- `env.py` imports every module in `app/models/`, so a new model file is picked up
  automatically. A model that is never imported has no table.
- MySQL limits names to 64 characters. The naming convention in `app/database.py`
  builds constraint names; if `alembic upgrade head` fails with "Identifier name ... is too
  long", shorten the table or column name or give that constraint an explicit `name=`.
- On SQLite (local tests) `env.py` uses batch mode, because SQLite cannot alter tables in place.
- Needs `alembic` in `requirements.txt` (see the PR description).
