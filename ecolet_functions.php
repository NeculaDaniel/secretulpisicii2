<?php
/**
 * ecolet_functions.php
 * Rescris identic cu logica oclar.ro/api/services/ecolet.js
 *
 * Auth:     grant_type=password (username + password) — NU client_credentials
 * Endpoint: /add-parcel/save-order-to-send
 * Returneaza: order_to_send_id (draft) — AWB-ul se genereaza de Ecolet ulterior
 */

require_once __DIR__ . '/config.php';

// ============================================================
// 1. AUTENTIFICARE — identic cu oclar authenticate()
// ============================================================
function getEcoletToken() {
    $cacheDir  = __DIR__ . '/cache';
    if (!is_dir($cacheDir)) @mkdir($cacheDir, 0755, true);
    $tokenFile = $cacheDir . '/ecolet_token.json';

    if (file_exists($tokenFile)) {
        $cached = json_decode(file_get_contents($tokenFile), true);
        if ($cached && isset($cached['access_token']) && time() < ($cached['expires_at'] - 300)) {
            return $cached['access_token'];
        }
    }

    $params = http_build_query([
        'grant_type'    => 'password',
        'client_id'     => ECOLET_CLIENT_ID,
        'client_secret' => ECOLET_CLIENT_SECRET,
        'username'      => ECOLET_USERNAME,
        'password'      => ECOLET_PASSWORD,
    ]);

    $ch = curl_init(ECOLET_BASE_URL . '/oauth/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $params,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $result   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($result === false) throw new Exception("Ecolet auth curl error: " . $curlErr);

    $data = json_decode($result, true);
    if ($httpCode !== 200 || !isset($data['access_token'])) {
        throw new Exception("Ecolet Auth Failed ($httpCode): " . ($data['message'] ?? $result));
    }

    @file_put_contents($tokenFile, json_encode([
        'access_token' => $data['access_token'],
        'expires_at'   => time() + ($data['expires_in'] ?? 3600),
    ]));

    return $data['access_token'];
}

// ============================================================
// 2. CAUTA LOCALITY ID — identic cu oclar getLocalityId()
// ============================================================
function getLocalityId($token, $county, $city) {
    if (!$county || !$city) return null;

    // Curata diacritice si prefixe — identic cu oclar cleanName()
    $clean = function($str) {
        if (!$str) return '';
        $str = mb_strtolower($str, 'UTF-8');
        $str = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str);
        $str = preg_replace('/\b(judetul|județul|county|district|comuna|sat|municipiul|orasul|oras)\b/', '', $str);
        $str = preg_replace('/[^a-z0-9\s]/', '', $str);
        return trim($str);
    };

    $simpleCity   = $clean($city);
    $simpleCounty = $clean($county);
    $searchQuery  = $simpleCity . ' ' . $simpleCounty;

    $url = rtrim(ECOLET_BASE_URL, '/') . '/locations/ro/localities/' . rawurlencode($searchQuery);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $result   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data       = json_decode($result, true);
        $localities = $data['localities'] ?? (is_array($data) ? $data : []);

        if (!empty($localities)) {
            foreach ($localities as $l) {
                $lCounty = $clean($l['county']['name'] ?? '');
                if (strpos($lCounty, $simpleCounty) !== false || strpos($simpleCounty, $lCounty) !== false) {
                    return $l['id'];
                }
            }
            return $localities[0]['id'];
        }
    }

    // Retry cu doar orasul
    $retryUrl = rtrim(ECOLET_BASE_URL, '/') . '/locations/ro/localities/' . rawurlencode($simpleCity);
    $ch2      = curl_init($retryUrl);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $result2   = curl_exec($ch2);
    $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    if ($httpCode2 === 200) {
        $data2 = json_decode($result2, true);
        $locs2 = $data2['localities'] ?? (is_array($data2) ? $data2 : []);
        foreach ($locs2 as $l) {
            $lCounty = $clean($l['county']['name'] ?? '');
            if (strpos($lCounty, $simpleCounty) !== false) return $l['id'];
        }
        if (!empty($locs2)) return $locs2[0]['id'];
    }

    return null;
}

