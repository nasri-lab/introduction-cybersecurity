# Lab IAM — Du silo d'identités au SSO (Keycloak + LDAP)

## Le scénario

Une école possède trois applications web développées séparément. Chacune gère ses propres comptes.
Pendant ce lab, vous faites évoluer **le même projet** en trois étapes, en modifiant vous-même le code et la
configuration :

```
ÉTAPE 1 : silos                 ÉTAPE 2 : SSO OIDC              ÉTAPE 3 : annuaire unique

 BiblioGI ── MySQL (users)       BiblioGI ─┐                     BiblioGI ─┐
 HelpDesk ── MySQL (users)       HelpDesk ─┴─ Keycloak           HelpDesk ─┴─ Keycloak ─┐
 IntraGI  ── MySQL (users)       IntraGI  ── MySQL (users)       IntraGI  ──────────────┴─ OpenLDAP

 3 comptes, 3 mots de passe      1 compte pour 2 applis           1 compte pour 3 applis
```

| Application | Techno | Port | Fonction |
|-------------|--------|------|----------|
| **BiblioGI** | PHP 8.3 + Apache | 8081 | Bibliothèque : catalogue, emprunts, ajout de livres |
| **HelpDesk** | Python 3.12 + Flask | 8082 | Tickets de support |
| **IntraGI** | PHP 8.3 + Apache | 8083 | Intranet : actualités, annuaire du personnel |

- **Étape 1** : vous découvrez les trois applications telles qu'elles sont, chacune avec ses comptes.
- **Étape 2** : vous ajoutez Keycloak et **modifiez le code** de BiblioGI et de HelpDesk pour qu'ils lui délèguent
  l'authentification (OpenID Connect).
- **Étape 3** : vous ajoutez un annuaire OpenLDAP, vous y **créez des utilisateurs**, vous **configurez Keycloak**
  pour qu'il l'utilise, puis vous **modifiez IntraGI** pour qu'il s'authentifie directement auprès de l'annuaire.

### Commandes Docker utiles (pour tout le lab)

Toutes les commandes se lancent **dans le dossier `lab-iam-sso`**.

| Besoin | Commande |
|--------|----------|
| Démarrer / appliquer vos modifications (code, `docker-compose.yml`, `.env`) | `docker compose up -d --build` |
| Voir l'état des services | `docker compose ps` |
| Suivre les journaux d'un service | `docker compose logs -f keycloak` (Ctrl+C pour quitter) |
| Redémarrer un service | `docker compose restart app1-biblio` |
| Copier un fichier dans un conteneur | `docker compose cp fichier service:/tmp/fichier` |
| Arrêter (données conservées) | `docker compose down` |
| Arrêter et **tout effacer** | `docker compose down -v` |

> ⚠ **N'utilisez jamais `docker compose down -v` entre deux étapes.** Le lab est cumulatif : l'étape 2 réutilise
> les données de l'étape 1, et l'étape 3 la configuration Keycloak de l'étape 2. `-v` efface tout cela.

> **Le code est copié dans les images** : après chaque modification d'un fichier source, relancez
> `docker compose up -d --build`, sinon l'ancienne version continue de tourner.

> **Toujours `http://localhost:…`, jamais `http://127.0.0.1:…`** : à partir de l'étape 2, Keycloak n'accepte que
> les adresses déclarées, et elles sont écrites avec `localhost`.

---

# Étape 1 — Trois applications, trois silos d'identités

**Objectif** : constater ce que coûte une gestion des comptes application par application.

## 1.1 Vocabulaire de l'étape

| Terme | Définition |
|-------|-----------|
| **Identification** | Annoncer qui l'on est (saisir un identifiant) |
| **Authentification** | Prouver cette identité (mot de passe, biométrie, second facteur…) |
| **Autorisation** | Décider de ce que l'identité authentifiée a le droit de faire |
| **Compte local** | Compte stocké dans la base de l'application elle-même |
| **Silo d'identités** | Situation où chaque application gère ses comptes de son côté, sans lien avec les autres |
| **Provisioning / déprovisioning** | Création / suppression (ou désactivation) des comptes au cours de la vie d'un utilisateur : arrivée, changement de poste, départ |
| **Compte orphelin** | Compte resté actif alors que son titulaire a quitté l'organisation |
| **RBAC** | *Role-Based Access Control* : les droits sont attachés à des rôles (`etudiant`, `enseignant`, `admin`), les rôles aux utilisateurs |
| **Hachage de mot de passe** | Transformation à sens unique stockée à la place du mot de passe (bcrypt, scrypt…) |
| **Session / cookie de session** | Après l'authentification, l'application remet au navigateur un cookie qui l'identifie aux requêtes suivantes |
| **Moindre privilège** | Ne donner à chaque composant que les droits strictement nécessaires (ici : un compte MySQL par application, limité à sa base) |

## 1.2 Organisation du projet

```
lab-iam-sso/
├── docker-compose.yml          5 services : mysql, adminer, app1-biblio, app2-helpdesk, app3-intra
├── mysql/
│   └── init.sql                3 bases, 3 comptes MySQL, 3 tables users DIFFÉRENTES
├── app1-biblio/                BiblioGI (PHP)
│   ├── Dockerfile
│   └── src/
│       ├── inc/bootstrap.php   session, base de données, CSRF, gabarit HTML, comptes initiaux
│       ├── index.php           catalogue et emprunts
│       ├── login.php           vérification du mot de passe (password_verify, bcrypt)
│       ├── register.php        inscription libre
│       ├── admin.php           gestion des comptes : rôle, activation, réinitialisation, suppression
│       └── logout.php
├── app2-helpdesk/              HelpDesk (Python / Flask)
│   ├── Dockerfile, requirements.txt
│   ├── app.py                  toute l'application : login, inscription, tickets, admin
│   └── templates/              pages HTML (Jinja2)
└── app3-intra/                 IntraGI (PHP)
    ├── Dockerfile
    └── src/
        ├── inc/bootstrap.php
        ├── index.php           actualités
        ├── login.php           vérification du mot de passe (MD5)
        ├── annuaire.php        annuaire tiré de la table locale
        ├── admin.php           création des comptes par le service RH
        └── logout.php
```

## 1.3 Adresses

| Service | URL | Identifiants |
|---------|-----|--------------|
| BiblioGI | http://localhost:8081 | voir 1.5 |
| HelpDesk | http://localhost:8082 | voir 1.5 |
| IntraGI | http://localhost:8083 | voir 1.5 |
| Adminer (bases MySQL) | http://localhost:8088 | Système `MySQL`, serveur `mysql`, `root` / `rootpass` |

## 1.4 Démarrer

```bash
cd lab-iam-sso
docker compose up -d --build
docker compose ps
```

✅ **Attendu** (après 20 à 40 s) : 5 services `Up`, dont `mysql` en `(healthy)`.

```
NAME                      SERVICE         STATUS
lab-iam-adminer-1         adminer         Up
lab-iam-app1-biblio-1     app1-biblio     Up
lab-iam-app2-helpdesk-1   app2-helpdesk   Up
lab-iam-app3-intra-1      app3-intra      Up
lab-iam-mysql-1           mysql           Up (healthy)
```

❌ **Problème si** :
- `app1-biblio` / `app3-intra` restent en `Created` → MySQL n'est pas encore prêt : attendez, puis `docker compose up -d` ;
- `app2-helpdesk` en `Restarting` → `docker compose logs app2-helpdesk` ; un `MySQL injoignable` disparaît en général au redémarrage suivant ;
- `port is already allocated` → un autre logiciel (XAMPP, Apache, Tomcat…) occupe le port : fermez-le.

## 1.5 Les comptes de l'étape 1

Chaque application a **ses propres comptes et ses propres mots de passe** :

| Application | Compte | Mot de passe | Droits |
|-------------|--------|--------------|--------|
| BiblioGI | `admin_biblio` | `Biblio@2026` | admin |
| BiblioGI | `alice` | `alice123` | etudiant |
| BiblioGI | `bob` | `Bob!2024` | enseignant |
| HelpDesk | `admin` | `Admin#Help1` | administrateur + support |
| HelpDesk | `alice` | `Alice2026!` | utilisateur |
| HelpDesk | `carol` | `Carol-Support9` | support |
| IntraGI | `rh_admin` | `rh2023` | RH (admin) |
| IntraGI | `alice` | `alice` | utilisateur |
| IntraGI | `bob` | `123456` | utilisateur |
| IntraGI | `mehdi` | `password` | utilisateur (ancien stagiaire, parti en juin) |

## 1.6 Manipulations et résultats attendus

| Manipulation | ✅ Résultat attendu | ❌ Si vous voyez… |
|--------------|--------------------|-------------------|
| BiblioGI, login `alice` / `alice123` | Message vert « Bienvenue alice ! », en-tête « Déconnexion (alice · etudiant) » | « Identifiants invalides. » → vérifiez que vous utilisez le mot de passe **de BiblioGI** |
| Emprunter un livre (gardez-le jusqu'à l'étape 2 !) | « Livre emprunté. », la ligne affiche « Emprunté par alice » et un bouton « Rendre » | « Jeton CSRF invalide » → rechargez la page |
| http://localhost:8081/admin.php en `alice` | « 403 - Votre rôle (etudiant) ne permet pas cette action. » | La page d'administration s'affiche → vous êtes connecté avec un autre compte |
| BiblioGI, login `admin_biblio` → *Utilisateurs* | Tableau des 3 comptes avec boutons de rôle, activation, réinitialisation, suppression | — |
| BiblioGI, *Inscription* avec un mot de passe de 5 caractères | « Mot de passe : 6 caractères minimum. » | — |
| HelpDesk, login `alice` / `Alice2026!` | Page « Mes tickets », « Bienvenue Alice Martin ! » | « Identifiants invalides. » si vous reprenez le mot de passe de BiblioGI |
| HelpDesk, créer un ticket (gardez-le aussi) | « Ticket créé. », statut « ouvert » | — |
| HelpDesk, login `carol` | « Tous les tickets » avec le badge « support » et un bouton « Fermer » | — |
| HelpDesk, *Inscription* avec `motdepasse` | « Mot de passe : 8 caractères minimum dont un chiffre. » | — |
| IntraGI, login `alice` / `alice` | « Bonjour Alice Martin 👋 » et 2 actualités | — |
| IntraGI, *Annuaire* | 4 personnes avec fonction, e-mail, téléphone | — |
| IntraGI, login `mehdi` / `password` | La connexion **réussit**, alors que Mehdi a quitté l'école | — |

**Le scénario du départ** : Mehdi est parti, et Bob devient administrateur de la bibliothèque. Faites les
modifications dans **toutes** les applications concernées et comptez vos opérations (et vos connexions).

