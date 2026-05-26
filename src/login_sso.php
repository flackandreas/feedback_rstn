<?php
/**
 * src/login_sso.php
 * Demonstrator für eine Single-Sign-On-Schnittstelle (IServ, Nextcloud, Microsoft 365)
 * Beinhaltet Mock-Daten zur Anschauung.
 */

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// In einem echten Szenario würden wir hier eine OIDC (z.B. jumbojett) 
// oder SAML-Bibliothek nutzen, um uns beim Schulserver zu authentifizieren.

// Mock-Auth-Rückgabe von IServ/OIDC:
$sso_response = [
    'email' => 'a.lehrer@beispiel-schule.de',
    'given_name' => 'Anna',
    'family_name' => 'Lehrer',
    'preferred_username' => 'aleh' // Das "Kürzel" in vielen Schulen
];

try {
    $conn = db_connect();
    
    // Checken, ob Lehrer bereits existiert
    $stmt = $conn->prepare("SELECT id, is_admin, name, force_password_change FROM teachers WHERE kuerzel = :kuerzel LIMIT 1");
    $stmt->execute([':kuerzel' => $sso_response['preferred_username']]);
    $user = $stmt->fetch();
    
    // Auto-Register, wenn er zum ersten Mal über SSO reinkommt
    if (!$user) {
        $dummy_password = password_hash(random_bytes(16), PASSWORD_DEFAULT); // SSO Nutzer brauchen kein lokales PW
        $full_name = $sso_response['given_name'] . ' ' . $sso_response['family_name'];
        
        $insert = $conn->prepare("INSERT INTO teachers (kuerzel, email, passwort_hash, is_admin, name, force_password_change) VALUES (?, ?, ?, ?, ?, 0)");
        $insert->execute([$sso_response['preferred_username'], $sso_response['email'], $dummy_password, 0, $full_name]);
        
        $user_id = $conn->lastInsertId();
        $user = [
            'id' => $user_id,
            'is_admin' => 0,
            'name' => $full_name,
            'force_password_change' => 0
        ];
    }
    
    // Local Session starten
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_kuerzel'] = $sso_response['preferred_username'];
    $_SESSION['is_admin'] = $user['is_admin'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['force_password_change'] = $user['force_password_change'];
    
    header("Location: /index.php");
    exit;

} catch (PDOException $e) {
    die("Fehler beim SSO-Login.");
}
?>
