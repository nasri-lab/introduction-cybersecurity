# 🛠️ LAB 5 : Stockage Sécurisé des Mots de Passe & Cassage Hors-Ligne

**Module :** Introduction à la Sécurité des Systèmes (GI2 / 4ème Année Génie Informatique)  
**Durée conseillée :** 2 heures  
**Environnement recommandé :** Machine virtuelle Kali Linux / Ubuntu Server avec Python 3, MariaDB/MySQL, John the Ripper et Hashcat.

---

## Objectifs Pédagogiques
1. Identifier un algorithme cryptographique à partir du format de son empreinte (*hash identification*).
2. Évaluer expérimentalement la vulnérabilité des fonctions d'intégrité (MD5, SHA-1, SHA-256) face aux attaques par dictionnaire.
3. Quantifier l'impact du salage (*salt*) sur la neutralisation des tables précalculées.
4. Mesurer et comparer le coût temporel et matériel des fonctions de dérivation de clés (KDF : bcrypt, Argon2id).
5. Décortiquer la syntaxe des empreintes modulaires système (champ de `/etc/shadow`, modular crypt format).
6. Implémenter une authentification sécurisée de bout en bout dans une application (Python / MySQL) avec gestion du ré-hachage transparent (*rehash*).

---

## Partie 1 : Identification d'Empreintes Cryptographiques

Avant d'attaquer ou d'auditer une base de comptes, l'ingénieur en sécurité doit savoir identifier la nature de l'empreinte trouvée.

### Exercice 1.1 : Analyse manuelle de signatures
Analysez les chaînes suivantes et complétez le tableau :

1. `5d41402abc4b2a76b9719d911017c592`
2. `aaf4c61ddcc5e8a2dabede0f3b482cd9aea9434d`
3. `ef92b778bafe771e89245b89ecbc08a44a4e166c06659911881f383d4473e94f`
4. `$2b$12$e80i/D7r.X4g5l5H6bK8teP9aWz9kE6N5P5d3L4M3K2J1I0H9G8F7`
5. `$argon2id$v=19$m=65536,t=3,p=2$c29tZXNhbHQ$RdescudvJCsgqlrqrcAgLkAwMT4FsJa6PqCcQ`
6. `$6$qZ8yKx91$d4Ew9...5Tz`

| N° | Longueur (caractères / bits) | Caractéristiques / Préfixe | Algorithme suspecté |
|:---|:---|:---|:---|
| 1  | ... | ... | ... |


### Exercice 1.2 : Utilisation d'outils d'identification automatisés
Sur Kali Linux, testez l'empreinte N° 1 et N° 4 avec les utilitaires dédiés :
```bash
# Avec hash-identifier
hash-identifier ...

# Avec hashID
hashid -m ...
```
*Question :* Pourquoi `hashID` affiche-t-il le code mode Hashcat associé (ex: `mode 3200`) ? À quoi sert ce numéro lors de l'audit ?

Cherchez un hash-finder en ligne et refaites la même manipulation.

---

## Partie 2 : Attaque Pratique sur MD5, SHA-1 et SHA-256 (Hashcat / John)

Dans cette section, nous constatons la vitesse d'évaluation des fonctions d'intégrité non conçues pour stocker des mots de passe.

### Préparation de la cible
Créez un fichier `hashes_simples.txt` contenant :
```text
5d41402abc4b2a76b9719d911017c592
2aae6c35c94fcfb415dbe95f408b9ce91ee846ed
8d969eef6ecad3c29a3a629280e686cf0c3f5d5a86aff3ca12020c923adc6c92
```

### Exercice 2.1 : Benchmark matériel
Exécutez un benchmark de vitesse sur votre machine avec Hashcat :

