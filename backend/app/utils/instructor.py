"""One rule for the instructor name shown on uploaded exam papers and lecture materials."""


def resolve_instructor_name(given: str | None, uploader) -> str:
    """Return the instructor name to store.

    Rule (same for exam papers and lecture materials):
      1. A teacher's upload is always filed under their own name, so a teacher
         cannot put files under a colleague's name.
      2. An HOD or admin may name another instructor: a given instructor_name
         that is not blank is used (trimmed).
      3. Otherwise the uploader's own name is used.
    """
    own = (getattr(uploader, "name", "") or "").strip() or "Unknown"
    if (getattr(uploader, "role", "") or "").lower() == "teacher":
        return own
    if given and given.strip():
        return given.strip()
    return own
