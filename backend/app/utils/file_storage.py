"""File upload helpers - used by exam papers and lecture materials.

Files go to UPLOAD_DIR (default: ./uploads, ignored by git). The database stores
only a RELATIVE path. Stored names are random UUIDs so users cannot overwrite
each other and a hostile file name can never escape the upload folder.

A file is accepted only if its first bytes match its extension (a "x.pdf" that is
really an .exe is refused), not just because of what it is called.
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

_OLE = b"\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1"  # old Word / PowerPoint (.doc / .ppt)
_ZIP = b"PK\x03\x04"                           # .docx / .pptx / .zip are zip files
# The bytes a genuine file of each type starts with. Every extension in the two
# sets above needs a rule here (or the special case for .txt in
# content_matches_extension); an extension with no rule is refused.
SIGNATURES: dict[str, tuple[bytes, ...]] = {
    ".pdf": (b"%PDF-",),
    ".png": (b"\x89PNG\r\n\x1a\n",),
    ".jpg": (b"\xff\xd8\xff",),
    ".jpeg": (b"\xff\xd8\xff",),
    ".docx": (_ZIP,),
    ".pptx": (_ZIP,),
    ".zip": (_ZIP,),
    ".doc": (_OLE, b"{\\rtf"),  # some tools save RTF with a .doc name
    ".ppt": (_OLE,),
}


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


def content_matches_extension(ext: str, head: bytes) -> bool:
    """True if the first bytes of a file are what a file with this extension starts with."""
    if ext == ".txt":
        # plain text has no signature: refuse binary data (NUL bytes), allow UTF-16 text (it has a BOM).
        # Known limit (fine for the demo): a UTF-16 BOM followed by ANY binary data also passes as .txt,
        # because UTF-16 text legitimately contains NUL bytes. Downloads are served as attachments, so
        # such a file is only ever saved, never displayed in the page.
        return b"\x00" not in head or head.startswith((b"\xff\xfe", b"\xfe\xff"))
    return any(head.startswith(sig) for sig in SIGNATURES.get(ext, ()))


def save_upload(file: UploadFile, subfolder: str, allowed_extensions: set[str]) -> SavedFile:
    """Validate (extension, then content) and store an upload. Raises HTTPException (400/413) on bad input."""
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
                if size == 0 and not content_matches_extension(ext, chunk):
                    raise HTTPException(400, f"File content does not look like a real {ext} file")
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
