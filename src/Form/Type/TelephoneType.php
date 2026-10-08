<?php

namespace App\Form\Type;

use App\Referentiel\Indicatifs;
use App\Referentiel\Telephone;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Numéro de téléphone avec indicatif : un pays (parmi les 50 d'Indicatifs) et
 * le numéro local. La donnée du modèle est le numéro international complet
 * (« +2250707080910 »), ou null si rien n'est saisi.
 *
 * Le contrôle de longueur (10 chiffres pour +225, 6 à 13 ailleurs) est fait à
 * la transformation : un numéro invalide porte son message sur ce champ.
 */
class TelephoneType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('pays', ChoiceType::class, [
                'label' => 'Indicatif',
                'choices' => Indicatifs::choix(),
                // Libellé court affiché dans le bouton : « +225 ».
                'choice_attr' => static fn (string $code): array => ['data-court' => '+' . Indicatifs::LISTE[$code]],
                'placeholder' => false,
                'required' => true,
            ])
            ->add('numero', TextType::class, [
                'label' => false,
                'required' => $options['required'],
            ]);

        $builder->addModelTransformer(new CallbackTransformer(
            static function (?string $numero): array {
                [$pays, $local] = Telephone::decouper($numero);

                return ['pays' => $pays, 'numero' => Telephone::grouper($local)];
            },
            static function (?array $saisie): ?string {
                try {
                    return Telephone::normaliser(
                        (string) ($saisie['pays'] ?? Indicatifs::PAYS_PAR_DEFAUT),
                        $saisie['numero'] ?? null,
                    );
                } catch (\InvalidArgumentException $erreur) {
                    $echec = new TransformationFailedException($erreur->getMessage());
                    $echec->setInvalidMessage($erreur->getMessage());

                    throw $echec;
                }
            },
        ));
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['chiffres_ci'] = Telephone::CHIFFRES_COTE_D_IVOIRE;
        $view->vars['chiffres_min'] = Telephone::CHIFFRES_MIN;
        $view->vars['chiffres_max'] = Telephone::CHIFFRES_MAX;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'compound' => true,
            'error_bubbling' => false,
            'invalid_message' => 'Ce numéro de téléphone n\'est pas valide.',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'telephone';
    }
}
