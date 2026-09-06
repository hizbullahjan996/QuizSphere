"""Unit tests for the PDF text extractor (Phase 4).

Generates small valid text-based PDFs on the fly and verifies:
  * text is extracted correctly from uncompressed content streams
  * image-only / unreadable PDFs are rejected with PdfExtractionError
"""

import pytest

from app.services.pdf_text_extractor import PdfExtractionError, PdfTextExtractor


def _build_text_pdf(lines: list[str]) -> bytes:
    """Minimal valid PDF with BT/Tf/Td/Tj text operators (uncompressed)."""
    content = ("\n".join(["BT", "/F1 12 Tf", "20 720 Td"] + [f"({l}) Tj" for l in lines] + ["ET"])).encode("latin-1")
    objects = [
        b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",  # 1
        b"<< /Type /Page /Parent 3 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 1 0 R >> >> >>",  # 2
        b"<< /Type /Pages /Kids [2 0 R] /Count 1 >>",  # 3
        b"<< /Length %d >>\nstream\n%s\nendstream" % (len(content), content),  # 4
        b"<< /Type /Catalog /Pages 3 0 R >>",  # 5
    ]

    offsets = []
    pieces = []
    pos = 0
    for i, obj in enumerate(objects, start=1):
        header = b"%d 0 obj\n" % i
        seg = header + obj + b"\nendobj\n"
        offsets.append(pos)
        pieces.append(seg)
        pos += len(seg)

    startxref = pos
    xref = b"xref\n0 6\n0000000000 65535 f \n"
    for off in offsets:
        xref += ("%010d 00000 n \n" % off).encode("ascii")
    trailer = b"trailer\n<< /Size 6 /Root 5 0 R >>\nstartxref\n%d\n%%%%EOF" % startxref
    return b"%PDF-1.4\n" + b"".join(pieces) + xref + trailer


def test_extract_text_pdf():
    pdf = _build_text_pdf(
        [
            "Photosynthesis converts light energy into chemical energy.",
            "This occurs in the chloroplasts of plant cells.",
        ]
    )
    text = PdfTextExtractor().extract(pdf)
    assert "Photosynthesis" in text
    assert "chloroplasts" in text


def test_extract_rejects_empty_content():
    pdf = _build_text_pdf([])  # no text shown
    with pytest.raises(PdfExtractionError):
        PdfTextExtractor().extract(pdf)


def test_extract_rejects_non_pdf():
    with pytest.raises(PdfExtractionError):
        PdfTextExtractor().extract(b"this is not a pdf at all")


def test_extract_rejects_image_only_pdf():
    pdf = b"%PDF-1.4\nbinary image stream, no text operators"
    with pytest.raises(PdfExtractionError):
        PdfTextExtractor().extract(pdf)
