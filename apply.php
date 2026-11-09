<?php
// apply.php - Marks an offer as applied

require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    echo json_encode(['success' => false, 'error' => 'ID inválido.']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE jobs SET applied = 1 WHERE id = :id");
    $stmt->execute([':id' => $id]);
    
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}