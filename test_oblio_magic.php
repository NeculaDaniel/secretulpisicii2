<?php
// test_oblio_magic.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

echo "<h1>✨ Test Oblio - Metoda Form Data</h1>";

// --- DATELE TALE REALE ---
$email  = 'david.altafini@gmail.com'; 
$secret = '9b....................'; // Pune cheia ta aici
// -------------------------

// 1. Curatam datele de orice spatiu invizibil (foarte important!)
$email = trim($email);
$secret = trim($secret);

echo "<p>Incercam conectarea pentru: <b>$email</b></p>";

// 2. Construim cererea "ca un formular", nu ca JSON
$url = 'https://www.oblio.eu/api/v1/authorize/token';

$postFields = [
    'client_id'     => $email,
    'client_secret' => $secret,
    'grant_type'    => 'client_credentials'
];

$ch = curl_init($url);
// Folosim http_build_query pentru a trimite datele ca x-www-form-urlencoded
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields)); 
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
// NU mai setam Header-ul de Authorization manual, lasam cURL sa faca treaba
// NU mai setam Content-Type: application/json

$result = curl_exec($ch);
$info = curl_getinfo($ch);
curl_close($ch);

echo "<h3>Rezultat:</h3>";
if ($info['http_code'] == 200) {
    $json = json_decode($result, true);
    if (isset($json['access_token'])) {
        echo "<h2 style='color:green'>✅ VICTORIE! Token obtinut: " . substr($json['access_token'], 0, 10) . "...</h2>";
        echo "<p>Aceasta este metoda care functioneaza pe serverul tau!</p>";
    } else {
        echo "<pre>$result</pre>";
    }
} else {
    echo "<h2 style='color:red'>❌ Tot Eroare (" . $info['http_code'] . ")</h2>";
    echo "<pre>$result</pre>";
    echo "<p>Daca primesti 'Invalid client_id', e sigur o problema cu Emailul sau Cheia.</p>";
}
?>