**Observer les bases** : ouvrez Adminer (http://localhost:8088), puis pour chacune des bases `biblio`,
`helpdesk` et `intragi` : table `users` → *Afficher les données*.

✅ **Attendu** : trois tables qui ne se ressemblent pas.

| Base | Identifiant | Secret stocké | Modèle de droits |
|------|-------------|---------------|------------------|
| `biblio` | `username` | `password_hash` (bcrypt, `$2y$10$…`) | colonne `role` (3 valeurs) + `active` |
| `helpdesk` | `login` | `pwd` (scrypt, `scrypt:32768:8:1$…`) | deux booléens `is_admin`, `is_staff` |
| `intragi` | `uid` | `password_md5` (32 caractères hexadécimaux) | un booléen `is_admin` |

Une même personne (Alice) existe donc trois fois, sous trois identifiants techniques, sans aucun lien entre eux.

**Ne faites pas `docker compose down -v`** : l'étape 2 continue sur ces données.

---

# Étape 2 — Migrer BiblioGI et HelpDesk vers Keycloak (OpenID Connect)

**Objectif** : BiblioGI et HelpDesk ne vérifient plus aucun mot de passe. Ils redirigent le navigateur vers
Keycloak, reçoivent un **ID Token** signé et en extraient l'identité et le rôle. IntraGI n'est pas modifiée.

## 2.1 Vocabulaire de l'étape

| Terme | Définition |
|-------|-----------|
| **IdP** (*Identity Provider*) | Service qui authentifie les utilisateurs pour le compte d'autres applications. Ici : **Keycloak** |
| **Client** (ou *Relying Party*) | Application qui fait confiance à l'IdP. Ici : `biblio` et `helpdesk` |
| **Realm** | Espace isolé de Keycloak, avec ses propres utilisateurs, rôles et clients. Ici : `gi4-lab` |
| **SSO** (*Single Sign-On*) | Une seule authentification auprès de l'IdP ouvre l'accès à plusieurs applications |
| **OAuth 2.0** | Protocole de **délégation d'autorisation** (obtenir un jeton d'accès) |
| **OpenID Connect (OIDC)** | Couche d'**authentification** au-dessus d'OAuth 2.0 : ajoute l'ID Token |
| **Flux Authorization Code** | Le navigateur rapporte un *code* à usage unique ; l'application l'échange elle-même contre les jetons, serveur à serveur |
| **PKCE** | *Proof Key for Code Exchange* : l'application garde un secret (`code_verifier`) et n'en envoie que l'empreinte (`code_challenge`) ; un code volé devient inutilisable |
| **`state`** | Valeur aléatoire vérifiée au retour : protège contre le CSRF sur la page de retour |
| **`nonce`** | Valeur aléatoire recopiée dans l'ID Token : protège contre le rejeu d'un jeton |
| **JWT** | *JSON Web Token* : `en-tête.charge.signature`, chaque partie encodée en Base64URL, signé par Keycloak (RS256) |
| **Claim** | Champ d'un JWT : `sub` (identifiant unique et stable), `iss` (émetteur), `aud` (destinataire), `exp` (expiration), `preferred_username`, `roles`… |
| **ID Token / Access Token** | Le premier dit **qui** est l'utilisateur (destiné à l'application) ; le second dit **ce qu'il peut faire** (destiné à une API) |
| **Client confidentiel / client secret** | Application capable de garder un secret côté serveur ; elle s'authentifie avec lui auprès de Keycloak |
| **Redirect URI** | Adresse exacte où Keycloak a le droit de renvoyer le navigateur après la connexion |
| **JWKS** | Clés publiques du realm (`/certs`), utilisées pour vérifier la signature des jetons |
| **Mapper** | Règle Keycloak qui ajoute une information dans les jetons (ici : la liste des rôles dans le claim `roles`) |
| **Provisioning JIT** | *Just-In-Time* : le profil local est créé ou mis à jour automatiquement à chaque connexion |
| **Front-channel / back-channel** | Échanges qui passent par le navigateur (redirections) / échanges directs serveur à serveur |

### Comment se déroule une connexion OIDC

```
 Navigateur              BiblioGI (PHP)                       Keycloak
    │  clic « Se connecter »  │                                    │
    │────────────────────────►│ 1. génère state, nonce, PKCE       │
    │◄── redirection 302 ─────│                                    │
    │──────── GET /auth?client_id=biblio&redirect_uri=…&state=… ──►│
    │◄──────────────── page de connexion Keycloak ─────────────────│
    │───────────────── identifiant + mot de passe ────────────────►│  ← seul endroit où
    │◄──── redirection vers /callback.php?code=XYZ&state=… ────────│    le mot de passe
    │────────────────────────►│                                    │    est saisi
    │                         │ 2. POST /token (code + secret      │
    │                         │    + code_verifier) ──────────────►│
    │                         │◄──────── id_token + access_token ──│
    │                         │ 3. vérifie signature, iss, aud,    │
    │                         │    exp, nonce, puis le rôle        │
    │◄── session ouverte ─────│                                    │
```

Deux adresses de Keycloak coexistent, et c'est voulu :

- `http://localhost:8080` : utilisée par **votre navigateur** et inscrite dans les jetons (`iss`) ;
- `http://keycloak:8080` : utilisée par **les applications** pour leurs appels directs (`/token`, `/certs`),
  à l'intérieur du réseau Docker.

## 2.2 Adresses

| Service | URL | Identifiants |
|---------|-----|--------------|
| BiblioGI | http://localhost:8081 | comptes Keycloak |
| HelpDesk | http://localhost:8082 | comptes Keycloak |
| IntraGI | http://localhost:8083 | inchangés (étape 1) |
| Adminer | http://localhost:8088 | `root` / `rootpass` |
| **Keycloak** (console d'administration) | http://localhost:8080 | `admin` / `admin` |

## 2.3 Ajouter Keycloak au projet

Dans `docker-compose.yml`, ajoutez le service `keycloak` juste avant `app1-biblio:` :

```yaml
  # ---- AJOUT ÉTAPE 2 : fournisseur d'identité ----------------------
  keycloak:
    image: quay.io/keycloak/keycloak:26.4
    command: start-dev
    environment:
      KC_BOOTSTRAP_ADMIN_USERNAME: admin
      KC_BOOTSTRAP_ADMIN_PASSWORD: admin
      KC_HOSTNAME: http://localhost:8080         # URL vue par le navigateur
      KC_HOSTNAME_BACKCHANNEL_DYNAMIC: "true"    # les applis peuvent appeler http://keycloak:8080
    ports: ["8080:8080"]
    volumes:
      - kc_data:/opt/keycloak/data
```

Ajoutez le volume en bas du fichier :

```yaml
volumes:
  mysql_data:
  kc_data:                       # AJOUT ÉTAPE 2
```

Démarrez **seulement Keycloak** pour l'instant :

```bash
docker compose up -d keycloak
docker compose logs -f keycloak
```

✅ **Attendu** : après 20 à 60 s, une ligne `Keycloak 26.4… started in …s. Listening on: http://0.0.0.0:8080`.
Faites Ctrl+C. http://localhost:8080 affiche la page de connexion de la console.

## 2.4 Configurer Keycloak

Connectez-vous à http://localhost:8080 avec `admin` / `admin`.

**a) Le realm** — menu déroulant en haut à gauche (*Keycloak* / *master*) → *Create realm* → *Realm name* :
`gi4-lab` → *Create*.
✅ `gi4-lab` est sélectionné en haut à gauche. Vérifiez-le avant chaque manipulation : tout ce qui suit se fait
dans ce realm, **pas** dans `master`.

**b) Les rôles** — *Realm roles* → *Create role* → `etudiant` → *Save*. Recommencez pour `enseignant` et `admin`.
✅ La liste contient vos 3 rôles, plus `default-roles-gi4-lab`, `offline_access` et `uma_authorization`.

**c) Les utilisateurs** — *Users* → *Create new user* :
- *Email verified* : **On** ; *Username*, *Email* (`<username>@gi4.lab`), *First name*, *Last name* → *Create* ;
- onglet *Credentials* → *Set password* → mot de passe, *Temporary* : **Off** → *Save* → *Save password* ;
- onglet *Role mapping* → *Assign role* → filtre *Filter by realm roles* → cochez le rôle → *Assign*.

| Username | Prénom / Nom | Mot de passe | Rôle |
|----------|--------------|--------------|------|
| `alice` | Alice Martin | `Alice#2026` | etudiant |
| `bob` | Bob Durand | `Bob#2026` | enseignant |
| `carol` | Carol Benali | `Carol#2026` | admin |
| `eve` | Eve Curieuse | `Eve#2026` | *aucun* |

✅ Dans *Role mapping*, le rôle apparaît à côté de `default-roles-gi4-lab`.

**d) Les deux clients** — *Clients* → *Create client* :

| Écran | Champ | BiblioGI | HelpDesk |
|-------|-------|----------|----------|
| General settings | Client type | OpenID Connect | OpenID Connect |
| | Client ID | `biblio` | `helpdesk` |
| Capability config | Client authentication | **On** | **On** |
| | Authentication flow | *Standard flow* uniquement (décochez *Direct access grants*) | idem |
| Login settings | Root URL | `http://localhost:8081` | `http://localhost:8082` |
| | Valid redirect URIs | `http://localhost:8081/callback.php` | `http://localhost:8082/callback` |
| | Valid post logout redirect URIs | `http://localhost:8081/*` | `http://localhost:8082/*` |
| | Web origins | `http://localhost:8081` | `http://localhost:8082` |
| Onglet *Advanced* (après création) | Proof Key for Code Exchange Code Challenge Method | `S256` | `S256` |

✅ Un onglet **Credentials** apparaît sur chaque client (il n'existe que si *Client authentication* est sur On).

**e) Faire voyager les rôles dans les jetons** — *Clients* → `biblio` → onglet *Client scopes* →
`biblio-dedicated` → *Configure a new mapper* (ou *Add mapper* → *By configuration*) → **User Realm Role** :

*Pourquoi cette étape ?* À l'étape b), vous avez créé les rôles `etudiant`, `enseignant` et `admin`, et vous les
avez attribués aux utilisateurs. Mais par défaut, Keycloak ne place pas ces rôles dans l'**ID Token**. Il les met
seulement dans l'Access Token, et à un endroit imbriqué (`realm_access.roles`). Or le code de BiblioGI
(`oidc_app_role()` dans `oidc.php`) et celui de HelpDesk (`app_flags()` dans `app.py`) lisent un claim nommé
`roles` **dans l'ID Token**. Sans réglage, ils ne trouveraient aucun rôle et refuseraient tout le monde, même
alice (« … aucun rôle ne vous autorise … »).

On ajoute donc un **mapper** : une règle qui dit à Keycloak « au moment de fabriquer les jetons pour ce client,
ajoute un claim `roles` qui contient la liste des rôles du realm de l'utilisateur ».

*Où le créer ?* Chaque client possède un *client scope* « dédié », nommé `<client>-dedicated`. Ce qu'on y met ne
s'applique qu'à ce client. On crée donc le même mapper deux fois : une fois pour `biblio`, une fois pour `helpdesk`.

1. Menu de gauche → *Clients* → cliquez sur `biblio`.
2. Onglet **Client scopes** (en haut). Dans la liste, cliquez sur **`biblio-dedicated`** (c'est un lien ; sa
   description est *Dedicated scope and mappers for this client*).
3. Onglet *Mappers* :
   - si la liste est vide, cliquez sur **Configure a new mapper** ;
   - sinon, cliquez sur **Add mapper** → **By configuration**.
4. Dans la liste des types de mappers, choisissez **User Realm Role**.
5. Remplissez le formulaire, puis cliquez sur *Save* :


| Champ | Valeur |
|-------|--------|
| Name | `roles-du-realm` |
| Multivalued | On |
| Token Claim Name | `roles` |
| Add to ID token / access token / userinfo | On / On / On |

Recommencez pour `helpdesk` (`helpdesk-dedicated`).
✅ Dans `biblio-dedicated` → *Mappers*, une ligne `roles-du-realm` de type *User Realm Role*.

**f) Les secrets** — créez le fichier `.env` à la racine du projet, et collez-y les secrets (onglet
*Credentials* de chaque client → *Client Secret* → icône copier) :

```ini
BIBLIO_CLIENT_SECRET=collez-ici-le-secret-du-client-biblio
HELPDESK_CLIENT_SECRET=collez-ici-le-secret-du-client-helpdesk
```

## 2.5 Brancher les applications sur Keycloak (docker-compose.yml)

Ajoutez ces variables dans `environment:` de **`app1-biblio`**, après `DB_PASS` :

```yaml
      # ---- AJOUT ÉTAPE 2 ----
      APP_URL: http://localhost:8081
      KC_PUBLIC_URL: http://localhost:8080       # utilisée par le NAVIGATEUR
      KC_INTERNAL_URL: http://keycloak:8080      # utilisée par le SERVEUR PHP
      KC_REALM: gi4-lab
      OIDC_CLIENT_ID: biblio
      OIDC_CLIENT_SECRET: ${BIBLIO_CLIENT_SECRET}
```

Et celles-ci dans `environment:` de **`app2-helpdesk`**, après `FLASK_SECRET` :

