<?php

namespace App\Form\Referentiel;

use App\Entity\Centre;
use App\Entity\Localite;
use App\Repository\LocaliteRepository;
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

/**
 * Formulaire de l'écran E2.5.
 *
 * Les localités sont regroupées par direction régionale : la liste devient
 * longue dès que le référentiel est complet, et le regroupement évite à
 * l'administrateur de confondre deux localités homonymes.
 */
class CentreType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('type', ChoiceType::class, [
                'label' => 'Type de centre',
                'choices' => [
                    'Établissement de formation' => 'etablissement',
                    'Entreprise' => 'entreprise',
                ],
                'placeholder' => 'Sélectionnez un type',
                'constraints' => [new Choice(choices: ['etablissement', 'entreprise'])],
            ])
            ->add('localite', EntityType::class, [
                'class' => Localite::class,
                'choice_label' => 'libelle',
                'label' => 'Localité',
                'placeholder' => 'Sélectionnez une localité',
                'group_by' => static fn (Localite $localite): string => (string) $localite
                    ->getDirectionRegionale()?->getLibelle(),
                'query_builder' => static fn (LocaliteRepository $repository) => $repository
                    ->createQueryBuilder('l')
                    ->join('l.directionRegionale', 'dr')
                    ->orderBy('dr.libelle', 'ASC')
                    ->addOrderBy('l.libelle', 'ASC'),
                'constraints' => [new NotNull(message: 'La localité est obligatoire.')],
            ])
            ->add('nom', TextType::class, [
                'label' => 'Nom du centre',
                'attr' => ['placeholder' => 'Ex. Centre de formation professionnelle de Cocody'],
                'constraints' => [new NotBlank(), new Length(max: 200)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Centre::class,
            'csrf_token_id' => 'referentiel_centre',
        ]);
    }
}
