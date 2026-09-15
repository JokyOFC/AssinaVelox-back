"""Assinatura dos webhooks: vetores gerados pelo PHP real (sdks/testdata)."""

from __future__ import annotations

import base64
import json
import unittest

from assinavelox import WebhookSignatureError, webhooks

from tests._support import load_vectors


class WebhookVectorsTest(unittest.TestCase):
    vectors = load_vectors()

    def test_ha_vetores_dos_dois_resultados(self) -> None:
        results = {case["expected"] for case in self.vectors["verify"]}
        self.assertEqual(results, {True, False})
        self.assertEqual(self.vectors["default_tolerance"], webhooks.DEFAULT_TOLERANCE_SECONDS)

    def test_compute(self) -> None:
        for case in self.vectors["compute"]:
            with self.subTest(case["name"]):
                body = base64.b64decode(case["body_base64"])
                self.assertEqual(webhooks.compute_signature(case["secret"], case["timestamp"], body), case["signature"])

    def test_header(self) -> None:
        for case in self.vectors["header"]:
            with self.subTest(case["name"]):
                body = base64.b64decode(case["body_base64"])
                self.assertEqual(webhooks.signature_header(case["secrets"], case["timestamp"], body), case["header"])

    def test_verify(self) -> None:
        for case in self.vectors["verify"]:
            with self.subTest(case["name"]):
                body = base64.b64decode(case["body_base64"])
                result = webhooks.verify_signature(
                    case["secret"],
                    case["signature_header"],
                    case["timestamp_header"],
                    body,
                    now=case["now"],
                    tolerance=case["tolerance"],
                )
                self.assertIs(result, case["expected"])

    def test_construct_event(self) -> None:
        case = self.vectors["verify"][0]
        body = base64.b64decode(case["body_base64"])
        headers = {"x-assinavelox-signature": case["signature_header"], "X-ASSINAVELOX-TIMESTAMP": case["timestamp_header"]}
        event = webhooks.construct_event(body, headers, case["secret"], now=case["now"])
        self.assertEqual(event, json.loads(body))
        with self.assertRaises(WebhookSignatureError):
            webhooks.construct_event(body + b" ", headers, case["secret"], now=case["now"])
        with self.assertRaises(WebhookSignatureError):
            webhooks.construct_event(body, {}, case["secret"], now=case["now"])

    def test_corpo_como_texto_e_como_bytes(self) -> None:
        case = self.vectors["verify"][0]
        body = base64.b64decode(case["body_base64"])
        self.assertTrue(
            webhooks.verify_signature(
                case["secret"], case["signature_header"], case["timestamp_header"], body.decode("utf-8"), now=case["now"]
            )
        )


if __name__ == "__main__":
    unittest.main()
