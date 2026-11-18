import sys

CONFIG = ("hostname R1\n"
          "interface Gi0/0\n"
          " ip address 10.10.0.1 255.255.255.0\n"
          "snmp-server community public RO\n")


def prompt():
    sys.stdout.write("routeur> ")
    sys.stdout.flush()


print("Routeur R1 - acces d'administration (SSH)")
prompt()
for line in sys.stdin:
    cmd = line.strip()
    if cmd in ("exit", "quit"):
        print("Bye")
        break
    if cmd == "show running-config":
        sys.stdout.write(CONFIG)
    elif cmd:
        print("% Commande inconnue")
    prompt()