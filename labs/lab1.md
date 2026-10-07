# 🛠️ TP 1 — Exercice 1 : Implémentation du Modèle de Bell-LaPadula

**Module :** Introduction à la Sécurité des Systèmes (GI2)  
**Enseignant :** Pr. Mohammed NASRI  
**Objectif :** Intégrer et valider la logique du modèle formel de **Bell-LaPadula** (sécurité en confidentialité) au sein d'une application Web PHP/MySQL.

---

## 📋 Contexte et Travail à réaliser

L'application Web fournie permet de consulter, créer et modifier des documents sans aucun contrôle d'accès formel. Vous devez implémenter les règles de **Bell-LaPadula** en suivant les 4 étapes ci-dessous.

---

### 1. Modification de la Base de Données

À l'aide de **phpMyAdmin** (`http://localhost:8080`) ou en exécutant des requêtes SQL directement sur la base de données `tp_securite` :

1. Ajoutez la colonne `niveau_habilitation_conf` (type `INT`, valeur par défaut `1`) dans la table `utilisateurs`.
2. Ajoutez la colonne `classification_conf` (type `INT`, valeur par défaut `1`) dans la table `documents`.

> **Échelle des niveaux :** `1` = Public, `2` = Confidentiel, `3` = Secret, `4` = Top Secret.

---

### 2. Développement des Fonctions PHP de Contrôle

Créez le fichier `src/security.php` et définissez-y les deux règles de Bell-LaPadula :

1. **Règle de Lecture — *Simple Security Property* ("No Read Up") :**
   * Implémentez la fonction `peutLireBellLaPadula($user, $document)` qui retourne `true` si le niveau d'habilitation de l'utilisateur est **supérieur ou égal** à la classification du document.
2. **Règle d'Écriture — *Star Property* ($\star$) ("No Write Down") :**
   * Implémentez la fonction `peutEcrireBellLaPadula($user, $classificationDocCible)` qui retourne `true` si la classification du document est **supérieure ou égale** au niveau d'habilitation de l'utilisateur (afin d'éviter les fuites d'informations vers un niveau inférieur).

---

### 3. Intégration des Contrôles dans le Code Source

Incluez `security.php` dans les pages de l'application et appliquez les vérifications :

* **Lecture (`lecture.php`) :** Vérifiez la règle *No Read Up* avant d'afficher un document. Interdisez l'affichage et renvoyez un message d'erreur si la condition n'est pas remplie.
* **Création (`creer.php`) :** Appliquez la règle *No Write Down* sur la classification attribuée au nouveau document lors de la soumission du formulaire.
* **Modification (`modifier.php`) :** Vérifiez la règle *No Write Down* par rapport à la classification du document cible avant d'autoriser la mise à jour.

---

### 4. Tests et Validation dans le Navigateur

1. **Mise à jour des niveaux dans la BDD :**
   * Modifiez directement les enregistrements dans MySQL :
     * Définissez l'habilitation d'un utilisateur `alice` à `4` (Top Secret) et d'un utilisateur `bob` à `1` (Public).
     * Attribuez une classification `4` à un document sensible et `1` à un document public.
2. **Scénarios de validation :**
   * Connectez-vous avec `bob` (`niveau = 1`) et tentez de lire le document de niveau `4` $\rightarrow$ **Vérifiez le blocage en lecture (*No Read Up*)**.
   * Connectez-vous avec `alice` (`niveau = 4`) et tentez de créer ou modifier un document de niveau `1` $\rightarrow$ **Vérifiez le blocage en écriture (*No Write Down*)**.