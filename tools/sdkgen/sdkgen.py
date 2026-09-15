"""Gerador dos SDKs da API v1 (docs/fase-3/sdks.md). Só biblioteca padrão.

Uso (na raiz do repositório, com o Python do venv do pdftool):

    python tools/sdkgen/sdkgen.py export     # php artisan scramble:export → sdks/openapi/v1.json
    python tools/sdkgen/sdkgen.py vectors    # vetores da assinatura de webhook (PHP real)
    python tools/sdkgen/sdkgen.py generate   # escreve os SDKs a partir da especificação
    python tools/sdkgen/sdkgen.py check      # falha se os SDKs não vierem da especificação atual
    python tools/sdkgen/sdkgen.py test       # servidor falso + testes dos três SDKs
    python tools/sdkgen/sdkgen.py all        # export + vectors + generate + test
"""

from __future__ import annotations

import argparse
import os
import shutil
import subprocess
import sys
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

from avsdkgen import spec as specmod  # noqa: E402
from avsdkgen.model import build  # noqa: E402

ROOT = specmod.ROOT
PHP_DIR = ROOT / "sdks" / "php"
NODE_DIR = ROOT / "sdks" / "node"
PYTHON_DIR = ROOT / "sdks" / "python"


def load_api():
    spec = specmod.load_spec()
    overrides = specmod.load_overrides()
    return build(spec, overrides, specmod.source_fingerprint(spec, overrides))


def generated_files(api, only: str | None = None) -> dict[Path, str]:
    import importlib

    targets = {"php": PHP_DIR, "node": NODE_DIR, "python": PYTHON_DIR}
    files: dict[Path, str] = {}
    for language, directory in targets.items():
        if only and language != only:
            continue
        files.update(importlib.import_module(f"avsdkgen.emit_{language}").emit(api, directory))
    return files


def cmd_export(args) -> int:
    spec = specmod.export(args.php)
    specmod.SPEC_PATH.parent.mkdir(parents=True, exist_ok=True)
    specmod.SPEC_PATH.write_text(specmod.dump(spec), encoding="utf-8", newline="\n")
    if not args.no_format:
        _format([specmod.SPEC_PATH])
    print(f"Especificação escrita em {specmod.SPEC_PATH.relative_to(ROOT)} ({specmod.fingerprint(spec)[:12]}).")
    return 0


def cmd_vectors(args) -> int:
    subprocess.run([args.php, str(ROOT / "tools" / "sdkgen" / "webhook_vectors.php"), "--write"], cwd=ROOT, check=True)
    if not args.no_format:
        _format([specmod.VECTORS_PATH])
    return 0


def _format(paths: list[Path]) -> None:
    """Deixa a saída no formato das ferramentas do repositório (pint e vp fmt), se existirem."""
    php_files = [str(p) for p in paths if p.suffix == ".php"]
    pint = ROOT / "vendor" / "bin" / ("pint.bat" if os.name == "nt" else "pint")
    if php_files and pint.exists():
        subprocess.run([str(pint), "--quiet", *php_files], cwd=ROOT, check=False)
    web_files = [str(p.relative_to(ROOT)).replace("\\", "/") for p in paths if p.suffix in (".ts", ".mjs", ".json")]
    npx = shutil.which("npx")
    if web_files and npx:
        subprocess.run([npx, "vp", "fmt", *web_files], cwd=ROOT, check=False, stdout=subprocess.DEVNULL)


def cmd_generate(args) -> int:
    api = load_api()
    files = generated_files(api, args.only)
    for path, content in files.items():
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(content, encoding="utf-8", newline="\n")
    if not args.no_format:
        _format(list(files))
    print(f"{len(files)} arquivos gerados; {len(api.operations)} operações; especificação {api.fingerprint[:12]}.")
    return 0


def cmd_check(args) -> int:
    """Os SDKs carregam a impressão digital da especificação de onde saíram."""
    api = load_api()
    problems: list[str] = []
    for path in generated_files(api):
        if not path.exists():
            problems.append(f"falta {path.relative_to(ROOT)}")
    import re

    stamp = re.compile(r"SPEC_SHA256\s*=\s*['\"]([0-9a-f]{64})['\"]")
    for path in (PHP_DIR / "src" / "Version.php", NODE_DIR / "src" / "version.ts", PYTHON_DIR / "assinavelox" / "_version.py"):
        if not path.exists():
            continue
        match = stamp.search(path.read_text(encoding="utf-8"))
        found = match.group(1) if match else ""
        if found != api.fingerprint:
            problems.append(f"{path.relative_to(ROOT)} foi gerado de outra especificação ({found[:12] or '?'})")
    if problems:
        print("SDKs desatualizados em relação a sdks/openapi/v1.json:")
        for problem in problems:
            print(f"  - {problem}")
        print("Regere: python tools/sdkgen/sdkgen.py generate")
        return 1
    print(f"SDKs em dia com a especificação {api.fingerprint[:12]}.")
    return 0


def cmd_test(args) -> int:
    sys.path.insert(0, str(ROOT / "tools" / "sdkgen"))
    import fake_server

    server, url = fake_server.start_in_thread()
    env = dict(os.environ, FAKE_API_URL=url, NO_PROXY="127.0.0.1,localhost", no_proxy="127.0.0.1,localhost")
    results: dict[str, int] = {}
    try:
        results["php"] = subprocess.run([args.php, str(PHP_DIR / "tests" / "run.php")], cwd=ROOT, env=env).returncode
        node = shutil.which("node")
        tsc = ROOT / "node_modules" / "typescript" / "bin" / "tsc"
        if node and tsc.exists():
            build_rc = subprocess.run([node, str(tsc), "-p", str(NODE_DIR / "tsconfig.json")], cwd=ROOT, env=env).returncode
            tests = sorted(str(p.relative_to(NODE_DIR)).replace("\\", "/") for p in (NODE_DIR / "test").glob("*.test.mjs"))
            results["node"] = build_rc or subprocess.run([node, "--test", *tests], cwd=NODE_DIR, env=env).returncode
        else:
            print("Node ou TypeScript indisponível: testes do SDK Node não rodaram.")
        results["python"] = subprocess.run(
            [sys.executable, "-m", "unittest", "discover", "-s", "tests", "-t", "."], cwd=PYTHON_DIR, env=env
        ).returncode
    finally:
        server.shutdown()
    print("Resultado: " + ", ".join(f"{k}={'ok' if v == 0 else 'FALHOU'}" for k, v in results.items()))
    return 0 if all(v == 0 for v in results.values()) else 1


def cmd_all(args) -> int:
    for step in (cmd_export, cmd_vectors, cmd_generate, cmd_test):
        code = step(args)
        if code:
            return code
    return 0


def main() -> int:
    parser = argparse.ArgumentParser(description="Gerador dos SDKs da API v1 da AssinaVelox")
    parser.add_argument("command", choices=["export", "vectors", "generate", "check", "test", "all"])
    parser.add_argument("--php", default=os.environ.get("PHP_BINARY", "php"))
    parser.add_argument("--no-format", action="store_true", help="não roda pint nem vp fmt na saída")
    parser.add_argument("--only", choices=["php", "node", "python"], help="gera só um SDK")
    args = parser.parse_args()
    return {
        "export": cmd_export,
        "vectors": cmd_vectors,
        "generate": cmd_generate,
        "check": cmd_check,
        "test": cmd_test,
        "all": cmd_all,
    }[args.command](args)


if __name__ == "__main__":
    raise SystemExit(main())
