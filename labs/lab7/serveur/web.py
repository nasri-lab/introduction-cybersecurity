from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import parse_qs
import secrets

SESSIONS = set()
FORM = """<html><body><h1>Intranet</h1>
<form method="post" action="/login">
  <input name="user" placeholder="Identifiant">
  <input name="password" type="password" placeholder="Mot de passe">
  <button>Connexion</button>
</form></body></html>""".encode()


class Handler(BaseHTTPRequestHandler):
    def reply(self, code, body, headers=()):
        self.send_response(code)
        for k, v in headers:
            self.send_header(k, v)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def do_GET(self):
        if self.path == "/notes":
            sid = self.headers.get("Cookie", "").removeprefix("session=")
            if sid in SESSIONS:
                return self.reply(200, "<p>Notes internes : réunion budget jeudi.</p>".encode())
            return self.reply(403, b"<p>Non connecte</p>")
        self.reply(200, FORM)

    def do_POST(self):
        size = int(self.headers.get("Content-Length", 0))
        form = parse_qs(self.rfile.read(size).decode())
        if form.get("user") == ["alice"] and form.get("password") == ["Ete2026!"]:
            sid = secrets.token_hex(8)
            SESSIONS.add(sid)
            return self.reply(200, b"<p>Bienvenue alice</p>",
                              [("Set-Cookie", f"session={sid}; Path=/")])
        self.reply(401, b"<p>Identifiants invalides</p>")

    def log_message(self, *args):
        pass


class Server(ThreadingHTTPServer):
    request_queue_size = 128


Server(("0.0.0.0", 80), Handler).serve_forever()