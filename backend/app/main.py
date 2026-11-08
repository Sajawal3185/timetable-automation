"""FastAPI application entry point.

Run locally from backend/:   uvicorn app.main:app --reload
Interactive API docs:        http://127.0.0.1:8000/docs

This file only wires things together (app, middleware, routers). Business
logic lives in routers/ and services/. Owner: Sajawal (one owner, so two PRs
never add routers in conflicting places; ask in your PR to have yours added).

Table creation is NOT done here on purpose: schema changes go through Alembic
migrations (task BE-10), so every machine and the server get the same tables.
"""
from fastapi import FastAPI
from fastapi.middleware.cors import CORSMiddleware

from app.config import settings
from app.routers import (
    auth,
    events,
    exams,
    gpa,
    makeup,
    materials,
    notifications,
    rooms,
    software,
    swaps,
    timetable,
)

app = FastAPI(title="Timetable Automation")

# --- CORS --------------------------------------------------------------------
# The frontend is served from a different origin (Netlify / a local static
# server), so the browser blocks its API calls unless we list it explicitly.
# An explicit list (not "*") is required because the frontend sends an
# Authorization header; origins come from settings so staging and production
# differ by environment variable only, with no code change.
app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.cors_origins,
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# --- Routers -----------------------------------------------------------------
# Each router already carries its own /api/... prefix and tag, so they are
# included as-is. Keep this list alphabetical to make merges painless.
# (routers/portal_deps.py is a helper module, not a router.)
for module in (
    auth,
    events,
    exams,
    gpa,
    makeup,
    materials,
    notifications,
    rooms,
    software,
    swaps,
    timetable,
):
    app.include_router(module.router)


# --- Health check ------------------------------------------------------------
# Public and dependency-free: it does not touch the database, so it answers
# "the process is up" even if the DB is slow. Used by Render's health check
# and to wake the free-tier server before the demo.
@app.get("/api/health", tags=["health"])
def health() -> dict[str, str]:
    return {"status": "ok"}
