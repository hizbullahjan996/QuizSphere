<?php
/**
 * QuizSphere - Public certificate verification API
 * -------------------------------------------------------------
 * GET. Public (no authentication). Given a certificate id, returns only the
 * public-safe verification fields (student name, achievement, score, issue
 * date) if the certificate is genuine (is_verified = true). Never exposes
 * account, session, or contact information.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    Security::json(['error' => 'method_not_allowed', 'message' => 'Use GET.'], 405);
}

$services = api_services();
if (!$services) {
    exit;
}
['client' => $client] = $services;

$id = (string) ($_GET['id'] ?? '');
if (!preg_match('/^[0-9a-fA-F-]{36}$/', $id)) {
    Security::json(['error' => 'bad_request', 'message' => 'Invalid certificate id.'], 400);
}

$cert = new CertificateService($client);
$row = $cert->verifyPublic($id);

if ($row === null) {
    Security::json([
        'error'        => 'not_found',
        'verified'     => false,
        'message'      => 'This certificate could not be verified.',
    ], 404);
}

Security::json([
    'error'        => null,
    'verified'     => true,
    'certificate'  => $row,
], 200);