// ============================================================
// 3. GENEREAZA AWB — identic cu oclar createDraftShipment()
// ============================================================
function generateEcoletAWB($orderId) {
    global $pdo;
    if (!isset($pdo)) $pdo = getDbConnection();

    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) throw new Exception("Comanda #$orderId nu exista.");

    $token = getEcoletToken();

    $targetCounty = $order['county'] ?? 'Bucuresti';
    $targetCity   = $order['city']   ?? 'Bucuresti';

    $isEasyBox = ($order['shipping_method'] === 'easybox' && !empty($order['easybox_locker_id']));

    // --- Adresa receiver — identic cu oclar ---
    if ($isEasyBox) {
        $rawInfo              = $order['address_line'] ?? '';
        $streetName           = $rawInfo;
        $streetNumber         = '.';
        $observations         = 'Locker: ' . $rawInfo . ' | Comanda #' . $orderId;
    } else {
        $streetName   = $order['address_line'] ?? 'Strada Principala';
        $streetNumber = '.';
        $observations = 'Comanda #' . $orderId;
    }

    // --- Locality ID dinamic ---
    $localityId = getLocalityId($token, $targetCounty, $targetCity);
    if (!$localityId) {
        $localityId = 323; // Fallback Bucuresti
    }

    $pickupDate = date('Y-m-d', strtotime('+1 day'));

    // --- Serviciu curier --- identic cu oclar ---
    $courierService = 'dpd_standard';
    $contractId     = defined('ECOLET_DPD_CONTRACT_ID') ? (int)ECOLET_DPD_CONTRACT_ID
                    : (defined('ECOLET_CONTRACT_ID') ? (int)ECOLET_CONTRACT_ID : 4);

    if ($isEasyBox) {
        $rawInfo  = strtoupper($order['address_line'] ?? '');
        if (strpos($rawInfo, 'SAMEDAY') !== false) {
            $courierService = 'sameday_locker';
            $contractId     = defined('ECOLET_SAMEDAY_CONTRACT_ID') ? (int)ECOLET_SAMEDAY_CONTRACT_ID : $contractId;
        } elseif (strpos($rawInfo, 'FAN') !== false || strpos($rawInfo, 'FANBOX') !== false) {
            $courierService = 'fan_courier_courier_to_locker';
        } elseif (strpos($rawInfo, 'CARGUS') !== false) {
            $courierService = 'easy_collect_locker_s';
        } elseif (strpos($rawInfo, 'DPD') !== false) {
            $courierService = 'dpd_automat_courier_to_locker';
        } else {
            $courierService = 'sameday_locker';
        }
    }

    // --- COD (ramburs) — NU la easybox ---
    // 'ramburs' in DB (oclar foloseste 'cash', secretulpisicii foloseste 'ramburs')
    $isCOD = (in_array($order['payment_method'], ['ramburs', 'cash']) && !$isEasyBox);

    // --- PAYLOAD FINAL — identic cu oclar ---
    $payload = [
        'sender' => [
            'name'           => ECOLET_SENDER_NAME,
            'country'        => 'ro',
            'county'         => ECOLET_SENDER_COUNTY,
            'locality_id'    => (int)ECOLET_SENDER_LOCALITY_ID,
            'locality'       => ECOLET_SENDER_CITY,
            'postal_code'    => ECOLET_SENDER_POSTAL,
            'street_name'    => ECOLET_SENDER_STREET,
            'street_number'  => defined('ECOLET_SENDER_NUMBER') ? ECOLET_SENDER_NUMBER : '1',
            'contact_person' => ECOLET_SENDER_NAME,
            'email'          => defined('ECOLET_SENDER_EMAIL') ? ECOLET_SENDER_EMAIL : ADMIN_EMAIL,
            'phone'          => defined('ECOLET_SENDER_PHONE') ? ECOLET_SENDER_PHONE : '0700000000',
            'has_map_point'  => false,
            'map_point_id'   => null,
        ],
        'receiver' => [
            'name'           => $order['full_name'],
            'country'        => 'ro',
            'county'         => $targetCounty,
            'locality_id'    => $localityId,
            'locality'       => $targetCity,
            'postal_code'    => $order['postal_code'] ?? '000000',
            'street_name'    => $streetName,
            'street_number'  => $isEasyBox ? '.' : $streetNumber,
            'contact_person' => $order['full_name'],
            'email'          => $order['email'] ?? '',
            'phone'          => preg_replace('/\s+/', '', $order['phone'] ?? '0700000000'),
            'has_map_point'  => $isEasyBox,
            'map_point_id'   => $isEasyBox ? (int)$order['easybox_locker_id'] : null,
        ],
        'parcel' => [
            'type'         => 'package',
            'weight'       => 1,
            'dimensions'   => ['length' => 20, 'width' => 20, 'height' => 10],
            'content'      => 'Perie Nano-Steam',
            'observations' => $observations,
            'shape'        => 'standard',
            'amount'       => 1,
        ],
        'additional_services' => [
            'cod' => [
                'status' => $isCOD,
                'amount' => $isCOD ? floatval($order['total_price']) : 0,
            ],
        ],
        'courier' => [
            'service'     => $courierService,
            'pickup'      => ['type' => 'courier', 'date' => $pickupDate, 'time' => '12:00'],
            'contract_id' => $contractId,
        ],
    ];

    $ch = curl_init(rtrim(ECOLET_BASE_URL, '/') . '/add-parcel/save-order-to-send');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
    ]);

    $result   = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($result === false) throw new Exception("Ecolet curl error: " . $curlErr);

    $response = json_decode($result, true);

    if (!($httpCode >= 200 && $httpCode < 300)) {
        $msg = $response['message'] ?? 'Eroare necunoscuta';
        if (!empty($response['errors'])) {
            $msg .= ' | ' . implode(', ', array_map(fn($v) => implode(', ', (array)$v), $response['errors']));
        }
        throw new Exception("Ecolet ($httpCode): $msg");
    }

    // Oclar returneaza order_to_send_id — asta salvam ca "AWB" (draft)
    $shipmentId = $response['order_to_send_id'] ?? $response['id'] ?? null;

    if (!$shipmentId) {
        throw new Exception("Ecolet: nu s-a primit order_to_send_id. Raspuns: " . substr($result, 0, 300));
    }

    $pdo->prepare("UPDATE orders SET awb_number = ?, ecolet_status = 1 WHERE id = ?")
        ->execute([$shipmentId, $orderId]);

    return "Draft creat cu succes! ID: $shipmentId (serviciu: $courierService)";
}