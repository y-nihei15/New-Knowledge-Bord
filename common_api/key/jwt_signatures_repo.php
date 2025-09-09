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

        $pub = @file_get_contents($publicKeyPath);
        if ($pub === false) throw new RuntimeException("public key not readable: {$publicKeyPath}");
        $secretHash = hash('sha256', $pub);

        $expiresAt = (new DateTimeImmutable('now'))
            ->add(new DateInterval('P'.$daysValid.'D'))
            ->format('Y-m-d H:i:s');

        $dbName = (string)$this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $useVal = $useVal ?? $this->chooseUseValue($dbName);

        // === id を自前採番 ===
        $this->pdo->beginTransaction();
        try {
            $nextId = (int)$this->pdo
                ->query("SELECT COALESCE(MAX(`id`),0)+1 FROM `jwt_signatures` FOR UPDATE")
                ->fetchColumn();

            $cols   = ['`id`','`key_id`','`secret_hash`','`alg`','`use`','`expires_at`'];
            $vals   = [':id',  ':key_id',  ':secret_hash',  ':alg',  ':use',  ':expires_at'];
            $params = [
                ':id'          => $nextId,
                ':key_id'      => $keyId,
                ':secret_hash' => $secretHash,
                ':alg'         => $alg,
                ':use'         => $useVal,
                ':expires_at'  => $expiresAt,
            ];
            if ($role !== null) {
                $cols[]='`role`'; $vals[]=':role'; $params[':role']=$role;
            }

            $sql = 'INSERT INTO `jwt_signatures` ('.implode(',', $cols).') VALUES ('.implode(',', $vals).')';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }
}