(d'abord découvrez le paramètre -b et -m de hashcat)

```bash
hashcat -b -m 0      # MD5
hashcat -b -m 100    # SHA-1
hashcat -b -m 1400   # SHA-256
```
*Questions :*
1. Relevez la vitesse mesurée pour chacun (en MH/s ou GH/s).
2. Combien de combinaisons SHA-256 votre machine peut-elle tester en 1 minute ?

### Exercice 2.2 : Cassage par dictionnaire avec John the Ripper & Hashcat
Utilisez la liste de mots de passe de référence `rockyou.txt` :
```bash
# Avec John the Ripper :
john --format=raw-md5 hashes_simples.txt --wordlist=/usr/share/wordlists/rockyou.txt
john --show --format=raw-md5 hashes_simples.txt

# Avec Hashcat pour SHA-256 :
hashcat -m 1400 hashes_simples.txt /usr/share/wordlists/rockyou.txt
```
*Constat :* Observez l'instantanéité de la découverte pour des mots de passe courants.

---

## Partie 3 : Rôle du Salage Cryptographique (*Salt*)

### Scénario
Deux utilisateurs distincts (`alice` et `bob`) choisissent tous les deux le même mot de passe : `supersecret`.

1. **Sans sel (SHA-256 brut) :**
   ```python
   import hashlib
   h1 = hashlib.sha256(b"supersecret").hexdigest()
   h2 = hashlib.sha256(b"supersecret").hexdigest()
   print(f"Sans sel :\nAlice = {h1}\nBob   = {h2}")
   ```
   *Observation :* Les deux empreintes sont rigoureusement identiques. Quel est le risque, si les mêmes mots de passe donnent le même hash ?

2. **Avec sel unique par utilisateur :**
   ```python
   import os, hashlib

   salt_alice = os.urandom(16)
   salt_bob   = os.urandom(16)

   h_alice = hashlib.sha256(b"supersecret" + salt_alice).hexdigest()
   h_bob   = hashlib.sha256(b"supersecret" + salt_bob).hexdigest()

   print(f"\nAvec sel :\nAlice = {h_alice} (sel: {salt_alice.hex()})")
   print(f"Bob   = {h_bob} (sel: {salt_bob.hex()})")
   ```

### Exercice 3.1 : Simulation d'attaque ciblée
Placez l'empreinte salée d'Alice dans un fichier au format Hashcat `hash:sel` :
```text
<hash_alice>:<sel_alice_en_hex>
```
Lancez Hashcat avec le mode salé (`-m 1420` pour SHA-256 salé) :
```bash
hashcat -m 1420 cible_salee.txt /usr/share/wordlists/rockyou.txt --hex-salt
```
*Question de réflexion :* Pourquoi le sel empêche-t-il une table de type Rainbow Table tout en laissant l'attaquant casser le hash si le mot de passe est présent dans le dictionnaire ?

---

## Partie 4 : Analyse Structurelle d'un Hash KDF Moderne

Examinez attentivement cette chaîne générée par **Argon2id** :
```text
$argon2id$v=19$m=65536,t=3,p=2$q8/1yZ4+vYq8L...$N9uYmXW...
  [1]     [2]     [3]              [4]           [5]
```

### Exercice 4.1 : Décomposition des champs
Détaillez le rôle de chaque section délimitée par `$` :
* **[1] :** ...
* **[2] :** ...
* **[3] :** ...
  * `t=` : ...
  * `p=` : ...
* **[4] :** ...
* **[5] :** ...

---

## Partie 5 : Comparatif de Performance et de Complexité (Script Python)

Analysez puis exécutez le script `benchmark_kdf.py` suivant pour mesurer le temps nécessaire à la validation d'une empreinte sur votre machine.

```python
import time, hashlib, bcrypt
from argon2 import PasswordHasher

password = b"MotDePasseEtudiant2026!"

print("=== BENCHMARK DES FONCTIONS DE HACHAGE ===")

# 1. SHA-256
t0 = time.perf_counter()
for _ in range(10000):
    hashlib.sha256(password).digest()
t_sha = (time.perf_counter() - t0) / 10000
print(f"[SHA-256] Temps moyen par hachage : {t_sha*1000:.6f} ms")

# 2. bcrypt (Coûts 10, 12, 14)
for cost in [10, 12, 14]:
    t0 = time.perf_counter()
    salt = bcrypt.gensalt(rounds=cost)
    bcrypt.hashpw(password, salt)
    t_b = (time.perf_counter() - t0)
    print(f"[bcrypt] rounds={cost} -> Temps : {t_b*1000:.2f} ms")

# 3. Argon2id (Variation du coût mémoire)
ph_standard = PasswordHasher(time_cost=2, memory_cost=65536, parallelism=2)
t0 = time.perf_counter()
ph_standard.hash(password.decode())
t_argon = time.perf_counter() - t0
print(f"[Argon2id] m=64Mo, t=2, p=2 -> Temps : {t_argon*1000:.2f} ms")
```

### Questions d'analyse :
1. De combien de fois bcrypt (coût 12) est-il plus lent que SHA-256 ?
2. Pourquoi cette lenteur est-elle un avantage critique pour la défense du système ?

---

## Partie 6 : Intégration Réelle dans un Projet Web (Python / MySQL)

Nous mettons en place le schéma de base de données et le module d'authentification robuste avec **migration de coût transparente** (*Rehash*).

### 1. Script SQL d'initialisation (`schema.sql`)
```sql
CREATE DATABASE IF NOT EXISTS auth_demo;
USE auth_demo;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### 2. Implémentation du service d'authentification (`auth_service.py`)
```python
import mysql.connector
from argon2 import PasswordHasher
from argon2.exceptions import VerifyMismatchError

# Configuration Argon2id recommandée par l'OWASP
ph = PasswordHasher(time_cost=3, memory_cost=65536, parallelism=2)

def get_db_connection():
    return mysql.connector.connect(
        host="localhost",
        user="root",
        password="secretpassword",
        database="auth_demo"
    )

def register_user(username, clear_password):
    # Génération automatique du sel + calcul KDF
    h = ph.hash(clear_password)
    
    conn = get_db_connection()
    cursor = conn.cursor()
    try:
        cursor.execute("INSERT INTO users (username, password_hash) VALUES (%s, %s)", (username, h))
        conn.commit()
        print(f"[OK] Utilisateur '{username}' enregistré avec succès.")
    except Exception as e:
        print(f"[ERREUR] Enregistrement échoué : {e}")
    finally:
        cursor.close()
        conn.close()

def login_user(username, clear_password):
    conn = get_db_connection()
    cursor = conn.cursor(dictionary=True)
    cursor.execute("SELECT id, password_hash FROM users WHERE username = %s", (username,))
    user = cursor.fetchone()

    if not user:
        print("[AUTH FAILED] Utilisateur inconnu.")
        return False

    stored_hash = user["password_hash"]

    try:
        # Vérification cryptographique
        ph.verify(stored_hash, clear_password)
        print(f"[AUTH SUCCESS] Bienvenue, {username} !")

        # Mécanisme de ré-hachage transparent (si la politique de coût a augmenté)
        if ph.check_needs_rehash(stored_hash):
            print("[INFO] Migration de sécurité : mise à niveau des paramètres du hash...")
            new_hash = ph.hash(clear_password)
            update_cursor = conn.cursor()
            update_cursor.execute("UPDATE users SET password_hash = %s WHERE id = %s", (new_hash, user["id"]))
            conn.commit()
            update_cursor.close()
            print("[INFO] Empreinte mise à jour en base de données.")

        return True

    except VerifyMismatchError:
        print("[AUTH FAILED] Mot de passe incorrect.")
        return False
    finally:
        cursor.close()
        conn.close()

if __name__ == "__main__":
    register_user("etudiant_gi", "P@ssw0rdProjet2026!")
    # Test connexion réussie
    login_user("etudiant_gi", "P@ssw0rdProjet2026!")
    # Test tentative erronée
    login_user("etudiant_gi", "MauvaisMotDePasse")
```

---

## Rapport de TP
   * Tableau de benchmark de votre machine (Partie 2 et Partie 5).
   * Réponses justifiées aux questions de réflexion.
   * Capture d'écran de l'exécution de la mise à jour automatique de coût (*check_needs_rehash*).