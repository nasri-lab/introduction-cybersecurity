import socket
import time
import urllib.parse
import urllib.request

SRV = "10.10.0.10"


def http_session():
    data = urllib.parse.urlencode({"user": "alice", "password": "Ete2026!"}).encode()
    with urllib.request.urlopen(f"http://{SRV}/login", data=data, timeout=5) as r:
        cookie = r.headers.get("Set-Cookie", "").split(";")[0]
    req = urllib.request.Request(f"http://{SRV}/notes", headers={"Cookie": cookie})
    with urllib.request.urlopen(req, timeout=5) as r:
        r.read()
    return "HTTP ok"


def telnet_session():
    with socket.create_connection((SRV, 23), timeout=5) as s:
        def wait_for(token):
            buf = b""
            while token not in buf:
                chunk = s.recv(1024)
                if not chunk:
                    raise ConnectionError("connexion fermée")
                buf += chunk

        wait_for(b"login: ")
        s.sendall(b"admin\r\n")
        wait_for(b"Password: ")
        s.sendall(b"R0uteur!26\r\n")
        wait_for(b"routeur> ")
        s.sendall(b"show running-config\r\n")
        wait_for(b"routeur> ")
        s.sendall(b"exit\r\n")
    return "Telnet ok"


while True:
    for task in (http_session, telnet_session):
        stamp = time.strftime("%H:%M:%S")
        try:
            print(stamp, task(), flush=True)
        except Exception as e:
            print(stamp, task.__name__, "ÉCHEC :", e, flush=True)
    time.sleep(8)