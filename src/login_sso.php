<?php
/**
 * src/login_sso.php
 * Real Single-Sign-On-Schnittstelle für IServ via OpenID Connect (OIDC)
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Konfiguration aus .env auslesen
$iserv_host = $_ENV['ISERV_HOST'] ?? getenv('ISERV_HOST') ?: '';
$client_id = $_ENV['ISERV_CLIENT_ID'] ?? getenv('ISERV_CLIENT_ID') ?: '';
$client_secret = $_ENV['ISERV_CLIENT_SECRET'] ?? getenv('ISERV_CLIENT_SECRET') ?: '';

if (empty($iserv_host) || empty($client_id) || empty($client_secret)) {
    die("SSO-Konfiguration fehlt. Bitte tragen Sie ISERV_HOST, ISERV_CLIENT_ID und ISERV_CLIENT_SECRET in der .env-Datei ein.");
}

// Dynamische Erstellung der Redirect-URI (muss bei IServ registriert sein)
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ? 'https' : 'http';
$redirect_uri = $scheme . '://' . $_SERVER['HTTP_HOST'] . '/login_sso.php';

$client = new \GuzzleHttp\Client();

if (isset($_GET['code'])) {
    // 1. CSRF-Schutz: State validieren
    $state = $_GET['state'] ?? '';
    if (empty($state) || empty($_SESSION['oauth_state']) || !hash_equals($_SESSION['oauth_state'], $state)) {
        die("Ungültiger OAuth-State. Bitte starten Sie den Anmeldevorgang erneut.");
    }
    unset($_SESSION['oauth_state']);

    // 2. OIDC Discovery aufrufen
    try {
        $discovery_url = rtrim($iserv_host, '/') . '/.well-known/openid-configuration';
        $discovery_response = $client->get($discovery_url);
        $discovery_data = json_decode($discovery_response->getBody(), true);
        
        $token_endpoint = $discovery_data['token_endpoint'] ?? '';
        $userinfo_endpoint = $discovery_data['userinfo_endpoint'] ?? '';
        
        if (empty($token_endpoint) || empty($userinfo_endpoint)) {
            throw new \Exception("Token- oder UserInfo-Endpoint fehlt in der OIDC-Konfiguration von IServ.");
        }
    } catch (\Exception $e) {
        error_log("IServ OIDC Discovery fehlgeschlagen: " . $e->getMessage());
        die("Fehler beim Abrufen der OIDC-Konfiguration von IServ.");
    }

    // 3. Autorisierungs-Code gegen Tokens tauschen
    try {
        $token_response = $client->post($token_endpoint, [
            'form_params' => [
                'grant_type' => 'authorization_code',
                'code' => $_GET['code'],
                'redirect_uri' => $redirect_uri,
                'client_id' => $client_id,
                'client_secret' => $client_secret,
            ]
        ]);
        
        $token_data = json_decode($token_response->getBody(), true);
        $access_token = $token_data['access_token'] ?? '';
        if (empty($access_token)) {
            throw new \Exception("Kein Access-Token in Token-Antwort erhalten.");
        }
    } catch (\Exception $e) {
        error_log("IServ Token-Austausch fehlgeschlagen: " . $e->getMessage());
        die("Fehler beim Austausch des Autorisierungs-Codes.");
    }

    // 4. Benutzerinformationen abrufen
    try {
        $userinfo_response = $client->get($userinfo_endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Accept' => 'application/json',
            ]
        ]);
        
        $userinfo_data = json_decode($userinfo_response->getBody(), true);
    } catch (\Exception $e) {
        error_log("IServ UserInfo-Abfrage fehlgeschlagen: " . $e->getMessage());
        die("Fehler beim Abfragen der Benutzerinformationen von IServ.");
    }

    // 5. Benutzerdaten auswerten & anpassen
    $email = $userinfo_data['email'] ?? '';
    $preferred_username = $userinfo_data['preferred_username'] ?? '';
    
    if (empty($preferred_username)) {
        $preferred_username = $userinfo_data['username'] ?? '';
        if (empty($preferred_username) && !empty($email)) {
            $preferred_username = explode('@', $email)[0];
        }
    }
    
    if (empty($preferred_username)) {
        die("Fehler: Das Benutzer-Kürzel konnte nicht aus der IServ-Antwort ermittelt werden.");
    }

    $full_name = $userinfo_data['name'] ?? '';
    if (empty($full_name)) {
        $given_name = $userinfo_data['given_name'] ?? '';
        $family_name = $userinfo_data['family_name'] ?? '';
        $full_name = trim($given_name . ' ' . $family_name);
    }
    if (empty($full_name)) {
        $full_name = $preferred_username;
    }

    // 6. Login / Registrierung in der lokalen DB durchführen
    try {
        $conn = db_connect();
        
        // Prüfen, ob der Lehrer bereits existiert
        $stmt = $conn->prepare("SELECT id, is_admin, name, force_password_change FROM teachers WHERE kuerzel = :kuerzel LIMIT 1");
        $stmt->execute([':kuerzel' => $preferred_username]);
        $user = $stmt->fetch();
        
        // Auto-Register, wenn der Lehrer zum ersten Mal über SSO reinkommt
        if (!$user) {
            $dummy_password = password_hash(random_bytes(16), PASSWORD_DEFAULT); // SSO-Nutzer brauchen kein lokales Passwort
            
            $insert = $conn->prepare("INSERT INTO teachers (kuerzel, email, passwort_hash, is_admin, name, force_password_change) VALUES (?, ?, ?, ?, ?, 0)");
            $insert->execute([$preferred_username, $email, $dummy_password, 0, $full_name]);
            
            $user_id = $conn->lastInsertId();
            $user = [
                'id' => $user_id,
                'is_admin' => 0,
                'name' => $full_name,
                'force_password_change' => 0
            ];
        }
        
        // Lokale Session starten
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_kuerzel'] = $preferred_username;
        $_SESSION['is_admin'] = $user['is_admin'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['force_password_change'] = $user['force_password_change'];
        
        header("Location: /index.php");
        exit;

    } catch (\PDOException $e) {
        error_log("SSO-Datenbankfehler: " . $e->getMessage());
        die("Datenbankfehler während des SSO-Logins.");
    }

} else {
    // 1. OIDC Discovery für Login-Redirect aufrufen
    try {
        $discovery_url = rtrim($iserv_host, '/') . '/.well-known/openid-configuration';
        $discovery_response = $client->get($discovery_url);
        $discovery_data = json_decode($discovery_response->getBody(), true);
        
        $auth_endpoint = $discovery_data['authorization_endpoint'] ?? '';
        
        if (empty($auth_endpoint)) {
            throw new \Exception("Authorization-Endpoint fehlt in der OIDC-Konfiguration von IServ.");
        }
    } catch (\Exception $e) {
        error_log("IServ OIDC Discovery fehlgeschlagen: " . $e->getMessage());
        die("Fehler beim Abrufen der OIDC-Konfiguration von IServ. Bitte überprüfen Sie ISERV_HOST.");
    }

    // 2. State generieren und in Session speichern
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;

    // 3. Zum IServ-Login weiterleiten
    $params = [
        'client_id' => $client_id,
        'redirect_uri' => $redirect_uri,
        'response_type' => 'code',
        'scope' => 'openid profile email',
        'state' => $state
    ];

    header('Location: ' . $auth_endpoint . '?' . http_build_query($params));
    exit;
}

