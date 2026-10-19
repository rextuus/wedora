<?php

declare(strict_types=1);

namespace App\Guest\Form;

use App\Guest\Entity\Guest;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @phpstan-extends AbstractType<array{displayName: string}>
 */
// PHPStan-Stubs machen AbstractType generisch, Mago kennt das nicht.
// @mago-expect analysis:excess-template-parameter
final class DisplayNameType extends AbstractType
{
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('displayName', TextType::class, [
            'label' => 'Wie sollen dich die anderen sehen?',
            'help' => 'Zum Beispiel dein Vorname. Du kannst ihn später ändern.',
            'attr' => ['autocomplete' => 'given-name', 'maxlength' => Guest::DISPLAY_NAME_MAX, 'autofocus' => true],
            'constraints' => [
                new Assert\NotBlank(message: 'Bitte gib einen Namen ein.'),
                new Assert\Length(max: Guest::DISPLAY_NAME_MAX, maxMessage: 'Höchstens {{ limit }} Zeichen.'),
                new Assert\Regex(pattern: '/\p{C}/u', match: false, message: 'Der Name enthält ungültige Zeichen.'),
            ],
        ]);
    }
}
