"""Paginação por cursor (`meta.next_cursor`) e resultado com `meta`."""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Any, Callable, Generic, Iterator, Mapping, TypeVar

T = TypeVar("T")


@dataclass(frozen=True)
class ApiResult(Generic[T]):
    """Resposta com `data` e `meta` (ex.: `send_envelope` traz `meta.invitations_sent`)."""

    data: T
    meta: dict[str, Any] = field(default_factory=dict)


class Page(Generic[T]):
    """Uma página de resultados. Itere com `auto_paging_iter()` para percorrer todas.

    A ordem e o cursor são os da API: o cursor é opaco; não o monte à mão.
    """

    def __init__(
        self,
        data: list[T],
        links: Mapping[str, Any],
        meta: Mapping[str, Any],
        fetch: Callable[[str], "Page[T]"],
    ) -> None:
        self.data = data
        self.links = dict(links)
        self.meta = dict(meta)
        self._fetch = fetch

    @property
    def next_cursor(self) -> str | None:
        value = self.meta.get("next_cursor")
        return value if isinstance(value, str) and value else None

    @property
    def prev_cursor(self) -> str | None:
        value = self.meta.get("prev_cursor")
        return value if isinstance(value, str) and value else None

    @property
    def per_page(self) -> int | None:
        value = self.meta.get("per_page")
        return value if isinstance(value, int) else None

    @property
    def has_more(self) -> bool:
        return self.next_cursor is not None

    def next_page(self) -> "Page[T] | None":
        cursor = self.next_cursor
        return self._fetch(cursor) if cursor else None

    def auto_paging_iter(self) -> Iterator[T]:
        """Todos os itens, desta página em diante, buscando as próximas sob demanda."""
        page: Page[T] | None = self
        while page is not None:
            yield from page.data
            page = page.next_page()

    def __iter__(self) -> Iterator[T]:
        return iter(self.data)

    def __len__(self) -> int:
        return len(self.data)

    def __repr__(self) -> str:
        return f"Page(itens={len(self.data)}, next_cursor={self.next_cursor!r})"

    @classmethod
    def from_json(cls, body: Any, convert: Callable[[Any], T], fetch: Callable[[str], "Page[T]"]) -> "Page[T]":
        body = body if isinstance(body, dict) else {}
        items = body.get("data") if isinstance(body.get("data"), list) else []
        links = body.get("links") if isinstance(body.get("links"), dict) else {}
        meta = body.get("meta") if isinstance(body.get("meta"), dict) else {}
        return cls([convert(item) for item in items], links, meta, fetch)
