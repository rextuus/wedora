<?php

declare(strict_types=1);

namespace App\Guest\Form;

use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @phpstan-extends AbstractType<array{password: string}>
 */
// PHPStan-Stubs machen AbstractType generisch, Mago kennt das nicht.
// @mago-expect analysis:excess-template-parameter
final class WeddingPasswordType extends AbstractType
{
    #[Override]
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('password', PasswordType::class, [
            'label' => 'Hochzeitspasswort',
            'help' => 'Steht auf der Einladung oder am Eingang.',
            'attr' => ['autocomplete' => 'current-password', 'autofocus' => true],
            'constraints' => [new Assert\NotBlank(message: 'Bitte gib das Passwort ein.')],
        ]);
    }
}