```yaml
      # ---- AJOUT ÉTAPE 2 ----
      APP_URL: http://localhost:8082
      KC_PUBLIC_URL: http://localhost:8080
      KC_INTERNAL_URL: http://keycloak:8080
      KC_REALM: gi4-lab
      OIDC_CLIENT_ID: helpdesk
      OIDC_CLIENT_SECRET: ${HELPDESK_CLIENT_SECRET}
```

## 2.6 Migrer les bases de données

Les tables `users` de BiblioGI et HelpDesk ne stockeront plus aucun mot de passe. Elles deviennent de simples
**profils**, rattachés à l'identité Keycloak par le claim `sub`. Via l'Adminer → *Requête SQL* → collez le contenu du fichier → *Exécuter*.

```sql
-- =====================================================================
--  MIGRATION ÉTAPE 2 : BiblioGI et HelpDesk ne stockent plus de mot de passe
--  À exécuter en root (Adminer ou ligne de commande), UNE seule fois.
-- =====================================================================
USE biblio;
ALTER TABLE users
  ADD COLUMN sub CHAR(36) NULL UNIQUE AFTER id,   -- identifiant Keycloak (claim "sub")
  DROP COLUMN password_hash,                       -- le secret vit désormais dans Keycloak
  DROP COLUMN active;                              -- l'activation du compte aussi

USE helpdesk;
ALTER TABLE users
  ADD COLUMN sub CHAR(36) NULL UNIQUE AFTER id,
  DROP COLUMN pwd;
```

À partir de maintenant, BiblioGI et HelpDesk ne fonctionnent plus (leur code cherche encore les mots de passe)
jusqu'à la fin de 2.9 : c'est normal.

## 2.7 Migrer BiblioGI (PHP)

### a) Créer le client OIDC : `app1-biblio/src/inc/oidc.php`

Lisez-le attentivement : il n'utilise aucune bibliothèque, chaque étape du flux est visible.

```php
<?php
/* =====================================================================
 *  Client OpenID Connect minimaliste - SANS bibliothèque, pour voir
 *  chaque étape du flux "Authorization Code + PKCE".
 *
 *   Navigateur          BiblioGI (PHP)                 Keycloak
 *      |  GET /login.php      |                             |
 *      |--------------------->| 1. génère state, nonce, PKCE|
 *      |<---- 302 /auth?... --|                             |
 *      |------------------- GET /auth?client_id=biblio... ->|
 *      |<------------- page de login Keycloak --------------|
 *      |-------------------- POST identifiants ------------>|
 *      |<--- 302 /callback.php?code=XYZ&state=... ----------|
 *      |--------------------->| 2. POST /token (code+secret+verifier)
 *      |                      |---------------------------->|
 *      |                      |<---- id_token, access_token-|
 *      |                      | 3. vérifie signature + claims
 *      |<--- session ouverte -|                             |
 * ===================================================================== */
declare(strict_types=1);

function oidc_cfg(string $key): string {
    $v = getenv($key);
    if ($v === false || $v === '') throw new RuntimeException("Variable d'environnement $key manquante");
    return $v;
}
// URL vue par le NAVIGATEUR (et valeur attendue du claim "iss")
function oidc_issuer(): string   { return rtrim(oidc_cfg('KC_PUBLIC_URL'), '/') . '/realms/' . oidc_cfg('KC_REALM'); }
// URL vue par le SERVEUR PHP, à l'intérieur du réseau Docker
function oidc_backend(): string  { return rtrim(oidc_cfg('KC_INTERNAL_URL'), '/') . '/realms/' . oidc_cfg('KC_REALM') . '/protocol/openid-connect'; }
function oidc_redirect_uri(): string { return rtrim(oidc_cfg('APP_URL'), '/') . '/callback.php'; }

function b64url_encode(string $data): string { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }
function b64url_decode(string $data): string {
    $d = base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);
    return $d === false ? '' : $d;
}

/* ---------- ÉTAPE 1 : redirection du navigateur vers Keycloak ---------- */
function oidc_authorize_url(): string {
    $state    = b64url_encode(random_bytes(16));   // anti-CSRF sur le retour
    $nonce    = b64url_encode(random_bytes(16));   // anti-rejeu de l'id_token
    $verifier = b64url_encode(random_bytes(32));   // PKCE : secret gardé côté serveur
    $_SESSION['oidc'] = ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier];

    return oidc_issuer() . '/protocol/openid-connect/auth?' . http_build_query([
        'response_type'         => 'code',
        'client_id'             => oidc_cfg('OIDC_CLIENT_ID'),
        'redirect_uri'          => oidc_redirect_uri(),
        'scope'                 => 'openid profile email',
        'state'                 => $state,
        'nonce'                 => $nonce,
        'code_challenge'        => b64url_encode(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
    ]);
}

/* ---------- Petits utilitaires HTTP (back-channel) ---------- */
function http_request(string $url, ?array $form = null): array {
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10];
    if ($form !== null) {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = http_build_query($form);
        $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/x-www-form-urlencoded'];
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new RuntimeException("Keycloak injoignable ($url) : $err");
    $json = json_decode((string) $body, true);
    if ($code !== 200 || !is_array($json)) throw new RuntimeException("Réponse HTTP $code de Keycloak : " . substr((string) $body, 0, 300));
    return $json;
}

/* ---------- Vérification d'un JWT signé RS256 avec les clés publiques du realm ---------- */
function oidc_verify_jwt(string $jwt): array {
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) throw new RuntimeException('Jeton mal formé');
    [$h64, $p64, $s64] = $parts;
    $header = json_decode(b64url_decode($h64), true) ?: [];
    $claims = json_decode(b64url_decode($p64), true) ?: [];

    // On impose l'algorithme : jamais "none", jamais HS256 avec une clé publique !
    if (($header['alg'] ?? '') !== 'RS256') throw new RuntimeException('Algorithme de signature refusé : ' . ($header['alg'] ?? '?'));

    // Clés publiques du realm (JWKS) - on prend celle dont le "kid" correspond
    $jwks = http_request(oidc_backend() . '/certs');
    $cert = null;
    foreach ($jwks['keys'] ?? [] as $k) {
        if (($k['kid'] ?? null) === ($header['kid'] ?? '') && !empty($k['x5c'][0])) $cert = $k['x5c'][0];
    }
    if ($cert === null) throw new RuntimeException('Clé de signature inconnue (kid)');
    $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($cert, 64, "\n") . "-----END CERTIFICATE-----\n";
    $pub = openssl_pkey_get_public($pem);
    if ($pub === false || openssl_verify("$h64.$p64", b64url_decode($s64), $pub, OPENSSL_ALGO_SHA256) !== 1) {
        throw new RuntimeException('Signature du jeton INVALIDE');
    }
    return $claims;
}

/* ---------- ÉTAPES 2 et 3 : retour de Keycloak sur /callback.php ---------- */
function oidc_handle_callback(): array {
    $saved = $_SESSION['oidc'] ?? null;
    unset($_SESSION['oidc']);                       // usage unique

    if (isset($_GET['error'])) throw new RuntimeException('Keycloak a refusé : ' . $_GET['error'] . ' - ' . ($_GET['error_description'] ?? ''));
    if (!$saved || !hash_equals($saved['state'], (string) ($_GET['state'] ?? ''))) {
        throw new RuntimeException('Paramètre "state" invalide (tentative de CSRF ?)');
    }

    // Échange du code contre les jetons (serveur -> Keycloak, jamais via le navigateur)
    $tokens = http_request(oidc_backend() . '/token', [
        'grant_type'    => 'authorization_code',
        'code'          => (string) ($_GET['code'] ?? ''),
        'redirect_uri'  => oidc_redirect_uri(),
        'client_id'     => oidc_cfg('OIDC_CLIENT_ID'),
        'client_secret' => oidc_cfg('OIDC_CLIENT_SECRET'),
        'code_verifier' => $saved['verifier'],
    ]);

    $claims = oidc_verify_jwt($tokens['id_token'] ?? '');

    // Validations imposées par OpenID Connect Core 1.0, §3.1.3.7
    if (($claims['iss'] ?? '') !== oidc_issuer())                                   throw new RuntimeException('Émetteur (iss) inattendu : ' . ($claims['iss'] ?? '?'));
    if (!in_array(oidc_cfg('OIDC_CLIENT_ID'), (array) ($claims['aud'] ?? []), true)) throw new RuntimeException('Jeton destiné à une autre application (aud)');
    if (($claims['exp'] ?? 0) < time() - 30)                                         throw new RuntimeException('Jeton expiré');
    if (!hash_equals($saved['nonce'], (string) ($claims['nonce'] ?? '')))            throw new RuntimeException('Nonce invalide (rejeu ?)');

    return ['claims' => $claims, 'id_token' => $tokens['id_token'], 'access_token' => $tokens['access_token'] ?? ''];
}

/* ---------- Déconnexion "RP-initiated" : on ferme aussi la session Keycloak ---------- */
function oidc_logout_url(string $idToken): string {
    return oidc_issuer() . '/protocol/openid-connect/logout?' . http_build_query([
        'id_token_hint'            => $idToken,
        'post_logout_redirect_uri' => rtrim(oidc_cfg('APP_URL'), '/') . '/',
        'client_id'                => oidc_cfg('OIDC_CLIENT_ID'),
    ]);
}

/* ---------- RBAC : rôle applicatif tiré du claim "roles" (mapper Keycloak) ---------- */
function oidc_app_role(array $claims): ?string {
    $roles = (array) ($claims['roles'] ?? []);
    foreach (['admin', 'enseignant', 'etudiant'] as $r) {     // du plus fort au plus faible
        if (in_array($r, $roles, true)) return $r;
    }
    return null;                                              // authentifié mais non autorisé
}

/* Décodage SANS vérification - uniquement pour l'affichage pédagogique */
function jwt_peek(string $jwt): array {
    $p = explode('.', $jwt);
    return [json_decode(b64url_decode($p[0] ?? ''), true), json_decode(b64url_decode($p[1] ?? ''), true)];
}
```

### b) Modifier `app1-biblio/src/inc/bootstrap.php`

1. **Supprimez** toute la fonction `seed_users()` ainsi que la ligne `seed_users();` qui la suit (les comptes
   sont désormais créés dans Keycloak), et **mettez à la place** :

   ```php
   require __DIR__ . '/oidc.php';
   ```

2. Dans `render_header()`, ajoutez le lien vers la page des jetons, **après** la ligne du lien *Utilisateurs* :

   ```php
           echo '<a href="/token.php">Mon jeton</a>';
   ```

3. Toujours dans `render_header()`, **remplacez** la ligne qui affiche *Connexion* et *Inscription* par :

   ```php
           echo '<a href="/login.php">Connexion</a>';
   ```

### c) Remplacer `app1-biblio/src/login.php`

```php
<?php
/* ÉTAPE 2 : BiblioGI ne voit PLUS jamais le mot de passe.
 * Elle redirige le navigateur vers Keycloak (flux Authorization Code + PKCE). */
require __DIR__ . '/inc/bootstrap.php';

if (isset($_GET['go'])) {
    header('Location: ' . oidc_authorize_url());
    exit;
}
render_header('Connexion');
?>
<div class="card">
  <h2>Connexion à BiblioGI</h2>
  <p>L'authentification est assurée par le fournisseur d'identité de l'école (<b>Keycloak</b>, realm <code>gi4-lab</code>).</p>
  <p><a class="btn" href="/login.php?go=1">🔐 Se connecter avec Keycloak GI4</a></p>
  <p class="muted">Plus de formulaire ni d'inscription locale : comptes et rôles sont gérés dans Keycloak.</p>
</div>
<?php render_footer();
```

### d) Créer `app1-biblio/src/callback.php`

C'est ici que Keycloak renvoie le navigateur. Le profil local est créé ou mis à jour ; un compte de l'étape 1
qui porte le même nom d'utilisateur est **rattaché** au compte Keycloak (il garde ses emprunts).

