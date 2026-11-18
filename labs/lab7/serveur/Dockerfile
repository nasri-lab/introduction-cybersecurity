FROM python:3.12-slim
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      iproute2 iptables openssh-server wireguard-tools openssl \
 && rm -rf /var/lib/apt/lists/* \
 && mkdir -p /run/sshd /home/admin/.ssh \
 && useradd -M -d /home/admin -s /bin/sh admin \
 && usermod -p '*' admin \
 && chown -R admin:admin /home/admin \
 && chmod 700 /home/admin/.ssh
COPY web.py telnet.py console.py /app/
CMD ["sh", "-c", "python3 -u /app/telnet.py & exec python3 -u /app/web.py"]