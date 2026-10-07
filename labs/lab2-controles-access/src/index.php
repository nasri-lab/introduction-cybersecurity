<?php
require_once 'config.php';
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
$currentUser = $_SESSION['user'];

$stmt = $pdo->query("SELECT d.*, u.username as auteur FROM documents d JOIN utilisateurs u ON d.auteur_id = u.id");
$documents = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Gestionnaire de Documents - Lab Sécurité GI2</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="page-app">
    <div class="app-container">
        <header class="app-header">
            <div class="brand">
                <h1>Plateforme Documentaire GI2</h1>
                <span class="badge badge-info">Lab 1 : Modèles de Sécurité</span>
            </div>
            <div class="user-profile">
                <span class="username"><?= htmlspecialchars($currentUser['username']) ?></span>
                <a href="logout.php" class="btn btn-outline-danger btn-sm">Déconnexion</a>
            </div>
        </header>

        <main class="app-content">
            <div class="content-toolbar">
                <h2>Liste des documents</h2>
                <a href="creer.php" class="btn btn-success">+ Nouveau document</a>
            </div>

            <div class="table-card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Titre</th>
                            <th>Auteur</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($documents as $doc): ?>
                        <tr>
                            <td>#<?= $doc['id'] ?></td>
                            <td class="font-weight-bold"><?= htmlspecialchars($doc['titre']) ?></td>
                            <td><?= htmlspecialchars($doc['auteur']) ?></td>
                            <td class="text-right">
                                <a href="lecture.php?id=<?= $doc['id'] ?>" class="btn btn-primary btn-sm">Consulter</a>
                                <a href="modifier.php?id=<?= $doc['id'] ?>" class="btn btn-secondary btn-sm">Modifier</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</body>
</html>