```php
<?php
/* Retour de Keycloak : validation des jetons, contrôle du rôle, profil local "Just-In-Time" */
require __DIR__ . '/inc/bootstrap.php';

try {
    $result = oidc_handle_callback();
} catch (Throwable $ex) {
    http_response_code(401);
    render_header('Échec de connexion');
    echo '<div class="alert err">Connexion refusée : ' . e($ex->getMessage()) . '</div><p><a href="/login.php">Réessayer</a></p>';
    render_footer(); exit;
}
$c = $result['claims'];

// AUTORISATION : être authentifié ne suffit pas, il faut un rôle BiblioGI
$role = oidc_app_role($c);
if ($role === null) {
    http_response_code(403);
    render_header('Accès refusé');
    echo '<div class="alert err">Bonjour ' . e($c['preferred_username'] ?? '?') . ' : vous êtes bien authentifié(e) par Keycloak, '
       . 'mais aucun rôle (etudiant, enseignant, admin) ne vous autorise à utiliser BiblioGI.</div>';
    echo '<p><a href="' . e(oidc_logout_url($result['id_token'])) . '">Se déconnecter de Keycloak</a></p>';
    render_footer(); exit;
}

// Provisioning JIT : on crée / met à jour le profil local, rattaché au "sub" Keycloak.
// Un compte de l'étape 1 portant le même nom d'utilisateur est RATTACHÉ (ses emprunts sont conservés).
db()->prepare('INSERT INTO users (sub, username, email, role, last_login) VALUES (?,?,?,?,NOW()) AS new
               ON DUPLICATE KEY UPDATE sub=new.sub, username=new.username, email=new.email,
                                       role=new.role, last_login=NOW()')
    ->execute([$c['sub'], $c['preferred_username'] ?? $c['sub'], $c['email'] ?? '', $role]);
$st = db()->prepare('SELECT id FROM users WHERE sub = ?');
$st->execute([$c['sub']]);

session_regenerate_id(true);
$_SESSION['user'] = ['id' => (int) $st->fetchColumn(), 'username' => $c['preferred_username'] ?? $c['sub'], 'role' => $role];
$_SESSION['id_token'] = $result['id_token'];          // utile pour la déconnexion
$_SESSION['access_token'] = $result['access_token'];  // affiché sur /token.php (pédagogie)
flash('Bienvenue ' . ($c['given_name'] ?? $_SESSION['user']['username']) . ' ! (authentifié par Keycloak)');
header('Location: /');
```

### e) Remplacer `app1-biblio/src/logout.php`

```php
<?php
/* Déconnexion : session locale + session SSO Keycloak (RP-initiated logout) */
require __DIR__ . '/inc/bootstrap.php';
$idToken = $_SESSION['id_token'] ?? null;
$_SESSION = [];
session_destroy();
setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/']);
header('Location: ' . ($idToken ? oidc_logout_url($idToken) : '/'));
```

### f) Créer `app1-biblio/src/token.php`

```php
<?php
/* Page pédagogique : que contiennent les jetons émis par Keycloak ?
 * (Ne JAMAIS exposer les jetons ainsi dans une application réelle.) */
require __DIR__ . '/inc/bootstrap.php';
require_login();
render_header('Mon jeton');
$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
foreach (['id_token' => 'ID Token (qui êtes-vous ?)', 'access_token' => 'Access Token (que pouvez-vous faire ?)'] as $k => $label) {
    [$h, $p] = jwt_peek($_SESSION[$k] ?? '');
    echo '<div class="card"><h3>' . e($label) . '</h3>';
    echo '<p class="muted">En-tête</p><pre>' . e(json_encode($h, $flags)) . '</pre>';
    echo '<p class="muted">Charge utile (claims)</p><pre>' . e(json_encode($p, $flags)) . '</pre></div>';
}
render_footer();
```

### g) Remplacer `app1-biblio/src/admin.php`

```php
<?php
/* ÉTAPE 2 : la gestion des comptes a quitté l'application.
 * Il ne reste qu'une vue en lecture seule des profils provisionnés. */
require __DIR__ . '/inc/bootstrap.php';
require_role('admin');

$users = db()->query('SELECT id, sub, username, email, role, created_at, last_login FROM users ORDER BY id')->fetchAll();
render_header('Utilisateurs');
?>
<div class="card">
  <h2>Profils connus de BiblioGI</h2>
  <div class="alert ok">Les comptes, mots de passe et rôles sont désormais gérés dans
    <a href="http://localhost:8080/admin/master/console/#/gi4-lab/users" target="_blank" rel="noopener">la console Keycloak</a>.
    Un profil apparaît ici au premier login (provisioning Just-In-Time).</div>
  <table>
    <tr><th>#</th><th>Utilisateur</th><th>sub (Keycloak)</th><th>E-mail</th><th>Rôle</th><th>Dernière connexion</th></tr>
    <?php foreach ($users as $x): ?>
    <tr><td><?= (int) $x['id'] ?></td><td><?= e($x['username']) ?></td><td><code style="font-size:.75em"><?= e($x['sub']) ?></code></td>
        <td><?= e($x['email']) ?></td><td><span class="badge"><?= e($x['role']) ?></span></td><td class="muted"><?= e($x['last_login']) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php render_footer();
```

### h) Supprimer `app1-biblio/src/register.php`

Il n'y a plus d'inscription locale : les comptes sont créés dans Keycloak.

## 2.8 Migrer HelpDesk (Python / Flask)

### a) `app2-helpdesk/requirements.txt` : ajoutez la ligne

```
pyjwt[crypto]==2.10.*
```

### b) `app2-helpdesk/app.py` : 4 blocs à remplacer

**Bloc 1 — l'en-tête et les imports.** Remplacez tout le début du fichier, jusqu'à la ligne
`from werkzeug.security import …` incluse, par :

```python
"""
HelpDesk - Application 2/3 du lab IAM (Python / Flask)
ÉTAPE 2 : authentification déléguée à Keycloak (OpenID Connect)
Le code OIDC est écrit "à la main" (requests + PyJWT) pour rendre chaque étape visible.
"""
import base64
import hashlib
import json
import os
import secrets
from functools import wraps
from urllib.parse import urlencode

import jwt                      # PyJWT : vérification de la signature des jetons
import pymysql
import requests
from flask import Flask, abort, flash, g, redirect, render_template, request, session, url_for
```

(`werkzeug.security` et `time` disparaissent : l'application ne hache plus de mots de passe et ne crée plus de
comptes au démarrage.)

**Bloc 2 — les comptes initiaux.** Supprimez la fonction `def seed_users():` et la ligne `seed_users()` qui la
suit, et mettez à la place la configuration OIDC :

```python
# ---------------------------------------------------------------- configuration OIDC
CLIENT_ID = os.environ["OIDC_CLIENT_ID"]
CLIENT_SECRET = os.environ["OIDC_CLIENT_SECRET"]
APP_URL = os.environ["APP_URL"].rstrip("/")
REDIRECT_URI = f"{APP_URL}/callback"
# URL vue par le NAVIGATEUR et valeur attendue du claim "iss"
ISSUER = f"{os.environ['KC_PUBLIC_URL'].rstrip('/')}/realms/{os.environ['KC_REALM']}"
# URL vue par le SERVEUR Flask, dans le réseau Docker (back-channel)
BACKEND = f"{os.environ['KC_INTERNAL_URL'].rstrip('/')}/realms/{os.environ['KC_REALM']}/protocol/openid-connect"
JWKS = jwt.PyJWKClient(f"{BACKEND}/certs")       # clés publiques du realm (mises en cache)

# Les jetons sont gardés CÔTÉ SERVEUR (le cookie de session Flask est signé mais lisible,
# et deux JWT dépasseraient la taille maximale d'un cookie, ~4 Ko). Stockage mémoire = lab uniquement.
TOKEN_STORE = {}


def b64url(data: bytes) -> str:
    return base64.urlsafe_b64encode(data).rstrip(b"=").decode()


def app_flags(claims):
    """RBAC : traduit les rôles Keycloak (claim "roles") vers le modèle interne du HelpDesk."""
    roles = set(claims.get("roles", []))
    if not roles & {"etudiant", "enseignant", "admin"}:
        return None                                  # authentifié mais non autorisé
    return {"is_admin": "admin" in roles, "is_staff": bool(roles & {"enseignant", "admin"})}
```

