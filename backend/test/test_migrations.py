"""Migrations create exactly the tables and columns the models define (review item M7).

Skipped until the first revision exists in alembic/versions/ (see alembic/README.md).
After that, a model change without a matching migration fails here.
"""
from pathlib import Path

import pytest

BACKEND = Path(__file__).resolve().parents[1]
VERSIONS = BACKEND / "alembic" / "versions"


def test_alembic_upgrade_head_matches_the_models(tmp_path):
    pytest.importorskip("alembic")
    if not list(VERSIONS.glob("*.py")):
        pytest.skip("first migration not generated yet: run `alembic revision --autogenerate -m 'initial schema'`")

    import importlib
    import pkgutil

    import sqlalchemy as sa
    from alembic import command
    from alembic.config import Config

    import app.models
    from app.database import Base

    for module in pkgutil.iter_modules(app.models.__path__):  # register every table on Base.metadata
        importlib.import_module(f"{app.models.__name__}.{module.name}")

    url = f"sqlite:///{tmp_path / 'migrated.db'}"
    cfg = Config(str(BACKEND / "alembic.ini"))
    cfg.set_main_option("script_location", str(BACKEND / "alembic"))
    cfg.set_main_option("sqlalchemy.url", url)  # env.py keeps a URL that is already set
    command.upgrade(cfg, "head")

    inspector = sa.inspect(sa.create_engine(url))
    migrated = set(inspector.get_table_names()) - {"alembic_version"}
    assert migrated == set(Base.metadata.tables), "tables in migrations differ from the models"
    for name, table in Base.metadata.tables.items():
        in_db = {column["name"] for column in inspector.get_columns(name)}
        assert in_db == {column.name for column in table.columns}, f"columns of {name} differ from the model"
