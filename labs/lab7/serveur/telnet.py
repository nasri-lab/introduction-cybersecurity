import socketserver

CONFIG = (b"hostname R1\r\n"
          b"interface Gi0/0\r\n"
          b" ip address 10.10.0.1 255.255.255.0\r\n"
          b"snmp-server community public RO\r\n")


class Console(socketserver.StreamRequestHandler):
    def line(self):
        data = self.rfile.readline(200)
        if not data:
            return None
        # On ignore les octets de négociation Telnet et les caractères non imprimables
        return bytes(b for b in data if 32 <= b < 127).decode().strip()

    def handle(self):
        w = self.wfile.write
        w(b"\r\nRouteur R1 - acces d'administration\r\n\r\nlogin: ")
        user = self.line()
        w(b"Password: ")
        pwd = self.line()
        if (user, pwd) != ("admin", "R0uteur!26"):
            w(b"\r\nLogin incorrect\r\n")
            return
        while True:
            w(b"\r\nrouteur> ")
            cmd = self.line()
            if cmd is None or cmd in ("exit", "quit"):
                w(b"Bye\r\n")
                return
            if cmd == "show running-config":
                w(CONFIG)
            elif cmd:
                w(b"% Commande inconnue")


class Server(socketserver.ThreadingTCPServer):
    allow_reuse_address = True
    daemon_threads = True


Server(("0.0.0.0", 23), Console).serve_forever()