**Bloc 3 — connexion, inscription, déconnexion.** Supprimez les routes `/login`, `/register` et `/logout`
(de `@app.route("/login", methods=["GET", "POST"])` jusqu'à la fin de la fonction `logout`), et mettez à la place :

```python
@app.route("/login")
def login():
    if request.args.get("go"):
        # ÉTAPE 1 du flux : redirection vers Keycloak avec state, nonce et PKCE
        verifier = b64url(secrets.token_bytes(32))
        session["oidc"] = {"state": b64url(secrets.token_bytes(16)),
                           "nonce": b64url(secrets.token_bytes(16)), "verifier": verifier}
        params = {
            "response_type": "code", "client_id": CLIENT_ID, "redirect_uri": REDIRECT_URI,
            "scope": "openid profile email", "state": session["oidc"]["state"], "nonce": session["oidc"]["nonce"],
            "code_challenge": b64url(hashlib.sha256(verifier.encode()).digest()), "code_challenge_method": "S256",
        }
        return redirect(f"{ISSUER}/protocol/openid-connect/auth?{urlencode(params)}")
    return render_template("login.html")


@app.route("/callback")
def callback():
    saved = session.pop("oidc", None)
    try:
        if "error" in request.args:
            raise ValueError(f"Keycloak a refusé : {request.args['error']}")
        if not saved or not secrets.compare_digest(saved["state"], request.args.get("state", "")):
            raise ValueError('Paramètre "state" invalide (tentative de CSRF ?)')

        # ÉTAPE 2 : échange du code contre les jetons (serveur -> Keycloak)
        resp = requests.post(f"{BACKEND}/token", timeout=10, data={
            "grant_type": "authorization_code", "code": request.args.get("code", ""),
            "redirect_uri": REDIRECT_URI, "client_id": CLIENT_ID, "client_secret": CLIENT_SECRET,
            "code_verifier": saved["verifier"],
        })
        if resp.status_code != 200:
            raise ValueError(f"Échange du code refusé ({resp.status_code}) : {resp.text[:300]}")
        tokens = resp.json()

        # ÉTAPE 3 : vérification de l'ID Token (signature RS256, iss, aud, exp) puis du nonce
        id_token = tokens["id_token"]
        key = JWKS.get_signing_key_from_jwt(id_token)
        claims = jwt.decode(id_token, key.key, algorithms=["RS256"], audience=CLIENT_ID,
                            issuer=ISSUER, leeway=30)
        if not secrets.compare_digest(saved["nonce"], claims.get("nonce", "")):
            raise ValueError("Nonce invalide (rejeu ?)")
    except (ValueError, KeyError, jwt.PyJWTError, requests.RequestException) as exc:
        return render_template("base.html", content=f"Connexion refusée : {exc}"), 401

    flags = app_flags(claims)
    if flags is None:
        return render_template("base.html", content=(
            f"Bonjour {claims.get('preferred_username')} : vous êtes authentifié(e) par Keycloak, mais aucun rôle "
            "(etudiant, enseignant, admin) ne vous autorise à utiliser le HelpDesk.")), 403

    # Provisioning JIT du profil local, rattaché au "sub" Keycloak.
    # Un compte de l'étape 1 portant le même login est RATTACHÉ (ses tickets sont conservés).
    query("""INSERT INTO users (sub, login, full_name, is_admin, is_staff) VALUES (%s,%s,%s,%s,%s) AS new
             ON DUPLICATE KEY UPDATE sub=new.sub, login=new.login, full_name=new.full_name,
                                     is_admin=new.is_admin, is_staff=new.is_staff""",
          (claims["sub"], claims.get("preferred_username", claims["sub"]), claims.get("name", ""),
           int(flags["is_admin"]), int(flags["is_staff"])))
    row = query("SELECT id FROM users WHERE sub=%s", (claims["sub"],), one=True)

    csrf = session.get("csrf")
    session.clear()                                   # nouvelle session après authentification
    session["csrf"] = csrf
    session["user"] = {"id": row["id"], "login": claims.get("preferred_username"),
                       "name": claims.get("name", ""), **flags}
    # Jetons conservés côté serveur pour la déconnexion et la page pédagogique /token
    session["tok"] = secrets.token_urlsafe(16)
    TOKEN_STORE[session["tok"]] = {"id_token": id_token, "access_token": tokens.get("access_token", "")}
    flash(f"Bienvenue {claims.get('given_name', '')} ! (authentifié par Keycloak)", "ok")
    return redirect(url_for("index"))


@app.route("/logout")
def logout():
    stored = TOKEN_STORE.pop(session.get("tok", ""), {})
    session.clear()
    params = {"post_logout_redirect_uri": f"{APP_URL}/login", "client_id": CLIENT_ID}
    if stored.get("id_token"):
        params["id_token_hint"] = stored["id_token"]
    return redirect(f"{ISSUER}/protocol/openid-connect/logout?{urlencode(params)}")


def jwt_peek(token):
    """Décodage SANS vérification - uniquement pour l'affichage pédagogique."""
    try:
        header = jwt.get_unverified_header(token)
        payload = jwt.decode(token, options={"verify_signature": False})
    except jwt.PyJWTError:
        return {}, {}
    return header, payload


@app.route("/token")
@login_required
def token():
    pretty = lambda d: json.dumps(d, indent=2, ensure_ascii=False)
    stored = TOKEN_STORE.get(session.get("tok", ""), {})
    tokens = [(label, *map(pretty, jwt_peek(stored.get(k, ""))))
              for k, label in [("id_token", "ID Token (qui êtes-vous ?)"),
                               ("access_token", "Access Token (que pouvez-vous faire ?)")]]
    return render_template("token.html", tokens=tokens)
```

**Bloc 4 — la page d'administration.** Remplacez la route `/admin/users` (de `@app.route("/admin/users", …)`
jusqu'à la fin de la fonction `admin_users`) par :

```python
@app.route("/admin/users")
@admin_required
def admin_users():
    # ÉTAPE 2 : lecture seule - les comptes sont gérés dans Keycloak
    users = query("SELECT id, sub, login, full_name, is_admin, is_staff, created_at FROM users ORDER BY id")
    return render_template("admin.html", users=users)
```

### c) Les templates HTML

Dans `templates/base.html`, ajoutez après la ligne du lien *Utilisateurs* :

```html
    <a href="{{ url_for('token') }}">Mon jeton</a>
```

et remplacez la ligne des liens *Connexion* / *Inscription* par :

```html
    <a href="{{ url_for('login') }}">Connexion</a>
```

Remplacez `templates/login.html` :

```html
{% extends "base.html" %}
{% block body %}
<div class="card">
  <h2>Connexion au HelpDesk</h2>
  <p>L'authentification est assurée par le fournisseur d'identité de l'école (<b>Keycloak</b>, realm <code>gi4-lab</code>).</p>
  <p><a class="btn" href="{{ url_for('login', go=1) }}">🔐 Se connecter avec Keycloak GI4</a></p>
  <p class="muted">Si vous êtes déjà connecté(e) à BiblioGI, observez bien ce qui se passe...</p>
</div>
{% endblock %}
```

Remplacez `templates/admin.html` :

```html
{% extends "base.html" %}
{% block body %}
<div class="card">
  <h2>Profils connus du HelpDesk</h2>
  <div class="alert ok">Les comptes et les rôles sont désormais gérés dans
    <a href="http://localhost:8080/admin/master/console/#/gi4-lab/users" target="_blank" rel="noopener">la console Keycloak</a>.
    Les booléens <code>is_admin</code> / <code>is_staff</code> sont recalculés à chaque connexion à partir des rôles du jeton.</div>
  <table>
    <tr><th>#</th><th>Login</th><th>Nom</th><th>sub (Keycloak)</th><th>Admin</th><th>Support</th><th>Créé le</th></tr>
    {% for u in users %}
    <tr>
      <td>{{ u.id }}</td><td>{{ u.login }}</td><td>{{ u.full_name }}</td><td><code style="font-size:.75em">{{ u.sub }}</code></td>
      <td>{{ '✅' if u.is_admin else '-' }}</td><td>{{ '✅' if u.is_staff else '-' }}</td>
      <td class="muted">{{ u.created_at }}</td>
    </tr>
    {% endfor %}
  </table>
</div>
{% endblock %}
```

Créez `templates/token.html` :

```html
{% extends "base.html" %}
{% block body %}
{# Page pédagogique : ne JAMAIS exposer les jetons ainsi dans une application réelle. #}
{% for label, header, payload in tokens %}
<div class="card">
  <h3>{{ label }}</h3>
  <p class="muted">En-tête</p><pre>{{ header }}</pre>
  <p class="muted">Charge utile (claims)</p><pre>{{ payload }}</pre>
</div>
{% endfor %}
{% endblock %}
```

Supprimez `templates/register.html`.

## 2.9 Reconstruire et tester

```bash
docker compose up -d --build
docker compose ps
```

✅ **Attendu** : **6 services** `Up` ; `app1-biblio` et `app2-helpdesk` ont été reconstruits (`Built`, puis
`Recreated` ou `Started`).


| Test | ✅ Résultat attendu | ❌ Si vous voyez… → cause probable |
|------|--------------------|-----------------------------------|
| **1. Connexion** : BiblioGI → « Se connecter avec Keycloak GI4 » | La barre d'adresse passe sur `localhost:8080/realms/gi4-lab/…` : page de connexion **de Keycloak** | « Invalid parameter: redirect_uri » → *Valid redirect URIs* du client incorrecte |
| Saisie `alice` / `Alice#2026` | Retour sur BiblioGI : « Bienvenue Alice ! (authentifié par Keycloak) », en-tête « alice · etudiant » et un lien **Mon jeton** | « Connexion refusée : Réponse HTTP 401 … unauthorized_client » → secret faux dans `.env`, ou `docker compose up -d` oublié après sa modification |
| | | « Connexion refusée : Émetteur (iss) inattendu » → vous naviguez sur `127.0.0.1` |
| | | « … aucun rôle … ne vous autorise » pour **alice** → mapper `roles` absent, ou rôle non attribué |
| | | Keycloak demande de compléter le profil → prénom, nom ou e-mail manquant sur l'utilisateur |
| **2. Comptes rattachés** : catalogue BiblioGI | Le livre emprunté à l'étape 1 est toujours « Emprunté par alice » : le compte local a été rattaché au compte Keycloak | — |
| **3. SSO** : HelpDesk → « Se connecter avec Keycloak GI4 » | Retour **immédiat** sur HelpDesk, connecté(e), **sans** page de mot de passe ; le ticket de l'étape 1 est toujours là | La page de mot de passe s'affiche → fenêtre privée ou autre navigateur |
| **4. Mon jeton** | Deux cartes : *ID Token* et *Access Token*. En-tête : `"alg": "RS256"` et un `kid`. ID Token : `"iss": "http://localhost:8080/realms/gi4-lab"`, `"aud": "biblio"`, `"preferred_username": "alice"`, `roles` contenant `etudiant`, et `exp − iat = 300` (5 min) | Pas de `roles` dans l'ID Token → *Add to ID token* désactivé dans le mapper |
| **5. eve** (fenêtre privée) | BiblioGI : « Bonjour eve : vous êtes bien authentifié(e) par Keycloak, mais aucun rôle … » ; même refus sur HelpDesk | eve entre → un rôle lui a été attribué par erreur |
| **6. RBAC centralisé** : rôle `admin` donné à `bob` dans Keycloak | **Aucun effet** tant que bob reste connecté ; après déconnexion et reconnexion : « bob · admin » et le lien *Utilisateurs*, dans les deux applications | — |
| **7. Adminer** | `biblio.users` : `sub` rempli (UUID de 36 caractères) pour alice et bob ; `admin_biblio` garde un `sub` vide (compte orphelin : plus personne ne peut s'y connecter) | — |
| **8. Déconnexion** de BiblioGI | Passage rapide par Keycloak, retour sur BiblioGI non connecté. Rechargez HelpDesk et notez votre état. Une reconnexion à BiblioGI **redemande** le mot de passe | Erreur Keycloak sur l'URI à la déconnexion → *Valid post logout redirect URIs* manquant |
| **9. IntraGI** | Toujours l'ancien formulaire et l'ancien mot de passe (`alice` / `alice`) | — |

## 2.10 Dépannage de l'étape 2

| Symptôme | Cause probable | Solution |
|----------|----------------|----------|
| « Paramètre "state" invalide » | Retour de Keycloak dans une autre session (autre onglet, double clic, cookie effacé) | Recommencer la connexion depuis la page de l'application |
| « Keycloak injoignable » | Keycloak redémarre ou démarre encore | Attendre 30 à 60 s ; `docker compose logs keycloak` |
| Keycloak exige de vérifier l'e-mail | *Email verified* resté sur Off (aucun serveur mail configuré) | Le passer sur On dans l'utilisateur |
| Les utilisateurs « disparaissent » de la console | Vous êtes dans le realm `master` | Sélectionner `gi4-lab` en haut à gauche |
| Un changement de rôle n'est pas visible | Les rôles sont lus à la connexion | Se déconnecter puis se reconnecter |
| `Unknown column 'password_hash'` ou `'pwd'` | Migration SQL faite, mais application non reconstruite | `docker compose up -d --build` |
| `Unknown column 'sub'` | Migration SQL oubliée | Exécuter 2.7 |

---

# Étape 3 — Un annuaire LDAP, source unique d'identités

**Objectif** : toutes les identités et tous les groupes vivent dans l'annuaire OpenLDAP `dc=gi4,dc=lab`.
Vous y ajoutez vous-même des utilisateurs, vous configurez Keycloak pour qu'il le **fédère** (BiblioGI et
HelpDesk ne changent pas d'une ligne), puis vous modifiez IntraGI pour qu'il s'authentifie **directement**
auprès de l'annuaire.

## 3.1 Vocabulaire de l'étape

| Terme | Définition |
|-------|-----------|
| **Annuaire** | Base de données optimisée pour la lecture, qui décrit des personnes, des groupes, des machines |
| **LDAP** | *Lightweight Directory Access Protocol* : protocole d'accès à un annuaire (port 389 ; 636 pour LDAPS, chiffré) |
| **DIT** | *Directory Information Tree* : l'arbre des entrées de l'annuaire |
| **DN** | *Distinguished Name* : chemin complet et unique d'une entrée, lu de gauche (feuille) à droite (racine) : `uid=alice,ou=people,dc=gi4,dc=lab` |
| **RDN** | *Relative DN* : la partie la plus à gauche du DN (`uid=alice`) |
| **Attributs courants** | `dc` (composant de domaine), `ou` (unité d'organisation), `uid` (identifiant), `cn` (nom complet), `sn` (nom), `givenName` (prénom), `mail`, `userPassword` |
| **objectClass** | Classe(s) d'une entrée, qui imposent ou autorisent des attributs. Ici : `inetOrgPerson` (personne), `groupOfNames` (groupe), `organizationalUnit` |
| **`member`** | Attribut d'un `groupOfNames` : le DN de chaque membre |
| **LDIF** | Format texte pour décrire des entrées ou des modifications d'annuaire |
| **Bind** | Authentification auprès du serveur LDAP avec un DN et un mot de passe. *Bind simple* : le mot de passe circule tel quel |
| **Compte de service** | Compte technique (`cn=readonly`) utilisé par une application pour interroger l'annuaire |
| **Search then bind** | 1) bind du compte de service, 2) recherche du DN de l'utilisateur, 3) bind avec ce DN et le mot de passe saisi, 4) lecture des groupes |
| **`{SSHA}`** | Format de stockage des mots de passe : SHA-1 salé |
| **`ldapsearch` / `ldapadd` / `ldapmodify` / `ldappasswd` / `ldapwhoami`** | Outils en ligne de commande : chercher / ajouter / modifier / changer un mot de passe / tester un bind |
| **Fédération d'utilisateurs** | Keycloak lit les comptes dans un annuaire externe au lieu de les stocker lui-même |
| **Mapper LDAP** | Règle Keycloak qui traduit un attribut ou un groupe LDAP en attribut ou rôle Keycloak |
| **Synchronisation** | Import (complet ou différentiel) des comptes de l'annuaire dans Keycloak |
| **Mode `READ_ONLY`** | Keycloak lit l'annuaire mais n'y écrit jamais : l'annuaire reste la seule source de vérité |
| **Injection LDAP** | Attaque qui modifie un filtre de recherche (`(uid=…)`) via une saisie non échappée |

## 3.2 Adresses

| Service | URL | Identifiants |
|---------|-----|--------------|
| BiblioGI | http://localhost:8081 | comptes de l'annuaire (via Keycloak) |
| HelpDesk | http://localhost:8082 | comptes de l'annuaire (via Keycloak) |
| IntraGI | http://localhost:8083 | comptes de l'annuaire (après 3.8) |
| Adminer | http://localhost:8088 | `root` / `rootpass` |
| Keycloak | http://localhost:8080 | `admin` / `admin` |
| **phpLDAPadmin** | http://localhost:8090 | Login DN `cn=admin,dc=gi4,dc=lab` / `adminldap` |
| **OpenLDAP** | ldap://localhost:10389 | administrateur `cn=admin,dc=gi4,dc=lab` / `adminldap` ; compte de service `cn=readonly,dc=gi4,dc=lab` / `readonlypass` |

## 3.3 Ajouter l'annuaire au projet

**a) Le contenu initial de l'annuaire.** Créez le dossier `ldap/` et le fichier `ldap/bootstrap.ldif` :

