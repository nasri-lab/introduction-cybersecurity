FROM kalilinux/kali-rolling
RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      dsniff tcpdump tshark hping3 iproute2 iputils-ping curl telnet \
 && rm -rf /var/lib/apt/lists/*
CMD ["sleep", "infinity"]