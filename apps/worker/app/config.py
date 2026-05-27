"""Application configuration loaded from environment variables."""

from __future__ import annotations

from functools import lru_cache

from pydantic import Field, SecretStr
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    """Worker configuration.

    Values are read from environment variables (`.env` file in development
    via Docker Compose env_file). See the monorepo root `.env.example` for
    the canonical list and defaults.
    """

    # --- App ---
    app_env: str = Field(default="local", description="local | staging | production")
    app_debug: bool = Field(default=False)

    # --- Worker meta ---
    worker_internal_key: SecretStr = Field(
        default=SecretStr("changeme"),
        description="HMAC secret shared with the Laravel API for internal calls.",
    )

    # --- Database ---
    db_host: str = Field(default="postgres")
    db_port: int = Field(default=5432)
    db_database: str = Field(default="bmssiteops")
    db_username: str = Field(default="bmssiteops")
    db_password: SecretStr = Field(default=SecretStr("changeme"))

    @property
    def database_url(self) -> str:
        """asyncpg-style connection URL."""
        return (
            f"postgresql://{self.db_username}:{self.db_password.get_secret_value()}"
            f"@{self.db_host}:{self.db_port}/{self.db_database}"
        )

    # --- Redis ---
    redis_host: str = Field(default="redis")
    redis_port: int = Field(default=6379)

    @property
    def redis_url(self) -> str:
        return f"redis://{self.redis_host}:{self.redis_port}/0"

    # --- AI / Anthropic ---
    anthropic_api_key: SecretStr = Field(default=SecretStr(""))
    litellm_base_url: str = Field(default="http://litellm:4000")
    litellm_master_key: SecretStr = Field(default=SecretStr("changeme"))
    default_ai_model: str = Field(default="claude-sonnet-4-5")

    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",  # ignore Laravel-only env vars sharing the same file
        case_sensitive=False,
    )


@lru_cache(maxsize=1)
def settings() -> Settings:
    """Return a cached Settings instance.

    Cached so that env-var parsing happens once. Tests can clear the cache
    via `settings.cache_clear()` to pick up `monkeypatch.setenv` changes.
    """
    return Settings()
