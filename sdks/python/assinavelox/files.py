"""Arquivos enviados (upload multipart) e recebidos (download)."""

from __future__ import annotations

import mimetypes
import re
from dataclasses import dataclass
from pathlib import Path
from typing import Mapping
from urllib.parse import unquote


@dataclass(frozen=True)
class FileUpload:
    """Arquivo para `upload_document` (multipart, campo `file`). A API não aceita upload por URL."""

    content: bytes
    filename: str
    content_type: str = "application/octet-stream"

    @classmethod
    def from_path(cls, path: str | Path, content_type: str | None = None) -> "FileUpload":
        file = Path(path)
        guessed = content_type or mimetypes.guess_type(file.name)[0] or "application/octet-stream"
        return cls(content=file.read_bytes(), filename=file.name, content_type=guessed)


@dataclass(frozen=True)
class DownloadedFile:
    """Arquivo baixado de `download_file` (original, assinado ou evidências)."""

    content: bytes
    content_type: str | None
    filename: str | None

    def save(self, path: str | Path) -> Path:
        target = Path(path)
        target.write_bytes(self.content)
        return target

    @classmethod
    def from_response(cls, headers: Mapping[str, str], body: bytes) -> "DownloadedFile":
        lowered = {k.lower(): v for k, v in headers.items()}
        disposition = lowered.get("content-disposition", "")
        filename = None
        extended = re.search(r"filename\*\s*=\s*UTF-8''([^;]+)", disposition, re.IGNORECASE)
        plain = re.search(r'filename\s*=\s*"([^"]*)"', disposition) or re.search(r"filename\s*=\s*([^;]+)", disposition)
        if extended:
            filename = unquote(extended.group(1).strip())
        elif plain:
            filename = plain.group(1).strip()
        content_type = lowered.get("content-type")
        return cls(content=body, content_type=content_type.split(";")[0].strip() if content_type else None, filename=filename)
