#!/usr/bin/env bash
# Create the virtualenv if needed, install pinned requirements and run pytest.
set -euo pipefail
cd "$(dirname "$0")"

if [ -x .venv/Scripts/python.exe ]; then
  PY=.venv/Scripts/python.exe
elif [ -x .venv/bin/python ]; then
  PY=.venv/bin/python
else
  python -m venv .venv
  if [ -x .venv/Scripts/python.exe ]; then PY=.venv/Scripts/python.exe; else PY=.venv/bin/python; fi
  "$PY" -m pip install --upgrade pip
  "$PY" -m pip install -r requirements.txt
fi

"$PY" -m pytest -q "$@"
