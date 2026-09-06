"""Shared response schemas."""

from pydantic import BaseModel


class HealthResponse(BaseModel):
    status: str


class ErrorBody(BaseModel):
    error: str
    message: str
