"""Revisão adversarial onda G (SDKs) — Retry-After com dígito não ASCII derruba o SDK Python.

``ApiError.retry_after`` usa ``str.isdigit()``, que aceita dígitos Unicode como ``²`` (U+00B2).
O ``http.client`` decodifica cabeçalhos como latin-1, então o byte 0xB2 num ``Retry-After``
(proxy/CDN mal configurado, WAF, resposta hostil) vira ``"²"``: ``isdigit()`` diz True e
``int("²")`` levanta ValueError dentro da propriedade — o código do cliente que só queria ler o
tempo de espera de um 429/503 quebra com uma exceção que não é do SDK.

Os outros dois SDKs devolvem "sem valor" para o mesmo cabeçalho: PHP ``ctype_digit`` (false) e
Node ``/^\\d+$/`` (sem flag ``u``, só ASCII). Divergência entre SDKs gerados da mesma especificação.

Rodar: cd sdks/python && python -m unittest tests.review_retry_after_non_ascii
(o nome não começa com ``test``: o ``unittest discover`` do SdkSuitesTest não o executa.)
"""

import unittest

from assinavelox.errors import ApiError


class RetryAfterNonAsciiTest(unittest.TestCase):
    def test_superscript_digit_is_not_a_number_of_seconds(self) -> None:
        error = ApiError.from_response(429, {"Retry-After": "²"}, b"")

        # Mesmo resultado do PHP (ctype_digit) e do Node (/^\d+$/): nenhum valor, sem exceção.
        self.assertIsNone(error.retry_after)

    def test_ascii_digits_still_work(self) -> None:
        self.assertEqual(ApiError.from_response(429, {"Retry-After": "7"}, b"").retry_after, 7)


if __name__ == "__main__":
    unittest.main()
