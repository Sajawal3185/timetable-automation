"""Small helpers shared by several API test files."""
import io

# Smallest byte strings that start like a real file of that type (see utils/file_storage.SIGNATURES).
PDF = b"%PDF-1.4 fake pdf bytes for testing"
PNG = b"\x89PNG\r\n\x1a\n" + b"\x00" * 16
PPTX = b"PK\x03\x04" + b"\x00" * 16
XLSX_MIME = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"


def pdf_file(name="paper.pdf", data=PDF):
    return {"file": (name, io.BytesIO(data), "application/pdf")}


def any_file(name, data):
    """An upload with any name and any content, e.g. a .pptx named file with PPTX bytes."""
    return {"file": (name, io.BytesIO(data), "application/octet-stream")}
