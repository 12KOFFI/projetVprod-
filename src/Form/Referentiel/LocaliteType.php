<?php

namespace App\Form\Referentiel;

use App\Entity\DirectionRegionale;
use App\Entity\Localite;
use App\Repository\DirectionRegionaleRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;

/** Formulaire de l'écran E2.2 : localité rattachée à une direction régionale. */
class LocaliteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('directionRegionale', EntityType::class, [
                'class' => DirectionRegionale::class,
                'choice_label' => 'libelle',
                'label' => 'Direction régionale',
                'placeholder' => 'Sélectionnez une direction régionale',
                'query_builder' => static fn (DirectionRegionaleRepository $repository) => $repository
                    ->createQueryBuilder('dr')
                    ->orderBy('dr.libelle', 'ASC'),
                'constraints' => [new NotNull(message: 'La direction régionale est obligatoire.')],
            ])
            ->add('libelle', TextType::class, [
                'label' => 'Libellé',
                'attr' => ['placeholder' => 'Ex. Cocody'],
                'constraints' => [new NotBlank(), new Length(max: 150)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Localite::class,
            'csrf_token_id' => 'referentiel_localite',
        ]);
    }
}
