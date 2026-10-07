-- init.sql
CREATE DATABASE IF NOT EXISTS `tp_securite` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `tp_securite`;

-- 1. Table des utilisateurs avec niveaux de sécurité
DROP TABLE IF EXISTS `utilisateurs`;
CREATE TABLE `utilisateurs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL,
  `niveau_habilitation_conf` INT NOT NULL DEFAULT 1, -- Confidentialité (Bell-LaPadula)
  `niveau_confiance_integ` INT NOT NULL DEFAULT 1     -- Intégrité (Biba)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Table des documents avec classifications
DROP TABLE IF EXISTS `documents`;
CREATE TABLE `documents` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `titre` VARCHAR(150) NOT NULL,
  `contenu` TEXT NOT NULL,
  `auteur_id` INT NOT NULL,
  `classification_conf` INT NOT NULL DEFAULT 1,     -- Confidentialité (Bell-LaPadula)
  `niveau_integ` INT NOT NULL DEFAULT 1,            -- Intégrité (Biba)
  FOREIGN KEY (`auteur_id`) REFERENCES `utilisateurs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. Insertion des utilisateurs de départ
-- Echelle des niveaux : 1 = Public/Faible, 2 = Confidentiel/Moyen, 3 = Secret/Haut, 4 = Top Secret/Très Haut
INSERT INTO `utilisateurs` (`id`, `username`, `password`, `niveau_habilitation_conf`, `niveau_confiance_integ`) VALUES
(1, 'alice', 'alice123', 4, 1),   -- Habilitation Confid. : Top Secret (4) | Confiance Intég. : Faible (1)
(2, 'bob', 'bob123', 1, 4),       -- Habilitation Confid. : Public (1)     | Confiance Intég. : Très Haut (4)
(3, 'charlie', 'charlie123', 2, 2);-- Habilitation Confid. : Confidentiel (2)| Confiance Intég. : Moyen (2)

-- 4. Insertion des documents de départ
INSERT INTO `documents` (`id`, `titre`, `contenu`, `auteur_id`, `classification_conf`, `niveau_integ`) VALUES
(1, 'Brochure Publique', 'Voici la brochure commerciale de l entreprise.', 2, 1, 4),
(2, 'Rapport d Essai', 'Résultats bruts des essais en laboratoire.', 3, 2, 2),
(3, 'Code Source Secret', 'Algorithme critique du produit R&D principal.', 1, 4, 1);