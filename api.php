<?php
/**
 * GLASSEM SA — Proxy API
 * =======================
 * 📂 Emplacement : /public_html/api.php
 *
 * Ce fichier est le SEUL à connaître les secrets.
 * Le navigateur appelle ce proxy, qui appelle Google Apps Script via cURL.
 * Aucun token, aucune URL GAS, aucun domaine n'est jamais envoyé au navigateur.
 */

// ── Sécurité headers ──────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

// ── Charger la config hors webroot ───────────────────────────────

$configPath = dirname(__DIR__) . '/Parametre_VAT_GLASSEM/config.php';

if (!file_exists($configPath)) {
    error_log('[GLASSEM] api.php : config.php introuvable à ' . $configPath);
    http_response_code(500);
    echo json_encode(['status' => 'error', 'error' => 'Configuration serveur manquante.']);
    exit;
}

$cfg = require $configPath;

// ── Vérification des clés de config ──────────────────────────────
foreach (['SCRIPT_URL', 'SECRET_TOKEN', 'DOMAINE', 'TAUX_MOMO'] as $key) {
    if (empty($cfg[$key])) {
        error_log("[GLASSEM] api.php : clé manquante dans config.php → $key");
        http_response_code(500);
        echo json_encode(['status' => 'error', 'error' => 'Configuration incomplète.']);
        exit;
    }
}

$SCRIPT_URL   = $cfg['SCRIPT_URL'];
$SECRET_TOKEN = $cfg['SECRET_TOKEN'];
$DOMAINE      = $cfg['DOMAINE'];
$TAUX_MOMO    = (float) $cfg['TAUX_MOMO'];

// ── Routage par action ────────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Fonction utilitaire cURL
function callGAS(string $url, string $method = 'GET', array $data = []): array {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,   // GAS redirige vers une URL finale
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'GLASSEM-Proxy/1.0',
    ]);

    if ($method === 'POST') {
        $json = json_encode($data);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Content-Length: ' . strlen($json)],
        ]);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        error_log("[GLASSEM] cURL error : $curlErr");
        return ['status' => 'error', 'error' => 'Impossible de joindre le serveur distant.'];
    }

    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log("[GLASSEM] Réponse GAS non-JSON (HTTP $httpCode) : " . substr($response, 0, 200));
        return ['status' => 'error', 'error' => 'Réponse serveur invalide.'];
    }

    return $decoded;
}

// ══════════════════════════════════════════════════════════════════
//  ACTION : charger les clients (GET /api.php?action=clients)
// ══════════════════════════════════════════════════════════════════
if ($method === 'GET' && $action === 'clients') {
    $url  = $SCRIPT_URL . '?token=' . urlencode($SECRET_TOKEN);
    $data = callGAS($url);
    echo json_encode($data);
    exit;
}

// ══════════════════════════════════════════════════════════════════
//  ACTION : soumettre un encaissement (POST /api.php?action=submit)
// ══════════════════════════════════════════════════════════════════
if ($method === 'POST' && $action === 'submit') {

    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);

    if (!$body) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'error' => 'Corps de requête invalide.']);
        exit;
    }

    // ── Validation serveur (défense en profondeur) ────────────────
    $required = ['Date_Encaissement','CAISSIER','Nom_Client','Total_Transaction','MODE_REGLEMENT','NO_REF_ENCAISSEMENT'];
    foreach ($required as $field) {
        if (empty($body[$field])) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'error' => "Champ manquant : $field"]);
            exit;
        }
    }

    // Validation format email caissier
    $caissier = trim($body['CAISSIER']);
    if (!str_ends_with($caissier, $DOMAINE)) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'error' => "Email caissier invalide. Domaine attendu : $DOMAINE"]);
        exit;
    }

    // Validation montant
    $total = filter_var($body['Total_Transaction'], FILTER_VALIDATE_FLOAT);
    if ($total === false || $total <= 0) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'error' => 'Montant invalide.']);
        exit;
    }

    // ── Recalcul des frais MoMo côté serveur (plus fiable) ───────
    $mode  = $body['MODE_REGLEMENT'];
    $frais = 0;
    $net   = $total;

    if ($mode === 'Mobile Money') {
        // On recalcule côté serveur — le client ne peut pas truquer TAUX_MOMO
        $fraisInclus = (bool) ($body['Frais_Inclus'] ?? false);
        if ($fraisInclus) {
            $frais = (int) round($total * $TAUX_MOMO);
            $net   = $total - $frais;
        }
        // Si frais non inclus : frais = 0, net = total (le client supporte les frais)
    }

    // ── Construction du payload vers GAS (on ajoute le token ici) ─
    $payload = [
        'token'                => $SECRET_TOKEN,   // ← jamais envoyé au navigateur
        'Date_Encaissement'    => $body['Date_Encaissement'],
        'CAISSIER'             => $caissier,
        'Nom_Client'           => $body['Nom_Client'],
        'Total_Transaction'    => $total,
        'Frais_Momo'           => $frais,
        'Versement_NetDeFrais' => $net,
        'MODE_REGLEMENT'       => $mode,
        'NO_REF_ENCAISSEMENT'  => trim($body['NO_REF_ENCAISSEMENT']),
    ];

    $data = callGAS($SCRIPT_URL, 'POST', $payload);

    // Renvoyer la réponse GAS au navigateur (sans les secrets)
    echo json_encode($data);
    exit;
}

// ── Route inconnue ────────────────────────────────────────────────
http_response_code(404);
echo json_encode(['status' => 'error', 'error' => 'Route inconnue.']);
