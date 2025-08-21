<?php
/*require_once '../jwt.php';

$headers = getallheaders();

if (!verifyToken($headers)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Unauthorized'
    ]);
    exit;
}*/

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Method not allowed']);
    exit;
}

// PDFファイルのパス
$pdfFile = '/var/www/html/api/extensions/extensions.pdf';

if (!file_exists($pdfFile)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'File not found'
    ]);
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="extensions.pdf"');
http_response_code(200);
readfile($pdfFile);
exit;
?>
