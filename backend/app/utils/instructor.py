"""One rule for the instructor name shown on uploaded exam papers and lecture materials."""


def resolve_instructor_name(given: str | None, uploader) -> str:
    """Return the instructor name to store.

    Rule (same for exam papers and lecture materials):
      1. If the uploader typed an instructor_name that is not blank, use it
         (a teacher may upload a colleague's paper under the colleague's name).
      2. Otherwise use the uploader's own name.
    """
    if given and given.strip():
        return given.strip()
    return (getattr(uploader, "name", "") or "").strip() or "Unknown"
