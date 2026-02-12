<?php
// config.php - DATE UNICE DE CONFIGURARE
if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) { die('Forbidden'); }

// 1. BAZA DE DATE
define('DB_HOST', 'localhost');
define('DB_NAME', 'alvoro_r1_admin');
define('DB_USER', 'alvoro_r1_user');
define('DB_PASS', 'Parola2020@');

// 2. OBLIO (Facturare)
define('OBLIO_EMAIL', 'david.altafini@gmail.com');
define('OBLIO_API_SECRET', '9b8deadd81e5fa6017575ec822820740a44306c1'); 
define('OBLIO_CUI_FIRMA', '53181323');
define('OBLIO_SERIE', 'ALT');

// 3. E-COLET / ALSENDO (AWB-uri)
// Datele de la Alsendo/E-colet
define('ECOLET_CLIENT_ID',     'PUNE_ID_AICI'); 
define('ECOLET_CLIENT_SECRET', 'PUNE_SECRET_AICI'); 
define('ECOLET_USERNAME',      'PUNE_EMAIL_CONT_AICI'); 
define('ECOLET_PASSWORD',      'PUNE_PAROLA_CONT_AICI'); 

// 4. DATE EXPEDITOR (SENDER) - Cine trimite coletul
define('SENDER_NAME',    'Secretul Pisicii');
define('SENDER_PHONE',   '07xxxxxxxx'); 
define('SENDER_CITY',    'Bucuresti');  
define('SENDER_STREET',  'Strada Exemplu Nr. 1'); 
define('SENDER_COUNTY',  'Bucuresti');

// 5. SETARI GENERALE
define('SHIPPING_COST', 14.00);
define('ADMIN_URL', 'https://secretulpisicii.alvoro.ro/admin.php');