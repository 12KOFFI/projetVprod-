<?php

namespace App\Form;

use App\Dto\RecevabiliteDto;
use App\Enum\StatutRecevabilite;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire de la décision de recevabilité (écran E4.5), ouvert uniquement
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
                'placeholder' => 'Sélectionnez une décision',
                'choice_label' => static fn (StatutRecevabilite $statut): string => $statut->libelle(),
                'choice_filter' => static fn (?StatutRecevabilite $s): bool => $s !== StatutRecevabilite::EN_ATTENTE,
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
