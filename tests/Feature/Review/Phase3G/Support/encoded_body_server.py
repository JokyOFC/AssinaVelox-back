"""Servidor HTTPS local da revisão G (ConnectorDownloadContentEncodingCapTest).

Uso: python encoded_body_server.py <endereço> <porta> <cert.pem> <chave.pem> <log.jsonl>

  /bomb     se o cliente anunciar gzip em Accept-Encoding: corpo com Content-Encoding: gzip,
            Content-Length = tamanho COMPRIMIDO (~48 KB) e 48 MB depois de descomprimido
            (o que um provedor que comprime a transferência faria com um arquivo compressível).
  /chunked  controle: 48 MB sem compressão, Transfer-Encoding: chunked, sem Content-Length.

Registra uma linha JSON por requisição (caminho e Accept-Encoding recebido). Só escuta no
loopback; é encerrado pelo teste pelo PID que ele iniciou.
"""

import gzip
import json
import ssl
import sys
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

BIND, PORT, CERT, KEY, LOG = sys.argv[1], int(sys.argv[2]), sys.argv[3], sys.argv[4], sys.argv[5]
DECODED_BYTES = 48 * 1024 * 1024
BOMB = gzip.compress(b"\0" * DECODED_BYTES, 9)
CHUNK = b"A" * 65536
_lock = threading.Lock()


def record(entry):
    with _lock:
        with open(LOG, "a", encoding="utf-8") as handle:
            handle.write(json.dumps(entry) + "\n")


class Handler(BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"

    def log_message(self, *_args):
        pass

    def do_GET(self):
        accept = self.headers.get("Accept-Encoding") or ""
        record({"path": self.path, "accept_encoding": accept, "compressed_bytes": len(BOMB)})

        try:
            if self.path.startswith("/bomb"):
                self.send_response(200)
                self.send_header("Content-Type", "application/pdf")
                self.send_header("Connection", "close")

                # Content-Encoding NÃO pedido: o Guzzle manda `Accept-Encoding:` vazio, mas deixa
                # CURLOPT_ENCODING = '' — o cURL decodifica o que vier.
                self.send_header("Content-Encoding", "gzip")
                self.send_header("Content-Length", str(len(BOMB)))
                self.end_headers()
                self.wfile.write(BOMB)
            elif self.path.startswith("/chunked"):
                self.send_response(200)
                self.send_header("Content-Type", "application/pdf")
                self.send_header("Transfer-Encoding", "chunked")
                self.send_header("Connection", "close")
                self.end_headers()

                for _ in range(DECODED_BYTES // len(CHUNK)):
                    self.wfile.write(b"%x\r\n" % len(CHUNK) + CHUNK + b"\r\n")

                self.wfile.write(b"0\r\n\r\n")
            else:
                self.send_response(404)
                self.send_header("Content-Length", "0")
                self.send_header("Connection", "close")
                self.end_headers()
        except (BrokenPipeError, ConnectionResetError, ConnectionAbortedError, ssl.SSLError, OSError):
            # O cliente abortou a transferência (teto de bytes): esperado no controle.
            pass


class Server(ThreadingHTTPServer):
    daemon_threads = True

    def handle_error(self, request, client_address):
        pass


def main():
    context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    context.load_cert_chain(CERT, KEY)

    server = Server((BIND, PORT), Handler)
    server.socket = context.wrap_socket(server.socket, server_side=True)
    print("ready", flush=True)
    server.serve_forever()


if __name__ == "__main__":
    main()