```ldif
# ====================================================================
#  Annuaire OpenLDAP de l'école : dc=gi4,dc=lab
#  Lu UNIQUEMENT au premier démarrage du conteneur openldap.
#  Comptes de départ (mots de passe hachés en {SSHA}) :
#    sara / Sara#2026 (etudiant)     karim / Karim#2026 (enseignant)
#    nadia / Nadia#2026 (admin)      invite / Invite#2026 (aucun groupe)
#  alice et bob seront ajoutés par vous pendant le lab.
# ====================================================================

dn: ou=people,dc=gi4,dc=lab
objectClass: organizationalUnit
ou: people

dn: ou=groups,dc=gi4,dc=lab
objectClass: organizationalUnit
ou: groups

dn: uid=sara,ou=people,dc=gi4,dc=lab
objectClass: inetOrgPerson
uid: sara
cn: Sara Idrissi
givenName: Sara
sn: Idrissi
mail: sara@gi4.lab
title: Etudiante GI4
userPassword: {SSHA}NQLL7CrZfcFK1AX23Es97SCXJzLvQsf5LJ/6lA==

dn: uid=karim,ou=people,dc=gi4,dc=lab
objectClass: inetOrgPerson
uid: karim
cn: Karim Alaoui
givenName: Karim
sn: Alaoui
mail: karim@gi4.lab
title: Enseignant
userPassword: {SSHA}PNM1hxaHHGAJ94FYPLnJ0KC7AX7lm9HLcBsWRA==

dn: uid=nadia,ou=people,dc=gi4,dc=lab
objectClass: inetOrgPerson
uid: nadia
cn: Nadia Bennani
givenName: Nadia
sn: Bennani
mail: nadia@gi4.lab
title: Administratrice SI
userPassword: {SSHA}yjq6ppzxXBkhxVldM/t7sbTR2MvwK5xUQNR0og==

dn: uid=invite,ou=people,dc=gi4,dc=lab
objectClass: inetOrgPerson
uid: invite
cn: Compte Invite
givenName: Compte
sn: Invite
mail: invite@gi4.lab
title: Visiteur (aucun groupe)
userPassword: {SSHA}Xsn7m6jG0f/67+gTws+3sWkzsyjm5vy73WZE8A==

dn: cn=etudiant,ou=groups,dc=gi4,dc=lab
objectClass: groupOfNames
cn: etudiant
member: uid=sara,ou=people,dc=gi4,dc=lab

dn: cn=enseignant,ou=groups,dc=gi4,dc=lab
objectClass: groupOfNames
cn: enseignant
member: uid=karim,ou=people,dc=gi4,dc=lab

dn: cn=admin,ou=groups,dc=gi4,dc=lab
objectClass: groupOfNames
cn: admin
member: uid=nadia,ou=people,dc=gi4,dc=lab
```

L'arbre obtenu :

```
dc=gi4,dc=lab
├── cn=admin            administrateur de l'annuaire (créé par l'image Docker)
├── cn=readonly         compte de service, lecture seule (créé par l'image Docker)
├── ou=people
│   ├── uid=sara        etudiant
│   ├── uid=karim       enseignant
│   ├── uid=nadia       admin
│   └── uid=invite      aucun groupe
└── ou=groups
    ├── cn=etudiant     member: uid=sara,…
    ├── cn=enseignant   member: uid=karim,…
    └── cn=admin        member: uid=nadia,…
```

**b) Les services.** Dans `docker-compose.yml`, ajoutez avant le service `keycloak` :

```yaml
  # ---- AJOUT ÉTAPE 3 : annuaire LDAP ---------------------------------
  openldap:
    image: osixia/openldap:1.5.0
    platform: linux/amd64            # image Intel uniquement (émulée sur Mac Apple Silicon)
    command: --copy-service
    environment:
      LDAP_ORGANISATION: "Ecole GI"
      LDAP_DOMAIN: gi4.lab           # => base dc=gi4,dc=lab
      LDAP_ADMIN_PASSWORD: adminldap
      LDAP_READONLY_USER: "true"     # crée cn=readonly,dc=gi4,dc=lab
      LDAP_READONLY_USER_USERNAME: readonly
      LDAP_READONLY_USER_PASSWORD: readonlypass
      LDAP_TLS: "false"
    ports: ["10389:389"]
    volumes:
      - ./ldap/bootstrap.ldif:/container/service/slapd/assets/config/bootstrap/ldif/custom/50-gi4.ldif:ro
      - ldap_data:/var/lib/ldap
      - ldap_config:/etc/ldap/slapd.d

  phpldapadmin:
    image: osixia/phpldapadmin:0.9.0
    platform: linux/amd64
    environment:
      PHPLDAPADMIN_LDAP_HOSTS: openldap
      PHPLDAPADMIN_HTTPS: "false"
    ports: ["8090:80"]
    depends_on: [openldap]
```

et complétez les volumes :

```yaml
volumes:
  mysql_data:
  kc_data:                       # AJOUT ÉTAPE 2
  ldap_data:                     # AJOUT ÉTAPE 3
  ldap_config:                   # AJOUT ÉTAPE 3
```

**c) Démarrer.**

```bash
docker compose up -d
docker compose ps
```

✅ **Attendu** : **8 services** `Up`, dont `openldap` et `phpldapadmin`. Sur Mac Apple Silicon, `openldap` met
une à deux minutes au premier démarrage (image émulée) : c'est normal.

## 3.4 Explorer l'annuaire

**phpLDAPadmin** : http://localhost:8090 → *login* → Login DN `cn=admin,dc=gi4,dc=lab`, mot de passe `adminldap`.
✅ À gauche, l'arbre `dc=gi4,dc=lab` avec `cn=readonly`, `ou=groups` (3 entrées) et `ou=people` (4 entrées).

**Recherche avec le compte de service** (une seule ligne) :

```bash
docker compose exec openldap ldapsearch -x -H ldap://localhost -D "cn=readonly,dc=gi4,dc=lab" -w readonlypass -b "ou=people,dc=gi4,dc=lab" "(uid=sara)" uid cn mail userPassword
```

✅ **Attendu** : une entrée `dn: uid=sara,ou=people,dc=gi4,dc=lab` avec `uid`, `cn`, `mail`, puis
`# numEntries: 1`. **Aucune** ligne `userPassword`.
❌ `ldap_bind: Invalid credentials (49)` → DN ou mot de passe du compte de service mal saisi.

**Même recherche avec l'administrateur** (remplacez `-D "cn=readonly,…" -w readonlypass` par
`-D "cn=admin,dc=gi4,dc=lab" -w adminldap`) :
✅ une ligne supplémentaire `userPassword:: e1NTSEF9…` (`::` signale du Base64 ; `e1NTSEF9` est l'encodage
de `{SSHA}`).

**Les groupes de Sara** :

```bash
docker compose exec openldap ldapsearch -x -H ldap://localhost -D "cn=readonly,dc=gi4,dc=lab" -w readonlypass -b "ou=groups,dc=gi4,dc=lab" "(member=uid=sara,ou=people,dc=gi4,dc=lab)" cn
```

✅ Une seule entrée : `dn: cn=etudiant,ou=groups,dc=gi4,dc=lab`.

## 3.5 À vous : ajouter deux utilisateurs

Alice et Bob, qui existaient dans Keycloak à l'étape 2, doivent maintenant vivre dans l'annuaire.

### a) Alice, en ligne de commande

1. Créez `ldap/alice.ldif` :

   ```ldif
   # Nouvel utilisateur : alice (le mot de passe sera défini ensuite avec ldappasswd)
   dn: uid=alice,ou=people,dc=gi4,dc=lab
   objectClass: inetOrgPerson
   uid: alice
   cn: Alice Martin
   givenName: Alice
   sn: Martin
   mail: alice@gi4.lab
   title: Etudiante GI4
   ```

2. Ajoutez l'entrée (avec le compte administrateur) :

   ```bash
   docker compose cp ldap/alice.ldif openldap:/tmp/alice.ldif
   docker compose exec openldap ldapadd -x -H ldap://localhost -D "cn=admin,dc=gi4,dc=lab" -w adminldap -f /tmp/alice.ldif
   ```

   ✅ `adding new entry "uid=alice,ou=people,dc=gi4,dc=lab"`
   ❌ `Already exists (68)` → l'entrée existe déjà ; `Invalid syntax (21)` ou `Object class violation (65)` → une
   faute dans le fichier (attribut obligatoire `cn` ou `sn` manquant, espace en fin de ligne).

3. Définissez son mot de passe : le serveur le hache lui-même en `{SSHA}`.

   ```bash
   docker compose exec openldap ldappasswd -x -H ldap://localhost -D "cn=admin,dc=gi4,dc=lab" -w adminldap -s "Alice#2026" "uid=alice,ou=people,dc=gi4,dc=lab"
   ```

   ✅ Aucune sortie : c'est un succès.

4. Ajoutez-la au groupe `etudiant`. Créez `ldap/alice-groupe.ldif` :

   ```ldif
   # Ajout d'alice comme membre du groupe etudiant
   dn: cn=etudiant,ou=groups,dc=gi4,dc=lab
   changetype: modify
   add: member
   member: uid=alice,ou=people,dc=gi4,dc=lab
   ```

   ```bash
   docker compose cp ldap/alice-groupe.ldif openldap:/tmp/alice-groupe.ldif
   docker compose exec openldap ldapmodify -x -H ldap://localhost -D "cn=admin,dc=gi4,dc=lab" -w adminldap -f /tmp/alice-groupe.ldif
   ```

   ✅ `modifying entry "cn=etudiant,ou=groups,dc=gi4,dc=lab"`

5. Vérifiez qu'Alice peut s'authentifier (bind avec **son** DN et **son** mot de passe) :

   ```bash
   docker compose exec openldap ldapwhoami -x -H ldap://localhost -D "uid=alice,ou=people,dc=gi4,dc=lab" -w "Alice#2026"
   ```

   ✅ `dn:uid=alice,ou=people,dc=gi4,dc=lab`
   ❌ `ldap_bind: Invalid credentials (49)` → mot de passe non défini (étape 3) ou mal saisi.

### b) Bob, avec phpLDAPadmin

