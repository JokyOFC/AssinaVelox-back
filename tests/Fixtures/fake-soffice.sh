#!/bin/sh
# ---------------------------------------------------------------------------
# FAKE do LibreOffice para os testes de tests/Feature/Pdf (Linux/macOS).
# NAO e o LibreOffice: apenas registra os argumentos recebidos e copia um PDF
# fixture para <--outdir>/<nome-da-entrada>.pdf, como "soffice --convert-to pdf"
# faria. Controlado por variaveis de ambiente passadas pelo teste via
# config('pdftool.libreoffice.env'):
#   FAKE_SOFFICE_LOG   arquivo onde gravar argumentos + ambiente recebido
#   FAKE_SOFFICE_PDF   PDF a copiar como resultado
#   FAKE_SOFFICE_FAIL  se definido, termina com exit code 1 sem gerar saida
#   FAKE_SOFFICE_NOOUT se definido, termina com exit code 0 sem gerar saida
#   FAKE_SOFFICE_SLEEP segundos a esperar antes de qualquer coisa (teste de timeout)
# Assume o layout de diretorios do LibreOfficeConverter: <tmp>/out e <tmp>/profile.
# O teste aplica chmod +x antes de executar.
# ---------------------------------------------------------------------------
if [ -n "$FAKE_SOFFICE_SLEEP" ]; then
  sleep "$FAKE_SOFFICE_SLEEP"
fi

outdir=""
prev=""
input=""

if [ -n "$FAKE_SOFFICE_LOG" ]; then
  printf 'ARGS=%s\n' "$*" > "$FAKE_SOFFICE_LOG"
fi

for arg in "$@"; do
  if [ "$prev" = "--outdir" ]; then
    outdir="$arg"
  fi
  prev="$arg"
  input="$arg"
done

if [ -z "$outdir" ]; then
  echo "fake-soffice: --outdir ausente" >&2
  exit 2
fi

base=$(basename "$input")
base="${base%.*}"

if [ -n "$FAKE_SOFFICE_LOG" ]; then
  if [ -f "$outdir/../profile/user/registrymodifications.xcu" ]; then
    echo "PROFILE_XCU=1" >> "$FAKE_SOFFICE_LOG"
  else
    echo "PROFILE_XCU=0" >> "$FAKE_SOFFICE_LOG"
  fi
  echo "CWD=$(pwd)" >> "$FAKE_SOFFICE_LOG"
  echo "--- ENV ---" >> "$FAKE_SOFFICE_LOG"
  env >> "$FAKE_SOFFICE_LOG"
fi

if [ -n "$FAKE_SOFFICE_FAIL" ]; then
  exit 1
fi
if [ -n "$FAKE_SOFFICE_NOOUT" ]; then
  exit 0
fi

cp "$FAKE_SOFFICE_PDF" "$outdir/$base.pdf"
