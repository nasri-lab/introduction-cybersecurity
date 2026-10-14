# 🛠️ LAB 3 : Audit, Exploitation (Red Team) et Durcissement (Blue Team) d'un Serveur Linux 

---

## 📌 1. Contexte & Objectifs du Laboratoire

Cet atelier pratique constitue le cœur des activités pratiques et de contrôle continu du module. En vous appuyant sur la préparation réalisée lors du **Lab 3 (Environnement de travail)**, vous allez jouer successivement le rôle d'un attaquant (**Red Team**) pour découvrir et exploiter les faiblesses d'un système, puis celui d'un administrateur système / RSSI (**Blue Team**) pour auditer, durcir et sécuriser l'infrastructure.

### Objectifs pédagogiques :
1. **Analyse de vulnérabilités & Scan (Red Team) :** Cartographier le système cible, identifier les services exposés, leurs versions et les vulnérabilités associées (CVEs).
2. **Exploitation & Élévation de privilèges (Red Team) :** Tester l'étanchéité des services (FTP, SSH) et tester des paires d'identifiants faibles.
3. **Audit de sécurité local (Blue Team) :** Utiliser **Lynis** et inspecter les fichiers journaux (`/var/log/auth.log`) pour évaluer l'état de sécurité initial et repérer les traces d'intrusion.
4. **Hardening & Filtrage réseau (Blue Team) :** Appliquer le principe du moindre privilège, configurer le pare-feu local (**iptables**), durcir la configuration SSH et automatiser le bannissement avec **Fail2ban**.
5. **Validation & Contrôle de non-régression :** Vérifier la fermeture de la surface d'attaque et mesurer l'augmentation du *Hardening Index*.

---

## 🛠️ 2. Architecture et Matériel Requis

* **Machine d'Attaque (Red Team) :** Kali Linux (déployée en conteneur Docker avec `--net=host` ou en Machine Virtuelle).
* **Machine Cible (Blue Team) :** Metasploitable 2 (déployée sous VirtualBox/VMware avec carte réseau en *Accès par pont* ou *Réseau privé hôte*).
* **Outils principaux :** `Nmap`, `Hydra`, `Metasploit Framework`, `Lynis`, `iptables`, `Fail2ban`, `OpenSSH`.

---

## 📋 3. Déroulement Pas à Pas du Laboratoire

### 🔍 PARTIE 1 : Reconnaissance et Évaluation des Risques (Red Team)

Depuis votre terminal **Kali Linux** (Docker ou VM) :

#### Étape 1.1 : Découverte et cartographie du réseau
1. Vérifiez la connectivité vers la machine cible Metasploitable 2 :
   ```bash
   ping -c 3 <IP_METASPLOITABLE_2>
   ```
2. Lancez un scan approfondi des ports et services avec **Nmap** :
   ```bash
   nmap -sV -sC -O -p- <IP_METASPLOITABLE_2> -oN scan_initial.txt
   ```

**Questions d'analyse (à inclure dans le rapport) :**
* Dressez un tableau récapitulatif des **5 services les plus critiques** identifiés (Port, Protocole, Service, Version exacte).
* Quelle version du serveur FTP (`vsftpd`) s'exécute sur le port 21 ? S'agit-il d'une version réputée vulnérable ?

#### Étape 1.2 : Attaque par Force Brute sur SSH
1. Utilisez **Hydra** pour tester la résistance du service SSH face à un dictionnaire de mots de passe courants :
   ```bash
   hydra -l msfadmin -P /usr/share/wordlists/rockyou.txt ssh://<IP_METASPLOITABLE_2>
   ```
2. Notez les identifiants valides obtenus et connectez-vous au serveur :
   ```bash
   ssh msfadmin@<IP_METASPLOITABLE_2>
   ```

---

### 🛡️ PARTIE 2 : Audit de Configuration Local et Traçabilité (Blue Team)

Connectez-vous sur la machine **Metasploitable 2** via SSH (`msfadmin` / `msfadmin`).

#### Étape 2.1 : Audit automatisé avec Lynis
1. Positionnez-vous dans le répertoire temporaire et récupérez/exécutez **Lynis** :
   ```bash
   cd /tmp
   wget https://downloads.cisofy.com/lynis/lynis-3.0.8.tar.gz
   tar -xvf lynis-3.0.8.tar.gz
   cd lynis
   ./lynis audit system
   ```
2. **Relevé de métriques :**
   * Relevez le **Hardening Index** initial (Score sur 100).
   * Notez au moins **3 avertissements majeurs (Warnings)** et **3 suggestions** fournies par le rapport Lynis.

#### Étape 2.2 : Analyse des fichiers journaux (Logs)
Inspectez les logs d'authentification pour identifier les empreintes laissées par l'attaque Hydra réalisée en Partie 1 :
```bash
sudo tail -n 30 /var/log/auth.log
```
* Identifiez l'adresse IP de l'attaquant et le nombre de tentatives de connexion échouées.

