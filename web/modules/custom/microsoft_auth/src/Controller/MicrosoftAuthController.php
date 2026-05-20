<?php

declare(strict_types=1);

namespace Drupal\microsoft_auth\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Handles Microsoft OAuth2 login/registration flow.
 *
 * Flow:
 *   GET /auth/microsoft          → redirect to Microsoft OAuth
 *   GET /auth/microsoft/callback → exchange code, login or start registration
 */
class MicrosoftAuthController extends ControllerBase
{
    private const AUTHORITY   = 'https://login.microsoftonline.com/common/oauth2/v2.0';
    private const GRAPH_ME    = 'https://graph.microsoft.com/v1.0/me';
    private const SCOPES      = 'openid email profile User.Read Calendars.ReadWrite offline_access';

    // -----------------------------------------------------------------------
    // Step 1: redirect to Microsoft
    // -----------------------------------------------------------------------

    public function redirectToMicrosoft(Request $request): RedirectResponse
    {
        $clientId    = getenv('AZURE_CLIENT_ID') ?: '';
        $redirectUri = getenv('AZURE_REDIRECT_URI') ?: $request->getSchemeAndHttpHost() . '/auth/microsoft/callback';
        $state       = \Drupal::csrfToken()->get('microsoft_oauth');

        $params = http_build_query([
            'client_id'     => $clientId,
            'response_type' => 'code',
            'redirect_uri'  => $redirectUri,
            'scope'         => self::SCOPES,
            'response_mode' => 'query',
            'state'         => $state,
        ]);

        return new RedirectResponse(self::AUTHORITY . '/authorize?' . $params);
    }

    // -----------------------------------------------------------------------
    // Step 2: handle callback from Microsoft
    // -----------------------------------------------------------------------

    public function callback(Request $request): RedirectResponse
    {
        // Validate state to prevent CSRF.
        $state = $request->query->get('state', '');
        if (!$state || !\Drupal::csrfToken()->validate((string) $state, 'microsoft_oauth')) {
            $this->messenger()->addError($this->t('Invalid OAuth state. Please try again.'));
            return new RedirectResponse('/user/login');
        }

        $code  = $request->query->get('code', '');
        $error = $request->query->get('error', '');

        if ($error || !$code) {
            $this->messenger()->addError($this->t('Microsoft login was cancelled or failed: @e', ['@e' => $error]));
            return new RedirectResponse('/user/login');
        }

        try {
            $tokens  = $this->exchangeCode((string) $code, $request);
            $profile = $this->fetchProfile($tokens['access_token']);
        } catch (\Throwable $e) {
            \Drupal::logger('microsoft_auth')->error('OAuth error: @e', ['@e' => $e->getMessage()]);
            $this->messenger()->addError($this->t('Microsoft login failed. Please try again.'));
            return new RedirectResponse('/user/login');
        }

        $email = strtolower(trim($profile['mail'] ?? $profile['userPrincipalName'] ?? ''));
        if (!$email) {
            $this->messenger()->addError($this->t('Could not retrieve email from Microsoft account.'));
            return new RedirectResponse('/user/login');
        }

        // Check if Drupal user already exists.
        $users   = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['mail' => $email]);
        $account = reset($users);

        if ($account && $account->id()) {
            // Existing user: log in and store tokens.
            user_login_finalize($account);
            $this->storeTokensInPlanning(
                $this->getMasterUuid((int) $account->id(), (string) $account->id()),
                $tokens
            );
            $this->messenger()->addStatus($this->t('Logged in with Microsoft.'));
            return new RedirectResponse('/');
        }

        // New user: store data in session and redirect to complete form.
        $request->getSession()->set('microsoft_auth_tokens', $tokens);
        $request->getSession()->set('microsoft_auth_profile', [
            'email'      => $email,
            'first_name' => $profile['givenName']  ?? '',
            'last_name'  => $profile['surname']     ?? '',
        ]);

        return new RedirectResponse('/auth/microsoft/complete');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Exchange authorization code for access + refresh tokens.
     */
    private function exchangeCode(string $code, Request $request): array
    {
        $redirectUri = getenv('AZURE_REDIRECT_URI') ?: $request->getSchemeAndHttpHost() . '/auth/microsoft/callback';

        $response = \Drupal::httpClient()->post(self::AUTHORITY . '/token', [
            'form_params' => [
                'client_id'     => getenv('AZURE_CLIENT_ID'),
                'client_secret' => getenv('AZURE_CLIENT_SECRET'),
                'code'          => $code,
                'redirect_uri'  => $redirectUri,
                'grant_type'    => 'authorization_code',
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);

        if (empty($data['access_token'])) {
            throw new \RuntimeException('No access_token in Microsoft response: ' . json_encode($data));
        }

        return $data;
    }

    /**
     * Fetch user profile from Microsoft Graph API.
     */
    private function fetchProfile(string $accessToken): array
    {
        $response = \Drupal::httpClient()->get(self::GRAPH_ME, [
            'headers' => ['Authorization' => 'Bearer ' . $accessToken],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    /**
     * Send OAuth tokens to planning service for Graph API sync.
     */
    public static function storeTokensInPlanning(string $identityUuid, array $tokens): void
    {
        if (!$identityUuid || empty($tokens['access_token'])) {
            return;
        }

        $planningUrl    = getenv('PLANNING_SERVICE_URL') ?: 'http://planning-planning-service-1:30050';
        $apiTokenSecret = getenv('PLANNING_API_TOKEN_SECRET') ?: '';

        if (!$apiTokenSecret) {
            \Drupal::logger('microsoft_auth')->warning('PLANNING_API_TOKEN_SECRET not set — skipping token storage.');
            return;
        }

        try {
            \Drupal::httpClient()->post($planningUrl . '/api/tokens', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiTokenSecret,
                    'Content-Type'  => 'application/json',
                ],
                'json' => [
                    'identity_uuid' => $identityUuid,
                    'access_token'  => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'] ?? '',
                    'expires_in'    => $tokens['expires_in']    ?? 3600,
                ],
            ]);
            \Drupal::logger('microsoft_auth')->info('Tokens stored in planning for identity_uuid=@id', ['@id' => $identityUuid]);
        } catch (\Throwable $e) {
            \Drupal::logger('microsoft_auth')->warning('Failed to store tokens in planning: @e', ['@e' => $e->getMessage()]);
        }
    }

    /**
     * Get master_uuid for a user (from user.data, fallback to Drupal uid).
     */
    private function getMasterUuid(int $uid, string $fallback): string
    {
        $masterUuid = (string) (\Drupal::service('user.data')->get('registration_form', $uid, 'master_uuid') ?? '');
        return $masterUuid !== '' ? $masterUuid : $fallback;
    }
}
