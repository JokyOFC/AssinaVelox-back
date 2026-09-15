"""SDK Python da API v1 da AssinaVelox (sem dependências; Python 3.10+).

    from assinavelox import AssinaVelox

    client = AssinaVelox(base_url="https://sua-instalacao.example/api/v1", token="12|avk_...")
    envelope = client.create_envelope({"title": "Contrato de locação — Apto 302"})

Veja sdks/python/README.md.
"""

from . import models, webhooks
from ._client import AssinaVelox
from ._version import API_MAJOR, API_VERSION, SDK_VERSION, SPEC_SHA256
from .errors import (
    ApiError,
    AssinaVeloxError,
    InvalidRequestError,
    NetworkError,
    RequestTimeoutError,
    WebhookSignatureError,
)
from .files import DownloadedFile, FileUpload
from .pagination import ApiResult, Page

__version__ = SDK_VERSION

__all__ = [
    "API_MAJOR",
    "API_VERSION",
    "SDK_VERSION",
    "SPEC_SHA256",
    "ApiError",
    "ApiResult",
    "AssinaVelox",
    "AssinaVeloxError",
    "DownloadedFile",
    "FileUpload",
    "InvalidRequestError",
    "NetworkError",
    "Page",
    "RequestTimeoutError",
    "WebhookSignatureError",
    "models",
    "webhooks",
]
