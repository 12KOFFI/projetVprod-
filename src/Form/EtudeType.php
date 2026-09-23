<?php

namespace App\Form;

use App\Dto\EtudeDto;
use App\Enum\StatutEtude;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de l'étude du dossier par le conseiller VAE (écran E4.4).
 */
class EtudeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('statut', EnumType::class, [
                'class' => StatutEtude::class,
                'label' => 'Résultat de l\'étude',
                'placeholder' => 'Sélectionnez un résultat',
                'choice_label' => static fn (StatutEtude $statut): string => $statut->libelle(),
                // « En attente » est l'état initial du dossier, pas un résultat que
                // le conseiller puisse rendre : il n'est donc pas proposé.
                'choice_filter' => static fn (?StatutEtude $statut): bool => $statut !== StatutEtude::EN_ATTENTE,
            ])
            ->add('commentaire', TextareaType::class, [
                'label' => 'Motif et observations',
                'required' => false,
                'attr' => [
                    'rows' => 5,
                    'placeholder' => 'Obligatoire en cas de refus : ce motif est communiqué au candidat.',
                ],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => EtudeDto::class,
            'csrf_token_id' => 'etude_dossier',
        ]);
    }
}
