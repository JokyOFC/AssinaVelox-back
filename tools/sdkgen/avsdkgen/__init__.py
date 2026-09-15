"""Gerador próprio dos SDKs da API v1 (roadmap §3.9, docs/fase-3/sdks.md).

Só biblioteca padrão. Lê `sdks/openapi/v1.json` (exportado pelo Scramble) e
`tools/sdkgen/overrides.json` (o que o Scramble não infere) e escreve clientes finos
em PHP, TypeScript (Node) e Python.
"""
