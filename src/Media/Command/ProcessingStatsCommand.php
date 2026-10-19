<?php

declare(strict_types=1);

namespace App\Media\Command;

use App\Media\Entity\VideoProcessingRecord;
use App\Media\Processing\ProcessingTimeEstimator;
use App\Media\Repository\VideoProcessingRecordRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:media:processing-stats', description: 'Zeigt Erfahrungswerte und Prognosen der Videoumwandlung')]
final readonly class ProcessingStatsCommand
{
    private const array CLASSES = ['hd' => 'bis 720p', 'fullhd' => 'Full HD', 'uhd' => '4K'];

    public function __construct(
        private VideoProcessingRecordRepository $records,
    ) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Anzahl jüngster Messungen')]
        int $window = 300,
    ): int {
        $total = $this->records->count([]);
        $failed = $this->records->count(['succeeded' => false]);
        $recent = $this->records->recentSuccessful($window);

        $io->title('Videoumwandlung – Erfahrungswerte');
        $io->definitionList(
            ['Messungen gesamt' => (string) $total],
            ['davon fehlgeschlagen' => (string) $failed],
            ['für Prognosen genutzt' => sprintf('%d jüngste erfolgreiche', count($recent))],
            ['Wartezeit in der Schlange (Median)' => sprintf(
                '%.1f s',
                ProcessingTimeEstimator::median(array_map(
                    static fn(VideoProcessingRecord $r): float => $r->getQueueWaitMs() / 1000,
                    $recent,
                )),
            )],
        );

        $rows = [];
        foreach (self::CLASSES as $class => $label) {
            $samples = array_values(array_filter(
                $recent,
                static fn(VideoProcessingRecord $r): bool => (
                    ProcessingTimeEstimator::resolutionClass($r->getInputWidth(), $r->getInputHeight()) === $class
                ),
            ));

            if (count($samples) < ProcessingTimeEstimator::MIN_SAMPLES) {
                $rows[] = [$label, (string) count($samples), 'zu wenig Daten', '–', '–', '–'];

                continue;
            }

            [$fixed, $perSecond] = ProcessingTimeEstimator::fit($samples);
            $rows[] = [
                $label,
                (string) count($samples),
                sprintf('%.1f s + %.2f × Länge', $fixed, $perSecond),
                sprintf('%.0f s', $fixed + (30 * $perSecond)),
                sprintf('%.0f s', $fixed + (60 * $perSecond)),
                sprintf('%.0f s', $fixed + (120 * $perSecond)),
            ];
        }

        $io->table(['Auflösung', 'Messungen', 'Modell', '30-s-Video', '60-s-Video', '120-s-Video'], $rows);
        $io->note(sprintf(
            'Prognosen gibt es ab %d Messungen; reicht eine Klasse nicht, werden alle Klassen gemeinsam genutzt.',
            ProcessingTimeEstimator::MIN_SAMPLES,
        ));

        return Command::SUCCESS;
    }
}
