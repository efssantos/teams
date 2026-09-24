<?php

use GlpiPlugin\Teams\Api\CurlHttpClient;
use GlpiPlugin\Teams\Api\GlpiOAuthClient;
use GlpiPlugin\Teams\Authentication\OAuthService;
use GlpiPlugin\Teams\Authentication\OAuthStateStore;
use GlpiPlugin\Teams\Authentication\TokenStorageService;
use GlpiPlugin\Teams\Service\ConfigurationService;
use GlpiPlugin\Teams\Service\LoggingService;

include '../../../inc/includes.php';

$logger = new LoggingService();
$success = false;
$message = __('The GLPI OAuth link could not be completed.');

try {
    $state = isset($_GET['state']) && is_string($_GET['state']) ? $_GET['state'] : '';
    $code = isset($_GET['code']) && is_string($_GET['code']) ? $_GET['code'] : '';
    $providerError = isset($_GET['error']) && is_string($_GET['error']) ? $_GET['error'] : '';

    if ($providerError !== '') {
        throw new RuntimeException('The user denied the GLPI OAuth authorization.');
    }

    $glpiUserId = (int) Session::getLoginUserID();
    if ($glpiUserId <= 0) {
        throw new RuntimeException('No authenticated GLPI session was found.');
    }

    $oauth = new OAuthService(
        new ConfigurationService(),
        new GlpiOAuthClient(new CurlHttpClient()),
        new OAuthStateStore(),
        new TokenStorageService(),
        $logger
    );

    $mapping = $oauth->completeGlpiLink($state, $code, $glpiUserId);
    $success = true;
    $message = __('The Microsoft Teams user was linked to the GLPI user successfully.');

    $logger->info('OAuth callback completed', [
        'glpi_user_id' => $mapping['glpi_user_id'],
        'teams_tenant_id' => $mapping['teams_tenant_id'],
    ]);
} catch (Throwable $exception) {
    // Do not expose provider responses, authorization codes or token details.
    $logger->error('OAuth callback failed', [
        'error_type' => get_class($exception),
    ]);
}

Html::header(
    __('GLPI Microsoft Teams Integration'),
    $_SERVER['PHP_SELF'],
    'config',
    'plugins',
    'teams'
);

echo '<div class="center">';
echo '<div class="alert ' . ($success ? 'alert-success' : 'alert-danger') . '">';
echo htmlescape($message);
echo '</div>';
echo '</div>';

Html::footer();