---

### 🔒 PARTIE 3 : Durcissement et Application de la Politique de Sécurité (Blue Team)

Toujours sur la machine **Metasploitable 2** :

#### Étape 3.1 : Durcissement du service OpenSSH
Éditez le fichier de configuration du serveur SSH :
```bash
sudo nano /etc/ssh/sshd_config
```
Appliquez les directives de sécurité suivantes :
* Interdire l'accès direct en tant que compte `root` : `PermitRootLogin no`
* Limiter le nombre maximal de tentatives d'authentification : `MaxAuthTries 3`
* Définir un délai d'inactivité (*Timeout*) : `ClientAliveInterval 300`

Redémarrez le service SSH pour appliquer les changements :
```bash
sudo /etc/init.d/ssh restart
```

#### Étape 3.2 : Arraisonnement et Filtrage Réseau avec iptables
Mettez en place une politique de pare-feu restrictive basée sur le principe du **Default DENY** (Bloquer tout le trafic non expressément autorisé).

Exécutez la série de commandes ci-dessous :
```bash
# 1. Réinitialisation complète des règles existantes
sudo iptables -F
sudo iptables -X

# 2. Définition des politiques par défaut
sudo iptables -P INPUT DROP
sudo iptables -P FORWARD DROP
sudo iptables -P OUTPUT ACCEPT

# 3. Autoriser la boucle locale (Loopback)
sudo iptables -A INPUT -i lo -j ACCEPT

# 4. Autoriser les connexions déjà établies et relatives
sudo iptables -A INPUT -m state --state ESTABLISHED,RELATED -j ACCEPT

# 5. Autoriser de façon stricte le flux SSH (Port 22)
sudo iptables -A INPUT -p tcp --dport 22 -j ACCEPT
```

#### Étape 3.3 : Désactivation des services non essentiels
Arrêtez et neutralisez les démons inutiles (ex: serveur FTP vulnérable) :
```bash
sudo /etc/init.d/vsftpd stop
```

---

### ✅ PARTIE 4 : Validation, Contrôle de Non-Régression et Re-Scan

#### Étape 4.1 : Test d'imperméabilité depuis Kali Linux (Red Team)
Relancez le scan Nmap depuis votre conteneur/VM Kali :
```bash
nmap -p- <IP_METASPLOITABLE_2>
```
* **Résultat attendu :** Tous les ports non autorisés (21 FTP, 80 HTTP, 23 Telnet, etc.) doivent désormais apparaître comme `filtered` ou `closed`. Seul le port 22 (SSH) doit rester ouvert.

#### Étape 4.2 : Re-scan d'audit Lynis sur Metasploitable 2 (Blue Team)
Relancez l'audit Lynis pour valider le niveau de durcissement :
```bash
cd /tmp/lynis
./lynis audit system
```
* **Résultat attendu :** Obtenir une hausse significative du **Hardening Index**.

---

## 📝 4. Travail à Rendre & Consignes du Compte-Rendu

Chaque binôme doit soumettre sur la plateforme pédagogique un rapport d'atelier au format **PDF** nommé `TP_GI2_Nom1_Nom2.pdf` contenant :

1. **Introduction & Architecture :** Les adresses IP et rôles de vos machines.
2. **Rapport Red Team :** 
   * Tableau des vulnérabilités découvertes via Nmap (Port, Service, Version, Risque).
   * Captures d'écran montrant l'exécution et le succès de l'attaque Hydra.
3. **Rapport Blue Team :**
   * Extrait du fichier `/var/log/auth.log` montrant la détection de l'attaque.
   * Copie des règles `iptables` appliquées et justification de chaque ligne.
4. **Bilan Comparatif :**
   * Capture du score **Lynis Initial** vs **Lynis Final**.
   * Tableau synthétique Récapitulatif : *Faiblesse identifiée ➔ Risque ➔ Action de Hardening appliquée*.

---

## 📊 5. Grille d'Évaluation (Sur 20 Points)

| Critère | Description | Barème |
| :--- | :--- | :--- |
| **Reconnaissance & Scan** | Exactitude de la cartographie Nmap et identification des CVEs/services | **3.0 pts** |
| **Exploitation Red Team** | Réussite du test de force brute SSH (Hydra) et documentation des étapes | **3.0 pts** |
| **Audit de Logs & Lynis** | Analyse correcte de `/var/log/auth.log` et pertinence des relevés Lynis | **4.0 pts** |
| **Hardening & Pare-feu** | Application conforme des règles `iptables` (Default DROP) et sécurisation SSH | **5.0 pts** |
| **Validation & Re-scan** | Preuve de la fermeture des ports non essentiels et hausse du Hardening Index | **3.0 pts** |
| **Rigueur du Rapport** | Qualité de la rédaction, présence des captures d'écran et structure PDF | **2.0 pts** |

```