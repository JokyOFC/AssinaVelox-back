"""Module entry point: ``python -m pdftool ...``."""

import sys

from pdftool.cli import main

if __name__ == "__main__":
    sys.exit(main())
