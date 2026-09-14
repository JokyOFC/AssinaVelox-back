"""Servidor HTTPS mínimo para o teste de integração do pino de IP (HttpsPinTest).

Uso: python https_server.py <endereço> <porta> <cert.pem> <chave.pem> <log.jsonl> <rótulo>

Registra, uma linha JSON por evento no arquivo de log:
  {"event": "sni", "sni": ..., "label": ...}            a cada ClientHello (SNI recebido)
  {"event": "request", "sni", "host", "path", ...}      a cada requisição HTTP concluída

Responde 200 "recebido-<rótulo>"; /redirect responde 302 para https://127.0.0.1:<porta>/internal.
Só escuta no endereço de loopback informado; é encerrado pelo teste (PID que ele iniciou).
"""

import json
import ssl
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

BIND, PORT, CERT, KEY, LOG, LABEL = sys.argv[1], int(sys.argv[2]), sys.argv[3], sys.argv[4], sys.argv[5], sys.argv[6]
_lock = threading.Lock()
_sni = {}


def record(entry):
    entry["label"] = LABEL
    with _lock:
        with open(LOG, "a", encoding="utf-8") as handle:
            handle.write(json.dumps(entry) + "\n")


def on_sni(sock, server_name, _context):
    _sni[id(sock)] = server_name
    record({"event": "sni", "sni": server_name})


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *_args):
        pass

    def _handle(self):
        length = int(self.headers.get("Content-Length") or 0)
        body = self.rfile.read(length).decode("utf-8", "replace") if length else ""
        record({
            "event": "request",
            "sni": _sni.get(id(self.connection)),
            "method": self.command,
            "path": self.path,
            "host": self.headers.get("Host"),
            "delivery": self.headers.get("X-AssinaVelox-Delivery-Id"),
            "timestamp": self.headers.get("X-AssinaVelox-Timestamp"),
            "signature": self.headers.get("X-AssinaVelox-Signature"),
            "body": body,
        })

        if self.path == "/redirect":
            payload = b"moved"
            self.send_response(302)
            self.send_header("Location", f"https://127.0.0.1:{PORT}/internal")
        else:
            payload = ("recebido-" + LABEL).encode()
            self.send_response(200)
            self.send_header("Content-Type", "text/plain")
        self.send_header("Content-Length", str(len(payload)))
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(payload)

    do_GET = _handle
    do_POST = _handle


class Server(ThreadingHTTPServer):
    daemon_threads = True

    def handle_error(self, request, client_address):
        # Handshake recusado pelo cliente (certificado inválido para o nome): esperado no teste.
        pass


def main():
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.load_cert_chain(CERT, KEY)
    context.sni_callback = on_sni

    server = Server((BIND, PORT), Handler)
    server.socket = context.wrap_socket(server.socket, server_side=True)
    print("ready", flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
