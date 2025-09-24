<?php
final class JwtSignaturesRepo {
    private PDO $pdo;
    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    private function chooseUseValue(string $dbName): string {
        $q = "SELECT DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH
              FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'jwt_signatures' AND COLUMN_NAME = 'use'";
        $def = $this->pdo->prepare($q);
        $def->execute([':db' => $dbName]);
        $c = $def->fetch(PDO::FETCH_ASSOC);

        $desired = 'sig';
        if (!$c) return $desired;

        $dataType = strtolower($c['DATA_TYPE'] ?? '');
        $colType  = strtolower($c['COLUMN_TYPE'] ?? '');
        $maxLen   = (int)($c['CHARACTER_MAXIMUM_LENGTH'] ?? 0);

        if ($dataType === 'enum' && preg_match_all("/'([^']+)'/", $colType, $m)) {
            $allowed = $m[1];
            foreach (['sig','enc','signature','encryption'] as $d) {
                if (in_array($d, $allowed, true)) return $d;
            }
            return $allowed[0];
        }
        if (in_array($dataType, ['tinyint','smallint','int','bigint'], true)) {
            return '1';
        }
        if (in_array($dataType, ['char','varchar'], true) && $maxLen > 0) {
            return substr($desired, 0, $maxLen);
        }
        return $desired;
    }

    public function insertSignatureRow(
    string $keyId,
    string $publicKeyPath,
    ?string $role = null,
    int $daysValid = 365,
    string $alg = 'RS256',
    ?string $useVal = null
): void {
    $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 公開鍵読み込み
    $pub = @file_get_contents($publicKeyPath);
    if ($pub === false) {
        throw new RuntimeException("public key not readable: {$publicKeyPath}");
    }

    // VARBINARY(65) に入るよう生バイトでハッシュ（32バイト）
    $secretHash = hash('sha256', $pub, true);

    // 失効日時
    $expiresAt =  (new DateTimeImmutable('now'))
        ->add(new DateInterval('PT3600S'))
        ->format('Y-m-d H:i:s');
    $scheduledDeletionAt = (new DateTimeImmutable('now'))
        ->add(new DateInterval('P'.$daysValid.'D'))
        ->format('Y-m-d H:i:s');

    // use の選択（enum: 'jwt' | 'hmac' | 'both'）
    $dbName = (string)$this->pdo->query('SELECT DATABASE()')->fetchColumn();
    $useVal = $useVal ?? $this->chooseUseValue($dbName);

    // スキーマのデフォルトに合わせる
    $role    = $role   ?? 'users';
    $status  = 'active';
    $isRevoked = 0;
    $revokedAt = null;

    $this->pdo->beginTransaction();
    try {
        // id を手動採番（テーブルが AUTO_INCREMENT でない前提）
        $nextId = (int)$this->pdo
            ->query('SELECT COALESCE(MAX(`id`),0)+1 FROM `jwt_signatures` FOR UPDATE')
            ->fetchColumn();

        $sql = '
            INSERT INTO `jwt_signatures`
                (`id`, `key_id`, `role`, `issued_at`, `status`, `is_revoked`,
                 `revoked_at`, `scheduled_deletion_at`, `secret_hash`, `alg`, `use`, `expires_at`)
            VALUES
                (:id, :key_id, :role, NOW(), :status, :is_revoked,
                 :revoked_at, :scheduled_deletion_at, :secret_hash, :alg, :use, :expires_at)
        ';
        $stmt = $this->pdo->prepare($sql);

        $stmt->bindValue(':id', $nextId, PDO::PARAM_INT);
        $stmt->bindValue(':key_id', $keyId, PDO::PARAM_STR);
        $stmt->bindValue(':role', $role, PDO::PARAM_STR);
        $stmt->bindValue(':status', $status, PDO::PARAM_STR);
        $stmt->bindValue(':is_revoked', $isRevoked, PDO::PARAM_INT);
        $stmt->bindValue(':revoked_at', $revokedAt, PDO::PARAM_NULL);
        $stmt->bindValue(':scheduled_deletion_at', $scheduledDeletionAt, PDO::PARAM_NULL);
        $stmt->bindValue(':secret_hash', $secretHash, PDO::PARAM_LOB); // 生バイト
        $stmt->bindValue(':alg', $alg, PDO::PARAM_STR);
        $stmt->bindValue(':use', $useVal, PDO::PARAM_STR);
        $stmt->bindValue(':expires_at', $expiresAt, PDO::PARAM_STR);

        $stmt->execute();
        $this->pdo->commit();
    } catch (\Throwable $e) {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
        throw $e;
    }
}
}
