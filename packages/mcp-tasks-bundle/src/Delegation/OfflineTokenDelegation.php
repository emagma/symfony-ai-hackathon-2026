<?php

declare(strict_types=1);

namespace Emagma\McpTasksBundle\Delegation;

use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Token\PostAuthenticationToken;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * OAuth offline access: the user consents once at the identity provider, the server keeps the offline
 * token, and the worker trades it for a fresh access token each time it acts. That access token goes
 * through the handler of the server's own firewall: same check, same rights, same actor as a request.
 *
 * @internal
 */
final class OfflineTokenDelegation implements DelegationInterface
{
    private const int STATE_LIFETIME = 900;

    private const string FIREWALL = 'mcp_tasks';

    private ?string $actingAs = null;

    private ?\DateTimeImmutable $freshUntil = null;

    public function __construct(
        private readonly TokenVaultInterface $vault,
        private readonly HttpClientInterface $httpClient,
        private readonly AccessTokenHandlerInterface $tokenHandler,
        private readonly TokenStorageInterface $tokenStorage,
        private readonly ClockInterface $clock,
        private readonly string $authorizeUrl,
        private readonly string $tokenUrl,
        private readonly string $clientId,
        #[\SensitiveParameter]
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        #[\SensitiveParameter]
        private readonly string $secret,
    ) {
    }

    public function isAuthorized(string $owner): bool
    {
        return null !== $this->vault->fetch($owner);
    }

    public function authorizationUrl(string $owner): string
    {
        return $this->authorizeUrl . (str_contains($this->authorizeUrl, '?') ? '&' : '?') . http_build_query([
            'client_id' => $this->clientId,
            'response_type' => 'code',
            'scope' => 'openid offline_access',
            'redirect_uri' => $this->redirectUri,
            'state' => $this->state($owner, $this->clock->now()->getTimestamp() + self::STATE_LIFETIME),
        ]);
    }

    /**
     * The return from the identity provider. False unless the token it issued is the one of the user the
     * link was made for: a link opened by someone else gives them nothing and gives nothing of theirs.
     */
    public function complete(string $code, string $state): bool
    {
        $owner = $this->ownerOf($state);
        if (null === $owner) {
            return false;
        }
        $tokens = $this->token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $this->redirectUri]);
        if (null === $tokens || null === $tokens['refresh']) {
            return false;
        }

        try {
            $identified = $this->tokenHandler->getUserBadgeFrom($tokens['access'])->getUserIdentifier();
        } catch (AuthenticationException) {
            return false;
        }
        if ($identified !== $owner) {
            return false;
        }
        $this->vault->store($owner, $tokens['refresh']);

        return true;
    }

    public function actAs(string $owner): void
    {
        $offline = $this->vault->fetch($owner);
        if (null === $offline) {
            $this->release();

            throw new DelegationRevoked('No authorization is on record for the owner of the task.');
        }
        $tokens = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $offline]);
        if (null === $tokens) {
            // Revoked, expired, account disabled: the token is of no use any more.
            $this->vault->forget($owner);
            $this->release();

            throw new DelegationRevoked('The identity provider no longer accepts the authorization of the owner of the task.');
        }
        if (null !== $tokens['refresh'] && $tokens['refresh'] !== $offline) {
            $this->vault->store($owner, $tokens['refresh']);
        }

        try {
            // Fills what a request fills: the grants and the client the token was issued to.
            $badge = $this->tokenHandler->getUserBadgeFrom($tokens['access']);
            $user = $badge->getUser();
        } catch (AuthenticationException $exception) {
            $this->release();

            throw new DelegationRevoked('The token of the owner of the task was refused: ' . $exception->getMessage(), previous: $exception);
        }
        if ($badge->getUserIdentifier() !== $owner) {
            $this->release();

            throw new DelegationRevoked('The token on record is not the one of the owner of the task.');
        }

        $this->tokenStorage->setToken(new PostAuthenticationToken($user, self::FIREWALL, $user->getRoles()));
        $this->actingAs = $owner;
        // A little before it expires: a call must not start with a token about to die.
        $this->freshUntil = $this->clock->now()->modify(\sprintf('+%d seconds', max(1, $tokens['expiresIn'] - 10)));
    }

    public function refresh(): void
    {
        if (null !== $this->actingAs && (null === $this->freshUntil || $this->clock->now() >= $this->freshUntil)) {
            $this->actAs($this->actingAs);
        }
    }

    public function release(): void
    {
        $this->tokenStorage->setToken(null);
        $this->actingAs = null;
        $this->freshUntil = null;
    }

    public function revoke(string $owner): void
    {
        $this->vault->forget($owner);
    }

    /**
     * @param array<string, string> $form
     *
     * @return array{access: string, refresh: string|null, expiresIn: int}|null null when the identity provider refuses
     */
    private function token(array $form): ?array
    {
        try {
            $response = $this->httpClient->request('POST', $this->tokenUrl, [
                'body' => $form + ['client_id' => $this->clientId, 'client_secret' => $this->clientSecret],
            ]);
            if (200 !== $response->getStatusCode()) {
                return null;
            }
            $tokens = $response->toArray(false);
        } catch (HttpExceptionInterface) {
            return null;
        }

        $access = $tokens['access_token'] ?? null;
        $refresh = $tokens['refresh_token'] ?? null;
        if (!\is_string($access) || '' === $access) {
            return null;
        }

        return [
            'access' => $access,
            'refresh' => \is_string($refresh) && '' !== $refresh ? $refresh : null,
            'expiresIn' => is_numeric($tokens['expires_in'] ?? null) ? (int) $tokens['expires_in'] : 60,
        ];
    }

    /**
     * Signed, not stored: who the link is for and until when.
     */
    private function state(string $owner, int $expires): string
    {
        $payload = rtrim(strtr(base64_encode($owner . '|' . $expires), '+/', '-_'), '=');

        return $payload . '.' . hash_hmac('sha256', $payload, $this->secret);
    }

    private function ownerOf(string $state): ?string
    {
        [$payload, $signature] = explode('.', $state, 2) + [1 => ''];
        if (!hash_equals(hash_hmac('sha256', $payload, $this->secret), $signature)) {
            return null;
        }
        $decoded = base64_decode(strtr($payload, '-_', '+/'), true);
        $separator = false === $decoded ? false : strrpos($decoded, '|');
        if (false === $decoded || false === $separator) {
            return null;
        }

        return (int) substr($decoded, $separator + 1) >= $this->clock->now()->getTimestamp() ? substr($decoded, 0, $separator) : null;
    }
}