1. Dans l'arbre, cliquez sur `ou=people` → *Create a child entry* → modèle **Default**.
2. *ObjectClasses* : sélectionnez **inetOrgPerson** → *Proceed*.
3. *RDN* : choisissez **uid**. Remplissez : `uid` = `bob`, `cn` = `Bob Durand`, `sn` = `Durand`,
   `givenName` = `Bob`, `mail` = `bob@gi4.lab`, `userPassword` = `Bob#2026` avec le hachage **ssha**
   → *Create Object* → *Commit*.
4. Ouvrez `ou=groups` → `cn=enseignant` → sur l'attribut `member`, *(add value)* →
   `uid=bob,ou=people,dc=gi4,dc=lab` → *Update Object* → *Commit*.

✅ **Attendu** : `uid=bob` apparaît sous `ou=people`, `cn=enseignant` liste deux membres (karim et bob), et :

```bash
docker compose exec openldap ldapwhoami -x -H ldap://localhost -D "uid=bob,ou=people,dc=gi4,dc=lab" -w "Bob#2026"
```

renvoie `dn:uid=bob,ou=people,dc=gi4,dc=lab`.

❌ Si l'interface vous bloque, faites Bob comme Alice (fichiers `bob.ldif` et `bob-groupe.ldif` sur le même
modèle, groupe `enseignant`).

## 3.6 Configurer Keycloak pour utiliser l'annuaire

### a) Supprimer les comptes locaux de Keycloak

Les comptes `alice`, `bob`, `carol` et `eve` créés à l'étape 2 sont stockés **dans** Keycloak. Si on les garde,
ils entrent en conflit avec ceux de l'annuaire (même nom d'utilisateur) et Keycloak continuerait d'utiliser les
anciens mots de passe. Realm `gi4-lab` → *Users* → cochez les 4 comptes → *Delete*.

✅ *Users* ne renvoie plus aucun résultat.

### b) Déclarer l'annuaire

*User federation* → *Add LDAP providers* (ou *Add new provider* → *ldap*) :

