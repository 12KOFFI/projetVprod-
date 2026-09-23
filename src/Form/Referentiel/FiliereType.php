<?php

namespace App\Form\Referentiel;

use App\Entity\Filiere;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** Formulaire de l'écran E2.3 : libellé d'une filière (V2.1). */
class FiliereType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('libelle', TextType::class, [
            'label' => 'Libellé',
            'attr' => ['placeholder' => 'Ex. Bâtiment et travaux publics', 'autofocus' => true],
            'constraints' => [new NotBlank(), new Length(max: 150)],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Filiere::class,
            'csrf_token_id' => 'referentiel_filiere',
        ]);
    }
}
