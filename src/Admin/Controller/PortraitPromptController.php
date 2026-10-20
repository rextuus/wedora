<?php

declare(strict_types=1);

namespace App\Admin\Controller;

use App\Account\WeddingPermission;
use App\Guest\Portrait\PortraitGadget;
use App\Guest\Portrait\PortraitQuestions;
use App\Guest\Portrait\PortraitStyle;
use App\Wedding\CoupleNames;
use App\Wedding\Entity\Wedding;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Zeigt exakt den Prompt, den die App für ein Porträt an Gemini schickt (Stil + Accessoire + Namen des Paares),
 * zum Kopieren und kostenlosen Ausprobieren von Hand. Schickt selbst nichts an Gemini.
 */
#[IsGranted('ROLE_USER')]
#[Route('/admin/h/{slug}/portraet-prompt', requirements: ['slug' => Wedding::SLUG_PATTERN])]
final class PortraitPromptController extends AdminController
{
    #[Route('', name: 'admin_portrait_prompt', methods: ['GET'])]
    public function show(Request $request, string $slug, #[Autowire(env: 'GEMINI_IMAGE_MODEL')] string $model): Response
    {
        $wedding = $this->wedding($slug, WeddingPermission::Configure);
        $names = CoupleNames::of($wedding);
        $style = PortraitStyle::tryFrom($request->query->getString('stil')) ?? PortraitStyle::Line;
        $gadget = PortraitGadget::tryFrom($request->query->getString('accessoire'));

        // Accessoires, die über die Fragen vergeben werden (andere nur noch bei alten Porträts).
        $inUse = [];
        foreach (PortraitQuestions::all($names) as $question) {
            foreach ($question['answers'] as $answer) {
                $inUse[$answer['gadget']->value] = true;
            }
        }

        return $this->page('admin/portrait_prompt.html.twig', $wedding, 'settings', [
            'styles' => PortraitStyle::cases(),
            'gadgets' => PortraitGadget::cases(),
            'inUse' => $inUse,
            'style' => $style,
            'gadget' => $gadget,
            'prompt' => $style->prompt($gadget, $names),
            'model' => $model,
            'initials' => $names->initials(),
        ]);
    }
}
