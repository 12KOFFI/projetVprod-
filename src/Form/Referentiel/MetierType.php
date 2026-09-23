<?php

namespace App\Form\Referentiel;

use App\Entity\Filiere;
use App\Entity\Metier;
use App\Repository\FiliereRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\NotNull;

/** Formulaire de l'écran E2.4 : métier rattaché à une filière, actif ou inactif (V2.3). */
class MetierType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('filiere', EntityType::class, [
                'class' => Filiere::class,
                'choice_label' => 'libelle',
                'label' => 'Filière',
                'placeholder' => 'Sélectionnez une filière',
                'query_builder' => static fn (FiliereRepository $repository) => $repository
                    ->createQueryBuilder('f')
                    ->orderBy('f.libelle', 'ASC'),
                'constraints' => [new NotNull(message: 'La filière est obligatoire.')],
            ])
            ->add('libelle', TextType::class, [
                'label' => 'Libellé',
                'attr' => ['placeholder' => 'Ex. Maçonnerie'],
                'constraints' => [new NotBlank(), new Length(max: 150)],
            ])
            ->add('statut', ChoiceType::class, [
                'label' => 'Statut',
                'choices' => [
                    'Actif — proposé aux candidats' => 'actif',
                    'Inactif — retiré des formulaires' => 'inactif',
                ],
                'constraints' => [new Choice(choices: ['actif', 'inactif'])],
                'help' => 'Un métier inactif reste lisible dans les dossiers déjà déposés.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Metier::class,
            'csrf_token_id' => 'referentiel_metier',
        ]);
    }
}
