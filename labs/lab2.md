# 🛠️ LAB 2 : Modèles de Contrôle d'Accès Applicatifs (DAC, MAC, RBAC, ABAC)

**Module :** Introduction à la Sécurité des Systèmes (GI2)
**Enseignant :** Pr. Mohammed NASRI
**Objectif :** Étendre l'application Web PHP/MySQL (Lab 1\) pour implémenter et comparer les quatre grands modèles de contrôle d'accès applicatifs : **DAC**, **MAC**, **RBAC** et **ABAC**.

---

# Exercice 1 : Contrôle d'Accès Discrétionnaire (DAC)

## 📋 Contexte et Travail à réaliser

Dans le modèle **DAC** (*Discretionary Access Control*), le propriétaire d'un document (l'auteur) a le contrôle total sur sa ressource et peut décider d'accorder ou de révoquer explicitement des privilèges de lecture ou d'écriture à d'autres utilisateurs.

### 1\. Modification de la Base de Données

À l'aide de **phpMyAdmin** (http\://localhost:8080) ou en exécutant des requêtes SQL directement sur la base de données tp\_securite, créez la table partages permettant d'associer un document, un utilisateur bénéficiaire et un droit spécifique (lecture ou ecriture) :

SQL

```
CREATE TABLE IF NOT EXISTS `partages` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `document_id` INT NOT NULL,
  `user_id` INT NOT NULL,
  `droit` ENUM('lecture', 'ecriture') NOT NULL,
  FOREIGN KEY (`document_id`) REFERENCES `documents`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`user_id`) REFERENCES `utilisateurs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 2\. Développement des Fonctions PHP de Contrôle

Dans le fichier src/security.php, ajoutez la fonction de vérification du modèle DAC peutAccederDAC(\$user, \$document, \$action) : 
  * Un utilisateur a accès à un document en **lecture** si :  
    * Il est l'auteur du document (\$document\['auteur\_id'\] \== \$user\['id'\]), **OU**  
    * Il existe une entrée dans la table partages pour ce document et cet utilisateur avec le droit lecture ou ecriture.  
  * Un utilisateur a accès à un document en **modification** (ecriture) si :  
    * Il est l'auteur du document, **OU**  
    * Il existe une entrée dans la table partages avec le droit ecriture.

### 3\. Intégration des Contrôles dans le Code Source

1. **Partage de document (lecture.php) :**  
   * Ajoutez un formulaire réservé à l'auteur du document lui permettant de saisir le nom d'un autre utilisateur et de lui attribuer un droit (lecture ou ecriture).  
2. **Protection des accès (lecture.php et modifier.php) :**  
   * Dans lecture.php, appliquez peutAccederDAC(\$currentUser, \$doc, 'lecture'). Interdisez l'affichage si la fonction renvoie false.  
   * Dans modifier.php, appliquez peutAccederDAC(\$currentUser, \$doc, 'ecriture') avant de valider le formulaire de mise à jour.

### 4\. Tests et Validation dans le Navigateur

1. Connectez-vous avec un utilisateur **alice** et créez un document.  
2. Tentez d'accéder à ce document avec un utilisateur **bob** \-\> **Vérifiez le refus d'accès**.  
3. Reconnectez-vous avec **alice** et partagez le document avec **bob** en droit lecture.  
4. Reconnectez-vous avec **bob** \-\> **Vérifiez que la consultation est autorisée mais que la modification reste bloquée**.

# Exercice 2 : Contrôle d'Accès Obligatoire (MAC)

## 📋 Contexte et Travail à réaliser

Contrairement au modèle DAC, le modèle **MAC** (*Mandatory Access Control*) impose une politique de sécurité globale et centralisée définie par l'administrateur système. L'auteur du document ne peut plus passer outre ces règles.

### 1\. Modification de la Base de Données

Exécutez les requêtes suivantes sur la base de données tp\_securite :

1. Ajoutez la colonne niveau\_habilitation (type INT, valeur par défaut 1) dans la table utilisateurs.  
2. Ajoutez la colonne classification\_niveau (type INT, valeur par défaut 1) dans la table documents.

**Échelle des niveaux :** 1 \= Public, 2 \= Confidentiel, 3 \= Secret.

### 2\. Développement des Fonctions PHP de Contrôle

Dans src/security.php, ajoutez la fonction de contrôle d'accès obligatoire basée sur le niveau d'habilitation **peutAccederMAC(\$user, \$document)** :
  * La fonction retourne true si le niveau d'habilitation de l'utilisateur est **supérieur ou égal** à la classification du document (\$user\['niveau\_habilitation'\] \>= \$document\['classification\_niveau'\]).  
  * Dans le cas contraire, elle retourne false.

### 3\. Intégration des Contrôles dans le Code Source

1. Dans lecture.php, appliquez la vérification MAC **en superposition** du contrôle DAC.  
2. Même si un utilisateur a reçu un partage explicite via le modèle DAC (Exercice 1), l'accès doit être strictly bloqué si la règle MAC n'est pas satisfaite.

### 4\. Tests et Validation dans le Navigateur

