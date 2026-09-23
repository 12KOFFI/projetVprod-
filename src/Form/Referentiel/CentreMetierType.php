<?php

namespace App\Form\Referentiel;

use App\Entity\CentreMetier;
use App\Entity\Metier;
use App\Repository\MetierRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Positive;

/**
 * Formulaire de l'écran E2.6 : ouverture d'un métier dans un centre.
 *
 * Le centre n'est jamais un champ : il est imposé par l'URL et affecté par le
 * contrôleur, pour qu'aucune requête forgée ne puisse déplacer une offre vers
 * un autre centre (règle transverse S2). Seuls les métiers actifs sont
 * proposés (R2.7).
 */
class CentreMetierType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('metier', EntityType::class, [
                'class' => Metier::class,
                'choice_label' => 'libelle',
                'label' => 'Métier',
                'placeholder' => 'Sélectionnez un métier',
                'group_by' => static fn (Metier $metier): string => (string) $metier->getFiliere()?->getLibelle(),
                'query_builder' => static fn (MetierRepository $repository) => $repository
                    ->createQueryBuilder('m')
                    ->join('m.filiere', 'f')
                    ->andWhere('m.statut = :actif')
                    ->setParameter('actif', 'actif')
                    ->orderBy('f.libelle', 'ASC')
                    ->addOrderBy('m.libelle', 'ASC'),
                'constraints' => [new NotNull(message: 'Le métier est obligatoire.')],
            ])
            ->add('nbrplace', IntegerType::class, [
                'label' => 'Nombre de places',
                'attr' => ['min' => 1, 'placeholder' => 'Ex. 25'],
                'constraints' => [new Positive(message: 'Le nombre de places doit être supérieur à zéro.')],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CentreMetier::class,
            'csrf_token_id' => 'referentiel_centre_metier',
        ]);
    }
}
