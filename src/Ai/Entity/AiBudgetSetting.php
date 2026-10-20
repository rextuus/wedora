<?php

declare(strict_types=1);

namespace App\Ai\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;

/**
 * In der Verwaltung eingestellte Grenzen der Kostenbremse (eine Zeile für die ganze Plattform).
 * Fehlt sie, gelten GEMINI_DAILY_LIMIT / GEMINI_MONTHLY_LIMIT aus der Server-Konfiguration.
 */
#[ORM\Entity]
#[ORM\Table(name: 'ai_budget_setting')]
class AiBudgetSetting
{
    public const int ID = 1;
    public const int MAX = 10_000;

    #[ORM\Id]
    #[ORM\Column]
    private int $id = self::ID;

    #[ORM\Column]
    private int $dailyLimit;

    #[ORM\Column]
    private int $monthlyLimit;

    #[ORM\Column]
    private DateTimeImmutable $updatedAt;

    public function __construct(int $dailyLimit, int $monthlyLimit, DateTimeImmutable $at)
    {
        $this->dailyLimit = 0;
        $this->monthlyLimit = 0;
        $this->updatedAt = $at;
        $this->change($dailyLimit, $monthlyLimit, $at);
    }

    public function change(int $dailyLimit, int $monthlyLimit, DateTimeImmutable $at): void
    {
        if ($dailyLimit < 0 || $monthlyLimit < 0 || $dailyLimit > self::MAX || $monthlyLimit > self::MAX) {
            throw new InvalidArgumentException(sprintf('Grenzen: 0 bis %d.', self::MAX));
        }

        $this->dailyLimit = $dailyLimit;
        $this->monthlyLimit = $monthlyLimit;
        $this->updatedAt = $at;
    }

    public function getDailyLimit(): int
    {
        return $this->dailyLimit;
    }

    public function getMonthlyLimit(): int
    {
        return $this->monthlyLimit;
    }

    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