1. Modifiez dans MySQL l'habilitation de **bob** à 1 (Public) et classez un document à 3 (Secret).  
2. Avec le compte de l'auteur, partagez ce document Secret à **bob** via l'interface DAC.  
3. Connectez-vous avec **bob** et tentez d'ouvrir le document \-\> **Vérifiez que le système (MAC) bloque l'accès malgré le partage accordé par l'auteur**.

# Exercice 3 : Contrôle d'Accès Basé sur les Rôles (RBAC)

## 📋 Contexte et Travail à réaliser

Le modèle **RBAC** (*Role-Based Access Control*) associe les permissions à des rôles professionnels (Etudiant, Enseignant, Admin), puis attribue ces rôles aux utilisateurs.

### 1\. Modification de la Base de Données

Créez les tables nécessaires pour représenter la structure d'un modèle RBAC complet :

SQL

```
CREATE TABLE IF NOT EXISTS `roles` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nom` VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `permissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nom` VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `role_permissions` (
  `role_id` INT NOT NULL,
  `permission_id` INT NOT NULL,
  PRIMARY KEY (`role_id`, `permission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `user_roles` (
  `user_id` INT NOT NULL,
  `role_id` INT NOT NULL,
  PRIMARY KEY (`user_id`, `role_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insertion des données de référence
INSERT INTO `permissions` (`id`, `nom`) VALUES (1, 'DOC_READ'), (2, 'DOC_CREATE'), (3, 'DOC_EDIT');
INSERT INTO `roles` (`id`, `nom`) VALUES (1, 'Etudiant'), (2, 'Enseignant');

-- Attributions des permissions : Etudiant (READ), Enseignant (READ, CREATE, EDIT)
INSERT INTO `role_permissions` (`role_id`, `permission_id`) VALUES (1, 1), (2, 1), (2, 2), (2, 3);
```

### 2\. Développement des Fonctions PHP de Contrôle

Dans src/security.php, implémentez la fonction de vérification des permissions RBAC **aPermissionRBAC(\$user\_id, \$nom\_permission, \$pdo)** :
  * Exécutez une requête SQL qui joint user\_roles, role\_permissions et permissions.  
  * La fonction retourne true si au moins un des rôles de l'utilisateur possède la permission \$nom\_permission.

### 3\. Intégration des Contrôles dans le Code Source

1. **Création (creer.php) :** Exigez la permission DOC\_CREATE avant de charger la page ou de traiter la soumission.  
2. **Modification (modifier.php) :** Exigez la permission DOC\_EDIT.  
3. Interdisez le chargement de la page si l'utilisateur ne possède pas la permission requise.

### 4\. Tests et Validation dans le Navigateur

1. Dans MySQL, attribuez le rôle Etudiant à **bob** et le rôle Enseignant à alice.  
2. Connectez-vous avec **bob** et tentez d'accéder à creer.php via l'URL \-\> **Vérifiez le refus d'accès par manque de permission RBAC**.  
3. Connectez-vous avec **alice** \-\> **Vérifiez que la création et la modification sont autorisées**.

# Exercice 4 : Contrôle d'Accès Basé sur les Attributs (ABAC)

## 📋 Contexte et Travail à réaliser

Le modèle **ABAC** (*Attribute-Based Access Control*) évalue dynamiquement les requêtes d'accès en combinant des attributs liés au sujet (utilisateur), à la ressource (document), à l'action et à l'environnement (contexte spatio-temporel).

### 1\. Modification de la Base de Données

1. Ajoutez la colonne statut (type VARCHAR(20), valeur par défaut 'brouillon') dans la table documents.  
2. Mettez à jour quelques documents au statut 'publie'.

### 2\. Développement des Fonctions PHP de Contrôle

Dans src/security.php, créez le moteur de règles ABAC **peutAccederABAC(\$user, \$document, \$action, \$heureAujourdhui)** :
  * **Règle 1 (Attribut Ressource) :** Si le document a le statut 'brouillon', la consultation (lecture) est autorisée **uniquement** si l'utilisateur est l'auteur du document.  
  * **Règle 2 (Attribut Environnement) :** L'action de modification (ecriture) est autorisée **uniquement pendant les heures ouvrables** (entre 08 et 18 heures).

### 3\. Intégration des Contrôles dans le Code Source

1. **Lecture (lecture.php) :** Appliquez la Règle 1 (ABAC) sur l'état du document (brouillon vs publie).  
2. **Modification (modifier.php) :** Appliquez la Règle 2 (ABAC) en passant l'heure actuelle système date('H').

### 4\. Tests et Validation dans le Navigateur

1. Passez un document en statut 'brouillon' dans MySQL. Connectez-vous avec un utilisateur autre que l'auteur \-\> **Vérifiez que la lecture du brouillon est refusée**.  
2. Pour tester la règle environnementale dans modifier.php, simulez une heure hors plage (ex. \$heureSimulee \= 22;) \-\> **Vérifiez le blocage avec le message : \[VIOLATION ABAC\] Modification interdite en dehors des heures ouvrables (08h-18h)

