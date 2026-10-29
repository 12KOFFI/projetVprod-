<?php

namespace App\Form;

use App\Dto\RecevabiliteDto;
use App\Enum\StatutRecevabilite;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de la décision de recevabilité du jury local, ouvert uniquement
 * une fois le dossier INSCRIT. Aucun commentaire n'est requis.
 */
class RecevabiliteType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('statut', EnumType::class, [
                'class' => StatutRecevabilite::class,
                'label' => 'Décision de recevabilité',
                'expanded' => true,
                'choice_label' => static fn (StatutRecevabilite $statut): string => $statut->libelle(),
                // Décision favorable d'abord ; « En attente » n'est pas proposé.
                'choices' => [StatutRecevabilite::RECEVABLE, StatutRecevabilite::NON_RECEVABLE],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RecevabiliteDto::class,
            'csrf_token_id' => 'recevabilite',
        ]);
    }
}
