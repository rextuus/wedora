<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Entity\AiBudgetSetting;
use App\Ai\Entity\AiImageUsage;
use App\Wedding\Entity\Wedding;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Kostenbremse für KI-Bilder: harte Obergrenzen pro Tag und Monat, plattformweit über alle Hochzeiten.
 * Gezählt wird jede angeforderte Generierung (auch fehlgeschlagene) – so bleibt die Bremse verlässlich.
 * Tageslimit 0 schaltet die Generierung sofort ab (Notaus).
 *
 * Die Grenzen stellt die Plattformadministration in der Verwaltung ein; solange dort nichts gespeichert ist,
 * gelten GEMINI_DAILY_LIMIT / GEMINI_MONTHLY_LIMIT aus der Server-Konfiguration.
 */
final readonly class AiBudget
{
    public function __construct(
        private EntityManagerInterface $em,
        private ClockInterface $clock,
        #[Autowire(env: 'int:GEMINI_DAILY_LIMIT')]
        private int $dailyLimit,
        #[Autowire(env: 'int:GEMINI_MONTHLY_LIMIT')]
        private int $monthlyLimit,
    ) {}

    public function allows(): bool
    {
        $usage = $this->usage();

        return $usage['today'] < $usage['dailyLimit'] && $usage['month'] < $usage['monthlyLimit'];
    }

    /**
     * @return array{today: int, month: int, dailyLimit: int, monthlyLimit: int, configured: bool}
     */
    public function usage(): array
    {
        $now = $this->now();
        $setting = $this->setting();

        return [
            'today' => $this->countSince($now->setTime(0, 0)),
            'month' => $this->countSince($now->modify('first day of this month')->setTime(0, 0)),
            'dailyLimit' => $setting?->getDailyLimit() ?? max(0, $this->dailyLimit),
            'monthlyLimit' => $setting?->getMonthlyLimit() ?? max(0, $this->monthlyLimit),
            'configured' => null !== $setting,
        ];
    }

    /**
     * Grenzen in der Verwaltung ändern (gilt sofort für alle Hochzeiten).
     *
     * @throws \InvalidArgumentException bei Werten außerhalb 0 bis AiBudgetSetting::MAX
     */
    public function configure(int $dailyLimit, int $monthlyLimit): void
    {
        $setting = $this->setting();
        if (null === $setting) {
            $this->em->persist(new AiBudgetSetting($dailyLimit, $monthlyLimit, $this->now()));
        } else {
            $setting->change($dailyLimit, $monthlyLimit, $this->now());
        }

        $this->em->flush();
    }

    private function setting(): ?AiBudgetSetting
    {
        return $this->em->find(AiBudgetSetting::class, AiBudgetSetting::ID);
    }

    public function record(string $purpose, ?Wedding $wedding): void
    {
        $this->em->persist(new AiImageUsage($purpose, $wedding, $this->now()));
        $this->em->flush();
    }

    private function countSince(DateTimeImmutable $since): int
    {
        return (int) $this->em
            ->createQueryBuilder()
            ->select('COUNT(u.id)')
            ->from(AiImageUsage::class, 'u')
            ->where('u.createdAt >= :since')
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function now(): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($this->clock->now());
    }
}
