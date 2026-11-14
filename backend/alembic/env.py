"""Alembic environment: the schema comes from the SQLAlchemy models, the URL from app settings."""
import importlib
import pkgutil
from logging.config import fileConfig

from alembic import context
from sqlalchemy import engine_from_config, pool

import app.models
from app.config import settings
from app.db_url import prepare_db_url
from app.database import Base

config = context.config
if config.config_file_name is not None:
    fileConfig(config.config_file_name)

# app/models/__init__.py only lists some models, so import EVERY module in the package:
# otherwise autogenerate would not see the tables of the modules that are left out.
for _module in pkgutil.iter_modules(app.models.__path__):
    importlib.import_module(f"{app.models.__name__}.{_module.name}")

target_metadata = Base.metadata

# A URL that was set on the Config before running (the migration test does this) wins;
# otherwise use DATABASE_URL from settings. "%" is doubled because ConfigParser
# treats it as interpolation and database passwords may contain it.
if not config.get_main_option("sqlalchemy.url"):
    config.set_main_option("sqlalchemy.url", settings.DATABASE_URL.replace("%", "%%"))

# Same URL clean-up and TLS settings as the app (app/db_url.py), so a pasted
# Aiven URL works for migrations too.
_db_url, _connect_args = prepare_db_url(config.get_main_option("sqlalchemy.url"), settings.DB_SSL_CA)
_url = _db_url.render_as_string(hide_password=False)
config.set_main_option("sqlalchemy.url", _url.replace("%", "%%"))
# SQLite cannot ALTER most things in place; "batch" mode makes it copy the table instead.
_COMMON = {"target_metadata": target_metadata, "compare_type": True, "render_as_batch": _url.startswith("sqlite")}


def run_migrations_offline() -> None:
    """Write the SQL to stdout instead of running it (alembic upgrade head --sql)."""
    context.configure(url=_url, literal_binds=True, dialect_opts={"paramstyle": "named"}, **_COMMON)
    with context.begin_transaction():
        context.run_migrations()


def run_migrations_online() -> None:
    connectable = engine_from_config(
        config.get_section(config.config_ini_section, {}), prefix="sqlalchemy.", poolclass=pool.NullPool,
        connect_args=_connect_args,
    )
    with connectable.connect() as connection:
        context.configure(connection=connection, **_COMMON)
        with context.begin_transaction():
            context.run_migrations()


if context.is_offline_mode():
    run_migrations_offline()
else:
    run_migrations_online()
