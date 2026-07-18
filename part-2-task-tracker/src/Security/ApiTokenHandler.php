<?php

declare(strict_types=1);

namespace App\Security;

use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Resolves "Authorization: Bearer tt2_..." into a user.
 *
 * The presented token is hashed with SHA-256 and looked up against the unique
 * token_hash index -- the plain token is never stored anywhere. Any miss
 * (wrong format, unknown, revoked-and-deleted) is the same 401.
 */
final class ApiTokenHandler implements AccessTokenHandlerInterface
{
    /** Plain tokens look like tt2_<64 hex chars> (tt2 = task tracker, part 2). */
    private const TOKEN_PATTERN = '/^tt2_[0-9a-f]{64}$/';

    public function __construct(
        private readonly ApiTokenRepository $tokens,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function getUserBadgeFrom(string $accessToken): UserBadge
    {
        if (preg_match(self::TOKEN_PATTERN, $accessToken) !== 1) {
            throw new BadCredentialsException('Invalid API token.');
        }

        $token = $this->tokens->findOneByHash(hash('sha256', $accessToken));
        if ($token === null) {
            throw new BadCredentialsException('Invalid API token.');
        }

        $token->touchLastUsed();
        $this->em->flush();

        $user = $token->getUser();

        // The closure hands the already-loaded user to the authenticator --
        // no second query through the user provider.
        return new UserBadge($user->getUserIdentifier(), fn () => $user);
    }
}
