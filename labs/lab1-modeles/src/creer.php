<?php
require_once 'config.php';
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit; }
$currentUser = $_SESSION['user'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $titre = $_POST['titre'] ?? '';
    $contenu = $_POST['contenu'] ?? '';

    if (!empty($titre) && !empty($contenu)) {
        $stmt = $pdo->prepare("INSERT INTO documents (titre, contenu, auteur_id) VALUES (:t, :c, :a)");
        $stmt->execute(['t' => $titre, 'c' => $contenu, 'a' => $currentUser['id']]);
        header('Location: index.php');
        exit;
    } else {
        $error = "Veuillez remplir tous les champs.";
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Créer un document</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="page-app">
    <div class="app-container form-container">
        <div class="content-toolbar">
            <a href="index.php" class="btn btn-outline-secondary btn-sm">← Annuler</a>
        </div>

        <div class="form-card">
            <h1 class="form-title">Nouveau document</h1>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label for="titre" class="form-label">Titre du document</label>
                    <input type="text" id="titre" name="titre" class="form-control" required>
                </div>

                <div class="form-group">
                    <label for="contenu" class="form-label">Contenu</label>
                    <textarea id="contenu" name="contenu" class="form-control" rows="8" required></textarea>
                </div>

                <button type="submit" class="btn btn-success">Enregistrer le document</button>
            </form>
        </div>
    </div>
</body>
</html>