"""Small helpers shared by several API test files."""
import io

PDF = b"%PDF-1.4 fake pdf bytes for testing"
XLSX_MIME = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"


def pdf_file(name="paper.pdf", data=PDF):
    return {"file": (name, io.BytesIO(data), "application/pdf")}
