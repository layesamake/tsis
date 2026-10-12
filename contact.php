<?php
/**
 * Traitement du formulaire « Demander un devis » — site TSIS.
 *
 * Hébergement mutualisé Hostinger (PHP). Aucune dépendance externe.
 * ⚠️  Configurer DESTINATAIRE avant mise en production.
 */

declare(strict_types=1);

// ============================================================
// CONFIGURATION
// ============================================================
const DESTINATAIRE = 'contact@tsis.sn';   // adresse qui reçoit les demandes
const EXPEDITEUR  = 'no-reply@tsis.sn';   // adresse d'envoi (doit appartenir au domaine)
const SUJET_PREFIXE = '[TSIS] Nouvelle demande de devis';
const PAGE_MERCI = '/contact/?sent=1';
const PAGE_ERREUR = '/contact/?error=1';
const LONGUEUR_MAX_MESSAGE = 5000;

// ============================================================
// Utilitaires
// ============================================================
function champ(string $nom): string {
    $v = $_POST[$nom] ?? '';
    if (!is_string($v)) return '';
    return trim(str_replace(["\r", "\n", "%0a", "%0d"], '', $v));
}

function champ_long(string $nom): string {
    $v = $_POST[$nom] ?? '';
    return is_string($v) ? trim($v) : '';
}

function redirection(string $cible): never {
    header('Location: ' . $cible, true, 303);
    exit;
}

// ============================================================
// Contrôles
// ============================================================
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    redirection('/contact/');
}

// Champ piège : rempli => robot
if (champ('site_web') !== '') {
    // On fait comme si tout s'était bien passé, sans rien envoyer.
    redirection(PAGE_MERCI);
}

$nom         = champ('nom');
$email       = champ('email');
$telephone   = champ('telephone');
$besoin      = champ('besoin');
$message     = champ_long('message');
$consentement = $_POST['consentement'] ?? '';

if ($nom === '' || $email === '' || $message === '' || $consentement === '') {
    redirection(PAGE_ERREUR);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    redirection(PAGE_ERREUR);
}

if (strlen($message) > LONGUEUR_MAX_MESSAGE) {
    $message = substr($message, 0, LONGUEUR_MAX_MESSAGE);
}

// ============================================================
// Envoi
// ============================================================
$corps = "Nouvelle demande de devis depuis le site tsis.sn\n\n"
    . "Nom            : {$nom}\n"
    . "Email          : {$email}\n"
    . "Téléphone      : {$telephone}\n"
    . "Type de besoin : {$besoin}\n"
    . "Date           : " . date('d/m/Y H:i') . "\n\n"
    . "Message :\n{$message}\n";

$entetes = [
    'From'         => EXPEDITEUR,
    'Reply-To'     => $email,
    'Content-Type' => 'text/plain; charset=UTF-8',
    'X-Mailer'     => 'PHP/' . phpversion(),
];

$entetesTxt = '';
foreach ($entetes as $k => $v) {
    $entetesTxt .= "{$k}: {$v}\r\n";
}

$sujet = SUJET_PREFIXE . ' — ' . $besoin;

$envoye = @mail(DESTINATAIRE, $sujet, $corps, $entetesTxt);

redirection($envoye ? PAGE_MERCI : PAGE_ERREUR);
