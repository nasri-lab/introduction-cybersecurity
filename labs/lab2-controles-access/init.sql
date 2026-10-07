CREATE DATABASE IF NOT EXISTS `tp_securite` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `tp_securite`;

-- Table utilisateurs SANS droits/attributs de sécurité
DROP TABLE IF EXISTS `documents`;
DROP TABLE IF EXISTS `utilisateurs`;

CREATE TABLE `utilisateurs` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password` VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Table documents SANS classifications
CREATE TABLE `documents` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `titre` VARCHAR(150) NOT NULL,
  `contenu` TEXT NOT NULL,
  `auteur_id` INT NOT NULL,
  FOREIGN KEY (`auteur_id`) REFERENCES `utilisateurs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Données initiales neutres
INSERT INTO `utilisateurs` (`id`, `username`, `password`) VALUES
(1, 'alice', 'alice123'),
(2, 'bob', 'bob123'),
(3, 'charlie', 'charlie123');

INSERT INTO `documents` (`id`, `titre`, `contenu`, `auteur_id`) VALUES
(1, 'Brochure Publique', 'Voici la brochure commerciale de l entreprise.', 2),
(2, 'Rapport d Essai', 'Résultats bruts des essais en laboratoire.', 3),
(3, 'Code Source Secret', 'Algorithme critique du produit R&D principal.', 1);