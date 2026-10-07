<?php
require_once 'config.php';
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }

$id = $_GET['id'] ?? null;
$stmt = $pdo->prepare("SELECT d.*, u.username as auteur FROM documents d JOIN utilisateurs u ON d.auteur_id = u.id WHERE d.id = :id");
$stmt->execute(['id' => $id]);
$doc = $stmt->fetch();

if (!$doc) { die("Document introuvable."); }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($doc['titre']) ?></title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="page-app">
    <div class="app-container">
        <div class="content-toolbar">
            <a href="index.php" class="btn btn-outline-secondary btn-sm">← Retour à la liste</a>
        </div>

        <div class="document-card">
            <div class="document-header">
                <h1 class="document-title"><?= htmlspecialchars($doc['titre']) ?></h1>
                <p class="document-meta">Auteur : <strong><?= htmlspecialchars($doc['auteur']) ?></strong></p>
            </div>
            <hr class="divider">
            <div class="document-body">
                <p><?= nl2br(htmlspecialchars($doc['contenu'])) ?></p>
            </div>
        </div>
    </div>
</body>
</html>