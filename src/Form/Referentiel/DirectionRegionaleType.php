<?php

namespace App\Form\Referentiel;

use App\Entity\DirectionRegionale;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/** Formulaire de l'écran E2.1 : libellé d'une direction régionale (V2.1). */
class DirectionRegionaleType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('libelle', TextType::class, [
            'label' => 'Libellé',
            'attr' => ['placeholder' => 'Ex. Direction Régionale d\'Abidjan 1', 'autofocus' => true],
            'constraints' => [new NotBlank(), new Length(max: 150)],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => DirectionRegionale::class,
            'csrf_token_id' => 'referentiel_direction_regionale',
        ]);
    }
}
