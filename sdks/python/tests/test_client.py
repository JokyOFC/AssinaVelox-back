"""Comportamento do cliente: autenticação, idempotência, erros RFC 9457, paginação, tempo esgotado."""

from __future__ import annotations

import hashlib
import re
import unittest

from assinavelox import (
    API_MAJOR,
    SDK_VERSION,
    SPEC_SHA256,
    ApiError,
    ApiResult,
    AssinaVelox,
    FileUpload,
    InvalidRequestError,
    NetworkError,
    RequestTimeoutError,
    models,
)

from tests._support import UUID_V4, fake_url, fetch_trace, free_port_url, new_trace

NOT_FOUND = "01FAKENOTFOUND000000000000"
ENVELOPE = "01J00000000000000000000000"


class ClientTest(unittest.TestCase):
    def setUp(self) -> None:
        self.client = AssinaVelox(base_url=fake_url(), token="tok_teste_sdk")

    def last(self, trace: str) -> dict:
        records = fetch_trace(trace)
        self.assertTrue(records)
        return records[-1]

    def test_versao_ligada_a_api_v1(self) -> None:
        self.assertEqual(SDK_VERSION, "1.0.0")
        self.assertEqual(API_MAJOR, 1)
        self.assertRegex(SPEC_SHA256, r"^[0-9a-f]{64}$")

    def test_cabecalhos_de_autenticacao_user_agent_e_corpo(self) -> None:
        headers, trace = new_trace()
        envelope = self.client.create_envelope({"title": "Contrato de locação — Apto 302"}, headers=headers)
        self.assertIsInstance(envelope, models.Envelope)
        record = self.last(trace)
        self.assertEqual(record["violations"], [])
        self.assertEqual(record["headers"]["authorization"], "Bearer tok_teste_sdk")
        self.assertRegex(record["headers"]["user-agent"], r"^assinavelox-python/1\.0\.0 \(api-v1; python/\d+\.\d+")
        self.assertEqual(record["headers"]["accept"], "application/json")
        self.assertEqual(record["headers"]["content-type"], "application/json")
        self.assertEqual(record["json"], {"title": "Contrato de locação — Apto 302"})

    def test_idempotency_key_gerada_quando_obrigatoria(self) -> None:
        headers, trace = new_trace()
        self.client.create_envelope({"title": "Contrato"}, headers=headers)
        self.assertRegex(self.last(trace)["headers"]["idempotency-key"], UUID_V4)

    def test_idempotency_key_informada_vai_como_esta(self) -> None:
        headers, trace = new_trace()
        self.client.create_envelope({"title": "Contrato"}, idempotency_key="pedido-42", headers=headers)
        self.assertEqual(self.last(trace)["headers"]["idempotency-key"], "pedido-42")

    def test_idempotency_key_opcional_so_vai_se_informada(self) -> None:
        headers, trace = new_trace()
        self.client.cancel_envelope(ENVELOPE, headers=headers)
        self.assertNotIn("idempotency-key", self.last(trace)["headers"])
        headers, trace = new_trace()
        self.client.cancel_envelope(ENVELOPE, {"reason": "Enviado por engano"}, idempotency_key="cancela-1", headers=headers)
        record = self.last(trace)
        self.assertEqual(record["headers"]["idempotency-key"], "cancela-1")
        self.assertEqual(record["json"], {"reason": "Enviado por engano"})

    def test_idempotency_key_invalida_nem_sai(self) -> None:
        headers, trace = new_trace()
        for invalid in ("com espaço", "", "x" * 256, "acentuação"):
            with self.assertRaises(InvalidRequestError):
                self.client.create_envelope({"title": "Contrato"}, idempotency_key=invalid, headers=headers)
        self.assertEqual(fetch_trace(trace), [])

    def test_erro_404_rfc_9457(self) -> None:
        with self.assertRaises(ApiError) as caught:
            self.client.get_envelope(NOT_FOUND)
        error = caught.exception
        self.assertEqual(error.status, 404)
        self.assertEqual(error.type, "urn:assinavelox:problem:not-found")
        self.assertTrue(error.has_type("not-found"))
        self.assertEqual(error.title, "Recurso não encontrado")
        self.assertTrue(error.correlation_id)
        self.assertEqual(error.instance, f"/api/v1/envelopes/{NOT_FOUND}")
        self.assertIn("404 Recurso não encontrado", str(error))

    def test_erro_422_com_erros_por_campo(self) -> None:
        with self.assertRaises(ApiError) as caught:
            self.client.create_envelope({"title": "fake-422"})
        error = caught.exception
        self.assertEqual(error.status, 422)
        self.assertEqual(error.slug, "validation-failed")
        self.assertEqual(error.detail, "Um ou mais campos não passaram na validação.")
        self.assertEqual(error.errors, {"title": ["O campo título deve ter pelo menos 3 caracteres."]})

    def test_erro_401(self) -> None:
        client = AssinaVelox(base_url=fake_url(), token="fake-401")
        with self.assertRaises(ApiError) as caught:
            client.list_templates()
        self.assertEqual(caught.exception.status, 401)
        self.assertEqual(caught.exception.slug, "unauthenticated")

    def test_erro_429_com_retry_after(self) -> None:
        client = AssinaVelox(base_url=fake_url(), token="fake-429")
        with self.assertRaises(ApiError) as caught:
            client.list_envelopes()
        self.assertEqual(caught.exception.status, 429)
        self.assertEqual(caught.exception.retry_after, 7)

    def test_erro_que_nao_e_rfc_9457(self) -> None:
        client = AssinaVelox(base_url=fake_url(), token="fake-502-html")
        with self.assertRaises(ApiError) as caught:
            client.list_envelopes()
        self.assertEqual(caught.exception.status, 502)
        self.assertEqual(caught.exception.type, "about:blank")
        self.assertEqual(caught.exception.title, "Erro HTTP 502")

    def test_tempo_esgotado(self) -> None:
        # Tempo do cliente e tempo por chamada.
        with self.assertRaises(RequestTimeoutError):
            AssinaVelox(base_url=fake_url(), token="fake-slow", timeout=0.3).list_envelopes()
        with self.assertRaises(RequestTimeoutError):
            AssinaVelox(base_url=fake_url(), token="fake-slow").list_envelopes(timeout=0.3)

    def test_conexao_recusada(self) -> None:
        client = AssinaVelox(base_url=free_port_url(), token="tok_teste_sdk", timeout=5)
        with self.assertRaises(NetworkError) as caught:
            client.list_envelopes()
        self.assertNotIsInstance(caught.exception, RequestTimeoutError)
        self.assertNotIn("tok_teste_sdk", str(caught.exception))

    def test_paginacao_e_query_com_lista(self) -> None:
        headers, trace = new_trace()
        page = self.client.list_envelopes(status=["draft", "completed"], per_page=10, headers=headers)
        record = self.last(trace)
        self.assertEqual(record["violations"], [])
        self.assertEqual(record["query"], [["per_page", "10"], ["status[]", "draft"], ["status[]", "completed"]])
        items = list(page.auto_paging_iter())
        self.assertEqual(len(items), 3)
        records = fetch_trace(trace)
        self.assertIn(["cursor", "fake-cursor-2"], records[-1]["query"])
        self.assertIn(["status[]", "completed"], records[-1]["query"])

    def test_upload_multipart_preserva_bytes(self) -> None:
        content = b"%PDF-1.4\r\n\x00\xff bytes\r\n--quase-um-boundary\r\n%%EOF"
        headers, trace = new_trace()
        document = self.client.upload_document(
            ENVELOPE, FileUpload(content=content, filename='contrato "final".pdf', content_type="application/pdf"), headers=headers
        )
        self.assertIsInstance(document, models.Document)
        record = self.last(trace)
        self.assertEqual(record["violations"], [])
        self.assertEqual(record["files"]["file"]["sha256"], hashlib.sha256(content).hexdigest())
        self.assertEqual(record["files"]["file"]["filename"], "contrato %22final%22.pdf")
        self.assertEqual(record["files"]["file"]["content_type"], "application/pdf")

    def test_download(self) -> None:
        downloaded = self.client.download_file(ENVELOPE, "original", document=ENVELOPE)
        self.assertTrue(downloaded.content.startswith(b"%PDF"))
        self.assertEqual(downloaded.filename, "AV-000123-original.pdf")

    def test_parametro_de_caminho_codificado(self) -> None:
        headers, trace = new_trace()
        self.client.get_envelope("a/b?c", headers=headers)
        record = self.last(trace)
        self.assertEqual(record["path"], "/api/v1/envelopes/a%2Fb%3Fc")
        self.assertEqual(record["path_params"], {"envelope": "a/b?c"})

    def test_token_nao_aparece_no_repr(self) -> None:
        client = AssinaVelox(base_url=fake_url(), token="tok_super_secreto")
        self.assertNotIn("tok_super_secreto", repr(client))
        self.assertNotIn("tok_super_secreto", repr(vars(client)))

    def test_configuracao_invalida(self) -> None:
        with self.assertRaises(InvalidRequestError):
            AssinaVelox(base_url="ftp://exemplo", token="x")
        with self.assertRaises(InvalidRequestError):
            AssinaVelox(base_url=fake_url(), token="com espaço")
        with self.assertRaises(InvalidRequestError):
            self.client.list_envelopes(headers={"X-Teste": "a\r\nInjetado: 1"})

    def test_exemplo_do_readme(self) -> None:
        client = AssinaVelox(base_url=fake_url(), token="12|avk_exemplo")

        envelope = client.create_envelope({"title": "Contrato de locação — Apto 302"})
        client.upload_document(envelope.id, FileUpload(content=b"%PDF-1.4 ...", filename="contrato.pdf"))
        client.sync_recipients(
            envelope.id,
            {"signing_order": "sequential", "recipients": [{"name": "Ana Souza", "email": "ana@example.com"}]},
        )
        sent = client.send_envelope(envelope.id)
        self.assertIsInstance(sent, ApiResult)
        self.assertIsInstance(sent.meta.get("invitations_sent"), int)

        codes = [item.display_code for item in client.list_envelopes(status=["in_progress"]).auto_paging_iter()]
        self.assertEqual(len(codes), 3)

        try:
            client.get_envelope(NOT_FOUND)
        except ApiError as error:
            self.assertTrue(re.match(r"^urn:assinavelox:problem:", error.type))


if __name__ == "__main__":
    unittest.main()
