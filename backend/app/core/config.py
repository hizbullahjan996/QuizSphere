"""Application configuration loaded from environment variables / .env file.

Secrets are only ever read here and injected into server-side clients; they
are never echoed in responses, logs, or error messages.
"""

from functools import lru_cache

from pydantic import AliasChoices, Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore",
    )

    # App
    APP_NAME: str = "QuizSphere API"
    APP_VERSION: str = "0.1.0"
    ENVIRONMENT: str = "development"
    LOG_LEVEL: str = "INFO"
    CORS_ORIGINS: str = "http://localhost:8000,http://127.0.0.1:8000"

    @property
    def cors_origins_list(self) -> list[str]:
        return [o.strip() for o in self.CORS_ORIGINS.split(",") if o.strip()]

    # Supabase (server-side only). Primary names follow the current Supabase
    # key model; the legacy PHP names are accepted as fallback aliases so the
    # same deployment secrets work in both backends.
    SUPABASE_URL: str = ""
    SUPABASE_PUBLISHABLE_KEY: str = Field(
        default="",
        validation_alias=AliasChoices("SUPABASE_PUBLISHABLE_KEY", "SUPABASE_ANON_KEY"),
    )
    SUPABASE_SECRET_KEY: str = Field(
        default="",
        validation_alias=AliasChoices("SUPABASE_SECRET_KEY", "SUPABASE_SERVICE_ROLE_KEY"),
    )
    SUPABASE_SCHEMA: str = "public"

    # AI providers (server-side only)
    GEMINI_API_KEY: str = ""
    GEMINI_MODEL: str = "gemini-3.6-flash"
    GROQ_API_KEY: str = ""
    GROQ_MODEL: str = "openai/gpt-oss-20b"

    # Sessions / security
    SESSION_SECRET: str = ""

    # Session store backend: "memory" (default, dev) or "redis" (production).
    # When "redis" is selected, REDIS_URL must be set. Redis is optional at
    # runtime: without it the in-memory store is used so local dev never fails.
    SESSION_STORE: str = "memory"
    REDIS_URL: str = ""
    SESSION_TTL_SECONDS: int = 12 * 60 * 60  # 12h

    # Rate limiting
    AI_RATE_LIMIT_MAX: int = 10
    AI_RATE_LIMIT_WINDOW: int = 3600

    # When true, new signups are created admin-confirmed so no email
    # verification (e.g. Gmail confirmation) is required to log in.
    DISABLE_EMAIL_VERIFICATION: bool = False

    @property
    def session_configured(self) -> bool:
        return self.SESSION_SECRET != ""

    @property
    def supabase_configured(self) -> bool:
        return bool(self.SUPABASE_URL) and bool(self.SUPABASE_PUBLISHABLE_KEY)

    @property
    def ai_configured(self) -> bool:
        return bool(self.GEMINI_API_KEY) or bool(self.GROQ_API_KEY)

    @property
    def secret_values(self) -> list[str]:
        """All configured secret strings, used exclusively for log redaction."""
        return [
            v
            for v in (
                self.SUPABASE_PUBLISHABLE_KEY,
                self.SUPABASE_SECRET_KEY,
                self.GEMINI_API_KEY,
                self.GROQ_API_KEY,
                self.SESSION_SECRET,
            )
            if v
        ]


@lru_cache
def get_settings() -> Settings:
    return Settings()
