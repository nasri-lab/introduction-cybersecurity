# Atelier Pratique — Préparation de l'Environnement de Travail

Bienvenue dans cet atelier pratique ! Avant de commencer les exercices d'audit et de tests d'intrusion, nous allons configurer votre environnement de travail.

Pour cet atelier, nous avons besoin de deux éléments principaux :

1. **La machine d'attaque** : Kali Linux (en Docker ou en Machine Virtuelle).

2. **La machine cible vulnérable** : Metasploitable 2.

## 1. Choix du mode d'installation pour Kali Linux

Pour que l'atelier se déroule de façon fluide sans ralentir votre ordinateur hôte, vous devez choisir le mode d'installation de Kali Linux en fonction de la configuration matérielle de votre PC.

> **Conseil :** Si votre ordinateur dispose de **8 Go de RAM** (ou moins), choisissez **impérativement l'Option A (Docker)**. Cela permettra de réserver la mémoire vive nécessaire au fonctionnement fluide de votre système Windows et de la VM cible Metasploitable 2.

## 2. Déploiement de la machine d'attaque Kali Linux

### Option A : Installation via Docker (Recommandé pour 8 Go de RAM)

Cette méthode permet de lancer Kali Linux sous forme de conteneur éphémère. Elle ne consomme de la mémoire RAM et du CPU que pendant l'exécution de vos commandes.

#### Étape 1 : Télécharger l'image officielle Kali Linux

Ouvrez votre terminal (PowerShell ou Invite de commandes sous Windows) et récupérez l'image minimale officielle :

```
docker pull kalilinux/kali-rolling

```

#### Étape 2 : Lancer le conteneur en mode réseau hôte

Démarrez le conteneur en partageant l'accès réseau de votre machine pour pouvoir joindre la VM vulnérable sans restriction :

```
docker run -it --net=host --name kali-atelier kalilinux/kali-rolling /bin/bash

```

#### Étape 3 : Installer la boîte à outils essentielle (Top 10)

L'image de base étant très épurée, mettez à jour le gestionnaire de paquets et installez les 10 outils majeurs de sécurité (Nmap, Metasploit, Hydra, Sqlmap, etc.) :

```
apt update && apt install -y kali-tools-top10

```

### Option B : Installation via Machine Virtuelle (Recommandé pour 16 Go de RAM ou +)

Cette méthode installe un OS complet avec une interface graphique (XFCE).

#### Étape 1 : Télécharger l'image préconfigurée pour VirtualBox / VMware

Rendez-vous sur le site officiel de Kali Linux ([kali.org/get-kali](https://www.kali.org/get-kali/#kali-platforms)) et téléchargez l'archive correspondante à votre hyperviseur (*Pre-built VMs*).

#### Étape 2 : Importer la VM dans l'hyperviseur

Décompressez l'archive et double-cliquez sur le fichier d'importation (`.vbox` pour VirtualBox ou `.vmx` pour VMware).

#### Étape 3 : Ajuster la mémoire RAM de la VM

Avant de démarrer la VM, ouvrez ses paramètres et allouez-lui **entre 2048 Mo (2 Go) et 4096 Mo (4 Go) de RAM**, selon la capacité globale de votre PC.

## 3. Déploiement de la machine cible vulnérable (Metasploitable 2)

Metasploitable 2 est une machine virtuelle Linux délibérément vulnérable, idéale pour pratiquer les scans de réseau, la recherche de vulnérabilités et l'exploitation de services.

1. **Télécharger Metasploitable 2** : Téléchargez l'archive officielle au format ZIP (contenant le disque virtuel `.vmdk`).

2. **Extraire le fichier** : Décompressez l'archive ZIP dans le dossier de votre choix sur votre machine hôte.

3. **Créer la machine virtuelle dans VirtualBox ou VMware** :

   * Ouvrez votre hyperviseur et choisissez **Créer une nouvelle VM**.

   * Sélectionnez le type d'OS : **Linux / Ubuntu (64-bit)**.

   * Allouez **512 Mo à 1024 Mo de RAM** (la machine est très légère).

   * Dans l'étape du disque dur, choisissez **"Utiliser un fichier de disque dur virtuel existant"** et sélectionnez le fichier `Metasploitable.vmdk` extrait précédemment.

4. **Démarrer la machine cible** : Lancez la VM Metasploitable 2. Lorsqu'elle a fini de démarrer, l'écran de connexion s'affiche :

   * **Identifiant par défaut** : `msfadmin`

   * **Mot de passe par défaut** : `msfadmin`

5. **Récupérer l'adresse IP de Metasploitable 2** : Une fois connecté, exécutez la commande suivante pour afficher son adresse IP :

   ```
   ifconfig
   
   ```

   *Notez cette adresse IP, elle sera la cible de tous vos exercices d'attaque.*

## 4. Configuration du réseau d'interconnexion

Pour que votre machine d'attaque Kali Linux puisse communiquer avec Metasploitable 2 :

* **Si vous utilisez Kali en Docker** : Dans les paramètres réseau de VirtualBox/VMware pour la VM Metasploitable 2, configurez la carte réseau en mode **Accès pont (Bridged)** ou **Réseau privé hôte (Host-Only)**. De cette façon, votre PC hôte et le conteneur Kali (qui utilise `--net=host`) pourront l'atteindre directement.

* **Si vous utilisez Kali en VM** : Placez les deux machines virtuelles (Kali et Metasploitable 2) sur le même réseau virtuel (ex. **Réseau privé hôte / Host-Only** ou **Réseau NAT / NAT Network**).

## 5. Validation de la préparation

### A. Test global de connectivité et d'outils

1. **Vérifier la présence de Nmap** :

   ```
   nmap --version
   
   ```

   * *Validation* : La commande doit afficher la version de Nmap installée sans renvoyer d'erreur.

2. **Tester la connectivité réseau vers Metasploitable 2** :

   ```
   ping -c 3 <IP_METASPLOITABLE_2>
   
   ```

   * *Validation* : Vous devez recevoir 3 réponses avec un temps de paquet (`time=...ms`) et 0 % de perte de paquets.

### B. Test spécifique aux utilisateurs Docker

Si vous avez opté pour Docker, effectuez ces vérifications supplémentaires :

1. **Vérifier l'accès au réseau hôte (`--net=host`)** :
   Dans le conteneur Kali, affichez les interfaces réseau :

   ```
   ip a
   
   ```

   * *Validation* : Vous devez voir les mêmes cartes réseau et adresses IP que celles de votre PC hôte (Windows/Linux), et non une IP interne Docker isolée (type `172.17.x.x`).

2. **Tester le lancement de la console Metasploit** :
   Lancez la console d'exploitation :

   ```
   msfconsole -q
   
   ```

   * *Validation* : Le prompt `msf6 >` doit s'afficher au bout de quelques secondes. Tapez `exit` pour quitter.

3. **Valider la gestion du conteneur en cas de fermeture** :
   Si vous fermez votre terminal, le conteneur s'arrête mais vos données et outils restent sauvegardés. Pour relancer votre conteneur Kali plus tard depuis votre hôte :

   ```
   docker start -ai kali-atelier
   
   ```

   * *Validation* : Vous devez vous retrouver directement dans le terminal de Kali avec tous vos outils déjà installés.