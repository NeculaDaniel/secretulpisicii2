<?php
// oblio_functions.php - Motorul Facturare + AWB
require_once __DIR__ . '/config.php';

// --- FUNCTII AUTENTIFICARE ---

function getOblioToken() {
    $url = 'https://www.oblio.eu/api/v1/authorize/token';
    $postData = http_build_query([
        'client_id'     => OBLIO_EMAIL,
        'client_secret' => OBLIO_API_SECRET,
        'grant_type'    => 'client_credentials'
    ]);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $result = curl_exec($ch);
    $data = json_decode($result, true);
    curl_close($ch);
    return $data['access_token'] ?? null;
}

function getEcoletToken() {
    $url = 'https://api.e-colet.ro/v2/login'; 
    $payload = json_encode(['username' => ECOLET_USERNAME, 'password' => ECOLET_PASSWORD]);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $result = curl_exec($ch);
    $data = json_decode($result, true);
    curl_close($ch);
    return $data['token'] ?? null;
}

// --- FUNCTIA OBLIO (FACTURA) ---

function sendOrderToOblio($orderId, $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) return ['success' => false, 'message' => 'Comanda negasita'];

        $token = getOblioToken();
        if (!$token) return ['success' => false, 'message' => 'Auth Oblio esuat'];

        $invoice = [
            'cif' => OBLIO_CUI_FIRMA,
            'seriesName' => OBLIO_SERIE,
            'client' => [
                'name' => $order['full_name'],
                'email' => $order['email'],
                'address' => $order['address_line'],
                'city' => $order['city'],
                'state' => $order['county'],
                'country' => 'RO'
            ],
            'products' => [
                ['name' => "Produse Pachet", 'quantity' => 1, 'price' => (floatval($order['total_price']) - SHIPPING_COST), 'vatPercentage' => 0],
                ['name' => "Transport", 'quantity' => 1, 'price' => SHIPPING_COST, 'vatPercentage' => 0]
            ]
        ];

        $ch = curl_init('https://www.oblio.eu/api/v1/docs/invoice');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($invoice));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Authorization: Bearer ' . $token]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($code == 201 || $code == 200) ? ['success' => true] : ['success' => false, 'message' => $res];
    } catch (Exception $e) { return ['success' => false, 'message' => $e->getMessage()]; }
}

// --- FUNCTIA E-COLET (AWB) ---

function generateEcoletAWB($orderId, $pdo) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) return ['success' => false, 'message' => 'Comanda negasita'];

        $token = getEcoletToken();
        if (!$token) return ['success' => false, 'message' => 'Auth Ecolet esuat'];

        $awbData = [
            'sender' => [
                'name' => SENDER_NAME, 'phone' => SENDER_PHONE, 'city' => SENDER_CITY, 'street' => SENDER_STREET, 'country' => 'RO'
            ],
            'receiver' => [
                'name' => $order['full_name'], 'phone' => $order['phone'], 'email' => $order['email'],
                'address' => $order['address_line'], 'city' => $order['city'], 'county' => $order['county']
            ],
            'service' => 'Standard',
            'cod_value' => $order['total_price'],
            'weight' => 1
        ];

        $ch = curl_init('https://api.e-colet.ro/v2/orders');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($awbData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code == 201 || $code == 200) {
            $resp = json_decode($res, true);
            $awb = $resp['data']['awb_number'] ?? 'Generat';
            // Update in baza de date
            $upd = $pdo->prepare("UPDATE orders SET ecolet_awb = ?, ecolet_status = 1 WHERE id = ?");
            $upd->execute([$awb, $orderId]);
            return ['success' => true, 'awb' => $awb];
        }
        return ['success' => false, 'message' => $res];
    } catch (Exception $e) { return ['success' => false, 'message' => $e->getMessage()]; }
}