| Section | Paramètre | Valeur |
|---------|-----------|--------|
| General options | UI display name | `ldap-gi4` |
| | Vendor | Other |
| Connection and authentication | Connection URL | `ldap://openldap:389` → *Test connection* |
| | Bind type | simple |
| | Bind DN | `cn=readonly,dc=gi4,dc=lab` |
| | Bind credentials | `readonlypass` → *Test authentication* |
| LDAP searching and updating | Edit mode | `READ_ONLY` |
| | Users DN | `ou=people,dc=gi4,dc=lab` |
| | Username LDAP attribute | `uid` |
| | RDN LDAP attribute | `uid` |
| | UUID LDAP attribute | `entryUUID` |
| | User object classes | `inetOrgPerson` |
| | Search scope | One Level |
| Synchronization settings | Import users | On |
| | Sync Registrations | Off |
| Cache settings | Cache policy | `NO_CACHE` (les changements de l'annuaire sont vus immédiatement) |

→ *Save*.

✅ *Test connection* et *Test authentication* affichent chacun un bandeau vert de succès.
❌ Échec de *Test connection* → l'URL doit utiliser le nom du service Docker `openldap`, pas `localhost`.
❌ Échec de *Test authentication* → Bind DN ou mot de passe du compte `cn=readonly`.

### c) Les mappers

Dans le menu `User Federation` Ouvrez `ldap-gi4` → onglet *Mappers* :

1. Ouvrez le mapper **first name** : remplacez l'attribut LDAP `cn` par `givenName` → *Save*.
   (Sans cela, le prénom d'Alice serait « Alice Martin ».)
2. *Add mapper* → *Mapper type* **role-ldap-mapper**, *Name* `roles-depuis-groupes-ldap` :

   | Champ | Valeur |
   |-------|--------|
   | LDAP Roles DN | `ou=groups,dc=gi4,dc=lab` |
   | Role Name LDAP Attribute | `cn` |
   | Role Object Classes | `groupOfNames` |
   | Membership LDAP Attribute | `member` |
   | Membership Attribute Type | `DN` |
   | Membership User LDAP Attribute | `uid` |
   | Mode | `READ_ONLY` |
   | User Roles Retrieve Strategy | `LOAD_ROLES_BY_MEMBER_ATTRIBUTE` |
   | Use Realm Roles Mapping | On |

   → *Save*. Les groupes LDAP `etudiant`, `enseignant`, `admin` correspondent ainsi aux rôles du realm du même
   nom, déjà utilisés par les applications.

### d) Synchroniser

Menu *Action* (en haut à droite de `ldap-gi4`) → **Sync all users**.

✅ **Attendu** : un message de succès annonçant **6 utilisateurs importés** (sara, karim, nadia, invite, alice,
bob). Dans *Users* (recherche `*`), chacun porte un lien de fédération `ldap-gi4`. *Users* → alice →
*Role mapping* : `etudiant` est présent ; *Details* : prénom `Alice`.

❌ **Problème si** :
- 0 utilisateur → *Users DN* ou *User object classes* incorrects ;
- « already exists » pour alice ou bob → un compte local n'a pas été supprimé en 3.7 a) ;
- 4 utilisateurs seulement → vous avez synchronisé avant d'ajouter alice et bob : relancez *Sync all users* ;
- rôle absent → vérifiez le mapper de rôles (DN `ou=groups,…`, attribut `member`, type `DN`).

### e) Tester BiblioGI et HelpDesk (sans avoir touché à leur code)

| Test | ✅ Résultat attendu |
|------|--------------------|
| BiblioGI, connexion `alice` / `Alice#2026` | « alice · etudiant » ; le livre emprunté à l'étape 1 est toujours à son nom (le profil a été rattaché au nouveau compte) |
| HelpDesk dans la foulée | Connexion sans saisie (SSO) |
| BiblioGI, `karim` / `Karim#2026` (fenêtre privée) | « karim · enseignant » et le formulaire « Ajouter un livre » |
| BiblioGI, `invite` / `Invite#2026` | « … aucun rôle (etudiant, enseignant, admin) ne vous autorise … » |
| Keycloak, *Users* → alice → modifier l'e-mail | Champs verrouillés ou enregistrement refusé : la fédération est en `READ_ONLY` |

## 3.7 Migrer IntraGI vers l'annuaire (PHP)

### a) La base

Créez `mysql/migration-etape3.sql` :

```sql
-- =====================================================================
--  MIGRATION ÉTAPE 3 : IntraGI ne stocke plus de mot de passe
--  À exécuter en root, UNE seule fois.
-- =====================================================================
USE intragi;
ALTER TABLE users
  DROP COLUMN password_md5,                -- le secret vit désormais dans l'annuaire
  ADD COLUMN last_login DATETIME NULL;
```

```bash
docker compose cp mysql/migration-etape3.sql mysql:/tmp/migration3.sql
docker compose exec mysql sh -c "mysql -uroot -prootpass < /tmp/migration3.sql"
```

✅ Dans Adminer, `intragi.users` n'a plus de colonne `password_md5` et a une colonne `last_login`.

### b) `docker-compose.yml` : variables LDAP d'IntraGI

Dans le service `app3-intra`, complétez `environment:` et `depends_on:` :

```yaml
      # ---- AJOUT ÉTAPE 3 ----
      LDAP_URL: ldap://openldap:389
      LDAP_BIND_DN: cn=readonly,dc=gi4,dc=lab      # compte de service en lecture seule
      LDAP_BIND_PW: readonlypass
      LDAP_PEOPLE_DN: ou=people,dc=gi4,dc=lab
      LDAP_GROUPS_DN: ou=groups,dc=gi4,dc=lab
    depends_on:
      mysql: { condition: service_healthy }
      openldap: { condition: service_started }   # AJOUT ÉTAPE 3
```

### c) Remplacer `app3-intra/Dockerfile`

```dockerfile
FROM php:8.3-apache
# Extension LDAP de PHP (client OpenLDAP)
RUN apt-get update \
 && apt-get install -y --no-install-recommends libldap2-dev \
 && docker-php-ext-configure ldap --with-libdir=lib/$(uname -m)-linux-gnu/ \
 && docker-php-ext-install ldap pdo_mysql \
 && rm -rf /var/lib/apt/lists/*
COPY src/ /var/www/html/
```

### d) Créer `app3-intra/src/inc/ldap.php`

Lisez-le attentivement : c'est le schéma *search then bind*, avec deux protections indispensables (refus du
mot de passe vide, échappement contre l'injection LDAP).

```php
<?php
/* =====================================================================
 *  IntraGI - authentification et annuaire via LDAP (OpenLDAP)
 *
 *  Schéma "search then bind" :
 *   1. bind avec le compte de SERVICE (lecture seule)
 *   2. recherche du DN de l'utilisateur à partir de son uid
 *   3. bind avec le DN de l'utilisateur + le mot de passe saisi
 *      => c'est l'annuaire qui vérifie le mot de passe, pas l'application
 *   4. re-bind en compte de service pour lire ses groupes (= rôles)
 * ===================================================================== */
declare(strict_types=1);

function ldap_env(string $k): string {
    $v = getenv($k);
    if ($v === false || $v === '') throw new RuntimeException("Variable d'environnement $k manquante");
    return $v;
}

/** Connexion + bind du compte de service */
function ldap_service_conn() {
    $c = ldap_connect(ldap_env('LDAP_URL'));
    if ($c === false) throw new RuntimeException('URL LDAP invalide');
    ldap_set_option($c, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($c, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($c, LDAP_OPT_NETWORK_TIMEOUT, 5);
    if (!@ldap_bind($c, ldap_env('LDAP_BIND_DN'), ldap_env('LDAP_BIND_PW'))) {
        throw new RuntimeException('Annuaire injoignable ou compte de service refusé : ' . ldap_error($c));
    }
    return $c;
}

/** Valeur simple d'un attribut dans un résultat ldap_get_entries() */
function ldap_val(array $entry, string $attr): string {
    return (string) ($entry[strtolower($attr)][0] ?? '');
}

/** Groupes (cn) dont le DN donné est membre */
function ldap_user_groups($c, string $userDn): array {
    // Échappement : un DN contient des caractères spéciaux pour un filtre (, = \ ...)
    $filter = '(&(objectClass=groupOfNames)(member=' . ldap_escape($userDn, '', LDAP_ESCAPE_FILTER) . '))';
    $sr = @ldap_search($c, ldap_env('LDAP_GROUPS_DN'), $filter, ['cn']);
    if ($sr === false) return [];
    $groups = [];
    $entries = ldap_get_entries($c, $sr);
    for ($i = 0; $i < $entries['count']; $i++) $groups[] = ldap_val($entries[$i], 'cn');
    return $groups;
}

/** Authentifie uid / mot de passe. Retourne le profil ou null. */
function ldap_authenticate(string $uid, string $password): ?array {
    // ⚠ INDISPENSABLE : un bind avec un mot de passe VIDE est un bind "non authentifié"
    // que certains annuaires (Active Directory, anciens OpenLDAP...) acceptent => connexion sans mot de passe !
    if ($uid === '' || $password === '') return null;

    $c = ldap_service_conn();

    // ⚠ INDISPENSABLE : ldap_escape contre l'injection LDAP (ex. uid = "*")
    $filter = '(&(objectClass=inetOrgPerson)(uid=' . ldap_escape($uid, '', LDAP_ESCAPE_FILTER) . '))';
    $sr = @ldap_search($c, ldap_env('LDAP_PEOPLE_DN'), $filter, ['uid', 'cn', 'mail']);
    if ($sr === false) return null;
    $entries = ldap_get_entries($c, $sr);
    if ($entries['count'] !== 1) return null;            // inconnu (ou ambigu)
    $dn = $entries[0]['dn'];

    // Vérification du mot de passe PAR L'ANNUAIRE
    if (!@ldap_bind($c, $dn, $password)) return null;

    // Retour au compte de service pour lire les groupes
    ldap_bind($c, ldap_env('LDAP_BIND_DN'), ldap_env('LDAP_BIND_PW'));
    $groups = ldap_user_groups($c, $dn);
    ldap_unbind($c);

    return ['uid' => ldap_val($entries[0], 'uid'), 'name' => ldap_val($entries[0], 'cn'),
            'mail' => ldap_val($entries[0], 'mail'), 'dn' => $dn, 'groups' => $groups];
}

/** Recherche dans l'annuaire (page Annuaire) */
function ldap_directory(string $q): array {
    $c = ldap_service_conn();
    $filter = '(objectClass=inetOrgPerson)';
    if ($q !== '') {
        $x = ldap_escape($q, '', LDAP_ESCAPE_FILTER);
        $filter = "(&(objectClass=inetOrgPerson)(|(cn=*$x*)(uid=*$x*)))";
    }
    $sr = @ldap_search($c, ldap_env('LDAP_PEOPLE_DN'), $filter, ['uid', 'cn', 'mail', 'title', 'telephoneNumber']);
    $people = [];
    if ($sr !== false) {
        $entries = ldap_get_entries($c, $sr);
        for ($i = 0; $i < $entries['count']; $i++) {
            $e = $entries[$i];
            $people[] = ['uid' => ldap_val($e, 'uid'), 'name' => ldap_val($e, 'cn'), 'mail' => ldap_val($e, 'mail'),
                         'title' => ldap_val($e, 'title'), 'phone' => ldap_val($e, 'telephoneNumber'),
                         'groups' => ldap_user_groups($c, $e['dn'])];
        }
    }
    ldap_unbind($c);
    usort($people, fn($a, $b) => strcmp($a['name'], $b['name']));
    return $people;
}
```

### e) Modifier `app3-intra/src/inc/bootstrap.php`

Juste après la ligne `session_start();`, ajoutez :

```php
require __DIR__ . '/ldap.php';
```

### f) Remplacer `app3-intra/src/login.php`

```php
<?php
/* ÉTAPE 3 : IntraGI vérifie les identifiants AUPRÈS DE L'ANNUAIRE LDAP (bind).
 * Le mot de passe transite par l'application (contrairement à OIDC) mais n'y est plus stocké. */
require __DIR__ . '/inc/bootstrap.php';

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $p = ldap_authenticate(trim($_POST['uid'] ?? ''), $_POST['password'] ?? '');
    } catch (RuntimeException $ex) {
        $p = null;
        $error = $ex->getMessage();
    }
    if ($p !== null) {
        // AUTORISATION : il faut appartenir à l'un des groupes applicatifs
        if (!array_intersect($p['groups'], ['etudiant', 'enseignant', 'admin'])) {
            $error = 'Authentifié(e) par l\'annuaire, mais aucun groupe (etudiant, enseignant, admin) ne vous autorise à utiliser IntraGI.';
        } else {
            $isAdmin = in_array('admin', $p['groups'], true);
            // Profil local JIT (sans mot de passe)
            db()->prepare('INSERT INTO users (uid, display_name, mail, is_admin, last_login) VALUES (?,?,?,?,NOW()) AS new
                           ON DUPLICATE KEY UPDATE display_name=new.display_name, mail=new.mail,
                                                   is_admin=new.is_admin, last_login=NOW()')
                ->execute([$p['uid'], $p['name'], $p['mail'], $isAdmin ? 1 : 0]);
            session_regenerate_id(true);
            $_SESSION['user'] = ['uid' => $p['uid'], 'name' => $p['name'], 'is_admin' => $isAdmin, 'groups' => $p['groups']];
            header('Location: /'); exit;
        }
    } elseif ($error === null) {
        $error = 'Identifiants invalides.';
    }
}
render_header('Connexion');
?>
<div class="card">
  <h2>Connexion à l'intranet</h2>
  <?php if ($error): ?><div class="alert err"><?= e($error) ?></div><?php endif; ?>
  <form method="post" class="stack"><?= csrf_field() ?>
    <input name="uid" placeholder="Identifiant (uid LDAP)" required autofocus>
    <input name="password" type="password" placeholder="Mot de passe de l'annuaire" required>
    <button>Se connecter</button>
  </form>
  <p class="muted">Identifiants vérifiés par l'annuaire <code>dc=gi4,dc=lab</code>. Remarquez : pas de SSO ici, il faut ressaisir son mot de passe.</p>
</div>
<?php render_footer();
```

### g) Remplacer `app3-intra/src/annuaire.php`

```php
<?php
/* ÉTAPE 3 : l'annuaire du personnel est lu DIRECTEMENT dans LDAP (source unique de vérité) */
require __DIR__ . '/inc/bootstrap.php';
require_login();

$q = trim($_GET['q'] ?? '');
$people = ldap_directory($q);
render_header('Annuaire');
?>
<div class="card">
  <h2>Annuaire <span class="badge">source : LDAP</span></h2>
  <form method="get" style="display:flex;gap:8px;margin-bottom:12px">
    <input name="q" value="<?= e($q) ?>" placeholder="Rechercher un nom..."><button>Rechercher</button>
  </form>
  <table>
    <tr><th>Nom</th><th>Identifiant</th><th>Fonction</th><th>E-mail</th><th>Téléphone</th><th>Groupes</th></tr>
    <?php foreach ($people as $p): ?>
    <tr><td><?= e($p['name']) ?></td><td><code><?= e($p['uid']) ?></code></td><td><?= e($p['title']) ?></td>
        <td><?= e($p['mail']) ?></td><td><?= e($p['phone']) ?></td>
        <td><?php foreach ($p['groups'] as $g): ?><span class="badge"><?= e($g) ?></span> <?php endforeach; ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php render_footer();
```

### h) Remplacer `app3-intra/src/admin.php`

```php
<?php
/* ÉTAPE 3 : les comptes se gèrent dans l'annuaire, plus dans IntraGI */
require __DIR__ . '/inc/bootstrap.php';
require_admin();

$users = db()->query('SELECT uid, display_name, is_admin, last_login FROM users ORDER BY uid')->fetchAll();
render_header('Comptes');
?>
<div class="card">
  <h2>Profils connus d'IntraGI</h2>
  <div class="alert ok">Création, désactivation, mots de passe et groupes : tout se fait désormais dans l'annuaire
    (<a href="http://localhost:8090" target="_blank" rel="noopener">phpLDAPadmin</a>).
    Un profil apparaît ici au premier login.</div>
  <table>
    <tr><th>Identifiant</th><th>Nom</th><th>Admin</th><th>Dernière connexion</th></tr>
    <?php foreach ($users as $x): ?>
    <tr><td><code><?= e($x['uid']) ?></code></td><td><?= e($x['display_name']) ?></td>
        <td><?= $x['is_admin'] ? '✅' : '' ?></td><td class="muted"><?= e($x['last_login']) ?></td></tr>
    <?php endforeach; ?>
  </table>
</div>
<?php render_footer();
```

### i) Reconstruire IntraGI

```bash
docker compose up -d --build app3-intra
```

✅ La construction installe `libldap2-dev` puis compile l'extension `ldap` (une à deux minutes), et
`app3-intra` redémarre.
❌ `Call to undefined function ldap_connect()` dans IntraGI → l'image n'a pas été reconstruite avec le nouveau
Dockerfile.

| Test | ✅ Résultat attendu | ❌ Si vous voyez… |
|------|--------------------|-------------------|
| IntraGI, `alice` / `Alice#2026` | « Bonjour Alice Martin 👋 » et les actualités | « Annuaire injoignable ou compte de service refusé : Can't contact LDAP server » → `openldap` pas prêt, ou `LDAP_URL` erronée |
| IntraGI, `alice` / `alice` (mot de passe de l'étape 1) | « Identifiants invalides. » : l'ancien mot de passe n'existe plus | La connexion réussit → l'image n'a pas été reconstruite |
| IntraGI, `mehdi` / `password` | « Identifiants invalides. » : Mehdi n'est pas dans l'annuaire, son ancien profil ne sert plus à rien | — |
| IntraGI, `invite` / `Invite#2026` | « Authentifié(e) par l'annuaire, mais aucun groupe (etudiant, enseignant, admin) ne vous autorise à utiliser IntraGI. » | — |
| *Annuaire* | Badge « source : LDAP », 6 personnes, colonne *Groupes* avec des badges ; `invite` n'en a aucun | Seulement 4 personnes → alice et bob n'ont pas été ajoutés (3.6) |
| `nadia` → *Comptes* | Bandeau « tout se fait désormais dans l'annuaire », liste des profils (dont les anciens `rh_admin`, `mehdi`…) | — |

## 3.8 Scénarios

| Scénario | ✅ Résultat attendu |
|----------|--------------------|
| **1. Une identité, trois applis** : alice sur BiblioGI, puis HelpDesk, puis IntraGI | BiblioGI : page Keycloak, saisie de `Alice#2026`. HelpDesk : **aucune** saisie (SSO). IntraGI : le **même** mot de passe est accepté, mais il faut le **ressaisir** (pas de SSO avec un bind LDAP) |
| **2. Arrivée** : créez votre propre compte dans l'annuaire (méthode de votre choix), membre de `etudiant` | Vous vous connectez aux trois applications avec le rôle `etudiant`, sans rien faire dans Keycloak ni dans les applications |
| **3. Promotion** : retirez bob de `enseignant` et ajoutez-le à `admin` | Après **reconnexion** : « bob · admin » et *Utilisateurs* dans BiblioGI, *Utilisateurs* dans HelpDesk, *Comptes* dans IntraGI |
| **4. Départ** : supprimez votre compte de l'annuaire | Toute **nouvelle** connexion échoue partout (« Invalid username or password. » chez Keycloak, « Identifiants invalides. » dans IntraGI). Pour une session restée ouverte dans un autre navigateur, notez ce que vous observez |
| **5. Panne** : `docker compose stop openldap` | IntraGI : « Annuaire injoignable … » ; Keycloak : échec des nouvelles connexions. Notez le comportement des sessions déjà ouvertes, puis `docker compose start openldap` |
| **6. Écoute réseau** pendant une connexion à IntraGI | Des lignes défilent à chaque requête LDAP ; lors du login, le DN `uid=…,ou=people,dc=gi4,dc=lab` apparaît en clair. Regardez attentivement ce qui le suit |

Commande du scénario 6 (dans un second terminal, avant de vous connecter à IntraGI) :

```bash
docker run --rm --net container:lab-iam-openldap-1 nicolaka/netshoot tcpdump -i any -A -s0 port 389
```

## 3.9 Dépannage de l'étape 3

| Symptôme | Cause probable | Solution |
|----------|----------------|----------|
| Fédération : *Test connection* échoue | `localhost` au lieu du nom de service | `ldap://openldap:389` |
| Keycloak refuse alice avec `Alice#2026` | Compte local d'alice encore présent dans Keycloak, ou mot de passe LDAP non défini | Supprimer le compte local (3.7 a) ; refaire `ldappasswd` ; tester avec `ldapwhoami` |
| Un utilisateur ajouté à l'annuaire n'apparaît pas dans *Users* | Pas encore synchronisé | Il est importé à sa première connexion, ou *Action* → *Sync all users* |
| Un changement de groupe n'est pas pris en compte | Les rôles sont lus à la connexion ; ou cache Keycloak | Se déconnecter / reconnecter ; *Cache policy* `NO_CACHE` |
| Modifier `bootstrap.ldif` n'a aucun effet | Le fichier n'est lu qu'au **premier** démarrage | Passer par `ldapadd` / phpLDAPadmin, ou réinitialiser l'annuaire seul (voir 3.4 c) |
| `ldap_bind: Invalid credentials (49)` | DN ou mot de passe faux | Vérifier le DN complet, les guillemets autour des mots de passe contenant `#` |
| phpLDAPadmin : « Unable to connect to LDAP server » | `openldap` encore en démarrage | Attendre, puis recharger |
| Tout est très lent (Mac Apple Silicon) | Images OpenLDAP émulées | Normal, patienter |

---

## Avertissement

Ce code est **pédagogique** et volontairement simplifié : HTTP sans TLS, secrets en clair dans `.env` et
`docker-compose.yml`, page « Mon jeton » qui affiche les JWT, LDAP non chiffré. **Ne l'exposez jamais sur un
réseau** et ne réutilisez pas ces mots de passe.
