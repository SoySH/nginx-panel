<?php

function getDB() {
    static $db = null;

    if ($db === null) {
        $dbFile = '/var/www/panel/data/challenges.db';
        $dataDir = dirname($dbFile);

        if (!is_dir($dataDir)) {
            mkdir($dataDir, 0750, true);
        }

        $db = new PDO("sqlite:$dbFile");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Crear tabla si no existe
        $db->exec("
            CREATE TABLE IF NOT EXISTS challenges (
                id TEXT PRIMARY KEY,
                code TEXT NOT NULL,
                session TEXT NOT NULL,
                action TEXT NOT NULL,
                expires INTEGER NOT NULL,
                used INTEGER DEFAULT 0,
                created_at TEXT,
                verified_at TEXT
            )
        ");
    }

    return $db;
}

function createChallenge($sessionId, $action = 'visudo') {
    try {
        $db = getDB();

        $code = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
        $id   = bin2hex(random_bytes(16));
        $expires = time() + 300;

        // Limpiar expirados
        $stmt = $db->prepare("DELETE FROM challenges WHERE expires < :now");
        $stmt->execute([':now' => time()]);

        $stmt = $db->prepare("
            INSERT INTO challenges (id, code, session, action, expires, created_at)
            VALUES (:id, :code, :session, :action, :expires, :created_at)
        ");

        $stmt->execute([
            ':id' => $id,
            ':code' => $code,
            ':session' => $sessionId,
            ':action' => $action,
            ':expires' => $expires,
            ':created_at' => date('Y-m-d H:i:s')
        ]);

        return [
            'id' => $id,
            'code' => $code,
            'session' => $sessionId,
            'action' => $action,
            'expires' => $expires
        ];

    } catch (Throwable $e) {
        error_log("Challenge create error: " . $e->getMessage());
        return false;
    }
}

function verifyChallenge($code, $sessionId) {
    try {
        $db = getDB();

        $code = strtoupper(trim($code));

        $stmt = $db->prepare("
            SELECT id FROM challenges
            WHERE code = :code
            AND session = :session
            AND expires >= :now
            AND used = 0
            LIMIT 1
        ");

        $stmt->execute([
            ':code' => $code,
            ':session' => $sessionId,
            ':now' => time()
        ]);

        $challenge = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$challenge) {
            return false;
        }

        $stmt = $db->prepare("
            UPDATE challenges
            SET used = 1,
                verified_at = :verified_at
            WHERE id = :id
        ");

        $stmt->execute([
            ':verified_at' => date('Y-m-d H:i:s'),
            ':id' => $challenge['id']
        ]);

        return true;

    } catch (Throwable $e) {
        error_log("Challenge verify error: " . $e->getMessage());
        return false;
    }
}
