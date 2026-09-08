"""pdftool - PDF inspection, composition, PAdES signing and validation CLI.

Designed to be invoked as a subprocess (``python -m pdftool <command> ...``)
by the AssinaVelox Laravel backend. Every command prints exactly one JSON
object on stdout; diagnostics go to stderr.
"""

__version__ = "1.0.0"
