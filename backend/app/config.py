"""Application settings, read once from environment variables / backend/.env.

Why pydantic-settings instead of os.getenv() scattered through the code:
  * One place lists every setting the app needs, with its type. A typo in a
    variable name or a non-numeric ACCESS_TOKEN_EXPIRE_MINUTES is caught at
    startup, not halfway through a request.
  * Required settings have NO default, so a missing secret stops the app
    immediately with a clear "field required" error, instead of the app quietly
    running with a weak placeholder (the classic "secret=changeme" leak).

Every other module imports the single `settings` object below:
    from app.config import settings
"""
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    # Where values come from, in priority order:
    #   1. real environment variables (what Render / CI set)
    #   2. backend/.env (what developers use locally; it is git-ignored)
    # extra="ignore" lets .env hold variables meant for other tools without
    # crashing the app.
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    # --- Database ---------------------------------------------------------
    # Required on purpose. A default (e.g. a local SQLite file) would let a
    # deploy that forgot DATABASE_URL "work" on a throwaway disk and lose all
    # data on the next restart. Failing at startup is the safer outcome.
    # Example: mysql+pymysql://user:pass@host:3306/timetable
    DATABASE_URL: str
    # Optional path to the database provider's CA certificate (Aiven: "CA
    # certificate" on the service page; on Render, upload it as a Secret File
    # and set this to /etc/secrets/<file name>). See app/db_url.py.
    DB_SSL_CA: str | None = None

    # --- Auth / JWT -------------------------------------------------------
    # Required, no default: anyone who knows the key can forge a token for any
    # user, so it must come from the environment and never live in the repo.
    JWT_SECRET_KEY: str
    JWT_ALGORITHM: str = "HS256"
    # Short-lived tokens limit how long a stolen token stays useful. (Role
    # changes take effect immediately anyway, because get_current_user reloads
    # the user from the database on every request.)
    ACCESS_TOKEN_EXPIRE_MINUTES: int = 60

    # --- File uploads -----------------------------------------------------
    # Defaults match what utils/file_storage.py already falls back to.
    UPLOAD_DIR: str = "uploads"
    MAX_UPLOAD_MB: int = 20

    # --- CORS -------------------------------------------------------------
    # Kept as a plain comma-separated string. pydantic-settings 2.5 tries to
    # JSON-decode list fields read from the environment, so a list[str] field
    # would reject "http://a,http://b". Use the `cors_origins` property below.
    # Default = common local static-server addresses for the frontend.
    CORS_ORIGINS: str = "http://localhost:5500,http://127.0.0.1:5500"

    @property
    def cors_origins(self) -> list[str]:
        """CORS_ORIGINS split into a clean list, ignoring blanks and spaces."""
        return [origin.strip() for origin in self.CORS_ORIGINS.split(",") if origin.strip()]


# Created at import time so a missing required variable fails the moment the
# app (or a test) starts, not on the first request that happens to need it.
settings = Settings()
