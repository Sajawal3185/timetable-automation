"""File upload helpers (Wajiha) - used by exam papers and lecture materials.

Files go to UPLOAD_DIR (default: ./uploads, ignored by git). The database stores
only a RELATIVE path. Stored names are random UUIDs so users cannot overwrite
each other and a hostile file name can never escape the upload folder.
"""
import os
import re
import uuid
from dataclasses import dataclass
from pathlib import Path

from fastapi import HTTPException, UploadFile

CHUNK = 1024 * 1024

PAPER_EXTENSIONS = {".pdf", ".png", ".jpg", ".jpeg", ".doc", ".docx"}
MATERIAL_EXTENSIONS = {".pdf", ".ppt", ".pptx", ".doc", ".docx", ".txt", ".zip", ".png", ".jpg", ".jpeg"}


def upload_dir() -> Path:
    """Read UPLOAD_DIR at call time so tests can point it at a temp folder."""
    return Path(os.getenv("UPLOAD_DIR", "uploads")).resolve()


def max_upload_mb() -> int:
    return int(os.getenv("MAX_UPLOAD_MB", "20"))


@dataclass
class SavedFile:
    relative_path: str
    original_filename: str
    content_type: str | None
    size: int


def _clean_name(name: str) -> str:
    name = (name or "file").replace("\\", "/").split("/")[-1]
    return re.sub(r"[^A-Za-z0-9._ -]", "_", name)[:200] or "file"


def save_upload(file: UploadFile, subfolder: str, allowed_extensions: set[str]) -> SavedFile:
    """Validate and store an upload. Raises HTTPException (400/413) on bad input."""
    original = _clean_name(file.filename or "")
    ext = Path(original).suffix.lower()
    if ext not in allowed_extensions:
        raise HTTPException(400, f"File type {ext or '(none)'} not allowed. Allowed: {sorted(allowed_extensions)}")

    folder = upload_dir() / subfolder
    folder.mkdir(parents=True, exist_ok=True)
    target = folder / f"{uuid.uuid4().hex}{ext}"
    limit = max_upload_mb() * 1024 * 1024
    size = 0
    try:
        with open(target, "wb") as out:
            while chunk := file.file.read(CHUNK):
                size += len(chunk)
                if size > limit:
                    raise HTTPException(413, f"File too large (max {max_upload_mb()} MB)")
                out.write(chunk)
        if size == 0:
            raise HTTPException(400, "Uploaded file is empty")
    except Exception:
        target.unlink(missing_ok=True)
        raise
    return SavedFile(f"{subfolder}/{target.name}", original, file.content_type, size)


def resolve_path(relative_path: str) -> Path:
    """Absolute path of a stored file; refuses anything outside the upload folder."""
    base = upload_dir()
    full = (base / relative_path).resolve()
    if base not in full.parents:
        raise HTTPException(400, "Invalid file path")
    if not full.is_file():
        raise HTTPException(404, "File is missing on the server")
    return full


def delete_file(relative_path: str) -> None:
    try:
        base = upload_dir()
        full = (base / relative_path).resolve()
        if base in full.parents:
            full.unlink(missing_ok=True)
    except OSError:
        pass
