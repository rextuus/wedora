<?php

declare(strict_types=1);

namespace App\Guest;

use App\Guest\Entity\Guest;
use App\Guest\Repository\GuestRepository;
use App\Wedding\Entity\Wedding;
use Doctrine\ORM\EntityManagerInterface;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Merkbares Codewort je Gast („Kuchen13“), um den Zugang wiederherzustellen, falls das Cookie weg ist.
 *
 * Auf der Seite „Ich war schon dabei“ wählt der Gast sein Codewort aus drei aus. Die beiden Köder hängen nur an
 * Hochzeit und Name (nicht am echten Codewort) und sind damit bei jedem Aufruf gleich; sortiert wird alphabetisch.
 * So verrät weder Neuladen noch die Reihenfolge, welches das echte ist.
 */
final readonly class Codeword
{
    /** Kurz, ohne Umlaute, leicht zu merken. */
    private const array WORDS = [
        'Kuchen',
        'Sekt',
        'Torte',
        'Ring',
        'Rose',
        'Tanz',
        'Kerze',
        'Wein',
        'Konfetti',
        'Brezel',
        'Kaffee',
        'Musik',
        'Gitarre',
        'Sonne',
        'Mond',
        'Stern',
        'Herz',
        'Schleier',
        'Fliege',
        'Ballon',
        'Toast',
        'Kuss',
        'Glocke',
        'Taube',
        'Laterne',
        'Lampion',
        'Girlande',
        'Praline',
        'Erdbeere',
        'Zitrone',
        'Limo',
        'Wolke',
        'Feder',
        'Perle',
        'Schleife',
        'Blume',
        'Brautstrauss',
        'Kirsche',
        'Polka',
        'Walzer',
    ];

    public function __construct(
        private GuestRepository $guests,
        private EntityManagerInterface $em,
        #[Autowire('%kernel.secret%')]
        #[SensitiveParameter]
        private string $secret,
    ) {}

    /** Codewort des Gastes; ältere Gäste ohne Codewort bekommen jetzt eins. */
    public function of(Guest $guest): string
    {
        $codeword = $guest->getCodeword();
        if (null === $codeword) {
            $codeword = $this->unused($guest->getWedding());
            $guest->assignCodeword($codeword);
            $this->em->flush();
        }

        return $codeword;
    }

    /** Neues, in der Hochzeit noch freies Codewort. */
    public function unused(Wedding $wedding): string
    {
        do {
            $codeword = self::WORDS[random_int(0, count(self::WORDS) - 1)] . random_int(10, 99);
        } while (null !== $this->guests->findOneBy(['wedding' => $wedding, 'codeword' => $codeword]));

        return $codeword;
    }

    /**
     * Gäste mit diesem Namen (nicht gesperrt, mit Codewort) und die Auswahl: ihre Codewörter plus Köder, mindestens drei.
     *
     * @return array{guests: list<Guest>, choices: list<string>}
     */
    public function choicesFor(Wedding $wedding, string $name): array
    {
        $key = self::normalize($name);
        $guests = array_values(array_filter(
            $this->guests->findBy(['wedding' => $wedding]),
            static fn(Guest $g): bool => (
                !$g->isBlocked()
                && null !== $g->getCodeword()
                && self::normalize($g->getDisplayName()) === $key
            ),
        ));
        if ([] === $guests) {
            return ['guests' => [], 'choices' => []];
        }

        $choices = array_map(static fn(Guest $g): string => (string) $g->getCodeword(), $guests);
        // Köder aus einem festen Zahlenstrom je Hochzeit und Name.
        $stream = hash_hmac('sha512', $wedding->getId()->toRfc4122() . '|' . $key, $this->secret);
        for ($i = 0; count($choices) < 3 && ($i + 6) <= strlen($stream); $i += 6) {
            $n = (int) hexdec(substr($stream, $i, 6));
            $decoy = self::WORDS[$n % count(self::WORDS)] . (10 + (intdiv($n, count(self::WORDS)) % 90));
            if (!in_array($decoy, $choices, true)) {
                $choices[] = $decoy;
            }
        }

        sort($choices);

        return ['guests' => $guests, 'choices' => $choices];
    }

    /** Für Vergleich und Bremse: Groß/klein und Leerzeichen egal. */
    public static function normalize(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
}
