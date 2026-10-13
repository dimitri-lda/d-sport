<?php

declare(strict_types=1);

namespace App\Domain\Identity\Repository;

use App\Domain\Identity\Entity\PasswordResetToken;
use App\Domain\Identity\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/** @extends ServiceEntityRepository<PasswordResetToken> */
class PasswordResetTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PasswordResetToken::class);
    }

    /** Live token for the (case-insensitive) email + raw-token hash pair. */
    public function findValid(
        string $email,
        string $tokenHash,
        \DateTimeImmutable $now,
    ): ?PasswordResetToken {
        $token = $this->createQueryBuilder('t')
            ->join('t.user', 'u')
            ->andWhere('LOWER(u.email) = LOWER(:email)')
            ->andWhere('t.tokenHash = :tokenHash')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('email', $email)
            ->setParameter('tokenHash', $tokenHash)
            ->setParameter('now', $now)
            ->getQuery()
            ->getOneOrNullResult();

        return $token instanceof PasswordResetToken ? $token : null;
    }

    /** One live token per user: replace atomically so resend races stay single. */
    public function replaceFor(
        User $user,
        string $tokenHash,
        \DateTimeImmutable $expiresAt,
    ): PasswordResetToken {
        $em = $this->getEntityManager();

        return $em->wrapInTransaction(function (EntityManagerInterface $em) use ($user, $tokenHash, $expiresAt): PasswordResetToken {
            $em->createQuery(
                'DELETE FROM App\Domain\Identity\Entity\PasswordResetToken t WHERE t.user = :user',
            )->execute(['user' => $user]);

            $token = new PasswordResetToken($user, $tokenHash, $expiresAt);
            $em->persist($token);
            $em->flush();

            return $token;
        });
    }
}
