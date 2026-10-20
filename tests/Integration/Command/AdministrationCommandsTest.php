<?php

declare(strict_types=1);

namespace App\Tests\Integration\Command;

use App\Account\Repository\UserRepository;
use App\Account\Repository\WeddingMemberRepository;
use App\Account\WeddingPermission;
use App\Tests\Integration\IntegrationTestCase;
use App\Wedding\Repository\InvitationRepository;
use App\Wedding\Repository\WeddingRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AdministrationCommandsTest extends IntegrationTestCase
{
    public function testWeddingInvitationUserAndGrantLifecycle(): void
    {
        self::bootKernel();
        $suffix = bin2hex(random_bytes(3));
        $slug = "cmd-hochzeit-{$suffix}";

        $create = $this->command('app:wedding:create');
        $create->setInputs(['geheim123']);
        $create->execute([
            'slug' => $slug,
            'title' => 'Anna & Lukas',
            '--date' => '2027-07-18',
            '--with-password' => true,
        ]);
        $create->assertCommandIsSuccessful();
        $wedding = self::service(WeddingRepository::class)->findOneBySlug($slug);
        self::assertNotNull($wedding);
        self::assertTrue($wedding->access()->requiresPassword());
        self::assertStringContainsString(
            "/h/{$slug}/join?k=" . $wedding->access()->openLinkToken(),
            self::flat($create),
        );

        $duplicate = $this->command('app:wedding:create');
        self::assertSame(Command::FAILURE, $duplicate->execute(['slug' => $slug, 'title' => 'Doppelt']));

        $invite = $this->command('app:wedding:invite');
        $invite->execute(['slug' => $slug, 'label' => 'Familie Müller', '--expires' => '+30 days']);
        $invite->assertCommandIsSuccessful();
        self::assertSame(1, preg_match('#/i/([A-Za-z0-9_-]{32})#', self::flat($invite), $m));
        $invitation = self::service(InvitationRepository::class)->findOneByToken($m[1]);
        self::assertNotNull($invitation);

        $revoke = $this->command('app:wedding:revoke-invitation');
        $revoke->execute(['id' => $invitation->getId()->toRfc4122()]);
        $revoke->assertCommandIsSuccessful();
        self::assertTrue(self::service(InvitationRepository::class)->findOneByToken($m[1])?->isRevoked());

        $user = $this->command('app:user:create');
        $user->setInputs(['zu-kurz']);
        self::assertSame(Command::INVALID, $user->execute([
            'email' => "paar-{$suffix}@wedora.test",
            'name' => 'Brautpaar',
        ]));
        $user->setInputs(['ein-langes-passwort']);
        $user->execute(['email' => "paar-{$suffix}@wedora.test", 'name' => 'Brautpaar']);
        $user->assertCommandIsSuccessful();
        $account = self::service(UserRepository::class)->findOneByEmail("PAAR-{$suffix}@wedora.test");
        self::assertNotNull($account);
        self::assertFalse($account->isPlatformAdmin());

        $grant = $this->command('app:wedding:grant');
        self::assertSame(Command::INVALID, $grant->execute([
            'slug' => $slug,
            'email' => $account->getEmail(),
            'permissions' => ['alles'],
        ]));
        $grant->execute([
            'slug' => $slug,
            'email' => $account->getEmail(),
            'permissions' => ['view_content', 'moderate'],
            '--label' => 'Brautpaar',
        ]);
        $grant->assertCommandIsSuccessful();
        $member = self::service(WeddingMemberRepository::class)->findOneFor($account, $wedding);
        self::assertSame([WeddingPermission::Moderate, WeddingPermission::ViewContent], $member?->getPermissions());
    }

    public function testScriptFriendlyLinkAndUserCreation(): void
    {
        self::bootKernel();
        $slug = 'link-' . bin2hex(random_bytes(3));
        $this->command('app:wedding:create')->execute(['slug' => $slug, 'title' => 'Mia & Ben']);
        $wedding = self::service(WeddingRepository::class)->findOneBySlug($slug);
        self::assertNotNull($wedding);

        $link = $this->command('app:wedding:link');
        $link->execute(['slug' => $slug, '--plain' => true]);
        $link->assertCommandIsSuccessful();
        self::assertSame(1, preg_match(
            '#^https?://\S+/h/' . $slug . '/join\?k=' . $wedding->access()->openLinkToken() . '$#',
            trim($link->getDisplay()),
        ));
        self::assertSame(Command::FAILURE, $this->command('app:wedding:link')->execute(['slug' => 'gibt-es-nicht']));

        $email = 'skript-' . bin2hex(random_bytes(3)) . '@wedora.test';
        $create = $this->command('app:user:create');
        $create->setInputs(['ein-langes-passwort-123']);
        $create->execute(['email' => $email, 'name' => 'Skript', '--password-stdin' => true], ['interactive' => false]);
        $create->assertCommandIsSuccessful();
        $user = self::service(UserRepository::class)->findOneByEmail($email);
        self::assertNotNull($user);
        self::assertTrue(password_verify('ein-langes-passwort-123', (string) $user->getPassword()));

        $reset = $this->command('app:user:set-password');
        $reset->setInputs(['noch-ein-langes-passwort']);
        $reset->execute(['email' => $email, '--password-stdin' => true], ['interactive' => false]);
        $reset->assertCommandIsSuccessful();
        self::service(EntityManagerInterface::class)->clear();
        $user = self::service(UserRepository::class)->findOneByEmail($email);
        self::assertTrue(password_verify('noch-ein-langes-passwort', (string) $user?->getPassword()));
        self::assertSame(Command::FAILURE, $this->command('app:user:set-password')->execute([
            'email' => 'niemand@wedora.test',
            '--password-stdin' => true,
        ], ['interactive' => false]));

        $short = $this->command('app:user:create');
        $short->setInputs(['kurz']);
        self::assertSame(Command::INVALID, $short->execute([
            'email' => 'x' . $email,
            'name' => 'Skript',
            '--password-stdin' => true,
        ], ['interactive' => false]));
    }

    private function command(string $name): CommandTester
    {
        return new CommandTester(new Application(self::$kernel ?? throw new \LogicException())->find($name));
    }

    private static function flat(CommandTester $tester): string
    {
        return (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
    }
}
